<?php

namespace App\Console\Commands;

use App\Services\Logging\AuditChainVerifier;
use Illuminate\Console\Command;

/**
 * Checks that no audit event was changed or removed from the middle of the hash chain.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit event hash chain and report the first broken link';

    public function handle(AuditChainVerifier $verifier): int
    {
        $result = $verifier->verify();

        if ($result['unchained'] > 0) {
            $this->line("Skipped {$result['unchained']} audit events written before the hash chain existed.");
        }

        if (! $result['intact']) {
            $this->error("Audit chain broken at event #{$result['broken_id']}: {$result['reason']}.");
            $this->line("Verified {$result['verified']} events before the broken link.");
            notifySecurityAlert('audit_chain_broken', 'The audit log hash chain is broken', [
                'audit_event_id' => $result['broken_id'],
                'reason' => $result['reason'],
            ]);

            return self::FAILURE;
        }

        $this->info("Audit chain intact: {$result['verified']} events verified.");

        return self::SUCCESS;
    }
}
