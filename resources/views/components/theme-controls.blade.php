@props(['variant' => 'full'])

@php($checkPath = 'm2.5 6.25 2.1 2.1 4.9-5')

@if ($variant === 'menu')
    {{-- Compact list for the profile dropdown. The parent controls visibility;
         all theme state/logic comes from the shared window.themeControls(). --}}
    <div x-data="themeControls()" class="grid gap-0.5">
        @foreach ([
            ['value' => 'light', 'label' => __('Light')],
            ['value' => 'system', 'label' => __('System')],
            ['value' => 'dark', 'label' => __('Dark')],
            ['value' => 'custom', 'label' => __('Custom')],
        ] as $option)
            @if ($option['value'] === 'custom')
                <div class="relative" @click.outside="pickerOpen = false">
                    <button type="button" @click="chooseCustom()" aria-haspopup="dialog" :aria-expanded="pickerOpen"
                        class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                        <span class="flex items-center gap-2">
                            <span class="size-3.5 rounded-full border border-white/20"
                                :style="`background: ${themeColor}`"></span>
                            {{ __('Custom') }}
                        </span>
                        <svg x-show="theme === 'custom'" class="size-3.5 text-coollabs dark:text-warning"
                            viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <x-theme-controls.picker position="left-full top-0 ml-2" />
                </div>
            @else
                <button type="button" @click="setTheme('{{ $option['value'] }}')"
                    class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                    <span>{{ $option['label'] }}</span>
                    <svg x-show="theme === '{{ $option['value'] }}'"
                        class="size-3.5 text-coollabs dark:text-warning" viewBox="0 0 12 12" fill="none"
                        aria-hidden="true">
                        <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            @endif
        @endforeach
        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>
        <div class="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
            {{ __('Page width') }}
        </div>
        @foreach ([
            ['value' => 'full', 'label' => __('Full width')],
            ['value' => 'centered', 'label' => __('Centered')],
        ] as $option)
            <button type="button" @click="setWidth('{{ $option['value'] }}')"
                class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                <span>{{ $option['label'] }}</span>
                <svg x-show="pageWidth === '{{ $option['value'] }}'"
                    class="size-3.5 text-coollabs dark:text-warning" viewBox="0 0 12 12" fill="none"
                    aria-hidden="true">
                    <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        @endforeach
    </div>
