<div class="application-settings-form">
    <x-application.settings-section id="storage-owner-section" :title="__('Owner')"
        :description="__('Only the owner and platform administrators can see and use this storage.')">
        @if ($owner === null)
            <x-callout type="warning" :title="__('No owner')">
                {{ __('This storage was created before owners were recorded, so only platform administrators can see it. Assign a team member to give them access.') }}
            </x-callout>
        @else
            <p class="text-sm">{{ __('Current owner: :email', ['email' => $owner->email]) }}</p>
        @endif

        <div class="mt-4 grid gap-4 lg:grid-cols-2 lg:items-end">
            <x-forms.listbox id="selectedUserId" :label="__('New owner')" :placeholder="__('Choose a team member')"
                :options="$candidates->map(fn ($member) => ['value' => $member->id, 'label' => $member->email])->values()->all()" />
            <div>
                <x-modal-confirmation :title="__('Change the storage owner?')" :buttonTitle="__('Change owner')"
                    submitAction="assignOwner" :confirmWithText="false" :step3ButtonText="__('Change owner')"
                    :warningMessage="e(__('The new owner can see the access keys of this storage and use it for backups.'))"
                    :actions="[__('Give the selected team member access to this storage.')]" />
            </div>
        </div>
    </x-application.settings-section>
</div>
