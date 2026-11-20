<?php

namespace App\Livewire\SharedVariables\Team;

use App\Livewire\Concerns\RemembersCanvasView;
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
    use RemembersCanvasView;

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
                'team_id' => $this->team->id,
            ]);
            $this->team->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        if ($this->prefersCanvasView('team_variables')) {
            return $this->redirectRoute('shared-variables.team.canvas', navigate: true);
        }
        $this->team = currentTeam();
        $this->authorize('viewSharedVariables', $this->team);
        $this->getDevView();
    }

    public function switch()
    {
        try {
            $this->authorize('viewSharedVariables', $this->team);
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
        if ($this->variables === null) {
            return;
        }

        // Parse before the save, so that an invalid "# coolify:" line changes nothing and keeps the edited text.
        try {
            $this->authorize('update', $this->team);
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
                $this->team->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => false,
                    'is_literal' => false,
                    ...$data['settings'],
                    'type' => 'team',
                    'team_id' => $this->team->id,
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
