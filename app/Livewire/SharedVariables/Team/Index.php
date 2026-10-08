<?php

namespace App\Livewire\SharedVariables\Team;

use App\Livewire\SharedVariables\Concerns\GatesDeveloperViewValues;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests, GatesDeveloperViewValues;

    public Team $team;

    public string $view = 'normal';

    public ?string $variables = null;

    protected $listeners = ['refreshEnvs' => 'refreshEnvs', 'saveKey' => 'saveKey', 'environmentVariableDeleted' => 'refreshEnvs'];

    public function saveKey($data)
    {
        try {
            $this->authorize('update', $this->team);
            $this->authorize('create', SharedEnvironmentVariable::class);

            $found = $this->team->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception(__('Variable already exists.'));
            }
            $this->team->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'comment' => $data['comment'] ?? null,
                'type' => 'team',
                'team_id' => currentTeam()->id,
            ]);
            $this->team->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->team = currentTeam();
        $this->getDevView();
    }

    public function switch()
    {
        try {
            $this->authorize('view', $this->team);
            $this->view = $this->view === 'normal' ? 'dev' : 'normal';
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getDevView()
    {
        $this->variables = $this->formatSharedVariablesForDeveloperView($this->team->environment_variables->sortBy('key'));
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->team);
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

        if ($changesMade) {
            $this->dispatch('success', __('Environment variables updated.'));
        }
    }

    private function deleteRemovedVariables($variables)
    {
        $variablesToDelete = $this->team->environment_variables()->whereNotIn('key', array_keys($variables))->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->team->environment_variables()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables)
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            $value = is_array($data) ? ($data['value'] ?? '') : $data;

            $found = $this->team->environment_variables()->where('key', $key)->first();
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
                $this->team->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => false,
                    'is_literal' => false,
                    'type' => 'team',
                    'team_id' => currentTeam()->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->team->refresh();
        $this->getDevView();
    }

    protected function sharedVariableOwner(): Model
    {
        return $this->team;
    }

    protected function sharedVariableType(): string
    {
        return 'team';
    }

    public function render()
    {
        return view('livewire.shared-variables.team.index');
    }
}
