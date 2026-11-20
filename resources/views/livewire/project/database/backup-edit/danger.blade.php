<div class="application-settings-form">
    <x-application.settings-section :title="__('Delete backup schedule')"
        :description="__('Permanently remove this schedule and optionally its backup archives.')">
        <x-danger-zone :title="__('This action cannot be undone.')">
            <p>{{ __('You can select which backup archives to remove.') }}</p>
            <x-slot:action>
        @if ($backup->database_id !== 0)
            <x-modal-confirmation :title="__('Confirm Backup Schedule Deletion?')" isErrorButton submitAction="delete"
                :checkboxes="$checkboxes" :actions="[
                    __('The selected backup schedule will be deleted.'),
                    __('Scheduled backups for this database will stop if this is its only schedule.'),
                ]"
                confirmationText="{{ $backup->database->name }}"
                :confirmationLabel="__('Enter the database name to confirm deletion.')"
                :shortConfirmationLabel="__('Database Name')">
                <x-slot:trigger>
                    <x-forms.button isError>{{ __('Delete schedule') }}</x-forms.button>
                </x-slot:trigger>
            </x-modal-confirmation>
        @endif
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
</div>
