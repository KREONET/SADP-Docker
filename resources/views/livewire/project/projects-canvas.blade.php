<div class="h-full">
    <x-slot:title>
        Projects > Canvas | Coolify
    </x-slot>
    <div data-environment-canvas data-endpoint="/projects/canvas"
        data-empty="{{ __('No resources in your projects yet') }}"
        data-view-only="{{ __('Only team admins can move cards and edit links.') }}"
        data-team="{{ $teamId }}" data-user="{{ auth()->id() }}"
        class="relative h-full" wire:ignore>
        <x-canvas-translations />
        {{-- The canvas moves this group into its toolbar (page actions last, at the right end),
             which can be outside this component,
             so buttons open the modals below with a window event. --}}
        <div data-ec-views class="flex items-center gap-2">
            <x-canvas-view-toggle on-canvas :list-url="route('project.index')"
                :canvas-url="route('project.canvas.index')" />
            @can('createAnyResource')
                <button type="button" x-data x-on:click="$dispatch('open-new-project')"
                    class="button button-highlighted whitespace-nowrap">
                    <x-reicon name="plus" class="size-3.5" />
                    {{ __('New project') }}
                </button>
            @endcan
        </div>
    </div>
    @can('createAnyResource')
        <x-modal-input title="{{ __('New Project') }}" x-on:open-new-project.window="modalOpen = true">
            <x-slot:content></x-slot:content>
            <livewire:project.add-empty />
        </x-modal-input>
    @endcan
</div>
