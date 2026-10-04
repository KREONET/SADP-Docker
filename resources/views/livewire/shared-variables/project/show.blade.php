<div>
    <x-slot:title>
        {{ __('Project Variables') }} | {{ brand_name() }}
    </x-slot>

    <x-shared-variables.editor :resource="$project" :variables="$project->environment_variables"
        type="project" title="{{ $project->name }}" :view="$view" :variablesLabel="__('Project shared variables')" />
</div>
