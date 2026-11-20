<?php

namespace App\Livewire\Security;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Livewire\Forms\ApiTokenForm;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use App\Support\Reauthentication;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ApiTokens extends Component
{
    use AuthorizesRequests;

    public ApiTokenForm $form;

    /**
     * The token in the edit modal. The browser fills it from ApiTokenForm::valuesOf(), so opening the modal needs no request.
     */
    public ApiTokenForm $editForm;

    public $tokens = [];

    /**
     * Search text of each listed token. Alpine filters and pages the list with it through $wire, so it must change with $tokens.
     *
     * @var list<array{id: string, search: string}>
     */
    #[Locked]
    public array $tokenSearchIndex = [];

    private const DEFAULT_EXPIRATION_DAYS = 30;

    private const EXPIRATION_PRESETS = [
        7 => '7 days',
        30 => '30 days',
        60 => '60 days',
        90 => '90 days',
        365 => '1 year',
    ];

    /** @var array<int, string> */
    #[Locked]
    public array $expirationOptions = [];

    #[Locked]
    public bool $canUseRootPermissions = false;

    #[Locked]
    public bool $canUseWritePermissions = false;

    #[Locked]
    public bool $canUseDeployPermissions = false;

    #[Locked]
    public bool $canUseSensitivePermissions = false;

    #[Locked]
    public bool $canUseDeletePermissions = false;

    #[Locked]
    public bool $canUseTeamScope = false;

    public function render()
    {
        return view('livewire.security.api-tokens', [
            'scopeLabels' => $this->scopeLabels(),
        ]);
    }

    /**
     * @return Collection<string, string>
     */
    private function scopeLabels(): Collection
    {
        return collect($this->scopeOptions)->reject(fn (array $option) => $option['header'] ?? false)->pluck('label', 'value');
    }

    /**
     * @param  list<string>  $selected
     * @return list<string>
     */
    private function withoutCoveredScopes(array $selected): array
    {
        $parents = collect($this->scopeOptions)->pluck('parents', 'value');

        return array_values(array_filter(
            array_unique($selected),
            fn (string $value) => array_intersect($parents[$value] ?? [], $selected) === [],
        ));
    }

