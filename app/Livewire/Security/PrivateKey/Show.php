<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use App\Support\Reauthentication;
use App\Support\ValidationPatterns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public PrivateKey $private_key;

    public bool $modalMode = false;

    // Explicit properties
    public string $name;

    public ?string $description = null;

    /**
     * Key material in the editor. Empty until the user re-authenticates and reveals
     * the stored key; a non-empty value on save replaces the stored key.
     */
    public string $privateKeyValue = '';

    /**
     * Whether the stored key was revealed into $privateKeyValue.
     */
    #[Locked]
    public bool $privateKeyRevealed = false;

    /**
     * True after a reveal attempt without a recent re-authentication.
     */
    #[Locked]
    public bool $sensitiveConfirmationRequired = false;

    public bool $isGitRelated = false;

    public bool $isInUse = false;

    public $public_key = 'Loading...';

    public string $deleteDisabledReason = 'This private key is currently used by a server, application, or Git app and cannot be deleted.';

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'privateKeyValue' => $this->privateKeyRevealed ? 'required|string' : 'nullable|string',
            'isGitRelated' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => __('The Name field is required.'),
                'privateKeyValue.required' => __('The Private Key field is required.'),
                'privateKeyValue.string' => __('The Private Key must be a valid string.'),
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
        'privateKeyValue' => 'private key',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->private_key->name = $this->name;
            $this->private_key->description = $this->description;
            // An empty editor without a revealed key means "keep the stored key".
            if (filled($this->privateKeyValue)) {
                $this->private_key->private_key = $this->privateKeyValue;
            }
            $this->private_key->is_git_related = $this->isGitRelated;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->private_key->name;
            $this->description = $this->private_key->description;
            // Key material is only sent to the browser after re-authentication (showPrivateKey()).
            if (! $this->privateKeyRevealed) {
                $this->privateKeyValue = '';
            }
            $this->isGitRelated = $this->private_key->is_git_related;
        }
    }

    public function mount(?string $private_key_uuid = null, bool $modalMode = false)
    {
        $this->modalMode = $modalMode;
        try {
            $this->private_key = PrivateKey::ownedByCurrentTeam(['uuid', 'name', 'description', 'private_key', 'is_git_related', 'team_id'])->whereUuid($private_key_uuid ?? request()->private_key_uuid)->firstOrFail();

            // Explicit authorization check - will throw 403 if not authorized
            $this->authorize('view', $this->private_key);

            $this->syncData(false);
            $this->isInUse = $this->private_key->isInUse();
            $this->public_key = $this->private_key->getPublicKey();
        } catch (AuthorizationException $e) {
            abort(403, __('You do not have permission to view this private key.'));
        } catch (\Throwable) {
            abort(404);
        }
    }

    /**
     * Reveals the stored private key after a recent re-authentication.
     *
     * @param  mixed  $password  Password or TOTP code; may be empty when a recent confirmation exists.
     * @return bool|string True when revealed, otherwise an error message for the confirmation form.
     */
    public function showPrivateKey(mixed $password = ''): bool|string
    {
        try {
            $this->authorize('update', $this->private_key);
        } catch (AuthorizationException) {
            $this->auditReveal('denied');

            return __('You do not have permission to view this private key.');
        }

        if (! hasRecentSensitiveConfirmation()) {
            // First click without a credential: ask for one instead of counting a failed attempt.
            if (blank($password) && ! Reauthentication::needsNoInput()) {
                $this->sensitiveConfirmationRequired = true;

                return __('Confirm your identity to view this private key.');
            }

            if (! verifyPasswordConfirmation($password, $this)) {
                $this->sensitiveConfirmationRequired = true;
                $this->auditReveal('denied');

                return $this->getErrorBag()->first('password') ?: Reauthentication::failureMessage(Reauthentication::method());
            }
        }

        $this->private_key->refresh();
        $this->privateKeyValue = (string) $this->private_key->private_key;
        $this->privateKeyRevealed = true;
        $this->sensitiveConfirmationRequired = false;
        $this->resetErrorBag('password');
        $this->auditReveal('success');

        return true;
    }

    /**
     * Records a reveal of the private key. Never includes key material.
     *
     * @param  'success'|'denied'  $result
     */
    private function auditReveal(string $result): void
    {
        auditLog('secret.private_key.revealed', [
            'private_key_uuid' => $this->private_key->uuid,
            'private_key_id' => $this->private_key->id,
            'team_id' => $this->private_key->team_id,
            'result' => $result,
        ], $result === 'success' ? 'info' : 'warning');
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->private_key);

            if ($this->private_key->isInUse()) {
                $this->isInUse = true;
                $this->dispatch('error', __($this->deleteDisabledReason));

                return;
            }

            $this->private_key->delete();
            currentTeam()->privateKeys = PrivateKey::where('team_id', currentTeam()->id)->get();

            if ($this->modalMode) {
                $this->dispatch('privateKeyDeleted');

                return null;
            }

            return redirectRoute($this, 'security.private-key.index');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changePrivateKey()
    {
        try {
            $this->authorize('update', $this->private_key);

            $this->validate();

            $replacesKey = filled($this->privateKeyValue);
            $this->syncData(true);
            if ($replacesKey) {
                $this->private_key->updatePrivateKey([
                    'private_key' => formatPrivateKey($this->private_key->private_key),
                ]);
                refresh_server_connection($this->private_key);
            } else {
                $this->private_key->save();
            }
            $this->dispatch('success', __('Private key updated.'));
            if ($this->modalMode) {
                $this->dispatch('privateKeyUpdated');

                return null;
            }
            $this->dispatch('securityResourceChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
