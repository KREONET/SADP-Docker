<?php

namespace App\Livewire\SharedVariables\Project;

use App\Livewire\SharedVariables\Concerns\GatesDeveloperViewValues;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests, GatesDeveloperViewValues;

    public Project $project;

    public string $view = 'normal';

    public ?string $variables = null;

    protected $listeners = ['refreshEnvs' => 'refreshEnvs', 'saveKey' => 'saveKey', 'environmentVariableDeleted' => 'refreshEnvs'];

    public function saveKey($data)
    {
        try {
            $this->authorize('update', $this->project);
            $this->authorize('create', SharedEnvironmentVariable::class);

            $found = $this->project->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception(__('Variable already exists.'));
            }
            $this->project->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'comment' => $data['comment'] ?? null,
                'type' => 'project',
                'team_id' => currentTeam()->id,
            ]);
            $this->project->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount(?string $project_uuid = null)
    {
        $projectUuid = $project_uuid ?? request()->route('project_uuid');
        $teamId = currentTeam()->id;
        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;
        $this->getDevView();
    }

    public function switch()
    {
        try {
            $this->authorize('view', $this->project);
            $this->view = $this->view === 'normal' ? 'dev' : 'normal';
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getDevView()
    {
        $this->variables = $this->formatSharedVariablesForDeveloperView($this->project->environment_variables->sortBy('key'));
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->project);
            $this->authorize('create', SharedEnvironmentVariable::class);
            $this->handleBulkSubmit();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->refreshEnvs();
        }
    }

    private function handleBulkSubmit()
    {
        // The bulk text only exists while the developer view is open; never treat a missing text as "delete everything".
        if ($this->variables === null) {
            return;
        }

        $variables = parseEnvFormatToArray($this->variables);

        $changesMade = DB::transaction(function () use ($variables) {
            // Delete removed variables
            $deletedCount = $this->deleteRemovedVariables($variables);

            // Update or create variables
            $updatedCount = $this->updateOrCreateVariables($variables);

            return $deletedCount > 0 || $updatedCount > 0;
        });

        if ($changesMade) {
            $this->dispatch('success', __('Environment variables updated.'));
        }
    }

    private function deleteRemovedVariables($variables)
    {
        $variablesToDelete = $this->project->environment_variables()->whereNotIn('key', array_keys($variables))->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->project->environment_variables()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables)
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            $value = is_array($data) ? ($data['value'] ?? '') : $data;

            $found = $this->project->environment_variables()->where('key', $key)->first();
            // A placeholder stands for a value that was never revealed: keep the stored one.
            $keepsStoredValue = $this->isHiddenValuePlaceholder($value);

            if ($found) {
                if (! $keepsStoredValue && ! $found->is_shown_once && ! $found->is_multiline) {
                    if ($found->value !== $value) {
                        $found->value = $value;
                        $found->save();
                        $count++;
                    }
                }
            } elseif (! $keepsStoredValue) {
                $this->project->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => false,
                    'is_literal' => false,
                    'type' => 'project',
                    'team_id' => currentTeam()->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->project->refresh();
        $this->getDevView();
    }

    protected function sharedVariableOwner(): Model
    {
        return $this->project;
    }

    protected function sharedVariableType(): string
    {
        return 'project';
    }

    public function render()
    {
        return view('livewire.shared-variables.project.show');
    }
}
