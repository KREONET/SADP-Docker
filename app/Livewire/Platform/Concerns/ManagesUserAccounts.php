<?php

namespace App\Livewire\Platform\Concerns;

use App\Actions\User\DeactivateUserAccount;
use App\Models\User;

/**
 * Platform admin actions on user accounts: deactivate, reactivate, unlock and the auditor role.
 *
 * Every action re-checks the platform admin gate and refuses the root user and the acting
 * admin's own account, so an admin can neither lock out the break-glass account nor bypass
 * their own lockout or deactivation. The using component must also use {@see AuthorizesPlatformAdmin}.
 */
trait ManagesUserAccounts
{
    public function deactivateUser(int $userId, mixed $password = ''): bool|string|null
    {
        $this->authorizePlatformAdmin();
        if (! verifyPasswordConfirmation($password, $this)) {
            return __('The provided password is incorrect.');
        }

        $user = $this->resolveManageableUser($userId, 'platform.user.deactivated');
        if (is_string($user)) {
            return $user;
        }

        DeactivateUserAccount::execute($user, 'admin');
        auditLog('platform.user.deactivated', $this->userAccountContext($user, 'success'), 'warning');
        $this->dispatch('success', __('Account :email deactivated.', ['email' => $user->email]));

        return true;
    }

    public function reactivateUser(int $userId): void
    {
        $this->authorizePlatformAdmin();
        $user = $this->resolveManageableUser($userId, 'platform.user.reactivated');
        if (is_string($user)) {
            $this->dispatch('error', $user);

            return;
        }

        DeactivateUserAccount::reactivate($user, 'admin');
        // A reactivated account must not be measured as inactive again on the next scheduled run.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        auditLog('platform.user.reactivated', $this->userAccountContext($user, 'success'));
        $this->dispatch('success', __('Account :email reactivated.', ['email' => $user->email]));
    }

    public function unlockUser(int $userId): void
    {
        $this->authorizePlatformAdmin();
        $user = $this->resolveManageableUser($userId, 'platform.user.unlocked');
        if (is_string($user)) {
            $this->dispatch('error', $user);

            return;
        }

        $user->unlockAccount();
        auditLog('platform.user.unlocked', $this->userAccountContext($user, 'success'));
        $this->dispatch('success', __('Account :email unlocked.', ['email' => $user->email]));
    }

    /**
     * Grant or revoke the read-only auditor role after the administrator confirms their password.
     *
     * The flag only adds read access to the instance logs; it never changes team roles, so a
     * platform admin who is also an auditor keeps every admin right. Admins cannot change their
     * own flag (refused like every other own-account change).
     */
    public function grantAuditor(int $userId, mixed $password = ''): bool|string
    {
        return $this->changeAuditorRole($userId, true, $password);
    }

    public function revokeAuditor(int $userId, mixed $password = ''): bool|string
    {
        return $this->changeAuditorRole($userId, false, $password);
    }

    private function changeAuditorRole(int $userId, bool $isAuditor, mixed $password): bool|string
    {
        $this->authorizePlatformAdmin();
        $event = $isAuditor ? 'platform.auditor.granted' : 'platform.auditor.revoked';

        $user = $this->resolveManageableUser($userId, $event);
        if (is_string($user)) {
            return $user;
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            auditLog($event, $this->userAccountContext($user, 'denied') + ['reason' => 'password_confirmation_failed'], 'warning');

            return __('The provided password is incorrect.');
        }

        if ((bool) $user->is_auditor === $isAuditor) {
            return true;
        }

        $user->forceFill(['is_auditor' => $isAuditor])->save();
        auditLog($event, $this->userAccountContext($user, 'success'), 'warning');
        notifySecurityAlert('auditor_changed', $isAuditor ? 'Auditor role granted' : 'Auditor role revoked', [
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'auditor' => $isAuditor,
            'changed_by' => auth()->user()?->email,
        ]);
        $this->dispatch('success', $isAuditor
            ? __('Auditor role granted to :email.', ['email' => $user->email])
            : __('Auditor role removed from :email.', ['email' => $user->email]));

        return true;
    }

    /**
     * Load the target account, or return the reason the acting admin may not manage it.
     */
    private function resolveManageableUser(int $userId, string $auditEvent): User|string
    {
        $user = User::query()->findOrFail($userId);

        $reason = match (true) {
            $user->id === 0 => 'root_user',
            $user->id === auth()->id() => 'own_account',
            default => null,
        };

        if ($reason === null) {
            return $user;
        }

        auditLog($auditEvent, $this->userAccountContext($user, 'denied') + ['reason' => $reason], 'warning');

        return $reason === 'root_user'
            ? __('The root user account cannot be changed here.')
            : __('You cannot change your own account here.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userAccountContext(User $user, string $result): array
    {
        return [
            'resource' => 'user',
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'result' => $result,
        ];
    }
}
