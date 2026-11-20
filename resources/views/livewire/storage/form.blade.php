<form wire:submit="submit" class="application-settings-form">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section :title="__('General')"
        description="{{ $storage->isS3() ? 'S3-compatible destination' : $storage->type->label().' destination' }} used by database and volume backups.">
        <x-slot:actions>
            @can('validateConnection', $storage)
                <x-forms.button type="button" wire:click="testConnection">
                    <x-reicon name="check-circle" class="size-3.5" />
                    {{ __('Validate connection') }}
                </x-forms.button>
            @endcan
        </x-slot:actions>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$storage" :label="__('Name')" id="name" />
            <x-forms.input canGate="update" :canResource="$storage" :label="__('Description')" id="description" />
            <x-forms.input :label="__('Type')" :value="$storage->type->label()" disabled
                :helper="__('The type cannot change after creation. Create a new storage for another type.')" />
            <div></div>
            @if ($storage->isS3())
            <div class="lg:col-span-2">
                @can('update', $storage)
                    {{-- Sends the endpoint right away, so the URL style below follows the provider. --}}
                    <div x-on:change="$wire.$commit()">
                        <x-forms.domain-input id="endpointParts" errorId="endpoint" host-label="{{ __('Host') }}"
                            host-placeholder="{{ __('minio.internal or 192.168.1.50') }}" />
                    </div>
                @else
                    <x-forms.input :label="__('Endpoint')" :value="$endpoint" disabled />
                @endcan
            </div>
            <x-forms.input canGate="update" :canResource="$storage" required :label="__('Bucket')" id="bucket" />
            <x-forms.input canGate="update" :canResource="$storage" required :label="__('Region')" id="region" />
            @if ($isPasswordHiddenForMember)
                <x-forms.input :label="__('Access key')" disabled :value="__('Hidden (only admins can view)')" />
                <x-forms.input :label="__('Secret key')" disabled :value="__('Hidden (only admins can view)')" />
            @else
                <x-forms.input canGate="update" :canResource="$storage" required type="password"
                    :label="__('Access key')" id="key" />
                <x-forms.input canGate="update" :canResource="$storage" type="password" :label="__('Secret key')"
                    id="secret" :required="! $hasSavedSecret"
                    :placeholder="$hasSavedSecret ? 'Saved. Leave empty to keep it.' : ''" />
            @endif
            <x-forms.checkbox canGate="update" :canResource="$storage" id="usePathStyleEndpoint"
                :label="__('Use path-style URLs')"
                :helper="__('Turn this off for providers that only accept virtual-hosted-style URLs (bucket.endpoint). Coolify turns it off for Alibaba Cloud OSS, Tencent Cloud COS, and Volcengine TOS, and when the provider rejects path-style URLs.')" />
            <x-forms.checkbox canGate="update" :canResource="$storage" id="skipTlsVerification"
                :label="__('Skip TLS certificate verification')"
                :helper="__('Turn this on only for an endpoint with a self-signed or private CA certificate. Coolify and the backup helper then accept any certificate, so an attacker on the network path can read or change your backups. Browsers that download files from this storage must still trust the certificate.')" />
            @else
                <div class="lg:col-span-2">
                    @if ($storage->type === App\Enums\BackupStorageType::Webdav)
                        @can('update', $storage)
                            <x-forms.domain-input id="endpointParts" errorId="endpoint" host-label="{{ __('Host') }}"
                                host-placeholder="cloud.example.com" port-placeholder="443"
                    path-placeholder="/remote.php/dav/files/USER/backups"
                    path-helper="The path of the backup directory on the WebDAV server, for example /remote.php/dav/files/USER/backups for Nextcloud." />
                        @else
                            <x-forms.input :label="__('URL')" :value="$endpoint" disabled />
                        @endcan
                    @else
                        @include('livewire.storage.server-endpoint-fields', [
                            'defaultPort' => $storage->type->defaultPort(),
                            'disabled' => auth()->user()?->cannot('update', $storage),
                        ])
                    @endif
                </div>
                @if ($isPasswordHiddenForMember)
                    <x-forms.input :label="__('Username')" disabled value="Hidden (only admins can view)" />
                    <x-forms.input :label="__('Password')" disabled :value="__('Hidden (only admins can view)')" />
                @elseif ($storage->type === App\Enums\BackupStorageType::Sftp)
                    <x-forms.input canGate="update" :canResource="$storage" required :label="__('Username')" id="key"
                        autocomplete="off" />
                    <div class="lg:col-span-2">
                        @include('livewire.storage.sftp-login-fields', [
                            'disabled' => auth()->user()?->cannot('update', $storage),
                        ])
                    </div>
                @else
                    <x-forms.input canGate="update" :canResource="$storage" required :label="__('Username')" id="key"
                        autocomplete="off" />
                    <x-forms.input canGate="update" :canResource="$storage" type="password" :label="__('Password')"
                        id="secret" :required="! $hasSavedSecret"
                        :placeholder="$hasSavedSecret ? 'Saved. Leave empty to keep it.' : ''" />
                @endif
                @if ($storage->type === App\Enums\BackupStorageType::Sftp)
                    <div class="flex items-end gap-2 lg:col-span-2">
                        <x-forms.input :label="__('Host key fingerprint')" disabled
                            :value="$storage->sftpHostKeyFingerprint() ?? 'Not saved yet. Validate the connection to save it.'"
                            :helper="__('Coolify saves the host key of the server when you validate the connection, and refuses the server when the key changes. Backups do not save a new key. Reset it only when you know why the key changed.')" />
                        @can('update', $storage)
                            @if ($storage->sftp_host_key)
                                <x-forms.button type="button" wire:click="resetHostKey">{{ __('Reset host key') }}</x-forms.button>
                            @endif
                        @endcan
                    </div>
                @endif
            @endif
        </div>
    </x-application.settings-section>
</form>
