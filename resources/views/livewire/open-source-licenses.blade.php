<div>
    <x-slot:title>{{ __('Open Source Licenses') }} | Coolify</x-slot>

    <div class="flex min-w-0 flex-col gap-6">
        <div>
            <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Open Source Licenses') }}</h1>
            <p class="mt-1 text-[13px] text-neutral-600 dark:text-fg-dim">
                {{ __('This software is built on open source projects. Thank you to their authors and contributors.') }}
            </p>
        </div>

        <x-application.settings-section title="Coolify">
            <div class="grid gap-3 text-[13px] text-neutral-700 dark:text-fg-dim">
                <p>
                    {!! __('This software is based on <a class="underline" href=":url" target="_blank" rel="noopener noreferrer">Coolify</a> by coollabsio and its contributors, licensed under the Apache License 2.0, and contains modifications.', ['url' => e('https://github.com/coollabsio/coolify')]) !!}
                </p>
                <div>
                    <x-forms.button type="button" wire:click="$toggle('showCoolifyLicense')">
                        {{ $showCoolifyLicense ? __('Hide license') : __('View Apache License 2.0') }}
                    </x-forms.button>
                </div>
                @if ($coolifyLicense)
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg border border-neutral-200 p-3 font-mono text-[11px] dark:border-white/[0.08]">{{ $coolifyLicense }}</pre>
                @endif
            </div>
        </x-application.settings-section>

        <x-application.settings-section :title="__('Third-party software')"
            :description="__('Software shipped with this version: PHP packages of the panel, packages bundled into the web interface, and packages of the terminal server. Development-only tools are not included.')" flush>
            <div class="flex flex-wrap gap-1.5 border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ __(':count packages:', ['count' => number_format($total)]) }}</span>
                @foreach ($licenseCounts as $licenseId => $count)
                    <button type="button" wire:key="license-chip-{{ $licenseId }}"
                        wire:click="$set('license', @js($license === $licenseId ? 'all' : $licenseId))"
                        @class([
                            'rounded-md px-2 py-0.5 text-[11px]',
                            'bg-neutral-100 dark:bg-white/[0.06]' => $license !== $licenseId,
                            'bg-coollabs text-white dark:bg-warning dark:text-black' => $license === $licenseId,
                        ])>
                        {{ $licenseId === 'Unknown' ? __('Unknown') : $licenseId }} · {{ $count }}
                    </button>
                @endforeach
            </div>

            <div class="flex flex-col gap-2 border-b border-neutral-200 p-3 sm:flex-row sm:items-center dark:border-white/[0.08]">
                <div class="relative min-w-0 flex-1 sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('Search packages') }}"
                        aria-label="{{ __('Search packages') }}"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                </div>
                <select class="input sm:w-64" wire:model.live="component" aria-label="{{ __('Component') }}">
                    <option value="all">{{ __('All components') }}</option>
                    @foreach ($componentLabels as $value => $label)
                        <option wire:key="component-{{ $value }}" value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>

            @if ($packages->isNotEmpty())
                <div class="overflow-x-auto">
                    <div class="data-table transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                        wire:target="search,component,license,setPage,previousPage,nextPage">
                        <div class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_8rem_12rem_10rem_6rem] gap-4 border-b border-neutral-200 px-4 py-2 text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:border-white/[0.07] dark:text-fg-faint">
                            <span>{{ __('Package') }}</span>
                            <span>{{ __('Version') }}</span>
                            <span>{{ __('Component') }}</span>
                            <span>{{ __('License') }}</span>
                            <span></span>
                        </div>
                        @foreach ($packages as $package)
                            <div wire:key="package-{{ $package['key'] }}"
                                class="border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                                <div class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_8rem_12rem_10rem_6rem] items-center gap-4 text-[12px]">
                                    <div class="min-w-0 truncate font-medium text-black dark:text-fg">
                                        @if ($package['homepage'])
                                            <a class="hover:underline" href="{{ $package['homepage'] }}" target="_blank" rel="noopener noreferrer">{{ $package['name'] }}</a>
                                        @else
                                            {{ $package['name'] }}
                                        @endif
                                    </div>
                                    <div class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ $package['version'] }}</div>
                                    <div class="truncate text-neutral-600 dark:text-fg-dim">{{ isset($componentLabels[$package['component']]) ? __($componentLabels[$package['component']]) : $package['component'] }}</div>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($package['licenses'] as $licenseId)
                                            <span wire:key="package-{{ $package['key'] }}-license-{{ $licenseId }}"
                                                class="rounded-md bg-neutral-100 px-1.5 py-0.5 text-[11px] dark:bg-white/[0.06]">{{ $licenseId }}</span>
                                        @empty
                                            <span class="text-[11px] text-neutral-500">{{ __('Unknown') }}</span>
                                        @endforelse
                                    </div>
                                    <div class="text-right">
                                        <button type="button" class="text-[11px] underline" wire:click="toggle(@js($package['key']))">
                                            {{ $expandedKey === $package['key'] ? __('Hide') : __('License') }}
                                        </button>
                                    </div>
                                </div>
                                @if ($expandedKey === $package['key'])
                                    @if ($package['license_text'])
                                        <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border border-neutral-200 p-3 font-mono text-[11px] text-neutral-700 dark:border-white/[0.08] dark:text-fg-dim">{{ $package['license_text'] }}</pre>
                                    @else
                                        <div class="mt-2 text-[12px] text-neutral-600 dark:text-fg-dim">
                                            {{ __('The package has no license file. See the') }}
                                            @foreach ($package['licenses'] as $licenseId)
                                                <a wire:key="spdx-{{ $package['key'] }}-{{ $licenseId }}" class="underline"
                                                    href="https://spdx.org/licenses/{{ rawurlencode($licenseId) }}.html" target="_blank" rel="noopener noreferrer">{{ $licenseId }}</a>
                                            @endforeach
                                            {{ __('license text.') }}
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <x-table-pagination :from="$packages->firstItem() ?? 0" :to="$packages->lastItem() ?? 0"
                    :total="$packages->total()" :current-page="$packages->currentPage()"
                    :last-page="$packages->lastPage()" wire-target="setPage,previousPage,nextPage"
                    previous-action="previousPage" next-action="nextPage" />
            @else
                <x-empty :title="__('No packages found')" :description="__('Try another search or filter.')" icon-name="file-content" size="sm" />
            @endif
        </x-application.settings-section>
    </div>
</div>
