@props(['target', 'text' => null])

<div wire:loading.flex wire:target="{{ $target }}"
    {{ $attributes->class(['table-loading-overlay absolute inset-0 z-30 hidden items-center justify-center bg-white/70 backdrop-blur-[1px] dark:bg-black/20']) }}>
    <x-loading aria-label="{{ $text ?? __('Loading records...') }}" class="[&_.loading-indicator]:size-5" />
</div>
