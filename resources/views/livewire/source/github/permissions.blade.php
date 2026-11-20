            <div class="application-settings-form flex flex-col gap-6">
                <x-application.settings-section :title="__('Permissions')"
                    :description="__('GitHub permissions currently granted to this App.')">
                    <x-slot:actions>
                        @can('view', $github_app)
                            @can('update', $github_app)
                                <x-forms.button type="button" wire:click.prevent="checkPermissions">
                                    <x-reicon name="refresh" class="size-3.5" />
                                    {{ __('Refetch') }}
                                </x-forms.button>
                            @endcan
                            <a href="{{ getPermissionsPath($github_app) }}" class="button">
                                {{ __('Update on GitHub') }}
                                <x-external-link />
                            </a>
                        @endcan
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <x-forms.input canGate="view" :canResource="$github_app" id="contents"
                            :helper="__('Read access is mandatory.')" :label="__('Contents')" readonly :placeholder="__('N/A')" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="metadata"
                            :helper="__('Read access is mandatory.')" :label="__('Metadata')" readonly :placeholder="__('N/A')" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="pullRequests"
                            :helper="__('Write access is needed for preview deployment status updates.')"
                            :label="__('Pull requests')" readonly :placeholder="__('N/A')" />
                    </div>
                </x-application.settings-section>

                @php($missingDeploymentRequirements = $github_app->missingDeploymentRequirements())
                <x-application.settings-section :title="__('GitHub deployments')"
                    :description="__('Coolify creates a GitHub deployment for each deployment and updates its status, so branches and pull requests show what is deployed.')">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <x-forms.input canGate="view" :canResource="$github_app"
                            :label="__('Deployments')" readonly placeholder="N/A" :value="$github_app->deployments"
                            :helper="__('Repository permission. Write access is needed to create deployments and their statuses.')" />
                    </div>
                    @if ($missingDeploymentRequirements !== [])
                        <x-callout type="info" :title="__('Not ready for GitHub deployments')" class="mt-4">
                            {{ __('Update the App on GitHub, then click Refetch. Missing:') }}
                            <ul class="mt-1 list-disc pl-4">
                                @foreach ($missingDeploymentRequirements as $requirement)
                                    <li>{{ $requirement }}</li>
                                @endforeach
                            </ul>
                            @can('view', $github_app)
                                <div class="mt-2">
                                    <a href="{{ getPermissionsPath($github_app) }}" class="button" target="_blank"
                                        rel="noopener noreferrer">
                                        {{ __('Update on GitHub') }}
                                        <x-external-link />
                                    </a>
                                </div>
                            @endcan
                        </x-callout>
                    @endif
                </x-application.settings-section>

                @php($missingRunnerRequirements = $github_app->missingRunnerRequirements())
                <x-application.settings-section :title="__('GitHub Actions runners')"
                    :description="__('Your servers can run the organization\'s workflow jobs in Docker containers. Configure them in the GitHub Runners menu of a server.')">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <x-forms.input canGate="view" :canResource="$github_app"
                            :label="__('Self-hosted runners')" readonly placeholder="N/A"
                            :value="$github_app->organization_self_hosted_runners"
                            :helper="__('Organization permission. Write access is needed to register runners.')" />
                        <x-forms.input canGate="view" :canResource="$github_app"
                            :label="__('Actions')" readonly placeholder="N/A" :value="$github_app->actions"
                            :helper="__('Repository permission. Read access is needed for the Workflow job webhook event.')" />
                        <x-forms.input canGate="view" :canResource="$github_app"
                            :label="__('Webhook events')" readonly placeholder="N/A"
                            :value="implode(', ', $github_app->webhook_events ?? [])"
                            :helper="__('Refetch to update this list after you change the App on GitHub.')" />
                    </div>
                    @if ($missingRunnerRequirements !== [])
                        <x-callout type="info" :title="__('Not ready for runners')" class="mt-4">
                            {{ __('Update the App on GitHub, then click Refetch. Missing:') }}
                            <ul class="mt-1 list-disc pl-4">
                                @foreach ($missingRunnerRequirements as $requirement)
                                    <li>{{ $requirement }}</li>
                                @endforeach
                            </ul>
                            @can('view', $github_app)
                                <div class="mt-2">
                                    <a href="{{ getPermissionsPath($github_app) }}" class="button" target="_blank"
                                        rel="noopener noreferrer">
                                        {{ __('Update on GitHub') }}
                                        <x-external-link />
                                    </a>
                                </div>
                            @endcan
                        </x-callout>
                    @endif
                </x-application.settings-section>
            </div>
