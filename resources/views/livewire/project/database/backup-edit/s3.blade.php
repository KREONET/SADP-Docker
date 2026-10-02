@if ($availableS3Storages->isEmpty())
    <x-application.settings-section :title="__('S3 storage')"
        :description="__('Send backup archives to a validated object storage destination.')" flush>
        <x-empty :title="__('No validated S3 storage')"
            :description="__('Add and validate an S3 storage destination before enabling remote backups.')"
            icon-name="storages">
            <x-slot:contents>
                <a class="button" {{ wireNavigate() }} href="{{ route('storage.index') }}">{{ __('Open S3 storage') }}</a>
            </x-slot:contents>
        </x-empty>
    </x-application.settings-section>
@else
    <form wire:submit="submit">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section :title="__('S3 storage')"
            :description="__('Choose where remote backups are stored and whether local copies are retained.')">
            <x-slot:actions>
                @if (! $saveS3)
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3" isHighlighted>{{ __('Enable S3') }}</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3">{{ __('Disable S3') }}</x-forms.button>
                @endif
            </x-slot:actions>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.listbox id="s3StorageId" :label="__('S3 storage')" portal :required="$saveS3"
                    :options="$availableS3Storages->map(fn ($s3) => [
                        'value' => $s3->id,
                        'label' => $s3->name,
                    ])->values()->all()" />
                <x-forms.listbox id="disableLocalBackup" :label="__('Local copy')" portal :disabled="! $saveS3"
                    :options="[
                        ['value' => false, 'label' => __('Keep local backup')],
                        ['value' => true, 'label' => __('Delete after S3 upload')],
                    ]" />
            </div>
        </x-application.settings-section>
    </form>
@endif
