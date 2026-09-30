<?php

namespace App\Livewire\Platform;

use App\Actions\Application\StopApplication;
use App\Exceptions\DisallowedSourceException;
use App\Jobs\DeleteResourceJob;
use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Models\Application;
use App\Models\Team;
use App\Rules\ValidGitBranch;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class Applications extends Component
{
    use AuthorizesPlatformAdmin;

    public const PAGE_SIZE = 100;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $team = '';

    public ?string $editingUuid = null;

    public string $name = '';

    public ?string $description = null;

    public ?string $gitRepository = null;

    public ?string $gitBranch = null;

    public bool $isAutoDeployEnabled = true;

    public function mount(): void
    {
        $this->authorizePlatformAdmin();
    }

    public function edit(string $uuid): void
    {
        $this->authorizePlatformAdmin();
        $application = $this->findApplication($uuid);

        $this->editingUuid = $application->uuid;
        $this->name = $application->name;
        $this->description = $application->description;
        $this->gitRepository = $application->git_repository;
        $this->gitBranch = $application->git_branch;
        $this->isAutoDeployEnabled = (bool) $application->settings?->is_auto_deploy_enabled;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingUuid', 'name', 'description', 'gitRepository', 'gitBranch', 'isAutoDeployEnabled']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizePlatformAdmin();

        try {
            $application = $this->findApplication((string) $this->editingUuid);
            $isGitApplication = $application->build_pack !== 'dockerimage' && blank($application->dockerfile);

            $this->validate([
                'name' => ['required', 'string', 'max:255', 'regex:'.ValidationPatterns::NAME_PATTERN],
                'description' => ['nullable', 'string', 'max:255', 'regex:'.ValidationPatterns::DESCRIPTION_PATTERN],
                'gitRepository' => $isGitApplication ? ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9@._:\/~+-]+\z/'] : ['nullable'],
                'gitBranch' => $isGitApplication ? ['required', 'string', new ValidGitBranch] : ['nullable'],
                'isAutoDeployEnabled' => ['boolean'],
            ]);

            $application->name = $this->name;
            $application->description = $this->description;
            if ($isGitApplication) {
                $application->git_repository = $this->gitRepository;
                $application->git_branch = $this->gitBranch;
            }
            $application->save();
            $application->settings?->update(['is_auto_deploy_enabled' => $this->isAutoDeployEnabled]);

            auditLog('platform.application.updated', ['application_uuid' => $application->uuid]);
            $this->cancelEdit();
            $this->dispatch('success', 'Application updated.');
        } catch (DisallowedSourceException $e) {
            $this->addError('gitRepository', $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function redeploy(string $uuid): void
    {
        $this->authorizePlatformAdmin();

        try {
            $application = $this->findApplication($uuid);
            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: new_public_id(),
                force_rebuild: false,
            );

            auditLog('platform.application.redeployed', ['application_uuid' => $application->uuid]);
            $this->dispatch($result['status'] === 'queue_full' ? 'error' : 'success', $result['message'] ?? 'Deployment queued.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function stop(string $uuid): void
    {
        $this->authorizePlatformAdmin();

        try {
            $application = $this->findApplication($uuid);
            StopApplication::dispatch($application);

            auditLog('platform.application.stopped', ['application_uuid' => $application->uuid]);
            $this->dispatch('success', 'Stopping the application.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function delete(string $uuid, mixed $password = ''): bool|string|null
    {
        $this->authorizePlatformAdmin();
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        try {
            $application = $this->findApplication($uuid);
            DeleteResourceJob::dispatch($application);

            auditLog('platform.application.deleted', ['application_uuid' => $application->uuid, 'application_name' => $application->name]);
            $this->dispatch('success', "Deleting {$application->name}.");

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function findApplication(string $uuid): Application
    {
        return Application::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function render()
    {
        $this->authorizePlatformAdmin();

        $query = Application::query()
            ->with(['environment.project.team:id,name', 'latestVulnerabilityScan', 'settings:id,application_id,is_auto_deploy_enabled'])
            ->when($this->search !== '', function (Builder $query) {
                $search = '%'.mb_strtolower($this->search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(git_repository) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(fqdn) LIKE ?', [$search]));
            })
            ->when($this->team !== '', fn (Builder $query) => $query->whereHas(
                'environment.project',
                fn (Builder $query) => $query->where('team_id', (int) $this->team),
            ))
            ->orderBy('name');

        return view('livewire.platform.applications', [
            'applications' => $query->limit(self::PAGE_SIZE)->get(),
            'total' => $query->count(),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