@else
    {{-- Full card grid for the Appearance settings page. --}}
    <div x-data="themeControls()" class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
        {{-- 화면 크기: 밀도(컨트롤 크기)와 글자 크기. 값은 localStorage 에 저장되고
             resources/css/design-tokens.css 가 <html data-density / data-text-size> 를 읽어 반영합니다. --}}
        <section class="application-settings-section" id="display-size">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Display size') }}</h2>
                    <p>{{ __('Make buttons and text easier to see and tap. The choice is saved in this browser.') }}</p>
                </div>
                <x-forms.button type="button" @click="resetDisplay()">{{ __('Reset to default') }}</x-forms.button>
            </div>
            <div class="application-settings-section-body flex flex-col gap-6">
                <div>
                    <h3 class="mb-2 text-sm font-semibold text-black dark:text-fg">{{ __('Control size') }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            ['value' => 'auto', 'label' => __('Auto'), 'description' => __('Large targets on touch screens, comfortable with a mouse.')],
                            ['value' => 'compact', 'label' => __('Compact'), 'description' => __('Dense layout that shows more on one screen.')],
                            ['value' => 'comfortable', 'label' => __('Comfortable'), 'description' => __('Balanced size for everyday use.')],
                            ['value' => 'touch', 'label' => __('Touch'), 'description' => __('Large buttons that are easy to tap with a finger.')],
                        ] as $option)
                            <div role="button" tabindex="0" wire:key="density-{{ $option['value'] }}"
                                @click="setDensity('{{ $option['value'] }}')"
                                @keydown.enter.prevent="setDensity('{{ $option['value'] }}')"
                                :aria-pressed="density === '{{ $option['value'] }}'"
                                class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                                :class="density === '{{ $option['value'] }}'
                                    ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                                    : ''">
                                {{-- 카드마다 해당 밀도로 실제 버튼과 입력창을 그립니다. --}}
                                <div class="flex flex-col gap-2 border-b border-neutral-200 bg-neutral-50 p-3 dark:border-white/[0.07] dark:bg-black/20"
                                    @if ($option['value'] === 'auto')
                                        x-bind:data-density="resolveUiDensity('auto')"
                                    @else
                                        data-density="{{ $option['value'] }}"
                                    @endif>
                                    <input class="input pointer-events-none" value="{{ __('Name') }}" readonly tabindex="-1" aria-hidden="true">
                                    <span class="button pointer-events-none w-fit" aria-hidden="true">{{ __('Save') }}</span>
                                </div>
                                <div class="p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                                        <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                            x-show="density === '{{ $option['value'] }}'" x-cloak />
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold text-black dark:text-fg">{{ __('Text size') }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            ['value' => 'small', 'label' => __('Small'), 'size' => '0.875rem'],
                            ['value' => 'normal', 'label' => __('Normal'), 'size' => '1rem'],
                            ['value' => 'large', 'label' => __('Large'), 'size' => '1.25rem'],
                            ['value' => 'xlarge', 'label' => __('Extra large'), 'size' => '1.5rem'],
                        ] as $option)
                            <button type="button" wire:key="text-size-{{ $option['value'] }}"
                                @click="setTextSize('{{ $option['value'] }}')"
                                :aria-pressed="textSize === '{{ $option['value'] }}'"
                                class="flex min-h-16 items-center justify-between gap-3 rounded-[10px] border border-neutral-200 bg-white px-4 py-3 text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                                :class="textSize === '{{ $option['value'] }}'
                                    ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                                    : ''">
                                <span class="font-semibold text-black dark:text-fg" style="font-size: {{ $option['size'] }}">Aa</span>
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">{{ $option['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- 현재 설정이 그대로 반영되는 미리보기 --}}
                <div class="rounded-[10px] border border-dashed border-neutral-300 p-4 dark:border-white/[0.12]">
                    <h3 class="mb-3 text-sm font-semibold text-black dark:text-fg">{{ __('Preview') }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <input class="input" value="{{ __('Application name') }}" readonly tabindex="-1">
                            <div class="flex flex-wrap gap-2">
                                <span class="button button-highlighted">{{ __('Deploy') }}</span>
                                <span class="button">{{ __('Cancel') }}</span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <span class="menu-item"><x-reicon name="dashboard" class="menu-item-icon" /><span class="menu-item-label">{{ __('Dashboard') }}</span></span>
                            <span class="menu-item menu-item-active"><x-reicon name="projects" class="menu-item-icon" /><span class="menu-item-label">{{ __('Projects') }}</span></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Color theme') }}</h2>
                    <p>{{ __('Choose the color scheme used in this browser.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['value' => 'light', 'label' => __('Light'), 'description' => __('Bright surfaces and dark text.'), 'preview' => 'bg-white'],
                    ['value' => 'system', 'label' => __('System'), 'description' => __('Follow your operating system.'), 'preview' => 'bg-gradient-to-r from-white via-neutral-400 to-[#050505]'],
                    ['value' => 'dark', 'label' => __('Dark'), 'description' => __('Dark surfaces and soft contrast.'), 'preview' => 'bg-[#181818]'],
                    ['value' => 'custom', 'label' => __('Custom'), 'description' => __('Tint light or dark surfaces with any color.'), 'preview' => ''],
                ] as $option)
                    @if ($option['value'] === 'custom')
                        {{-- Clicking the card opens the custom color picker popover (color + light/dark). --}}
                        <div class="relative" @click.outside="pickerOpen = false">
                            <div role="button" tabindex="0" @click="chooseCustom()" @keydown.enter.prevent="chooseCustom()"
                                aria-haspopup="dialog" :aria-expanded="pickerOpen"
                                class="group block w-full overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                                :class="theme === 'custom'
                                    ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                                    : ''">
                                <div class="h-20 border-b border-neutral-200 dark:border-white/[0.07]"
                                    :style="`background: color-mix(in oklab, ${themeColor} 28%, ${customMode === 'light' ? 'oklch(97% 0 0)' : 'oklch(17.35% 0.0020 286.18)'})`">
                                    <div class="flex h-full items-center justify-center">
                                        <div class="h-10 w-20 rounded-md border border-white/15 p-1 shadow-sm">
                                            <div class="h-full w-full rounded-sm" :style="`background: ${themeColor}`"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                                        <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                            x-show="theme === 'custom'" x-cloak />
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                        {{ $option['description'] }}
                                    </p>
                                </div>
                            </div>
                            <x-theme-controls.picker position="left-0 top-full mt-2" />
                        </div>
                    @else
                        <div role="button" tabindex="0"
                            @click="setTheme('{{ $option['value'] }}')"
                            @keydown.enter.prevent="setTheme('{{ $option['value'] }}')"
                            class="group relative overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                            :class="theme === '{{ $option['value'] }}'
                                ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                                : ''">
                            <div class="h-20 {{ $option['preview'] }} border-b border-neutral-200 dark:border-white/[0.07]">
                                <div class="flex h-full items-center justify-center">
                                    <div
                                        class="h-8 w-20 rounded-md border border-black/10 bg-neutral-100/80 shadow-sm dark:border-white/10 dark:bg-black/20">
                                    </div>
                                </div>
                            </div>
                            <div class="p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold text-black dark:text-fg">
                                        {{ $option['label'] }}
                                    </span>
                                    <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                        x-show="theme === '{{ $option['value'] }}'" x-cloak />
                                </div>
                                <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    {{ $option['description'] }}
                                </p>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Page width') }}</h2>
                    <p>{{ __('Choose how content uses the available browser width.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['value' => 'full', 'label' => __('Full width'), 'description' => __('Use all available space for page content.')],
                    ['value' => 'centered', 'label' => __('Centered'), 'description' => __('Keep content centered at a comfortable maximum width.')],
                ] as $option)
                    <button type="button" @click="setWidth('{{ $option['value'] }}')"
                        class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                        :class="pageWidth === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div class="flex h-20 items-center border-b border-neutral-200 bg-neutral-50 px-4 dark:border-white/[0.07] dark:bg-black/15">
                            <div class="flex h-11 w-full gap-1.5 rounded-md border border-neutral-300 bg-white p-1.5 dark:border-white/15 dark:bg-[#181818]">
                                <div class="w-3 shrink-0 rounded-sm bg-neutral-200 dark:bg-white/10"></div>
                                <div @class([
                                    'h-full rounded-sm bg-neutral-200 dark:bg-white/10',
                                    'w-full' => $option['value'] === 'full',
                                    'mx-auto w-2/3' => $option['value'] === 'centered',
                                ])></div>
                            </div>
                        </div>
                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                                <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                    x-show="pageWidth === '{{ $option['value'] }}'" x-cloak />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    </div>
@endif
