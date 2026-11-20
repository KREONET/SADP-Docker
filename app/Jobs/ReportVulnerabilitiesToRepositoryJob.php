<?php

namespace App\Jobs;

use App\Models\VulnerabilityScan;
use App\Services\Forgejo\ForgejoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a Trivy scan result to the application's Forgejo repository as an issue,
 * authored by the administrator-configured bot account.
 */
class ReportVulnerabilitiesToRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_REPORTED_FINDINGS = 100;

    public $tries = 3;

    public $backoff = [30, 120];

    public function __construct(public VulnerabilityScan $scan)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $client = ForgejoClient::fromSettings(instanceSettings());
        if ($client === null || ! $client->hasToken()) {
            Log::warning('Skipping vulnerability report: Forgejo bot is not configured.', ['scan' => $this->scan->uuid]);

            return;
        }

        $repository = $client->repositoryFromUrl($this->scan->repository);
        if ($repository === null) {
            Log::warning('Skipping vulnerability report: repository is not hosted on the configured Forgejo instance.', [
                'scan' => $this->scan->uuid,
                'repository' => $this->scan->repository,
            ]);

            return;
        }

        try {
            $url = $client->reportIssue($repository['owner'], $repository['name'], self::issueTitle($this->scan), self::issueBody($this->scan));
            $this->scan->update(['report_url' => $url ?: null]);
        } catch (Throwable $e) {
            Log::error('Failed to report vulnerabilities to Forgejo.', ['scan' => $this->scan->uuid, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public static function issueTitle(VulnerabilityScan $scan): string
    {
        return "[Coolify 보안 점검] {$scan->branch} 브랜치에서 패키지 취약점이 발견되었습니다";
    }

    public static function issueBody(VulnerabilityScan $scan): string
    {
        $application = $scan->application;
        $findings = collect($scan->findings ?? []);
        $blocked = $scan->status === VulnerabilityScan::STATUS_BLOCKED;

        $lines = [
            '## Trivy 패키지 취약점 점검 결과',
            '',
            $blocked
                ? '> :no_entry: 취약점이 발견되어 **배포가 중단되었습니다.** 아래 패키지를 수정 버전으로 업데이트한 뒤 다시 커밋해 주세요.'
                : '> :warning: 취약점이 발견되었습니다. 아래 패키지를 수정 버전으로 업데이트해 주세요.',
            '',
            '| 항목 | 값 |',
            '| --- | --- |',
            '| 애플리케이션 | '.self::cell($application?->name ?? '-').' |',
            '| 브랜치 | '.self::cell($scan->branch ?? '-').' |',
            '| 커밋 | `'.self::cell($scan->commit ?? '-').'` |',
            '| 배포 ID | `'.self::cell($scan->deployment_uuid ?? '-').'` |',
            "| CRITICAL / HIGH / MEDIUM / LOW | {$scan->critical_count} / {$scan->high_count} / {$scan->medium_count} / {$scan->low_count} |",
            '',
            '### 발견된 취약점',
            '',
            '| 심각도 | 취약점 | 패키지 | 설치 버전 | 수정 버전 | 위치 | 설명 |',
            '| --- | --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($findings->take(self::MAX_REPORTED_FINDINGS) as $finding) {
            $id = self::cell(data_get($finding, 'id', ''));
            $url = (string) data_get($finding, 'url', '');
            $idCell = str_starts_with($url, 'https://') ? "[{$id}](".str_replace([')', ' '], ['%29', '%20'], $url).')' : $id;

            $lines[] = '| '.implode(' | ', [
                self::cell(data_get($finding, 'severity', '')),
                $idCell,
                self::cell(data_get($finding, 'package', '')),
                self::cell(data_get($finding, 'installed_version', '')),
                self::cell(data_get($finding, 'fixed_version', '') ?: '없음'),
                self::cell(data_get($finding, 'target', '')),
                self::cell(data_get($finding, 'title', '')),
            ]).' |';
        }

        if ($findings->count() > self::MAX_REPORTED_FINDINGS) {
            $lines[] = '';
            $lines[] = '외 '.($findings->count() - self::MAX_REPORTED_FINDINGS).'건은 Coolify 관리자 대시보드에서 확인할 수 있습니다.';
        }

        $lines[] = '';
        $lines[] = '_이 이슈는 Coolify 배포 파이프라인이 자동으로 생성했습니다._';

        return implode("\n", $lines);
    }

    private static function cell(mixed $value): string
    {
        return str((string) $value)->replace(['|', "\r", "\n", '`'], ['\\|', ' ', ' ', "'"])->limit(160)->value();
    }
}
