<?php

namespace App\Livewire\Platform;

use App\Jobs\ShipLogsToLokiJob;
use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Models\AuditEvent;
use App\Models\LogEntry;
use App\Services\Logging\LokiClient;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Instance-wide log collection: user activity (audit events), Coolify system logs and
 * application container logs, with export and shipping to Loki for Grafana.
 *
 * [한국어] 플랫폼 관리자 로그 화면(/platform/logs)의 동작입니다. 화면 파일: resources/views/livewire/platform/logs.blade.php
 * 하는 일: ① 수집 켜기/끄기(마스터 + 항목별) ② 로그 조회·검색 ③ 내보내기 ④ Loki 연결 설정·테스트.
 * 사용자 활동(감사 로그)은 보안 기록이라 항상 수집되며 끌 수 없습니다. 시스템·컨테이너 로그 보관 기간은 최소값(security.audit.system_log_min_retention_days) 이상이어야 합니다.
 * 화면을 열 때와 설정 저장·내보내기 같은 동작은 서버에서 플랫폼 관리자인지 확인합니다. 새 동작을 추가할 때도 같은 확인을 넣으세요.
 * 감사자(users.is_auditor)는 viewAuditLogs 게이트로 조회·검색·내보내기만 할 수 있습니다. 설정을 바꾸는 동작은 모두 authorizePlatformAdmin()으로 막습니다.
 *
 * Auditors (`users.is_auditor`) pass the `viewAuditLogs` gate: they may view, filter and export every
 * team's logs, but every action that changes settings still requires the platform admin gate.
 */
class Logs extends Component
{
    use AuthorizesPlatformAdmin;
    use WithPagination;

    public const SOURCE_AUDIT = 'audit';

    public const SOURCES = [self::SOURCE_AUDIT, LogEntry::SOURCE_SYSTEM, LogEntry::SOURCE_CONTAINER];

    public const EXPORT_LIMIT = 10000;

    public bool $isLogCollectionEnabled = false;

    public bool $isAuditLogCollectionEnabled = true;

    public bool $isSystemLogCollectionEnabled = true;

    public bool $isContainerLogCollectionEnabled = false;

    public int $logRetentionDays = 180;

    public bool $isLokiShippingEnabled = false;

    public ?string $lokiUrl = null;

    public ?string $lokiUsername = null;

    /**
     * Never prefilled; a blank value keeps the stored password.
     */
    public ?string $lokiPassword = null;

    public ?string $lokiTenantId = null;

    #[Url(except: self::SOURCE_AUDIT)]
    public string $source = self::SOURCE_AUDIT;

    #[Url(except: 'all')]
    public string $level = 'all';

    #[Url(except: '')]
    public string $search = '';

    public int $perPage = 50;

    public function mount(): void
    {
        $this->authorizeLogViewer();
        $this->syncFromSettings();

        if (! in_array($this->source, self::SOURCES, true)) {
            $this->source = self::SOURCE_AUDIT;
        }
    }

    public function updatedSource(): void
    {
        if (! in_array($this->source, self::SOURCES, true)) {
            $this->source = self::SOURCE_AUDIT;
        }
        $this->level = 'all';
        $this->resetPage();
    }

