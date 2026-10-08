<?php

namespace App\Livewire\Security;

use App\Models\InstanceSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ApiTokens extends Component
{
    use AuthorizesRequests;

    public ?string $description = null;

    /**
     * Preset token lifetimes in days. Presets longer than the policy maximum are not offered.
     *
     * @var array<int, string>
     */
    public const EXPIRATION_PRESETS = [
        7 => '7 days',
        30 => '30 days',
        60 => '60 days',
        90 => '90 days',
        365 => '1 year',
    ];

    public const DEFAULT_EXPIRATION_DAYS = 30;

    /**
     * Untyped on purpose: the value arrives from the listbox as a string and is validated
     * against {@see allowedExpirationDays()} on every request.
     *
     * @var int|string|null
     */
    public $expiresInDays = self::DEFAULT_EXPIRATION_DAYS;

    public $tokens = [];

    public array $permissions = ['read'];

    /**
     * @var array<int, string>
     */
    #[Locked]
    public array $expirationOptions = [];

    #[Locked]
    public int $maxLifetimeDays = 0;

    public $isApiEnabled;

    #[Locked]
    public bool $canUseRootPermissions = false;

    #[Locked]
    public bool $canUseWritePermissions = false;

    #[Locked]
    public bool $canUseDeployPermissions = false;

    #[Locked]
    public bool $canUseSensitivePermissions = false;

    public function render()
    {
        return view('livewire.security.api-tokens');
    }

    public function mount()
    {
        $this->maxLifetimeDays = static::maxLifetimeDays();
        $this->expirationOptions = static::expirationOptions();
        $allowedDays = static::allowedExpirationDays();
        $this->expiresInDays = in_array(self::DEFAULT_EXPIRATION_DAYS, $allowedDays, true)
            ? self::DEFAULT_EXPIRATION_DAYS
            : max($allowedDays);
        $this->isApiEnabled = InstanceSettings::get()->is_api_enabled;
        $this->canUseRootPermissions = auth()->user()->can('useRootPermissions', PersonalAccessToken::class);
        $this->canUseWritePermissions = auth()->user()->can('useWritePermissions', PersonalAccessToken::class);
        $this->canUseDeployPermissions = auth()->user()->can('useDeployPermissions', PersonalAccessToken::class);
        $this->canUseSensitivePermissions = auth()->user()->can('useSensitivePermissions', PersonalAccessToken::class);
        $this->getTokens();
    }

    /**
     * Maximum token lifetime from `security.api_tokens.max_lifetime_days`; 0 means no maximum.
     * Tokens never get an unlimited lifetime either way.
     */
    public static function maxLifetimeDays(): int
    {
        return max(0, (int) config('security.api_tokens.max_lifetime_days'));
    }

    /**
     * Token lifetimes (in days) a user may choose under the current policy.
     *
     * @return list<int>
     */
    public static function allowedExpirationDays(): array
    {
        return array_keys(static::expirationOptions());
    }

    /**
     * @return array<int, string>
     */
    public static function expirationOptions(): array
    {
        $max = static::maxLifetimeDays();
        $options = [];

        foreach (self::EXPIRATION_PRESETS as $days => $label) {
            if ($max === 0 || $days <= $max) {
                $options[$days] = __($label);
            }
        }

        if ($max > 0 && ! array_key_exists($max, $options)) {
            $options[$max] = __(':days days', ['days' => $max]);
        }

        ksort($options);

        return $options;
    }

    /**
     * Whether an issued token outlives the current maximum lifetime (including tokens that never expire).
     */
    public function exceedsLifetimePolicy(PersonalAccessToken $token): bool
    {
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return false;
        }

        if ($token->expires_at === null) {
            return true;
        }

        if ($this->maxLifetimeDays === 0) {
            return false;
        }

        return $token->expires_at->gt(($token->created_at ?? now())->copy()->addDays($this->maxLifetimeDays)->addMinute());
    }

    private function getTokens()
    {
        $this->tokens = auth()->user()->tokens()
            ->where('team_id', currentTeam()->id)
            ->latest()
            ->get();
    }

    public function updatedPermissions($permissionToUpdate)
    {
        // Re-evaluate policies fresh — never trust stored snapshot booleans.
        if ($permissionToUpdate == 'root' && ! auth()->user()->can('useRootPermissions', PersonalAccessToken::class)) {
            $this->dispatch('error', __('You do not have permission to use root permissions.'));
            $this->permissions = array_diff($this->permissions, ['root']);

            return;
        }

        if (in_array($permissionToUpdate, ['write', 'write:sensitive'], true) && ! auth()->user()->can('useWritePermissions', PersonalAccessToken::class)) {
            $this->dispatch('error', __('You do not have permission to use write permissions.'));
            $this->permissions = array_diff($this->permissions, ['write', 'write:sensitive']);

            return;
        }

        if ($permissionToUpdate == 'deploy' && ! auth()->user()->can('useDeployPermissions', PersonalAccessToken::class)) {
            $this->dispatch('error', __('You do not have permission to use deploy permissions.'));
            $this->permissions = array_diff($this->permissions, ['deploy']);

            return;
        }

        if ($permissionToUpdate == 'read:sensitive' && ! auth()->user()->can('useSensitivePermissions', PersonalAccessToken::class)) {
            $this->dispatch('error', __('You do not have permission to use read:sensitive permissions.'));
            $this->permissions = array_diff($this->permissions, ['read:sensitive']);

            return;
        }

        if ($permissionToUpdate == 'root' && in_array('root', $this->permissions, true)) {
            $this->permissions = ['root'];
        } elseif ($permissionToUpdate == 'read:sensitive' && ! in_array('read', $this->permissions, true)) {
            $this->permissions[] = 'read';
        } elseif ($permissionToUpdate == 'deploy' && in_array('deploy', $this->permissions, true)) {
            $this->permissions = ['deploy'];
        } else {
            if (count($this->permissions) == 0) {
                $this->permissions = ['read'];
            }
        }
        sort($this->permissions);
    }

    /**
     * Called by the confirmation modal, which passes the password as the last argument.
     */
    public function addNewToken(mixed $password = ''): bool|string
    {
        try {
            $this->authorize('create', PersonalAccessToken::class);

            $validated = $this->validate([
                'description' => 'required|min:3|max:255',
                'expiresInDays' => ['required', 'integer', Rule::in(static::allowedExpirationDays())],
                'permissions' => 'required|array|min:1',
                'permissions.*' => ['required', 'string', Rule::in(['read', 'read:sensitive', 'write', 'write:sensitive', 'deploy', 'root'])],
            ]);
            $permissions = array_values($validated['permissions']);

            // Re-evaluate policies fresh against the current authenticated user.
            // Never trust $this->canUse* booleans — they come from the Livewire
            // snapshot which can be replayed from another user's session.
            if (in_array('root', $permissions, true) && ! auth()->user()->can('useRootPermissions', PersonalAccessToken::class)) {
                throw new \Exception(__('You do not have permission to create tokens with root permissions.'));
            }

            if (array_intersect(['write', 'write:sensitive'], $permissions) && ! auth()->user()->can('useWritePermissions', PersonalAccessToken::class)) {
                throw new \Exception(__('You do not have permission to create tokens with write permissions.'));
            }

            if (in_array('deploy', $permissions, true) && ! auth()->user()->can('useDeployPermissions', PersonalAccessToken::class)) {
                throw new \Exception(__('You do not have permission to create tokens with deploy permissions.'));
            }

            if (in_array('read:sensitive', $permissions, true) && ! auth()->user()->can('useSensitivePermissions', PersonalAccessToken::class)) {
                throw new \Exception(__('You do not have permission to create tokens with read:sensitive permissions.'));
            }

            if (! verifyPasswordConfirmation($password, $this)) {
                auditLog('ui.api_token.created', [
                    'team_id' => currentTeam()->id,
                    'api_token_name' => $this->description,
                    'result' => 'denied',
                    'reason' => 'password_confirmation_failed',
                ], 'warning');

                return __('The provided password is incorrect.');
            }

            $expiresAt = now()->addDays((int) $validated['expiresInDays']);
            $token = auth()->user()->createToken($this->description, $permissions, $expiresAt);
            auditLog('ui.api_token.created', [
                'team_id' => currentTeam()->id,
                'api_token_name' => $this->description,
                'abilities' => $permissions,
                'expires_at' => $expiresAt->toIso8601String(),
                'result' => 'success',
            ]);
            $this->getTokens();
            // Do NOT strip the numeric prefix (e.g. "69|...") — Sanctum uses it to index and look up tokens.
            session()->flash('token', $token->plainTextToken);

            return true;
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            handleError($e, $this);

            return $e->validator->errors()->first();
        } catch (\Exception $e) {
            handleError($e, $this);

            return __('The API token was not created.');
        }
    }

    public function revoke(int $id)
    {
        try {
            $token = auth()->user()->tokens()
                ->where('team_id', currentTeam()->id)
                ->where('id', $id)
                ->firstOrFail();
            $this->authorize('delete', $token);
            $tokenName = $token->name;
            $token->delete();
            auditLog('ui.api_token.revoked', [
                'team_id' => currentTeam()->id,
                'api_token_name' => $tokenName,
            ]);
            $this->getTokens();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
