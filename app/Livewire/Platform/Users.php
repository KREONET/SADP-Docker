<?php

namespace App\Livewire\Platform;

use App\Actions\User\ChangePlatformRole;
use App\Enums\PlatformRole;
use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every user account and its platform role (administrator, sub-administrator, user).
 *
 * Sub-administrators see the list; only administrators change platform roles, and never
 * their own or the root user's role.
 */
class Users extends Component
{
    use AuthorizesPlatformAdmin;

    /**
     * Platform role filters for the user list.
     */
    public const ROLE_FILTERS = ['', 'admin', 'sub_admin', 'user'];

    #[Url(as: 'users', except: '')]
    public string $accountSearch = '';

    #[Url(as: 'role', except: '')]
    public string $roleFilter = '';

    public function mount(): void
    {
        $this->authorizePlatformOperator();
    }

    /**
     * Grant a platform role after the administrator confirms their password.
     */
    public function makePlatformAdmin(int $userId, mixed $password = ''): bool|string
    {
        return $this->changePlatformRole($userId, PlatformRole::ADMIN, $password);
    }

    public function makePlatformSubAdmin(int $userId, mixed $password = ''): bool|string
    {
        return $this->changePlatformRole($userId, PlatformRole::SUB_ADMIN, $password);
    }

    public function makePlatformUser(int $userId, mixed $password = ''): bool|string
    {
        return $this->changePlatformRole($userId, PlatformRole::USER, $password);
    }

    public function render()
    {
        $this->authorizePlatformOperator();

        return view('livewire.platform.users', [
            'accounts' => $this->accounts(),
            'platformRoles' => PlatformRole::cases(),
            'canChangeRoles' => auth()->user()->isInstanceAdmin(),
        ]);
    }

    private function changePlatformRole(int $userId, PlatformRole $role, mixed $password): bool|string
    {
        $this->authorizePlatformAdmin();
        $user = User::query()->findOrFail($userId);
        $context = [
            'resource' => 'user',
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'role' => $role->value,
        ];

        $reason = match (true) {
            $user->id === 0 => __('The root user account cannot be changed here.'),
            $user->id === auth()->id() => __('You cannot change your own account here.'),
            default => null,
        };
        if ($reason !== null) {
            auditLog('platform.role.changed', $context + ['result' => 'denied'], 'warning');

            return $reason;
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            auditLog('platform.role.changed', $context + ['result' => 'denied', 'reason' => 'password_confirmation_failed'], 'warning');

            return __('The provided password is incorrect.');
        }

        if ($user->platformRole() === $role) {
            return true;
        }

        try {
            $previousRole = ChangePlatformRole::execute($user, $role);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $context += ['previous_role' => $previousRole->value, 'result' => 'success'];
        auditLog('platform.role.changed', $context, 'warning');
        if (($previousRole === PlatformRole::ADMIN) !== ($role === PlatformRole::ADMIN)) {
            auditLog($role === PlatformRole::ADMIN ? 'platform.admin.granted' : 'platform.admin.revoked', $context, 'warning');
        }
        $this->dispatch('success', __('Platform role of :email changed to :role.', ['email' => $user->email, 'role' => $role->label()]));

        return true;
    }

    /**
     * @return Collection<int, User>
     */
    private function accounts(): Collection
    {
        $search = trim($this->accountSearch);
        $filter = in_array($this->roleFilter, self::ROLE_FILTERS, true) ? $this->roleFilter : '';
        $rootTeamAdmins = fn (Builder $query) => $query->where('teams.id', 0)->whereIn('team_user.role', ['admin', 'owner']);

        return User::query()
            ->with('teams')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->when($filter === 'admin', fn (Builder $query) => $query->whereHas('teams', $rootTeamAdmins))
            ->when($filter === 'sub_admin', fn (Builder $query) => $query
                ->where('is_sub_admin', true)
                ->whereDoesntHave('teams', $rootTeamAdmins))
            ->when($filter === 'user', fn (Builder $query) => $query
                ->where('is_sub_admin', false)
                ->whereDoesntHave('teams', $rootTeamAdmins))
            ->orderBy('id')
            ->limit(50)
            ->get();
    }
}
