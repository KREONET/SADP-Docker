<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\ForgejoApp;
use App\Models\Project;
use App\Rules\ValidGitBranch;
use App\Services\Forgejo\ForgejoClient;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Create an application from a repository of a Forgejo server that platform admins registered.
 *
 * [한국어] 새 애플리케이션을 만들 때 Forgejo 저장소와 브랜치를 목록에서 골라 만드는 단계의 동작입니다.
 * 저장소·브랜치 목록은 ForgejoClient 로 가져옵니다. 선택된 소스가 현재 팀에서 쓸 수 있는 것인지 항상 서버에서 다시 확인하세요.
 */
class ForgejoPrivateRepository extends Component
{
    use AuthorizesRequests;

    private const MAX_PAGES = 40;

    public string $current_step = 'forgejo_apps';

    public $forgejo_apps;

    public ?int $forgejo_app_id = null;

    public $parameters;

    public $currentRoute;

    public $query;

    public $type;

    public ?int $selected_repository_id = null;

    public string $selected_repository_path = '';

    public string $selected_branch_name = 'main';

    public $repositories;

    public int $total_repositories_count = 0;

    public $branches;

    public int $port = 3000;

    public bool $is_static = false;

    public ?string $publish_directory = null;

    public ?string $base_directory = '/';

    public ?string $docker_compose_location = '/docker-compose.yaml';

    public $build_pack = 'railpack';

    public bool $show_is_static = true;

    private function getForgejoApp(): ForgejoApp
    {
        return ForgejoApp::ownedByCurrentTeam()->where('id', $this->forgejo_app_id)->firstOrFail();
    }

    public function mount()
    {
        $this->currentRoute = Route::currentRouteName();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->forgejo_apps = ForgejoApp::ownedByCurrentTeam()->select(['id', 'name', 'html_url', 'login'])->orderBy('name')->get();
    }

    public function updatedSelectedRepositoryId(): void
    {
        $this->loadBranches();
    }

    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks' || $this->build_pack === 'railpack') {
            $this->show_is_static = true;
            if (! $this->is_static) {
                $this->port = 3000;
            }
        } elseif ($this->build_pack === 'static') {
            $this->show_is_static = false;
            $this->is_static = false;
            $this->port = 80;
        } else {
            $this->show_is_static = false;
            $this->is_static = false;
        }
    }

    public function loadRepositories($forgejo_app_id)
    {
        $this->repositories = collect();
        $this->branches = collect();
        $this->selected_repository_id = null;
        $this->selected_repository_path = '';
        $this->forgejo_app_id = (int) $forgejo_app_id;

        try {
            $client = ForgejoClient::forSource($this->getForgejoApp());
            $repositories = collect();
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $result = $client->repositories($page);
                $repositories = $repositories->concat($result['repositories']);
                if (! $result['has_more']) {
                    break;
                }
            }

            $this->repositories = $repositories->unique('id')->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)->values();
            $this->total_repositories_count = $this->repositories->count();
            if ($this->repositories->isNotEmpty()) {
                $this->selected_repository_id = (int) data_get($this->repositories->first(), 'id');
                $this->selected_repository_path = (string) data_get($this->repositories->first(), 'full_name');
            }
            $this->current_step = 'repository';
        } catch (\Throwable $e) {
            return $this->dispatch('error', e($e->getMessage()));
        }
    }

    public function loadBranches()
    {
        $repository = $this->repositories->firstWhere('id', $this->selected_repository_id);
        if (! $repository) {
            return;
        }
        $this->selected_repository_path = (string) data_get($repository, 'full_name');
        $this->branches = collect();

        try {
            [$owner, $name] = array_pad(explode('/', $this->selected_repository_path, 2), 2, '');
            $client = ForgejoClient::forSource($this->getForgejoApp());
            $branches = collect();
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $pageBranches = $client->branches($owner, $name, $page);
                $branches = $branches->concat($pageBranches);
                if (count($pageBranches) < ForgejoClient::PAGE_SIZE) {
                    break;
                }
            }

            $this->branches = sortBranchesByPriority($branches);
            $defaultBranch = data_get($repository, 'default_branch', 'main');
            $this->selected_branch_name = $this->branches->contains('name', $defaultBranch) ? $defaultBranch : data_get($this->branches, '0.name', 'main');
        } catch (\Throwable $e) {
            return $this->dispatch('error', e($e->getMessage()));
        }
    }

    public function submit()
    {
        try {
            $this->authorize('create', Application::class);

            $validator = validator([
                'selected_repository_path' => $this->selected_repository_path,
                'selected_branch_name' => $this->selected_branch_name,
                'docker_compose_location' => $this->docker_compose_location,
            ], [
                'selected_repository_path' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\z/'],
                'selected_branch_name' => ['required', 'string', new ValidGitBranch],
                'docker_compose_location' => ValidationPatterns::filePathRules(),
            ]);

            if ($validator->fails()) {
                throw new \RuntimeException(__('Invalid repository data: :error', ['error' => $validator->errors()->first()]));
            }

            $destination = find_destination_for_current_team($this->query['destination'] ?? null);
            if (! $destination) {
                throw new \Exception(__('Destination not found.'));
            }

            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();

            $source = $this->getForgejoApp();
            // The repository must be one the token can read, so a team cannot deploy arbitrary paths from the server.
            if (! collect($this->repositories)->contains('full_name', $this->selected_repository_path)) {
                throw new \RuntimeException(__('Choose a repository from the list.'));
            }

            $application = Application::create([
                'name' => generate_application_name($this->selected_repository_path, $this->selected_branch_name),
                'repository_project_id' => $this->selected_repository_id,
                'git_repository' => $this->selected_repository_path,
                'git_branch' => str($this->selected_branch_name)->trim()->toString(),
                'build_pack' => $this->build_pack,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'base_directory' => $this->base_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination->getMorphClass(),
                'source_id' => $source->id,
                'source_type' => $source->getMorphClass(),
            ]);
            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application->health_check_enabled = false;
            }
            if ($this->build_pack === 'dockercompose') {
                $application['docker_compose_location'] = $this->docker_compose_location;
            }
            $application->fqdn = generateUrl(server: $destination->server, random: $application->uuid);
            $application->name = generate_application_name($this->selected_repository_path, $this->selected_branch_name, $application->uuid);
            $application->save();

            return redirect()->route('project.application.configuration', [
                'application_uuid' => $application->uuid,
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        if ($this->is_static) {
            $this->port = 80;
            $this->publish_directory = '/dist';
        } else {
            $this->port = 3000;
            $this->publish_directory = null;
        }
        $this->dispatch('success', __('Application settings updated!'));
    }
}
