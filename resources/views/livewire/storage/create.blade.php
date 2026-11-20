@can('create', App\Models\S3Storage::class)
    <div class="w-full">
        <div class="mb-4">{{ __('For more details, please visit the') }} <a class="underline dark:text-brand-light"
                href="https://coolify.io/docs/knowledge-base/s3/introduction" target="_blank">{{ __('Coolify Docs') }}</a>.</div>
        <form class="flex flex-col gap-2" wire:submit='submit'>
            <div class="w-64">
                <x-forms.listbox required live id="type" :label="__('Type')" :options="App\Enums\BackupStorageType::options()" />
            </div>
            <div class="flex gap-2">
                <x-forms.input required :label="__('Name')" id="name" />
                <x-forms.input :label="__('Description')" id="description" />
            </div>
            @if ($type === 's3')
                {{-- Sends the endpoint right away, so the URL style below follows the provider. --}}
                <div x-on:change="$wire.$commit()">
                    <x-forms.domain-input id="endpointParts" errorId="endpoint" host-label="{{ __('Host') }}"
                        host-placeholder="{{ __('minio.internal or 192.168.1.50') }}" />
                </div>
                <div class="flex gap-2">
                    <x-forms.input required :label="__('Bucket')" id="bucket" />
                    <x-forms.input required :helper="__('Region only required for AWS. Leave it as-is for other providers.')"
                        :label="__('Region')" id="region" />
                </div>
                <div class="flex gap-2">
                    <x-forms.input required type="password" :label="__('Access Key')" id="key" />
                    <x-forms.input required type="password" :label="__('Secret Key')" id="secret" />
                </div>
                <div class="w-64">
                    <x-forms.checkbox id="usePathStyleEndpoint" :label="__('Use path-style URLs')"
                        :helper="__('Turn this off for providers that only accept virtual-hosted-style URLs (bucket.endpoint). Coolify turns it off for Alibaba Cloud OSS, Tencent Cloud COS, and Volcengine TOS, and when the provider rejects path-style URLs.')" />
                    <x-forms.checkbox id="skipTlsVerification" :label="__('Skip TLS certificate verification')"
                        :helper="__('Turn this on only for an endpoint with a self-signed or private CA certificate. Coolify and the backup helper then accept any certificate, so an attacker on the network path can read or change your backups. Browsers that download files from this storage must still trust the certificate.')" />
                </div>
            @else
                @if ($type === 'webdav')
                    <x-forms.domain-input id="endpointParts" errorId="endpoint" host-label="{{ __('Host') }}"
                        host-placeholder="cloud.example.com" port-placeholder="443"
                    path-placeholder="/remote.php/dav/files/USER/backups"
                    path-helper="The path of the backup directory on the WebDAV server, for example /remote.php/dav/files/USER/backups for Nextcloud." />
                @else
                    @include('livewire.storage.server-endpoint-fields', ['defaultPort' => $type === 'sftp' ? 22 : 21])
                @endif
                @if ($type === 'sftp')
                    <x-forms.input required :label="__('Username')" id="key" autocomplete="off" />
                    @include('livewire.storage.sftp-login-fields')
                @else
                    <div class="flex gap-2">
                        <x-forms.input required :label="__('Username')" id="key" autocomplete="off" />
                        <x-forms.input required type="password" :label="__('Password')" id="secret" />
                    </div>
                @endif
                <p class="text-xs text-neutral-500 dark:text-fg-dim">{{ __('Backups keep their full path on the server (for example data/coolify/backups/...) below this directory. Coolify connects from its own container to check, restore, download, and delete backups, and from each server to upload them.') }}
                </p>
            @endif

            <x-forms.button class="mt-4" type="submit" wire:target="submit">
                {{ __('Validate Connection & Continue') }}
            </x-forms.button>
        </form>
    </div>
@else
    <x-callout type="danger" :title="__('Insufficient Permissions')">
        {{ __('You don\'t have permission to create new storage configurations. Please contact your team administrator for access.') }}
    </x-callout>
@endcan