    public function updatedLevel(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function saveCollection(): void
    {
        $this->authorizePlatformAdmin();
        // Audit events are a security record; the switch cannot be turned off.
        $this->isAuditLogCollectionEnabled = true;
        $this->validate([
            'isLogCollectionEnabled' => ['boolean'],
            'isSystemLogCollectionEnabled' => ['boolean'],
            'isContainerLogCollectionEnabled' => ['boolean'],
            'logRetentionDays' => ['required', 'integer', 'min:'.LogEntry::minimumRetentionDays(), 'max:'.LogEntry::maximumRetentionDays()],
        ]);

        instanceSettings()->update([
            'is_log_collection_enabled' => $this->isLogCollectionEnabled,
            'is_audit_log_collection_enabled' => true,
            'is_system_log_collection_enabled' => $this->isSystemLogCollectionEnabled,
            'is_container_log_collection_enabled' => $this->isContainerLogCollectionEnabled,
            'log_retention_days' => $this->logRetentionDays,
        ]);

        auditLog('platform.logs.collection_updated', [
            'enabled' => $this->isLogCollectionEnabled,
            'audit' => true,
            'system' => $this->isSystemLogCollectionEnabled,
            'container' => $this->isContainerLogCollectionEnabled,
            'retention_days' => $this->logRetentionDays,
        ]);
        $this->dispatch('success', $this->isLogCollectionEnabled ? __('Log collection settings saved.') : __('Log collection is off.'));
    }

    public function saveLoki(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate($this->lokiRules());

        $settings = instanceSettings();
        $attributes = [
            'is_loki_shipping_enabled' => $this->isLokiShippingEnabled,
            'loki_url' => filled($this->lokiUrl) ? trim($this->lokiUrl) : null,
            'loki_username' => filled($this->lokiUsername) ? trim($this->lokiUsername) : null,
            'loki_tenant_id' => filled($this->lokiTenantId) ? trim($this->lokiTenantId) : null,
        ];
        if (filled($this->lokiPassword)) {
            $attributes['loki_password'] = $this->lokiPassword;
        }
        if (blank($attributes['loki_username'])) {
            $attributes['loki_password'] = null;
        }
        // Only rows created after shipping is turned on are sent, so old history never floods Loki.
        if ($this->isLokiShippingEnabled && ! $settings->is_loki_shipping_enabled) {
            $attributes['loki_shipping_started_at'] = now();
            $attributes['loki_last_error'] = null;
        }

        $settings->update($attributes);
        $this->lokiPassword = null;

        auditLog('platform.logs.loki_updated', [
            'enabled' => $this->isLokiShippingEnabled,
            'loki_host' => parse_url((string) $attributes['loki_url'], PHP_URL_HOST),
        ]);
        $this->dispatch('success', $this->isLokiShippingEnabled ? __('Loki shipping settings saved.') : __('Loki shipping is off.'));
    }

    public function testLokiConnection(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate([...$this->lokiRules(), 'lokiUrl' => ['required', ...$this->lokiRules()['lokiUrl']]]);

        $settings = instanceSettings();
        $username = filled($this->lokiUsername) ? trim($this->lokiUsername) : null;
        $client = new LokiClient(
            url: trim((string) $this->lokiUrl),
            username: $username,
            password: filled($this->lokiPassword) ? $this->lokiPassword : ($username ? $settings->loki_password : null),
            tenantId: filled($this->lokiTenantId) ? trim($this->lokiTenantId) : null,
        );

        $error = $client->testConnection(ShipLogsToLokiJob::instanceLabel($settings));
        if ($error !== null) {
            $this->dispatch('error', __('Loki connection failed.'), $error);

            return;
        }

        $this->dispatch('success', __('Loki accepted the test log line.'));
    }

    public function clearLokiPassword(): void
    {
        $this->authorizePlatformAdmin();
        instanceSettings()->update(['loki_password' => null]);
        $this->lokiPassword = null;
        auditLog('platform.logs.loki_password_removed');
        $this->dispatch('success', __('Loki password removed.'));
    }

    public function export(string $format = 'ndjson'): ?StreamedResponse
    {
        $this->authorizeLogViewer();
        if (! in_array($format, ['ndjson', 'csv'], true)) {
            $this->dispatch('error', __('Unknown export format.'));

            return null;
        }

        $source = $this->currentSource();
        $query = $this->entriesQuery()->limit(self::EXPORT_LIMIT);
        $fileName = 'coolify-'.$source.'-logs-'.now()->format('Ymd-His').'.'.$format;

        auditLog('platform.logs.exported', [
            'log_source' => $source,
            'format' => $format,
            'level' => $this->level,
            'search' => $this->search !== '' ? $this->search : null,
        ]);

        return response()->streamDownload(function () use ($query, $format): void {
            $output = fopen('php://output', 'w');
            if ($format === 'csv') {
                fputcsv($output, ['time', 'source', 'level', 'result', 'actor', 'resource', 'message', 'details'], escape: '\\');
            }

            foreach ($query->lazy(500) as $row) {
                $record = $this->exportRecord($row);
                if ($format === 'csv') {
                    $record['details'] = json_encode($record['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    fputcsv($output, array_map(
                        fn (mixed $value): string => self::neutralizeSpreadsheetFormula((string) $value),
                        array_values($record),
                    ), escape: '\\');
                } else {
                    fwrite($output, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                }
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => $format === 'csv' ? 'text/csv' : 'application/x-ndjson',
        ]);
    }

    public function render(): View
    {
        $this->authorizeLogViewer();
        $settings = instanceSettings();

        return view('livewire.platform.logs', [
            'canManageLogs' => $this->canManageLogs(),
            'entries' => $this->entriesQuery()->paginate($this->perPage),
            'levelOptions' => $this->currentSource() === self::SOURCE_AUDIT
                ? ['info', 'warning', 'error']
                : LogEntry::LEVELS,
            'sourceActive' => match ($this->currentSource()) {
                self::SOURCE_AUDIT => $settings->isAuditLogCollectionActive(),
                LogEntry::SOURCE_SYSTEM => $settings->isSystemLogCollectionActive(),
                default => $settings->isContainerLogCollectionActive(),
            },
            'hasLokiPassword' => filled($settings->loki_password),
            'lokiActive' => $settings->isLokiShippingActive(),
            'lokiLastPushedAt' => $settings->loki_last_pushed_at,
            'lokiLastError' => $settings->loki_last_error,
            'lokiPending' => $settings->isLokiShippingActive() ? $this->pendingLokiCount($settings->loki_shipping_started_at) : 0,
            'lokiParked' => $settings->isLokiShippingActive() ? $this->parkedLokiCount($settings->loki_shipping_started_at) : 0,
            'minLogRetentionDays' => LogEntry::minimumRetentionDays(),
            'maxLogRetentionDays' => LogEntry::maximumRetentionDays(),
            'auditRetentionDays' => AuditEvent::retentionDays(),
        ]);
    }

    /**
     * Viewing, filtering and exporting logs: platform administrators and auditors.
     */
    protected function authorizeLogViewer(): void
    {
        abort_unless(auth()->check() && Gate::allows('viewAuditLogs'), 403);
    }

    /**
     * Whether the current user may change log collection and Loki settings (platform administrators only).
     */
    private function canManageLogs(): bool
    {
        return auth()->check() && Gate::allows('accessPlatformAdmin');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function lokiRules(): array
    {
        return [
            'isLokiShippingEnabled' => ['boolean'],
            'lokiUrl' => [$this->isLokiShippingEnabled ? 'required' : 'nullable', 'url:http,https', 'max:255'],
            'lokiUsername' => ['nullable', 'string', 'max:255'],
            'lokiPassword' => ['nullable', 'string', 'max:2048'],
            'lokiTenantId' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\-|]+$/'],
        ];
    }

    private function syncFromSettings(): void
    {
        $settings = instanceSettings();

        $this->isLogCollectionEnabled = (bool) $settings->is_log_collection_enabled;
        $this->isAuditLogCollectionEnabled = true;
        $this->isSystemLogCollectionEnabled = (bool) ($settings->is_system_log_collection_enabled ?? true);
        $this->isContainerLogCollectionEnabled = (bool) $settings->is_container_log_collection_enabled;
        $this->logRetentionDays = LogEntry::retentionDays($settings);
        $this->isLokiShippingEnabled = (bool) $settings->is_loki_shipping_enabled;
        if (! $this->canManageLogs()) {
            // Auditors never see the Loki connection; it is not part of the log record.
            return;
        }

        $this->lokiUrl = $settings->loki_url;
        $this->lokiUsername = $settings->loki_username;
        $this->lokiPassword = null;
        $this->lokiTenantId = $settings->loki_tenant_id;
    }

    private function currentSource(): string
    {
        return in_array($this->source, self::SOURCES, true) ? $this->source : self::SOURCE_AUDIT;
    }

    private function entriesQuery(): Builder
    {
        $search = trim($this->search);

        if ($this->currentSource() === self::SOURCE_AUDIT) {
            return AuditEvent::query()
                ->filtered($search)
                ->when($this->level !== 'all', fn (Builder $query) => $query->where('level', $this->level))
                ->latestFirst();
        }

        return LogEntry::query()
            ->filtered($this->currentSource(), $this->level, $search)
            ->latestFirst();
    }

    private function pendingLokiCount(mixed $since): int
    {
        return ShipLogsToLokiJob::pendingQuery(AuditEvent::query(), $since)->count()
            + ShipLogsToLokiJob::pendingQuery(LogEntry::query(), $since)->count();
    }

    private function parkedLokiCount(mixed $since): int
    {
        return ShipLogsToLokiJob::parkedQuery(AuditEvent::query(), $since)->count()
            + ShipLogsToLokiJob::parkedQuery(LogEntry::query(), $since)->count();
    }

    /**
     * @return array{time: ?string, source: string, level: string, result: ?string, actor: ?string, resource: ?string, message: string, details: array<string, mixed>}
     */
    private function exportRecord(AuditEvent|LogEntry $row): array
    {
        if ($row instanceof AuditEvent) {
            return [
                'time' => $row->created_at?->toIso8601String(),
                'source' => self::SOURCE_AUDIT,
                'level' => (string) $row->level,
                'result' => $row->result,
                'actor' => $row->actor_email ?: $row->actor_name ?: $row->actor_type,
                'resource' => $row->resource_name ?: $row->resource_uuid,
                'message' => (string) $row->description,
                'details' => [
                    'event' => $row->event,
                    'audit_source' => $row->source,
                    'team_id' => $row->team_id,
                    'resource_type' => $row->resource_type,
                    'ip' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                    'metadata' => $row->metadata,
                ],
            ];
        }

        return [
            'time' => $row->logged_at?->format('Y-m-d\TH:i:s.uP'),
            'source' => $row->source,
            'level' => $row->level,
            'result' => null,
            'actor' => null,
            'resource' => $row->resource_name,
            'message' => $row->message,
            'details' => array_filter([
                'team_id' => $row->team_id,
                'server_id' => $row->server_id,
                'resource_type' => $row->resource_type,
                'resource_uuid' => $row->resource_uuid,
                'container' => $row->container_name,
                'context' => $row->context ?: null,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * Spreadsheet apps run cells starting with these characters as formulas.
     */
    private static function neutralizeSpreadsheetFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
