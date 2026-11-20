<div>
    <x-slot:title>
        {{ __('Notifications') }} | {{ brand_name() }}
    </x-slot>

    <x-notification.settings-layout>
    <div class="flex flex-col gap-6">
        <form wire:submit="submit" class="application-settings-form">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section :title="__('Email delivery')">
                <x-slot:actions>
                    @php
                        $canSendTest = auth()->user()->isAdminFromSession() && auth()->user()->can('sendTest', $settings);
                        $sendTestReason = match (true) {
                            ! $canSendTest => 'Only admins can send a test email.',
                            ! $team->isNotificationEnabled('email') => 'Save an email provider first.',
                            default => null,
                        };
                    @endphp
                    @if ($sendTestReason === null)
                        <x-modal-input :title="__('Send Test Email')">
                            <x-slot:content>
                                <button type="button" class="button">
                                    <x-reicon name="notifications" class="size-3.5" />
                                    {{ __('Send test') }}
                                </button>
                            </x-slot:content>
                            <form wire:submit.prevent="sendTestEmail" class="flex w-full flex-col gap-4">
                                <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com"
                                    id="testEmailAddress" :label="__('Recipient')" required />
                                <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                    <button type="submit" @click="modalOpen=false"
                                        class="button button-highlighted">
                                        {{ __('Send email') }}
                                    </button>
                                </div>
                            </form>
                        </x-modal-input>
                    @else
                        <button type="button" class="button" disabled title="{{ $sendTestReason }}">
                            <x-reicon name="notifications" class="size-3.5" />
                            {{ __('Send test') }}
                        </button>
                    @endif
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        @if (isCloud())
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$settings" id="useInstanceEmailSettings" :label="__('Email service')"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => __('Use hosted email service')],
                                        ['value' => false, 'label' => __('Use team email settings')],
                                    ]" />
                            </div>
                        @else
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$settings" id="useInstanceEmailSettings" :label="__('Email service')"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => __('Use system-wide settings')],
                                        ['value' => false, 'label' => __('Use team email settings')],
                                    ]" />
                            </div>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        @if (!$useInstanceEmailSettings)
            <div class="application-settings-form">
                <x-application.settings-section :title="__('Email provider')"
                    :description="__('Only one provider sends email. Select a provider to see its settings.')">
                    <x-slot:badges>
                        <div class="w-40">
                            <x-forms.listbox canGate="update" :canResource="$settings" id="emailProvider"
                                onChange="selectEmailProvider"
                                :disabled="!auth()->user()->can('update', $settings)"
                                :options="collect(email_provider_labels())
                                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                                    ->values()
                                    ->all()" />
                        </div>
                    </x-slot:badges>
                    <div class="grid gap-4 lg:grid-cols-3">
                        @if ($emailProvider === 'none')
                            <p class="text-sm text-neutral-500 lg:col-span-3 dark:text-neutral-400">
                                {{ __('The team does not get email notifications. Select a provider next to the title to set it up.') }}
                            </p>
                        @endif

                        @if ($emailProvider !== $this->activeEmailProvider)
                            <div class="lg:col-span-3">
                                <x-callout type="warning" :title="__('Not active yet')">
                                    Enter the {{ email_provider_labels()[$emailProvider] }} settings and save.
                                    @if ($this->activeEmailProvider === 'none')
                                        {{ __('Until then, the team does not get email notifications.') }}
                                    @else
                                        Until then, Coolify sends email with
                                        {{ email_provider_labels()[$this->activeEmailProvider] }}.
                                    @endif
                                </x-callout>
                            </div>
                        @endif

                        @if ($emailProvider === 'smtp')
                            @include('livewire.partials.smtp-server-picker')
                        @elseif ($emailProvider === 'resend')
                            <div>
                                @can('update', $settings)
                                    <x-forms.input canGate="update" :canResource="$settings" required type="password"
                                        id="resendApiKey" :placeholder="__('API key')" :label="__('Resend API key')"
                                        autocomplete="new-password" />
                                @else
                                    <x-forms.input disabled :label="__('Resend API key')" value="Hidden (only admins can view)" />
                                @endcan
                            </div>
                            <x-forms.input canGate="update" :canResource="$settings" required id="resendFromName"
                                :helper="__('Name shown in email from Resend.')" :label="__('From name')" />
                            <x-forms.input canGate="update" :canResource="$settings" required id="resendFromAddress"
                                :helper="__('Address used in email from Resend. It must be on a domain verified in Resend.')"
                                :label="__('From address')" />
                        @endif
                    </div>
                </x-application.settings-section>
            </div>
        @endif

        <div class="application-settings-form">
            <x-application.settings-section :title="__('Notification events')">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-notification.event-multiselect :settings="$settings" id="deployment-email-events" :label="__('Deployments')"
                        :events="[
                            ['property' => 'deploymentSuccessEmailNotifications', 'label' => __('Deployment success'), 'enabled' => $deploymentSuccessEmailNotifications],
                            ['property' => 'deploymentFailureEmailNotifications', 'label' => __('Deployment failure'), 'enabled' => $deploymentFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="resource-email-events" :label="__('Resources')"
                        :events="[
                            ['property' => 'statusChangeEmailNotifications', 'label' => __('Resource status changes'), 'enabled' => $statusChangeEmailNotifications],
                            ['property' => 'restartLimitReachedEmailNotifications', 'label' => __('Restart limit reached'), 'enabled' => $restartLimitReachedEmailNotifications],
                            ['property' => 'imageVulnerabilitiesEmailNotifications', 'label' => 'Image vulnerabilities', 'enabled' => $imageVulnerabilitiesEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="backup-email-events" :label="__('Backups')"
                        :events="[
                            ['property' => 'backupSuccessEmailNotifications', 'label' => __('Backup success'), 'enabled' => $backupSuccessEmailNotifications],
                            ['property' => 'backupFailureEmailNotifications', 'label' => __('Backup failure'), 'enabled' => $backupFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="scheduled-task-email-events"
                        :label="__('Scheduled tasks')" :events="[
                            ['property' => 'scheduledTaskSuccessEmailNotifications', 'label' => __('Scheduled task success'), 'enabled' => $scheduledTaskSuccessEmailNotifications],
                            ['property' => 'scheduledTaskFailureEmailNotifications', 'label' => __('Scheduled task failure'), 'enabled' => $scheduledTaskFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="server-email-events" :label="__('Server')"
                        :events="[
                            ['property' => 'dockerCleanupSuccessEmailNotifications', 'label' => __('Docker cleanup success'), 'enabled' => $dockerCleanupSuccessEmailNotifications],
                            ['property' => 'dockerCleanupFailureEmailNotifications', 'label' => __('Docker cleanup failure'), 'enabled' => $dockerCleanupFailureEmailNotifications],
                            ['property' => 'serverDiskUsageEmailNotifications', 'label' => __('Server disk usage'), 'enabled' => $serverDiskUsageEmailNotifications],
                            ['property' => 'serverReachableEmailNotifications', 'label' => __('Server reachable'), 'enabled' => $serverReachableEmailNotifications],
                            ['property' => 'serverUnreachableEmailNotifications', 'label' => __('Server unreachable'), 'enabled' => $serverUnreachableEmailNotifications],
                            ['property' => 'serverPatchEmailNotifications', 'label' => __('Server patching'), 'enabled' => $serverPatchEmailNotifications],
                            ['property' => 'traefikOutdatedEmailNotifications', 'label' => __('Traefik proxy outdated'), 'enabled' => $traefikOutdatedEmailNotifications],
                        ]" />
                </div>
                <div class="mt-4">
                    <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveDeploymentCommitDetails"
                        id="deploymentCommitDetailsEmailNotifications"
                        :label="__('Include commit details in deployment emails')"
                        :helper="__('Adds the commit SHA, branch, and commit message to deployment success and failure emails.')" />
                </div>
            </x-application.settings-section>
        </div>
    </div>
    </x-notification.settings-layout>
</div>
