<?php

namespace App\Livewire\Platform;

use App\Enums\PlatformRole;
use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Platform\Concerns\ManagesUserAccounts;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every user account: platform role (administrator, sub-administrator, user), auditor role and account state.
 */
class Users extends Component
{
    use AuthorizesPlatformAdmin;
    use ManagesUserAccounts;

    /**
     * Filters for the user list: account states and platform roles.
     */
    public const ACCOUNT_FILTERS = ['', 'locked', 'deactivated', 'admin', 'sub_admin'];

    #[Url(as: 'users', except: '')]
    public string $accountSearch = '';

    #[Url(as: 'account', except: '')]
    public string $accountFilter = '';

    public function mount(): void
    {
        $this->authorizePlatformOperator();
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

    /**
     * @return Collection<int, User>
     */
    private function accounts(): Collection
    {
        $search = trim($this->accountSearch);
        $filter = in_array($this->accountFilter, self::ACCOUNT_FILTERS, true) ? $this->accountFilter : '';
        $rootTeamAdmins = fn (Builder $query) => $query->where('teams.id', 0)->whereIn('team_user.role', ['admin', 'owner']);

        return User::query()
            ->with('teams')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->when($filter === 'locked', fn (Builder $query) => $query->where('locked_until', '>', now()))
            ->when($filter === 'deactivated', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($filter === 'admin', fn (Builder $query) => $query->whereHas('teams', $rootTeamAdmins))
            ->when($filter === 'sub_admin', fn (Builder $query) => $query
                ->where('is_sub_admin', true)
                ->whereDoesntHave('teams', $rootTeamAdmins))
            ->orderBy('id')
            ->limit(50)
            ->get();
    }
}
