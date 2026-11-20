<div>
    <x-slot:title>{{ __('Users') }} | {{ __('Platform Admin') }} | {{ brand_name() }}</x-slot>

    <x-platform.layout>
        <div class="flex min-w-0 flex-col gap-6">
            <x-application.settings-section :title="__('User accounts')"
                :description="__('Administrators manage the whole platform, its settings and the platform roles. Sub-administrators operate teams and applications but cannot change roles or settings. Users have no platform admin access.')" flush>
                <x-slot:actions>
                    <input type="search" class="input w-48" wire:model.live.debounce.300ms="accountSearch"
                        placeholder="{{ __('Search users') }}" aria-label="{{ __('Search users') }}" />
                    <select class="input w-40" wire:model.live="roleFilter" aria-label="{{ __('Filter accounts') }}">
                        <option value="">{{ __('All accounts') }}</option>
                        @foreach ($platformRoles as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
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
                    @endphp
                    <div wire:key="platform-account-{{ $account->id }}"
                        class="grid items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 lg:grid-cols-[minmax(10rem,1fr)_minmax(8rem,auto)_auto] dark:border-white/[0.06]">
                        <div class="min-w-0">
                            <div class="truncate text-[12px] font-semibold">
                                {{ $account->name }}
                                @if ($account->id === 0)
                                    <span class="ml-1 text-[11px] font-normal text-neutral-500">{{ __('(root)') }}</span>
                                @endif
                            </div>
                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $account->email }}</div>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <x-status-badge :status="$accountRole->label()" :type="$roleBadgeType" />
                            @if ($account->isDeactivated())
                                <x-status-badge :status="__('Deactivated')" type="error" />
                            @endif
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($canChangeRoles && $account->id !== 0 && $account->id !== auth()->id())
                                @foreach ($platformRoles as $role)
                                    @continue($role === $accountRole)
                                    @php
                                        [$roleAction, $roleWarning] = match ($role) {
                                            \App\Enums\PlatformRole::ADMIN => ['makePlatformAdmin', __('Administrators manage every team, the instance settings, the security settings and the platform roles. The user joins the root team as admin.')],
                                            \App\Enums\PlatformRole::SUB_ADMIN => ['makePlatformSubAdmin', __('Sub-administrators operate teams and applications. They cannot change platform roles, instance settings or security settings.')],
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
                        </div>
                    </div>
                @empty
                    <x-empty size="sm" :title="__('No matching users')" />
                @endforelse
            </x-application.settings-section>
        </div>
    </x-platform.layout>
</div>
