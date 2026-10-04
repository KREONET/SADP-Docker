<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Services\Logging\LokiClient;
use App\Services\Logging\LokiStreamBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends audit events and collected log lines that Loki has not received yet.
 *
 * Rows stay unshipped on network errors, 429 and 5xx, so the next run retries them. Any other 4xx means
 * Loki will never accept the batch (for example samples older than its limit), so the batch is marked
 * as shipped to keep it from blocking newer rows.
 *
 * [한국어] 아직 Loki 로 보내지 않은 로그를 일정 간격으로 묶어 전송하는 작업(Job)입니다. 스케줄: app/Console/Kernel.php
 * 네트워크 오류·429·5xx 는 다음 실행에서 다시 시도하고, 그 밖의 4xx 는 영원히 거부될 데이터이므로 보낸 것으로 표시해 막히지 않게 합니다.
 * 켜기/끄기: InstanceSettings::is_loki_shipping_enabled (마스터 스위치 is_log_collection_enabled 도 함께 확인).
 */
class ShipLogsToLokiJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH_SIZE = 500;

    public const MAX_BATCHES_PER_RUN = 20;

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
                    AuditEvent::query()->whereNull('loki_shipped_at')->where('created_at', '>=', $since),
                    fn (Collection $events): array => LokiStreamBuilder::fromAuditEvents($events, $instance),
                    $client,
                    $error,
                )
                : 0;

            $sentEntries = $sources !== []
                ? $this->shipBatch(
                    LogEntry::query()->whereNull('loki_shipped_at')->where('created_at', '>=', $since)->whereIn('source', $sources),
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
            $error = LokiClient::describeFailure($response).' '.$rows->count().' rejected entries were skipped.';
        }

        $rows->first()->newQuery()->whereKey($rows->modelKeys())->update(['loki_shipped_at' => now()]);

        return $response->successful() ? $rows->count() : 0;
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }
}
