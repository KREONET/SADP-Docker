<?php

namespace App\Services\Logging;

use App\Models\AuditEvent;
use Illuminate\Support\Collection;

/**
 * Walks the audit event hash chain by id and reports the first row that does not verify.
 *
 * Rows written before the chain existed (no hash) are skipped while they come first. Retention
 * pruning deletes the oldest rows, so the first hashed row's `previous_hash` is trusted as the anchor.
 *
 * [한국어] 감사 로그 해시 체인을 id 순서로 따라가며 처음으로 어긋난 행을 찾습니다.
 * 보관 기간 정리로 앞쪽 행이 지워지므로, 남아 있는 가장 오래된 행의 previous_hash 를 출발점으로 믿습니다.
 */
class AuditChainVerifier
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{intact: bool, verified: int, unchained: int, broken_id: ?int, reason: ?string}
     */
    public function verify(): array
    {
        $verified = 0;
        $unchained = 0;
        $started = false;
        $previous = null;
        $broken = null;

        AuditEvent::query()->chunkById(self::CHUNK_SIZE, function (Collection $events) use (&$verified, &$unchained, &$started, &$previous, &$broken): bool {
            foreach ($events as $event) {
                if (! $started && $event->hash === null) {
                    $unchained++;

                    continue;
                }

                $reason = match (true) {
                    $event->hash === null => 'the row has no hash',
                    $started && ! hash_equals((string) $previous?->hash, (string) $event->previous_hash) => "previous_hash does not match the hash of row #{$previous?->id}",
                    ! hash_equals((string) $event->hash, $event->computeHash()) => 'the stored hash does not match the row contents',
                    default => null,
                };

                if ($reason !== null) {
                    $broken = ['id' => $event->id, 'reason' => $reason];

                    return false;
                }

                $started = true;
                $previous = $event;
                $verified++;
            }

            return true;
        });

        return [
            'intact' => $broken === null,
            'verified' => $verified,
            'unchained' => $unchained,
            'broken_id' => $broken['id'] ?? null,
            'reason' => $broken['reason'] ?? null,
        ];
    }
}
