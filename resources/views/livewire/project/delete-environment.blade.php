<x-modal-confirmation :title="__('Confirm Environment Deletion?')" :buttonTitle="__('Delete')" isErrorButton
    submitAction="delete" :actions="[__('This will delete the selected environment.')]"
    :confirmationLabel="__('Please confirm the execution of the actions by entering the Environment Name below')"
    :shortConfirmationLabel="__('Environment Name')" confirmationText="{{ $environmentName }}" :confirmWithPassword="false"
    :step2ButtonText="__('Permanently Delete')" />
