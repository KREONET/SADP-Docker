<?php

namespace App\Livewire\SharedVariables\Concerns;

use App\Models\SharedEnvironmentVariable;
use App\Traits\ConfirmsSensitiveAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * Developer (bulk) view of shared variables.
 *
 * The bulk text only exists while the developer view is open, and secret values are
 * only included after a recent re-authentication. Until then every value is written as
 * a placeholder, and saving keeps the stored value for each placeholder.
 *
 * Host components define `$view`, `$variables` and `getDevView()`.
 */
trait GatesDeveloperViewValues
{
    use ConfirmsSensitiveAccess;

    /**
     * True when the developer view holds placeholders because the user has not re-authenticated recently.
     */
    #[Locked]
    public bool $sensitiveConfirmationRequired = false;

    /**
     * Whether the developer view text currently contains decrypted values.
     */
    #[Locked]
    public bool $devValuesRevealed = false;

    /**
     * The team, project, environment or server that owns the shared variables.
     */
    abstract protected function sharedVariableOwner(): Model;

    /**
     * The shared variable scope: team, project, environment or server.
     */
    abstract protected function sharedVariableType(): string;

    /**
     * Re-authenticate (password, TOTP code, or recent sign-in) and reveal the developer view values.
     *
     * @return bool|string True on success, otherwise the error message for the confirmation form.
     */
    public function confirmSensitiveAccess(mixed $password = ''): bool|string
    {
        $this->authorize('view', $this->sharedVariableOwner());

        if (! verifyPasswordConfirmation($password, $this)) {
            $this->auditSharedVariableBulkReveal('denied');

            return $this->sensitiveConfirmationFailureMessage();
        }

        $this->resetErrorBag('password');
        $this->getDevView();

        return true;
    }

    /**
     * Builds the developer view text, or null while the normal view is shown.
     *
     * @param  Collection<int, SharedEnvironmentVariable>  $variables
     */
    protected function formatSharedVariablesForDeveloperView(Collection $variables): ?string
    {
        if ($this->view !== 'dev') {
            $this->sensitiveConfirmationRequired = false;
            $this->devValuesRevealed = false;

            return null;
        }

        $isMember = auth()->user()?->isMember() ?? true;
        $revealableCount = $variables
            ->reject(fn ($item): bool => (bool) $item->is_shown_once || (bool) $item->is_multiline)
            ->count();
        $revealValues = ! $isMember && $revealableCount > 0 && $this->hasValidSensitiveConfirmation();

        $this->sensitiveConfirmationRequired = ! $isMember && $revealableCount > 0 && ! $revealValues;

        if ($revealValues && ! $this->devValuesRevealed) {
            $this->auditSharedVariableBulkReveal('success', $revealableCount);
        }
        $this->devValuesRevealed = $revealValues;

        return $variables->map(function ($item) use ($isMember, $revealValues) {
            if ($isMember) {
                return "$item->key=".self::MEMBER_HIDDEN_PLACEHOLDER;
            }
            if ($item->is_shown_once) {
                return "$item->key=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "$item->key=(Multiline environment variable, edit in normal view)";
            }
            if (! $revealValues) {
                return "$item->key=".self::HIDDEN_SECRET_PLACEHOLDER;
            }

            return "$item->key=$item->value";
        })->join("\n");
    }

    /**
     * Records a bulk reveal of shared variable values. Never includes keys or values.
     *
     * @param  'success'|'denied'  $result
     */
    private function auditSharedVariableBulkReveal(string $result, ?int $variableCount = null): void
    {
        $owner = $this->sharedVariableOwner();

        auditLog('secret.environment_variable.bulk_revealed', array_filter([
            'resource_type' => 'shared_'.$this->sharedVariableType(),
            'resource_id' => $owner->getKey(),
            'resource_uuid' => data_get($owner, 'uuid'),
            'variable_count' => $variableCount,
            'result' => $result,
        ], fn ($value): bool => $value !== null), $result === 'success' ? 'info' : 'warning');
    }
}
