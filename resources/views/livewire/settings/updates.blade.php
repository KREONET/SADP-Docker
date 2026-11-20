<div>
    <x-slot:title>
        {{ __('Update Settings') }} | {{ brand_name() }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex min-w-0 flex-col gap-6">
            <x-application.settings-section :title="__('Version')"
                :helper="__('In-app updates are disabled. New versions are published on GitHub.')">
                <div class="flex flex-col gap-4">
                    <p class="text-sm">
                        {{ __('Current version') }}: <code>v{{ config('constants.coolify.version') }}</code>
                    </p>
                    <x-callout type="info" :title="__('How to update')">
                        <p>{{ __('Check the release notes on GitHub, then update the server from the repository checkout:') }}</p>
                        <pre class="mt-2 overflow-x-auto"><code>git pull
sudo bash scripts/paded-deploy.sh</code></pre>
                        <a class="mt-2 inline-block font-medium underline underline-offset-2"
                            href="{{ config('constants.coolify.github_releases_url') }}" target="_blank"
                            rel="noopener noreferrer">{{ __('GitHub Releases') }}</a>
                    </x-callout>
                </div>
            </x-application.settings-section>

            <x-application.settings-section :title="__('Image registry')">
                <div class="max-w-md">
                    <x-forms.listbox id="docker_registry_url" :label="__('Docker registry')" onChange="instantSave"
                        :options="[
                            ['value' => 'docker.io', 'label' => 'Docker Hub'],
                            ['value' => 'ghcr.io', 'label' => 'GitHub Container Registry'],
                        ]"
                        :helper="__('Switch registries if the current source is rate limited.')" />
                </div>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
