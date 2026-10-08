<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use App\Traits\ConfirmsSensitiveAccess;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShowHardcoded extends Component
{
    use ConfirmsSensitiveAccess;

    public bool $showEnvironmentType = true;

    public array $env;

    public string $key;

    #[Locked]
    public ?string $value = null;

    public ?string $comment = null;

    public ?string $serviceName = null;

    #[Locked]
    public bool $isPreview = false;

    #[Locked]
    public ?string $resourceableType = null;

    #[Locked]
    public ?int $resourceableId = null;

    /**
     * True when copying was refused because the user has not re-authenticated recently.
     * The details modal then shows a confirmation form.
     */
    #[Locked]
    public bool $sensitiveConfirmationRequired = false;

    /**
     * Entangled with the details modal so it stays open while the confirmation form re-renders.
     */
    public bool $detailsOpen = false;

    public function mount()
    {
        $this->key = $this->env['key'];
        $this->value = $this->env['value'] ?? null;
        $this->comment = $this->env['comment'] ?? null;
        $this->serviceName = $this->env['service_name'] ?? null;
    }

    /**
     * Resolves the value (including referenced secrets) for the clipboard.
     *
     * Like the editable rows, copying needs a recent re-authentication and is audited.
     */
    public function copyValue(): ?string
    {
        $environmentVariable = $this->makeEnvironmentVariable();
        $resource = $environmentVariable->resourceable;

        if (! $resource || (auth()->user()?->cannot('update', $resource) ?? true)) {
            $this->auditCopy('denied', $resource);

            return null;
        }

        if (! $this->hasValidSensitiveConfirmation()) {
            $this->sensitiveConfirmationRequired = true;
            $this->auditCopy('denied', $resource);

            return null;
        }

        $this->sensitiveConfirmationRequired = false;
        $this->auditCopy('success', $resource);

        return $environmentVariable->resolveReferencedValue();
    }

    /**
     * Re-authenticate (password, TOTP code, or recent sign-in) so the value can be copied.
     *
     * @return bool|string True on success, otherwise the error message for the confirmation form.
     */
    public function confirmSensitiveAccess(mixed $password = ''): bool|string
    {
        $resource = $this->makeEnvironmentVariable()->resourceable;
        if (! $resource || (auth()->user()?->cannot('update', $resource) ?? true)) {
            $this->auditCopy('denied', $resource);

            return __('You do not have permission to copy this value.');
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            $this->auditCopy('denied', $resource);

            return $this->sensitiveConfirmationFailureMessage();
        }

        $this->resetErrorBag('password');
        $this->sensitiveConfirmationRequired = false;

        return true;
    }

    private function makeEnvironmentVariable(): EnvironmentVariable
    {
        return EnvironmentVariable::make([
            'value' => $this->value,
            'is_preview' => $this->isPreview,
            'resourceable_type' => $this->resourceableType,
            'resourceable_id' => $this->resourceableId,
        ]);
    }

    /**
     * Records a copy of a Compose-managed value. Never includes the value itself.
     *
     * @param  'success'|'denied'  $result
     */
    private function auditCopy(string $result, ?Model $resource): void
    {
        auditLog('secret.environment_variable.copied', [
            'variable_key' => $this->key,
            'hardcoded' => true,
            'resource_type' => $resource ? class_basename($resource) : null,
            'resource_uuid' => data_get($resource, 'uuid'),
            'result' => $result,
        ], $result === 'success' ? 'info' : 'warning');
    }

    public function render()
    {
        return view('livewire.project.shared.environment-variable.show-hardcoded');
    }
}
