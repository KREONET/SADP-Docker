<div>
    @if ($isInstalled)
        <x-callout type="success" :title="__('Sysbox is installed')">
            {{ __('Jobs get a private Docker daemon in an unprivileged container.') }}
        </x-callout>
    @else
        <x-callout type="warning" :title="__('Sysbox is not installed')">
            Isolated Docker needs the Sysbox runtime. Coolify installs Sysbox
            {{ config('constants.github_runner.sysbox.version') }} from the official package
            (Debian and Ubuntu, kernel 5.12 or newer). Docker reloads its configuration, and
            running containers are not restarted.
            @can('update', $server)
                <div class="mt-3">
                    <x-forms.button type="button" wire:click="installSysbox"
                        wire:loading.attr="disabled" wire:target="installSysbox">
                        {{ __('Install Sysbox') }}
                    </x-forms.button>
                </div>
            @endcan
        </x-callout>
    @endif
</div>
