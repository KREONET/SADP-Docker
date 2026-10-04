{{--
    UI 카탈로그(/dev/ui) 전용: 예제 한 칸.
    사용법:
        <x-dev.example title="버튼" description="설명">
            ...실제로 동작하는 예제...
            <x-slot:code>@verbatim ...복사해 쓸 코드... @endverbatim</x-slot:code>
        </x-dev.example>
--}}
@props([
    'title',
    'description' => null,
    'code' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-[10px] border border-neutral-200 bg-white dark:border-white/[0.07] dark:bg-white/[0.04]']) }}>
    <div class="border-b border-neutral-200 px-4 py-3 dark:border-white/[0.07]">
        <h3 class="text-sm font-semibold text-black dark:text-fg">{{ $title }}</h3>
        @if ($description)
            <p class="mt-0.5 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $description }}</p>
        @endif
    </div>
    <div class="p-4">
        {{ $slot }}
    </div>
    @if ($code)
        <details class="border-t border-neutral-200 dark:border-white/[0.07]">
            <summary class="cursor-pointer select-none px-4 py-2 text-xs font-medium text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg">
                코드 보기
            </summary>
            <pre class="overflow-x-auto bg-neutral-50 px-4 py-3 text-[11px] leading-5 text-neutral-700 dark:bg-black/30 dark:text-fg-dim"><code>{{ trim($code) }}</code></pre>
        </details>
    @endif
</div>