    /**
     * Projects, environments, and resources of the current team that the user can see, for the scope picker.
     *
     * Each option lists its "parents": the project and environment scopes that already include it.
     * A project or environment is an option only when the member has a grant on all of it, because a token
     * cannot have more access than its owner. Parents that a member sees only through a granted child are not options.
     *
     * @return list<array{value: string, label: string, header?: bool, parents?: list<string>}>
     */
    #[Computed]
    public function scopeOptions(): array
    {
        $resolver = app(AccessResolver::class);
        $memberGrants = $resolver->memberGrants(auth()->user(), currentTeam()->id);
        $memberLayer = $memberGrants === null ? null : $resolver->resolve(currentTeam()->id, $memberGrants);

        $projects = Project::query()->where('team_id', currentTeam()->id)->orderBy('name')->get(['id', 'uuid', 'name']);
        $environments = Environment::query()->whereIn('project_id', $projects->pluck('id'))->get(['id', 'uuid', 'name', 'project_id']);
        $selectableProjects = $projects->filter(fn (Project $project) => $memberLayer?->allows(AccessLevel::Read, $project->id) ?? true);
        $selectableEnvironments = $environments->filter(fn (Environment $environment) => $memberLayer?->allows(AccessLevel::Read, $environment->project_id, $environment->id) ?? true);
        $projectNames = $projects->pluck('name', 'id');
        $projectUuids = $projects->pluck('uuid', 'id');
        $environmentParents = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->id => ["project:{$projectUuids[$environment->project_id]}", "environment:{$environment->uuid}"],
        ]);
        $environmentPaths = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->id => $projectNames[$environment->project_id].' / '.$environment->name,
        ]);

        $resources = collect(AccessResolver::RESOURCE_MODELS)
            ->flatMap(fn (string $model) => $model::query()->whereIn('environment_id', $environments->pluck('id'))->get(['uuid', 'name', 'environment_id']))
            ->map(fn ($resource) => [
                'value' => "resource:{$resource->uuid}",
                'label' => $environmentPaths[$resource->environment_id].' / '.$resource->name,
                'parents' => $environmentParents[$resource->environment_id],
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            ...($selectableProjects->isEmpty() ? [] : [['value' => '__projects', 'label' => 'Projects', 'header' => true]]),
            ...$selectableProjects->map(fn (Project $project) => ['value' => "project:{$project->uuid}", 'label' => $project->name])->values()->all(),
            ...($selectableEnvironments->isEmpty() ? [] : [['value' => '__environments', 'label' => 'Environments', 'header' => true]]),
            ...$selectableEnvironments->map(fn (Environment $environment) => [
                'value' => "environment:{$environment->uuid}",
                'label' => $environmentPaths[$environment->id],
                'parents' => ["project:{$projectUuids[$environment->project_id]}"],
            ])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ...($resources->isEmpty() ? [] : [['value' => '__resources', 'label' => 'Resources', 'header' => true]]),
            ...$resources->all(),
        ];
    }

    public function mount()
    {
        $this->canUseRootPermissions = auth()->user()->can('useRootPermissions', PersonalAccessToken::class);
        $this->canUseWritePermissions = auth()->user()->can('useWritePermissions', PersonalAccessToken::class);
        $this->canUseDeployPermissions = auth()->user()->can('useDeployPermissions', PersonalAccessToken::class);
        $this->canUseSensitivePermissions = auth()->user()->can('useSensitivePermissions', PersonalAccessToken::class);
        $this->canUseDeletePermissions = auth()->user()->can('useDeletePermissions', PersonalAccessToken::class);
        $this->canUseTeamScope = app(AccessResolver::class)->coversWholeTeam(auth()->user(), currentTeam()->id);
        $this->expirationOptions = static::expirationOptions();
        $this->resetCreateForm();
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

        $max = static::maxLifetimeDays();
        if ($max === 0) {
            return false;
        }

        return $token->expires_at->gt(($token->created_at ?? now())->copy()->addDays($max)->addMinute());
    }

    private function resetCreateForm(): void
    {
        $this->form->reset();
        $this->form->scopeMode = $this->canUseTeamScope ? 'team' : 'selected';
        $allowedDays = static::allowedExpirationDays();
        $this->form->expiresInDays = in_array(self::DEFAULT_EXPIRATION_DAYS, $allowedDays, true)
            ? self::DEFAULT_EXPIRATION_DAYS
            : max($allowedDays);
    }

    private function getTokens()
    {
        $this->tokens = auth()->user()->tokens()
            ->where('team_id', currentTeam()->id)
            ->latest()
            ->get();

        $scopeLabels = $this->scopeLabels();
        $this->tokenSearchIndex = $this->tokens->map(fn (PersonalAccessToken $token) => [
            'id' => (string) $token->id,
            'search' => Str::lower(collect([$token->name, ...($token->abilities ?? [])])
                ->merge($token->scopes === null
                    ? ['All resources in the team']
                    : collect(Grant::listFrom($token->scopes))->map(fn (Grant $scope) => $scopeLabels[$scope->scope->value.':'.$scope->uuid] ?? null)->filter())
                ->join(' ')),
        ])->values()->all();
    }

    public function addNewToken(mixed $credential = ''): bool|string
    {
        try {
            $this->authorize('create', PersonalAccessToken::class);

            $teamId = currentTeam()->id;
            ['permissions' => $permissions, 'scopes' => $storedScopes, 'expiresInDays' => $expiresInDays] = $this->validateTokenForm($this->form, $teamId);
            if (! verifyPasswordConfirmation($credential, $this)) {
                auditLog('ui.api_token.created', [
                    'team_id' => $teamId,
                    'api_token_name' => $this->form->description,
                    'result' => 'denied',
                    'reason' => 'password_confirmation_failed',
                ], 'warning');

                return $this->getErrorBag()->first('password') ?: Reauthentication::failureMessage(Reauthentication::method());
            }
            $this->resetErrorBag('password');

            $expiresAt = now()->addDays((int) $expiresInDays);
            // One transaction, so a token never exists team-wide when saving its scopes fails.
            $token = DB::transaction(function () use ($permissions, $expiresAt, $teamId, $storedScopes) {
                $token = auth()->user()->createToken($this->form->description, $permissions, $expiresAt, $teamId);
                $token->accessToken->forceFill(['scopes' => $storedScopes])->save();

                return $token;
            });
            auditLog('ui.api_token.created', [
                'team_id' => $teamId,
                'api_token_name' => $this->form->description,
                'abilities' => $permissions,
                'scopes' => $storedScopes,
                'expires_at' => $expiresAt->toIso8601String(),
                'result' => 'success',
            ]);
            $this->getTokens();
            $this->resetCreateForm();
            // Do NOT strip the numeric prefix (e.g. "69|...") — Sanctum uses it to index and look up tokens.
            $this->dispatch('api-token-issued', token: $token->plainTextToken);

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

    /**
     * Save the edited token. The token value does not change, and the next request with it uses the new access.
     */
    public function updateToken(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);

            $teamId = currentTeam()->id;
            ['permissions' => $permissions, 'scopes' => $storedScopes, 'expiresInDays' => $expiresInDays] = $this->validateTokenForm($this->editForm, $teamId, editing: true);
            $before = ['abilities' => $token->abilities, 'scopes' => $token->scopes, 'expires_at' => $token->expires_at?->toIso8601String()];

            $token->forceFill([
                'name' => $this->editForm->description,
                'abilities' => $permissions,
                'scopes' => $storedScopes,
            ]);
            if ($expiresInDays !== ApiTokenForm::KEEP_EXPIRY) {
                $expiresAt = now()->addDays((int) $expiresInDays);
                $max = static::maxLifetimeDays();
                if ($max > 0 && $expiresAt->gt(($token->created_at ?? now())->copy()->addDays($max)->addMinute())) {
                    throw ValidationException::withMessages([
                        'editForm.expiresInDays' => __('Tokens expire after at most :days days.', ['days' => $max]),
                    ]);
                }
                $token->forceFill([
                    'expires_at' => $expiresAt,
                    // A new expiry date needs a new expiration warning.
                    'api_token_expiration_warning_sent_at' => null,
                ]);
            }
            $token->save();

            auditLog('ui.api_token.updated', [
                'team_id' => $teamId,
                'api_token_name' => $token->name,
                'before' => $before,
                'after' => ['abilities' => $permissions, 'scopes' => $storedScopes, 'expires_at' => $token->expires_at?->toIso8601String()],
            ]);
            $this->editForm->reset();
            $this->getTokens();
            $this->dispatch('api-token-updated');
            $this->dispatch('success', 'API token updated.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Validate the form and check that the user may give the token this access.
     * Never trust the $this->canUse* booleans: check the current user's access in the team again.
     *
     * @return array{permissions: list<string>, scopes: list<array{scope: string, uuid: string}>|null, expiresInDays: int|string}
     */
    private function validateTokenForm(ApiTokenForm $form, int $teamId, bool $editing = false): array
    {
        $expiryChoices = static::allowedExpirationDays();
        if ($editing) {
            $expiryChoices[] = ApiTokenForm::KEEP_EXPIRY;
        }

        $validated = $form->validate([
            'description' => 'required|min:3|max:255',
            'expiresInDays' => ['required', Rule::in($expiryChoices)],
            'permissions' => 'required|array|min:1',
            'permissions.*' => ['required', 'string', Rule::in(['read', 'read:sensitive', 'write', 'write:sensitive', 'deploy', 'delete', 'root'])],
            'scopeMode' => ['required', Rule::in(['team', 'selected'])],
            'selectedScopes' => ['exclude_unless:scopeMode,selected', 'required', 'array', 'min:1'],
            'selectedScopes.*' => ['string', 'regex:/^(project|environment|resource):[A-Za-z0-9_-]+$/'],
        ], [
            'selectedScopes.required' => 'Select at least one project, environment, or resource.',
        ]);
        $permissions = array_values($validated['permissions']);
        $form->selectedScopes = $this->withoutCoveredScopes($form->selectedScopes);
        $scopes = $form->scopeMode === 'selected' ? $this->parseSelectedScopes($form->selectedScopes, $teamId) : null;

        if (in_array('write:sensitive', $permissions, true) && ! auth()->user()->isAdminOfTeam($teamId)) {
            throw new \Exception('You do not have permission to give tokens write:sensitive permissions.');
        }
        $levels = AccessLevel::parseMany($permissions);
        $grants = $scopes === null
            ? [Grant::team($levels)]
            : array_map(fn (Grant $scope) => new Grant($scope->scope, $scope->uuid, $levels), $scopes);
        if (! app(AccessResolver::class)->userHolds(auth()->user(), $teamId, $grants)) {
            throw new \Exception('A token cannot have more access than you have. Choose fewer permissions or narrower projects, environments, or resources.');
        }

        return [
            'permissions' => $permissions,
            'scopes' => $scopes === null ? null : array_map(fn (Grant $scope) => ['scope' => $scope->scope->value, 'uuid' => $scope->uuid], $scopes),
            'expiresInDays' => $validated['expiresInDays'],
        ];
    }

    private function findOwnToken(int $id): PersonalAccessToken
    {
        return auth()->user()->tokens()
            ->where('team_id', currentTeam()->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $selectedScopes
     * @return list<Grant> scopes without levels; a token's levels are its abilities
     */
    private function parseSelectedScopes(array $selectedScopes, int $teamId): array
    {
        $resolver = app(AccessResolver::class);
        $scopes = [];
        foreach (array_unique($selectedScopes) as $selected) {
            [$scope, $uuid] = explode(':', $selected, 2);
            $grant = new Grant(AccessScope::from($scope), $uuid, []);
            $layer = $resolver->resolve($teamId, [new Grant($grant->scope, $uuid, [AccessLevel::Read])]);
            $existsInTeam = match ($grant->scope) {
                AccessScope::Project => $layer->visibleProjectIds() !== [],
                AccessScope::Environment => $layer->fullEnvironmentIds() !== [],
                default => $layer->resourceUuids() !== [],
            };
            if (! $existsInTeam) {
                throw new \Exception('A selected project, environment, or resource does not exist in this team.');
            }
            $scopes[] = $grant;
        }

        return $scopes;
    }

    /**
     * Pause a token so it stops authenticating requests, or resume a paused token. The token value does not change.
     * The caller sends the wanted state, so a stale page cannot resume a token that another page paused.
     */
    public function setPaused(int $id, bool $paused)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);

            if ($token->isPaused() !== $paused) {
                $token->forceFill(['paused_at' => $paused ? now() : null])->save();
                auditLog($paused ? 'ui.api_token.paused' : 'ui.api_token.resumed', [
                    'team_id' => currentTeam()->id,
                    'api_token_name' => $token->name,
                ]);
            }
            $this->getTokens();
            $this->dispatch('success', $paused ? 'API token paused.' : 'API token resumed.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function roll(int $id, mixed $credential = ''): bool|string
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);
            if ($this->exceedsLifetimePolicy($token)) {
                return __('Tokens exceed the lifetime policy');
            }

            if (! verifyPasswordConfirmation($credential, $this)) {
                auditLog('ui.api_token.rolled', [
                    'team_id' => $token->team_id,
                    'api_token_name' => $token->name,
                    'result' => 'denied',
                ], 'warning');

                return $this->getErrorBag()->first('password') ?: Reauthentication::failureMessage(Reauthentication::method());
            }
            $this->resetErrorBag('password');

            $plainTextToken = $token->roll();
            auditLog('ui.api_token.rolled', [
                'team_id' => $token->team_id,
                'api_token_name' => $token->name,
                'result' => 'success',
            ]);
            $this->getTokens();
            $this->dispatch('api-token-issued', token: $plainTextToken);

            return true;
        } catch (\Exception $e) {
            handleError($e, $this);

            return __('The API token was not created.');
        }
    }

    public function revoke(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
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
