<?php

namespace App\Traits;

use App\Support\Reauthentication;

/**
 * Shared re-authentication checks for Livewire components that reveal or copy secret values.
 *
 * Mirrors the rules used by the environment variable editor: a confirmation from the
 * last few minutes is accepted, and SSO users without two-factor authentication pass
 * silently when they signed in recently.
 */
trait ConfirmsSensitiveAccess
{
    /**
     * Placeholder written into bulk (developer view) text instead of a secret the user
     * has not re-authenticated for. Saving the text keeps the stored value for it.
     */
    public const HIDDEN_SECRET_PLACEHOLDER = '(Hidden, confirm your identity to view)';

    /**
     * Placeholder shown to team members, who may never see secret values.
     */
    public const MEMBER_HIDDEN_PLACEHOLDER = '(Hidden, only admins can view)';

    /**
     * Whether the current user re-authenticated within the sensitive-action window.
     */
    protected function hasValidSensitiveConfirmation(): bool
    {
        if (hasRecentSensitiveConfirmation()) {
            return true;
        }

        if (Reauthentication::needsNoInput() && Reauthentication::hasRecentLogin()) {
            Reauthentication::markConfirmed();

            return true;
        }

        return false;
    }

    /**
     * The error to show in a confirmation form after a failed re-authentication.
     */
    protected function sensitiveConfirmationFailureMessage(): string
    {
        return $this->getErrorBag()->first('password') ?: Reauthentication::failureMessage(Reauthentication::method());
    }

    /**
     * Whether a submitted bulk value is a placeholder for a value that was never revealed.
     */
    protected function isHiddenValuePlaceholder(mixed $value): bool
    {
        return is_string($value)
            && in_array(trim($value), [self::HIDDEN_SECRET_PLACEHOLDER, self::MEMBER_HIDDEN_PLACEHOLDER], true);
    }
}
