<?php

namespace App\Livewire\Platform;

use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Platform\Concerns\ManagesUserAccounts;
use App\Models\Application;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Teams ("groups") and their projects across the whole instance.
 */
class Groups extends Component
{
    use AuthorizesPlatformAdmin;
    use ManagesUserAccounts;

    public const ROLES = ['member', 'admin', 'owner'];

    #[Url(as: 'team', except: null)]
    public ?int $selectedTeamId = null;

    public ?string $editingType = null;

    public ?int $editingId = null;

    public string $name = '';

    public ?string $description = null;

    public function mount(): void
    {
        $this->authorizePlatformAdmin();
    }

    public function selectTeam(int $teamId): void
    {
        $this->authorizePlatformAdmin();
        $this->selectedTeamId = Team::query()->findOrFail($teamId)->id;
        $this->cancelEdit();
    }

    public function editTeam(int $teamId): void
    {
        $this->authorizePlatformAdmin();
        $team = Team::query()->findOrFail($teamId);
        $this->startEdit('team', $team->id, $team->name, $team->description);
    }

    public function editProject(int $projectId): void
    {
        $this->authorizePlatformAdmin();
        $project = Project::query()->findOrFail($projectId);
        $this->startEdit('project', $project->id, $project->name, $project->description);
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingType', 'editingId', 'name', 'description']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:'.ValidationPatterns::NAME_PATTERN],
            'description' => ['nullable', 'string', 'max:255', 'regex:'.ValidationPatterns::DESCRIPTION_PATTERN],
        ]);

        $model = match ($this->editingType) {
            'team' => Team::query()->findOrFail($this->editingId),
            'project' => Project::query()->findOrFail($this->editingId),
            default => abort(404),
        };
        $model->update(['name' => $this->name, 'description' => $this->description]);

        auditLog("platform.{$this->editingType}.updated", ['id' => $model->id, 'name' => $model->name]);
        $this->cancelEdit();
        $this->dispatch('success', __('Saved.'));
    }

    public function updateMemberRole(int $teamId, int $userId, string $role): void
    {
        $this->authorizePlatformAdmin();
        if (! in_array($role, self::ROLES, true)) {
            abort(422);
        }

        try {
            $previousRole = DB::transaction(function () use ($teamId, $userId, $role): string {
                $team = Team::query()->lockForUpdate()->findOrFail($teamId);
                $member = $team->members()->where('users.id', $userId)->firstOrFail();
                $previousRole = (string) $member->pivot->role;

                if ($previousRole === 'owner' && $role !== 'owner'
                    && $team->members()->wherePivot('role', 'owner')->count() <= 1) {
                    throw new RuntimeException(__('A team must keep at least one owner.'));
                }

                $team->members()->updateExistingPivot($member->id, ['role' => $role]);

                return $previousRole;
            });
            Cache::forget("user:{$userId}:team:{$teamId}");

            auditLog('platform.team.member_role_updated', ['team_id' => $teamId, 'user_id' => $userId, 'role' => $role, 'previous_role' => $previousRole]);
            if ($teamId === 0 && $previousRole !== $role) {
                $this->recordPlatformAdminChange($userId, $previousRole, $role);
            }
            $this->dispatch('success', __('Role updated.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteProject(int $projectId, mixed $password = ''): bool|string|null
    {
        $this->authorizePlatformAdmin();
        if (! verifyPasswordConfirmation($password, $this)) {
            return __('The provided password is incorrect.');
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $project->isEmpty()) {
            return __('Delete every resource in this project first.');
        }

        $project->delete();
        auditLog('platform.project.deleted', ['id' => $project->id, 'name' => $project->name]);
        $this->dispatch('success', __('Project :name deleted.', ['name' => $project->name]));

        return true;
    }

    public function deleteTeam(int $teamId, mixed $password = ''): bool|string|null
    {
        $this->authorizePlatformAdmin();
        if (! verifyPasswordConfirmation($password, $this)) {
            return __('The provided password is incorrect.');
        }

        try {
            $name = DB::transaction(function () use ($teamId): string {
                $team = Team::query()->lockForUpdate()->findOrFail($teamId);
                if ($team->id === 0) {
                    throw new RuntimeException(__('The root team cannot be deleted.'));
                }
                if ($team->servers()->exists()) {
                    throw new RuntimeException(__('Delete all team servers before deleting this team.'));
                }
                if (! $team->isEmpty()) {
                    throw new RuntimeException(__('Delete the team projects, servers and git sources before deleting this team.'));
                }

                $team->members()->get()->each(function (User $member) use ($team): void {
                    $member->teams()->detach($team);
                    $member->clearStoredTeamIfMatches($team->id);
                    DB::table('sessions')->where('user_id', $member->id)->delete();
                });
                $team->delete();

                return $team->name;
            });

            if ($this->selectedTeamId === $teamId) {
                $this->selectedTeamId = null;
            }
            auditLog('platform.team.deleted', ['id' => $teamId, 'name' => $name]);
            $this->dispatch('success', __('Team :name deleted.', ['name' => $name]));

            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * A role change in the root team grants or revokes platform administration, so it gets its
     * own audit event and a security alert in addition to the generic role update event.
     */
    private function recordPlatformAdminChange(int $userId, string $previousRole, string $newRole): void
    {
        $granted = array_search($newRole, self::ROLES, true) > array_search($previousRole, self::ROLES, true);
        $target = User::query()->find($userId);
        $context = [
            'resource' => 'user',
            'team_id' => 0,
            'target_user_id' => $userId,
            'target_user_email' => $target?->email,
            'previous_role' => $previousRole,
            'new_role' => $newRole,
            'result' => 'success',
        ];

        auditLog($granted ? 'platform.admin.granted' : 'platform.admin.revoked', $context, 'warning');
        notifySecurityAlert(
            'platform_admin_changed',
            $granted
                ? __('Platform administrator role granted to :email', ['email' => $target?->email ?? "#{$userId}"])
                : __('Platform administrator role revoked from :email', ['email' => $target?->email ?? "#{$userId}"]),
            $context + ['actor_user_id' => auth()->id(), 'actor_email' => auth()->user()?->email],
        );
    }

    private function startEdit(string $type, int $id, string $name, ?string $description): void
    {
        $this->editingType = $type;
        $this->editingId = $id;
        $this->name = $name;
        $this->description = $description;
        $this->resetValidation();
    }

    public function render()
    {
        $this->authorizePlatformAdmin();

        $selectedTeam = $this->selectedTeamId === null ? null : Team::query()
            ->with([
                'members' => fn ($query) => $query->orderBy('name'),
                'projects' => fn ($query) => $query->withCount('environments')->orderBy('name'),
            ])
            ->find($this->selectedTeamId);

        $applicationCounts = $selectedTeam === null ? collect() : Application::query()
            ->join('environments', 'environments.id', '=', 'applications.environment_id')
            ->whereIn('environments.project_id', $selectedTeam->projects->pluck('id'))
            ->selectRaw('environments.project_id as project_id, count(*) as aggregate')
            ->groupBy('environments.project_id')
            ->pluck('aggregate', 'project_id');

        return view('livewire.platform.groups', [
            'teams' => Team::query()->withCount(['members', 'projects', 'servers'])->orderBy('id')->get(),
            'selectedTeam' => $selectedTeam,
            'applicationCounts' => $applicationCounts,
        ]);
    }
}
