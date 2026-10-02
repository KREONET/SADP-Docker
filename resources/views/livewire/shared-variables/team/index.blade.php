<div>
    <x-slot:title>
        {{ __('Team Variables') }} | Coolify
    </x-slot>

    <x-shared-variables.editor :resource="$team" :variables="$team->environment_variables"
        type="team" :title="__('Team variables')" :view="$view" :variablesLabel="__('Team shared variables')" />
</div>
