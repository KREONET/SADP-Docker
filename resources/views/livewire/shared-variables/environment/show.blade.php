<div>
    <x-slot:title>
        {{ __('Environment Variables') }} | {{ brand_name() }}
    </x-slot>

    <x-shared-variables.editor :resource="$environment"
        :variables="$environment->environment_variables" type="environment"
        title="{{ $project->name }} / {{ $environment->name }}"
        :view="$view" :variablesLabel="__('Environment shared variables')" />
</div>
