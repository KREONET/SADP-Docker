<div>
    <x-slot:title>{{ __('Security') }} | {{ __('Platform Admin') }} | {{ brand_name() }}</x-slot>

    <x-platform.layout>
        <div class="flex min-w-0 flex-col gap-6">
            {{-- Opened by saveLogin() when SSO-only login changes; it calls saveLogin(password)
                 after the administrator re-enters their password. --}}
            <div class="fixed">
                <x-modal-confirmation :title="__('Confirm login policy change')" submitAction="saveLogin"
                    :confirmWithText="false" :step3ButtonText="__('Save login policy')"
                    :warningMessage="e(__('This setting decides whether users may sign in with a password.'))"
                    :actions="[__('Change whether only SSO login is allowed for every user except the root user.')]">
                    <x-slot:trigger>
                        <span x-on:confirm-login-policy.window="modalOpen = true"></span>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </div>

            <form wire:submit="saveLogin">
                <x-application.settings-section :title="__('Login policy')"
                    :description="__('Require company SSO (OIDC). The root user keeps password login as a break-glass account. Configure the OIDC provider under Settings → Authentication or with php artisan platform:configure-oidc.')">
                    <div class="grid gap-4">
                        <x-forms.checkbox id="isSsoOnlyLoginEnabled" :label="__('Only allow SSO login')" />
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                            {{ __('Enabled providers:') }}
                            @forelse ($enabledProviders as $provider)
                                <x-status-badge wire:key="provider-{{ $provider->provider }}" :status="$provider->loginLabel()" type="success" />
                            @empty
                                <span class="text-red-500">{{ __('none') }}</span>
                                · <a class="underline" href="{{ route('settings.oauth.provider', 'oidc') }}" {{ wireNavigate() }}>{{ __('Configure OIDC') }}</a>
                            @endforelse
                        </div>
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>{{ __('Save login policy') }}</x-forms.button>
                        </div>
                    </div>
                </x-application.settings-section>
            </form>

            <x-application.settings-section :title="__('User accounts')"
                :description="__('Deactivate, reactivate or unlock accounts. Locked accounts reopen automatically after the lockout period.')" flush>
                <x-slot:actions>
                    <input type="search" class="input w-48" wire:model.live.debounce.300ms="accountSearch"
                        placeholder="{{ __('Search users') }}" aria-label="{{ __('Search users') }}" />
                    <select class="input w-40" wire:model.live="accountFilter" aria-label="{{ __('Filter accounts') }}">
                        <option value="">{{ __('All accounts') }}</option>
                        <option value="locked">{{ __('Locked') }}</option>
                        <option value="deactivated">{{ __('Deactivated') }}</option>
                    </select>
                </x-slot:actions>

                @forelse ($accounts as $account)
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
                            @if ($account->id !== 0 && $account->id !== auth()->id())
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
                        </div>
                    </div>
                @empty
                    <x-empty size="sm" :title="__('No matching users')" />
                @endforelse
            </x-application.settings-section>

            <form wire:submit="saveTrivy">
                <x-application.settings-section :title="__('Vulnerability scanning (Trivy)')"
                    :description="__('Before building, every deployment scans the cloned repository\'s dependencies with Trivy on the build server. Findings can stop the deployment and are reported to the repository by the Forgejo bot.')">
                    <div class="grid gap-4">
                        <div class="grid gap-1 lg:grid-cols-2">
                            <x-forms.checkbox id="isTrivyScanEnabled" :label="__('Scan packages before every deployment')" />
                            <x-forms.checkbox id="trivyBlockOnFindings" :label="__('Stop the deployment when vulnerabilities are found')" />
                            <x-forms.checkbox id="trivyReportToRepository" :label="__('Open an issue in the repository with the bot')" />
                            <x-forms.checkbox id="trivyIgnoreUnfixed" :label="__('Ignore vulnerabilities without a fix')" />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input id="trivyImage" :label="__('Trivy image')" required
                                :helper="__('Use a mirrored image (for example registry.example.com/aquasec/trivy:0.58.1) on air-gapped build servers.')" />
                            <div>
                                <div class="mb-1.5 text-sm font-medium leading-4">{{ __('Severities') }}</div>
                                <div class="flex flex-wrap gap-3">
                                    @foreach (\App\Services\Security\TrivyScanner::SEVERITIES as $severity)
                                        <label wire:key="severity-{{ $severity }}" class="flex items-center gap-1.5 text-[12px]">
                                            <input type="checkbox" class="checkbox" value="{{ $severity }}" wire:model="trivySeverities" />
                                            {{ $severity }}
                                        </label>
                                    @endforeach
                                </div>
                                @error('trivySeverities')
                                    <div class="mt-1 text-xs text-red-500">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>{{ __('Save scanning settings') }}</x-forms.button>
                        </div>
                    </div>
                </x-application.settings-section>
            </form>

            <x-application.settings-section :title="__('Scan history')" flush>
                <x-slot:actions>
                    <select class="input w-40" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                        <option value="">{{ __('All results') }}</option>
                        <option value="blocked">{{ __('Blocked') }}</option>
                        <option value="found">{{ __('Found') }}</option>
                        <option value="passed">{{ __('Passed') }}</option>
                        <option value="error">{{ __('Error') }}</option>
                    </select>
                </x-slot:actions>

                @forelse ($scans as $scan)
                    <div wire:key="platform-scan-{{ $scan->uuid }}"
                        class="border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.06]">
                        <div class="grid items-center gap-3 lg:grid-cols-[minmax(10rem,1fr)_minmax(8rem,1fr)_10rem_7rem_auto]">
                            <div class="min-w-0">
                                <div class="truncate text-[12px] font-semibold">{{ $scan->application?->name ?? __('Deleted application') }}</div>
                                <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $scan->created_at?->diffForHumans() }}</div>
                            </div>
                            <div class="min-w-0 text-[11px] text-neutral-600 dark:text-fg-dim">
                                <div class="truncate">{{ $scan->branch }}</div>
                                <div class="truncate font-mono">{{ str($scan->commit)->limit(12, '') }}</div>
                            </div>
                            <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                C {{ $scan->critical_count }} · H {{ $scan->high_count }} · M {{ $scan->medium_count }} · L {{ $scan->low_count }}
                            </div>
                            <x-status-badge :status="$scan->status" :type="match ($scan->status) { 'passed' => 'success', 'found' => 'warning', default => 'error' }" />
                            <div class="flex flex-wrap justify-end gap-2">
                                @if (str_starts_with((string) $scan->report_url, 'https://') || str_starts_with((string) $scan->report_url, 'http://'))
                                    <a class="button" href="{{ $scan->report_url }}" target="_blank" rel="noopener noreferrer">{{ __('Issue') }}</a>
                                @endif
                                @if ($scan->totalCount() > 0)
                                    <x-forms.button wire:click="resendReport('{{ $scan->uuid }}')">{{ __('Report again') }}</x-forms.button>
                                @endif
                                @if ($scan->totalCount() > 0 || $scan->error)
                                    <x-forms.button wire:click="toggleScan('{{ $scan->uuid }}')">
                                        {{ $expandedScanUuid === $scan->uuid ? __('Hide') : __('Details') }}
                                    </x-forms.button>
                                @endif
                            </div>
                        </div>
                        @if ($expandedScanUuid === $scan->uuid)
                            <div class="mt-3 overflow-x-auto rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                                @if ($scan->error)
                                    <pre class="whitespace-pre-wrap p-3 text-[11px]">{{ $scan->error }}</pre>
                                @endif
                                @if (filled($scan->findings))
                                    <table class="w-full text-left text-[11px]">
                                        <thead>
                                            <tr class="border-b border-neutral-200 dark:border-white/[0.08]">
                                                <th class="p-2">{{ __('Severity') }}</th>
                                                <th class="p-2">ID</th>
                                                <th class="p-2">{{ __('Package') }}</th>
                                                <th class="p-2">{{ __('Installed') }}</th>
                                                <th class="p-2">{{ __('Fixed') }}</th>
                                                <th class="p-2">{{ __('Target') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($scan->findings as $finding)
                                                <tr wire:key="finding-{{ $scan->uuid }}-{{ md5(json_encode($finding)) }}" class="border-b border-neutral-100 last:border-b-0 dark:border-white/[0.04]">
                                                    <td class="p-2">{{ data_get($finding, 'severity') }}</td>
                                                    <td class="p-2">
                                                        @if (str_starts_with((string) data_get($finding, 'url'), 'https://'))
                                                            <a class="underline" href="{{ data_get($finding, 'url') }}" target="_blank" rel="noopener noreferrer">{{ data_get($finding, 'id') }}</a>
                                                        @else
                                                            {{ data_get($finding, 'id') }}
                                                        @endif
                                                    </td>
                                                    <td class="p-2">{{ data_get($finding, 'package') }}</td>
                                                    <td class="p-2">{{ data_get($finding, 'installed_version') }}</td>
                                                    <td class="p-2">{{ data_get($finding, 'fixed_version') ?: '-' }}</td>
                                                    <td class="p-2">{{ data_get($finding, 'target') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <x-empty size="sm" :title="__('No scans')" :description="__('Scans appear here after the next deployment with scanning enabled.')" />
                @endforelse
            </x-application.settings-section>
        </div>
    </x-platform.layout>
</div>
