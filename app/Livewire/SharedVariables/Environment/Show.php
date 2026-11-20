<?php

namespace App\Livewire\SharedVariables\Environment;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Livewire\SharedVariables\Concerns\GatesDeveloperViewValues;
use App\Models\Application;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests, GatesDeveloperViewValues;
    use RemembersCanvasView;

    public Project $project;

    public Application $application;

    public $environment;

    public array $parameters;

    public string $view = 'normal';

    public ?string $variables = null;

    protected $listeners = ['refreshEnvs' => 'refreshEnvs', 'saveKey', 'environmentVariableDeleted' => 'refreshEnvs'];

    public function saveKey($data)
    {
        try {
            $this->authorize('update', $this->environment);
            $this->authorize('create', SharedEnvironmentVariable::class);

            $found = $this->environment->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception(__('Variable already exists.'));
            }
            $this->environment->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'comment' => $data['comment'] ?? null,
                'type' => 'environment',
                'team_id' => $this->project->team_id,
            ]);
            $this->environment->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount(?string $project_uuid = null, ?string $environment_uuid = null)
    {
        $this->parameters = get_route_parameters();
        $projectUuid = $project_uuid ?? request()->route('project_uuid');
        $environmentUuid = $environment_uuid ?? request()->route('environment_uuid');

        $this->project = Project::ownedByCurrentTeam()->where('uuid', $projectUuid)->firstOrFail();
        $this->environment = $this->project->environments()->where('uuid', $environmentUuid)->firstOrFail();
        $this->authorize('viewSharedVariables', $this->environment);
        if ($this->prefersCanvasView('environment_variables')) {
            return $this->redirectRoute('shared-variables.environment.canvas', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid], navigate: true);
        }
        $this->getDevView();
    }

    public function switch()
    {
        $this->authorize('viewSharedVariables', $this->environment);
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->getDevView();
    }

    public function getDevView()
    {
        $this->variables = $this->formatSharedVariablesForDeveloperView($this->environment->environment_variables->sortBy('key'));
    }

    public function submit()
    {
        if ($this->variables === null) {
            return;
        }

        // Parse before the save, so that an invalid "# coolify:" line changes nothing and keeps the edited text.
        try {
            $this->authorize('update', $this->environment);
            $this->authorize('create', SharedEnvironmentVariable::class);
            $variables = parseEnvDevView($this->variables, SharedEnvironmentVariable::DEV_VIEW_FLAG_DEFAULTS);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        try {
            $this->handleBulkSubmit($variables);
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->refreshEnvs();
        }
    }

    private function handleBulkSubmit(array $variables)
    {
        $changesMade = false;

        DB::transaction(function () use ($variables, &$changesMade) {
            // Delete removed variables
            $deletedCount = $this->deleteRemovedVariables($variables);
            if ($deletedCount > 0) {
                $changesMade = true;
            }

            // Update or create variables
            $updatedCount = $this->updateOrCreateVariables($variables);
            if ($updatedCount > 0) {
                $changesMade = true;
            }
        });

        // Only dispatch success after transaction has committed
        if ($changesMade) {
            $this->dispatch('success', __('Environment variables updated.'));
        }
    }

    private function deleteRemovedVariables($variables)
    {
        $variablesToDelete = $this->environment->environment_variables()->whereNotIn('key', array_keys($variables))->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->environment->environment_variables()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables)
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            $value = is_array($data) ? ($data['value'] ?? '') : $data;

            $found = $this->environment->environment_variables()->where('key', $key)->first();
            // A placeholder stands for a value that was never revealed: keep the stored one.
            $keepsStoredValue = $this->isHiddenValuePlaceholder($value);

            if ($found) {
                if (! $keepsStoredValue && ! $found->is_shown_once && ! $found->is_multiline && formatEnvValueForDevView($found->value) !== null) {
                    if ($found->value !== $value) {
                        $found->value = $value;
                    }
                    $found->fill($data['settings']);
                    if ($found->isDirty()) {
                        $found->save();
                        $count++;
                    }
                }
            } elseif (! $keepsStoredValue) {
                $this->environment->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => false,
                    'is_literal' => false,
                    ...$data['settings'],
                    'type' => 'environment',
                    'team_id' => $this->project->team_id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->environment->refresh();
        $this->getDevView();
    }

    protected function sharedVariableOwner(): Model
    {
        return $this->environment;
    }

    protected function sharedVariableType(): string
    {
        return 'environment';
    }

    public function render()
    {
        return view('livewire.shared-variables.environment.show');
    }
}
