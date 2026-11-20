<div>
    <x-slot:title>
        {{ __('Team Variables') }} | {{ brand_name() }}
    </x-slot>

    <x-shared-variables.editor :resource="$team" :variables="$team->environment_variables"
        type="team" :title="__('Team variables')" :view="$view" :variablesLabel="__('Team shared variables')" />
</div>
