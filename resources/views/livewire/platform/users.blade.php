<div>
    <x-slot:title>{{ __('Users') }} | {{ __('Platform Admin') }} | {{ brand_name() }}</x-slot>

    <x-platform.layout>
        <div class="flex min-w-0 flex-col gap-6">
            <x-application.settings-section :title="__('User accounts')"
                :description="__('Administrators manage the whole platform, its settings and the platform roles. Sub-administrators operate teams, applications and user accounts but cannot change roles or settings. Users have no platform admin access.')" flush>
                <x-slot:actions>
                    <input type="search" class="input w-48" wire:model.live.debounce.300ms="accountSearch"
                        placeholder="{{ __('Search users') }}" aria-label="{{ __('Search users') }}" />
                    <select class="input w-40" wire:model.live="accountFilter" aria-label="{{ __('Filter accounts') }}">
                        <option value="">{{ __('All accounts') }}</option>
                        <option value="admin">{{ __('Administrator') }}</option>
                        <option value="sub_admin">{{ __('Sub-administrator') }}</option>
                        <option value="locked">{{ __('Locked') }}</option>
                        <option value="deactivated">{{ __('Deactivated') }}</option>
                    </select>
                </x-slot:actions>

                @forelse ($accounts as $account)
                    @php
                        $accountRole = $account->platformRole();
                        $roleBadgeType = match ($accountRole) {
                            \App\Enums\PlatformRole::ADMIN => 'error',
                            \App\Enums\PlatformRole::SUB_ADMIN => 'warning',
                            default => 'neutral',
                        };
                        $isManageable = $account->id !== 0 && $account->id !== auth()->id()
                            && ($canChangeRoles || $accountRole === \App\Enums\PlatformRole::USER);
                    @endphp
                    <div wire:key="platform-account-{{ $account->id }}"
                        class="grid items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 lg:grid-cols-[minmax(10rem,1fr)_10rem_minmax(8rem,auto)_auto] dark:border-white/[0.06]">
                        <div class="min-w-0">
                            <div class="truncate text-[12px] font-semibold">
                                {{ $account->name }}
                                @if ($account->id === 0)
                                    <span class="ml-1 text-[11px] font-normal text-neutral-500">{{ __('(root)') }}</span>
                                @endif
                            </div>
                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $account->email }}</div>
                        </div>
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                            {{ __('Last login') }}: {{ $account->last_login_at?->diffForHumans() ?? __('Never') }}
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <x-status-badge :status="$accountRole->label()" :type="$roleBadgeType" />
                            @if ($account->isDeactivated())
                                <x-status-badge :status="__('Deactivated')" type="error" />
                            @elseif ($account->isLockedOut())
                                <x-status-badge :status="__('Locked until :time', ['time' => $account->locked_until->format('Y-m-d H:i')])" type="warning" />
                            @else
                                <x-status-badge :status="__('Active')" type="success" />
                            @endif
                            @if ($account->is_auditor)
                                <x-status-badge :status="__('Auditor')" type="neutral" />
                            @endif
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($isManageable)
                                @if ($canChangeRoles)
                                    @foreach ($platformRoles as $role)
                                        @continue($role === $accountRole)
                                        @php
                                            [$roleAction, $roleWarning] = match ($role) {
                                                \App\Enums\PlatformRole::ADMIN => ['makePlatformAdmin', __('Administrators manage every team, the instance settings, the security settings and the platform roles. The user joins the root team as admin.')],
                                                \App\Enums\PlatformRole::SUB_ADMIN => ['makePlatformSubAdmin', __('Sub-administrators operate teams, applications and user accounts. They cannot change platform roles, instance settings or security settings.')],
                                                default => ['makePlatformUser', __('The user loses platform admin access. Team roles outside the root team do not change.')],
                                            };
                                        @endphp
                                        <x-modal-confirmation wire:key="platform-role-{{ $account->id }}-{{ $role->value }}"
                                            :title="__('Change the platform role of :email to :role?', ['email' => $account->email, 'role' => $role->label()])"
                                            :buttonTitle="__('Make :role', ['role' => $role->label()])"
                                            :isErrorButton="$role === \App\Enums\PlatformRole::ADMIN"
                                            submitAction="{{ $roleAction }}({{ $account->id }})"
                                            :confirmWithText="false" :step3ButtonText="__('Make :role', ['role' => $role->label()])"
                                            :warningMessage="e($roleWarning)"
                                            :actions="[__('Change the platform role to :role.', ['role' => $role->label()])]" />
                                    @endforeach
                                @endif
                                @if ($account->isLockedOut())
                                    <x-forms.button wire:click="unlockUser({{ $account->id }})">{{ __('Unlock') }}</x-forms.button>
                                @endif
                                @if ($account->isDeactivated())
                                    <x-forms.button wire:click="reactivateUser({{ $account->id }})">{{ __('Reactivate') }}</x-forms.button>
                                @else
                                    <x-modal-confirmation :title="__('Deactivate :email?', ['email' => $account->email])" :buttonTitle="__('Deactivate')" isErrorButton
                                        submitAction="deactivateUser({{ $account->id }})"
                                        :warningMessage="e(__('The account keeps its teams and resources and can be reactivated later.'))"
                                        :actions="[__('Sign the user out of every session.'), __('Revoke the user\'s API tokens.'), __('Block sign-in until the account is reactivated.')]"
                                        confirmationText="{{ $account->email }}" :confirmationLabel="__('Enter the email address to confirm deactivation')"
                                        :shortConfirmationLabel="__('Email')" />
                                @endif
                                @if ($canChangeRoles)
                                    @if ($account->is_auditor)
                                        <x-modal-confirmation :title="__('Remove the auditor role from :email?', ['email' => $account->email])" :buttonTitle="__('Remove auditor')"
                                            submitAction="revokeAuditor({{ $account->id }})" :confirmWithText="false" :step3ButtonText="__('Remove auditor')"
                                            :warningMessage="e(__('The user can no longer read the instance logs. Team roles do not change.'))"
                                            :actions="[__('Remove read-only access to the logs of every team.')]" />
                                    @else
                                        <x-modal-confirmation :title="__('Make :email an auditor?', ['email' => $account->email])" :buttonTitle="__('Make auditor')"
                                            submitAction="grantAuditor({{ $account->id }})" :confirmWithText="false" :step3ButtonText="__('Make auditor')"
                                            :warningMessage="e(__('Auditors can read and export the activity, system and container logs of every team. They cannot change any setting. Team roles do not change.'))"
                                            :actions="[__('Allow read-only access to the logs of every team.')]" />
                                    @endif
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <x-empty size="sm" :title="__('No matching users')" />
                @endforelse
            </x-application.settings-section>
        </div>
    </x-platform.layout>
</div>
