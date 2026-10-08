<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Services\Logging\LokiClient;
use App\Services\Logging\LokiStreamBuilder;
use App\Services\Logging\ThrottledSecurityAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends audit events and collected log lines that Loki has not received yet.
 *
 * Rows stay unshipped on network errors, 429 and 5xx, so the next run retries them. Any other 4xx means
 * Loki rejected the batch (for example samples older than its limit). Rejected rows are never marked as
 * shipped: their `loki_attempts` counter goes up and they are retried on the following runs. After
 * MAX_REJECTED_ATTEMPTS rejections the rows are parked (left unshipped, excluded from shipping) so they
 * cannot block newer rows, and an error is logged. Every failure ends the current run, so a failing Loki
 * is contacted at most once per scheduled run (no hot loop), and administrators get a throttled
 * `log_shipping_failed` security alert.
 *
 * [한국어] 아직 Loki 로 보내지 않은 로그를 일정 간격으로 묶어 전송하는 작업(Job)입니다. 스케줄: app/Console/Kernel.php
 * 네트워크 오류·429·5xx 는 다음 실행에서 다시 시도합니다. 그 밖의 4xx 로 거부된 행은 '보냄' 으로 표시하지 않고
 * 거부 횟수(loki_attempts)를 올려 다음 실행에서 다시 보냅니다. MAX_REJECTED_ATTEMPTS 번 거부되면 보내지 않은 채로 남겨 두고
 * 오류 로그를 남겨 새 로그 전송을 막지 않게 합니다. 실패하면 이번 실행은 바로 끝나며, 관리자에게 보안 알림(10분에 한 번)을 보냅니다.
 * 켜기/끄기: InstanceSettings::is_loki_shipping_enabled (마스터 스위치 is_log_collection_enabled 도 함께 확인).
 */
class ShipLogsToLokiJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH_SIZE = 500;

    public const MAX_BATCHES_PER_RUN = 20;

    public const MAX_REJECTED_ATTEMPTS = 5;

    public $tries = 1;

    public $timeout = 300;

    public function handle(): void
    {
        $settings = instanceSettings();
        if (! $settings->isLokiShippingActive()) {
            return;
        }

        $client = LokiClient::fromSettings($settings);
        $instance = self::instanceLabel($settings);
        $since = $settings->loki_shipping_started_at ?? now();
        $sources = array_values(array_filter([
            $settings->isSystemLogCollectionActive() ? LogEntry::SOURCE_SYSTEM : null,
            $settings->isContainerLogCollectionActive() ? LogEntry::SOURCE_CONTAINER : null,
        ]));

        $error = null;
        $shipped = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++) {
            $sentAudit = $settings->isAuditLogCollectionActive()
                ? $this->shipBatch(
                    self::pendingQuery(AuditEvent::query(), $since),
                    fn (Collection $events): array => LokiStreamBuilder::fromAuditEvents($events, $instance),
                    $client,
                    $error,
                )
                : 0;

            $sentEntries = $sources !== []
                ? $this->shipBatch(
                    self::pendingQuery(LogEntry::query(), $since)->whereIn('source', $sources),
                    fn (Collection $entries): array => LokiStreamBuilder::fromLogEntries($entries, $instance),
                    $client,
                    $error,
                )
                : 0;

            $shipped += $sentAudit + $sentEntries;

            if ($error !== null || ($sentAudit < self::BATCH_SIZE && $sentEntries < self::BATCH_SIZE)) {
                break;
            }
        }

        $status = ['loki_last_error' => $error];
        if ($shipped > 0) {
            $status['loki_last_pushed_at'] = now();
        }
        InstanceSettings::query()->whereKey(0)->update($status);

        if ($error !== null) {
            ThrottledSecurityAlert::send('log_shipping_failed', 'Logs could not be shipped to Loki', [
                'error' => $error,
            ]);
        }
    }

    /**
     * Rows created since shipping started that Loki has neither accepted nor rejected too often.
     *
     * @template TModel of AuditEvent|LogEntry
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function pendingQuery(Builder $query, mixed $since): Builder
    {
        return $query
            ->whereNull('loki_shipped_at')
            ->where('loki_attempts', '<', self::MAX_REJECTED_ATTEMPTS)
            ->where('created_at', '>=', $since ?? now());
    }

    /**
     * @template TModel of AuditEvent|LogEntry
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function parkedQuery(Builder $query, mixed $since): Builder
    {
        return $query
            ->whereNull('loki_shipped_at')
            ->where('loki_attempts', '>=', self::MAX_REJECTED_ATTEMPTS)
            ->where('created_at', '>=', $since ?? now());
    }

    public static function instanceLabel(InstanceSettings $settings): string
    {
        return (string) (str($settings->instance_name)->slug()->value()
            ?: parse_url((string) $settings->fqdn, PHP_URL_HOST)
            ?: 'coolify');
    }

    /**
     * @template TModel of AuditEvent|LogEntry
     *
     * @param  Builder<TModel>  $query
     * @param  callable(Collection<int, TModel>): array  $buildStreams
     * @return int The number of rows marked as shipped.
     */
    private function shipBatch(Builder $query, callable $buildStreams, LokiClient $client, ?string &$error): int
    {
        if ($error !== null) {
            return 0;
        }

        $rows = $query->orderBy('id')->limit(self::BATCH_SIZE)->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        try {
            $response = $client->push($buildStreams($rows));
        } catch (Throwable $e) {
            $error = 'Could not reach Loki: '.str($e->getMessage())->limit(300);

            return 0;
        }

        if (! $response->successful() && $this->isRetryable($response)) {
            $error = LokiClient::describeFailure($response);

            return 0;
        }

        if (! $response->successful()) {
            $error = $this->recordRejection($rows, $response);

            return 0;
        }

        $rows->first()->newQuery()->whereKey($rows->modelKeys())->update(['loki_shipped_at' => now()]);

        return $rows->count();
    }

    /**
     * Rejected rows stay unshipped; only their attempt counter goes up, so a later run retries them
     * until they are parked.
     *
     * @param  Collection<int, AuditEvent|LogEntry>  $rows
     */
    private function recordRejection(Collection $rows, Response $response): string
    {
        $rows->first()->newQuery()->whereKey($rows->modelKeys())->increment('loki_attempts');

        $attempts = (int) $rows->max('loki_attempts') + 1;
        $failure = LokiClient::describeFailure($response);

        if ($attempts < self::MAX_REJECTED_ATTEMPTS) {
            return $failure.' '.$rows->count()." rejected entries are kept and retried (attempt {$attempts} of ".self::MAX_REJECTED_ATTEMPTS.').';
        }

        Log::error('Loki rejected log entries repeatedly; they are no longer retried', [
            'table' => $rows->first()->getTable(),
            'count' => $rows->count(),
            'first_id' => $rows->first()->getKey(),
            'last_id' => $rows->last()->getKey(),
            'status' => $response->status(),
        ]);

        return $failure.' '.$rows->count().' entries were rejected '.self::MAX_REJECTED_ATTEMPTS.' times and are kept unshipped without further retries.';
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }
}
