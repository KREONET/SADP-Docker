<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > {{ __('Log Drains') }} | {{ brand_name() }}
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="log-drains" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($server->isFunctional())
                <x-application.settings-section id="server-log-drains-overview-section" :title="__('Log drains')"
                    :helper="__('Forward container logs from this server to one external destination.')">
                    <x-slot:actions>
                        <x-status-badge :status="$server->isLogDrainEnabled() ? __('Active') : __('Not configured')"
                            :type="$server->isLogDrainEnabled() ? 'success' : 'neutral'" />
                    </x-slot:actions>
                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        {{ __('Only one log drain can be active at a time. Disable the current destination before enabling another provider.') }}
                    </p>
                </x-application.settings-section>

                <form wire:submit="submit" class="contents">
                    <x-unsaved-bar action="submit" />

                    <x-application.settings-section id="server-new-relic-drain-section" title="New Relic"
                        :helper="__('Send logs through the New Relic Log API.')">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainNewRelicEnabled" :label="__('Status')"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => __('Disabled')],
                                    ['value' => true, 'label' => __('Enabled')],
                                ]"
                                :disabled="$isLogDrainAxiomEnabled || $isLogDrainCustomEnabled || $isLogDrainCloudwatchEnabled || !auth()->user()->can('update', $server)" />
                            <x-forms.input canGate="update" :canResource="$server" type="password" :required="$isLogDrainNewRelicEnabled"
                                id="logDrainNewRelicLicenseKey" :label="__('License key')"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" :required="$isLogDrainNewRelicEnabled"
                                id="logDrainNewRelicBaseUri" :label="__('Endpoint')"
                                placeholder="https://log-api.eu.newrelic.com/log/v1"
                                :helper="__('Use the EU or US New Relic Log API endpoint.')"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-axiom-drain-section" title="Axiom"
                        :helper="__('Send logs to an Axiom dataset using its ingest API.')">
                        <div class="mb-4 max-w-sm">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainAxiomEnabled" :label="__('Status')"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => __('Disabled')],
                                    ['value' => true, 'label' => __('Enabled')],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainCustomEnabled || $isLogDrainCloudwatchEnabled || !auth()->user()->can('update', $server)" />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" type="password" :required="$isLogDrainAxiomEnabled"
                                id="logDrainAxiomApiKey" :label="__('API key')"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" :required="$isLogDrainAxiomEnabled"
                                id="logDrainAxiomDatasetName" :label="__('Dataset name')"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" :required="$isLogDrainAxiomEnabled"
                                id="logDrainAxiomDomain" :label="__('Edge domain')"
                                placeholder="us-east-1.aws.edge.axiom.co"
                                helper="Base domain of the edge deployment of your Axiom organization.<br><br>US: <code>us-east-1.aws.edge.axiom.co</code><br>EU: <code>eu-central-1.aws.edge.axiom.co</code>"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-cloudwatch-drain-section" title="Amazon CloudWatch Logs"
                        helper="Send container logs directly to CloudWatch Logs using the Docker awslogs logging driver.<br><br>AWS credentials are read by the Docker daemon on this server, not by Coolify. Attach an IAM instance role, or configure <code>AWS_ACCESS_KEY_ID</code> and <code>AWS_SECRET_ACCESS_KEY</code> for the Docker service or in <code>/root/.aws/credentials</code>.<br><br>The credentials need <code>logs:CreateLogStream</code> and <code>logs:PutLogEvents</code>, plus <code>logs:CreateLogGroup</code> if the log group does not exist yet.<br><br>Delivery errors do not stop containers; they appear in the Docker daemon logs (<code>journalctl -u docker</code>). Redeploy or restart resources to apply the logging driver.">
                        <div class="mb-4 max-w-sm">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainCloudwatchEnabled" :label="__('Status')"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => __('Disabled')],
                                    ['value' => true, 'label' => __('Enabled')],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainAxiomEnabled || $isLogDrainCustomEnabled || !auth()->user()->can('update', $server)" />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" :required="$isLogDrainCloudwatchEnabled"
                                id="logDrainCloudwatchGroup" :label="__('Log group')" placeholder="/coolify/production"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server"
                                id="logDrainCloudwatchStreamPrefix" :label="__('Log stream prefix')" placeholder="docker/"
                                :helper="__('Optional. Log streams are named after the container, prefixed with this value (e.g. docker/<container name>).')"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" :required="$isLogDrainCloudwatchEnabled"
                                id="logDrainCloudwatchRegion" :label="__('Region')" placeholder="us-east-1"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-custom-drain-section" :title="__('Custom Fluent Bit')"
                        :helper="__('Provide a custom Fluent Bit output and optional parser configuration.')">
                        <div class="mb-4 max-w-sm">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainCustomEnabled" :label="__('Status')"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => __('Disabled')],
                                    ['value' => true, 'label' => __('Enabled')],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainAxiomEnabled || $isLogDrainCloudwatchEnabled || !auth()->user()->can('update', $server)" />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.textarea canGate="update" :canResource="$server" rows="8" :required="$isLogDrainCustomEnabled"
                                id="logDrainCustomConfig" :label="__('Fluent Bit configuration')"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.textarea canGate="update" :canResource="$server" rows="8"
                                id="logDrainCustomConfigParser" :label="__('Parser configuration')"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                </form>
            @else
                <x-application.settings-section :title="__('Log drains')"
                    :helper="__('Forward container logs from this server to an external destination.')">
                    <x-empty size="sm" :title="__('Server validation required')"
                        :description="__('Validate this server before configuring log drains.')"
                        icon-name="notifications" />
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
