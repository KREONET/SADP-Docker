<div class="application-settings-form">
    <x-application.settings-section :title="__('Delete backup schedule')"
        :description="__('Permanently delete this schedule and optionally delete its local and S3 archives.')"
        class="danger-section">
        <x-danger-zone :title="__('This action cannot be undone.')">
            <p>{{ __('You can select which backup archives to remove.') }}</p>
            <x-slot:action>
            @if ($backup)
                <x-modal-confirmation :title="__('Delete backup schedule?')" isErrorButton submitAction="delete"
                    :checkboxes="$deleteScheduleCheckboxes"
                    :actions="[__('Delete the selected backup schedule.')]"
                    confirmationText="{{ $backup->targetName() }}"
                    :confirmationLabel="__('Enter the :type identifier to confirm.', ['type' => __($backup->targetType())])"
                    :shortConfirmationLabel="__(':type identifier', ['type' => __($backup->targetType())])">
                    <x-slot:trigger>
                        <x-forms.button isError>{{ __('Delete schedule') }}</x-forms.button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            @endif
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
</div>
