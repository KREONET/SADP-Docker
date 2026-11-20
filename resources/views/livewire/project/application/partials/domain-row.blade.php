@php
    $isSuggested = (bool) ($row['is_suggested'] ?? false);
    $dnsMessage = filled($row['dns_message'] ?? null) ? __($row['dns_message']) : ($row['dns_message'] ?? null);
    $dnsType = match ($row['dns_status']) {
        'ok' => 'success',
        'failed' => 'error',
        'skipped' => 'warning',
        default => 'neutral',
    };
    $dnsLabel = match ($row['dns_status']) {
        'ok' => __('DNS matches'),
        'failed' => __('DNS mismatch'),
        'skipped' => __('DNS skipped'),
        'checking' => __('Checking DNS...'),
        'pending' => __('Not checked'),
        default => __('DNS unknown'),
    };
    $gridClass = 'service-domains-overview-grid';
    $publicUrl = getFqdnWithoutPort($row['url']);
    $faviconUrl = $isSuggested || $application->is_http_basic_auth_enabled ? null : \App\Support\DomainFavicon::url($publicUrl, $row['dns_status'] ?? null, $row['expected_ip'] ?? null);
    $rowDirection = $isCompose
        ? ($serviceRedirects[$this->serviceRedirectWireKey($row['service'])] ?? 'both')
        : $redirect;
    $isNoindexed = $application->isDomainNoindexed($row['url']);
    $domainKey = hash('sha256', $row['url'].'|'.($row['service'] ?? ''));
    $editingParts = \App\Support\DomainUrlParts::split($row['url']);
    if ($row['has_port_override'] ?? false) {
        $editingParts['port'] = (string) $row['internal_port'];
    }
@endphp

<div wire:key="domain-row-{{ md5(($isSuggested ? 's:' : '') . $row['url'] . '|' . ($row['service'] ?? '')) }}"
    x-show="matchesDomainSearch(@js(($row['service'] ?? '').' '.$row['url']))" class="env-table-item">
    <div @class([
        'data-table-row',
        $gridClass,
        'domains-row-suggested' => $isSuggested,
    ])>
        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 items-center gap-2">
                @if ($isSuggested)
                    <span
                        class="min-w-0 text-[13px] text-black sm:truncate dark:text-white"
                        title="{{ __(':url (not configured yet)', ['url' => $row['url']]) }}">
                        {{ $row['url'] }}
                    </span>
                @else
                    @if ($faviconUrl)
                        <span class="relative size-4 shrink-0" aria-hidden="true">
                            <x-reicon name="globe"
                                class="domain-favicon-fallback size-4 text-neutral-400 dark:text-fg-faint" />
                            <img src="{{ $faviconUrl }}" alt="" loading="lazy" decoding="async"
                                referrerpolicy="no-referrer"
                                x-init="if ($el.complete && $el.naturalWidth > 0) { $el.previousElementSibling.classList.add('hidden'); $el.classList.remove('invisible') }"
                                x-on:load="$el.previousElementSibling.classList.add('hidden'); $el.classList.remove('invisible')"
                                x-on:error="$el.remove()"
                                class="invisible absolute inset-0 size-4 rounded-sm" />
                        </span>
                    @endif
                    <a href="{{ $publicUrl }}" target="_blank" rel="noopener noreferrer"
                        class="min-w-0 flex-1 truncate text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs dark:text-fg dark:decoration-white/20 dark:hover:decoration-brand-light"
                        title="{{ $publicUrl }}">
                        {{ $publicUrl }}
                    </a>
                @endif
                @if ($isSuggested && ! empty($row['suggestion_label']))
                    <span class="table-badge table-badge-warning shrink-0">{{ $row['suggestion_label'] }}</span>
                @endif
            </div>
            @if ($isSuggested && filled($row['dns_message']))
                <p class="text-[12px] leading-4 text-amber-700 sm:truncate dark:text-amber-400/90"
                    title="{{ $dnsMessage }}">
                    {{ $dnsMessage }}
                </p>
            @endif
        </div>

        <div class="service-domain-detail" title="{{ __('Protocol redirect') }}">
            <span class="service-domain-detail-label">{{ __('Protocol redirect') }}</span>
            <span>{{ str_starts_with($row['url'], 'https://') && $isForceHttpsEnabled ? 'HTTP → HTTPS' : __('Disabled') }}</span>
        </div>
        <div class="service-domain-detail" title="{{ __('Domain redirect') }}">
            <span class="service-domain-detail-label">{{ __('Domain redirect') }}</span>
            <span>{{ match ($rowDirection) { 'www' => 'non-www → www', 'non-www' => 'www → non-www', default => __('Disabled') } }}</span>
        </div>
        <div class="service-domain-detail"
            title="{{ ($row['has_port_override'] ?? false) ? __('Custom internal port for this domain') : __('Inherited from the application or Compose service port') }}">
            <span class="service-domain-detail-label">{{ __('Internal port') }}</span>
            @if (filled($row['internal_port'] ?? null))
                <span aria-label="{{ __('Internal port :port', ['port' => $row['internal_port']]) }}">{{ $row['internal_port'] }}</span>
            @else
                <span role="img" aria-label="{{ __('No internal port') }}" title="{{ __('No internal port. Set Ports Exposes or a per-domain internal port so the proxy can route this domain.') }}" class="text-red-500 dark:text-red-400">
                    <x-reicon name="alert-triangle" class="size-4" />
                </span>
            @endif
        </div>
        <div class="service-domain-detail">
            <span class="service-domain-detail-label">{{ __('Search indexing') }}</span>
            <span role="img" aria-label="{{ $isNoindexed ? __('Search indexing blocked') : __('Search indexing allowed') }}"
                title="{{ $isNoindexed ? __('Search indexing blocked') : __('Search indexing allowed') }}">
                <x-reicon :name="$isNoindexed ? 'x' : 'check'" class="size-4" />
            </span>
        </div>

        <div class="service-domain-mobile-summary" aria-label="{{ __('Domain routing summary') }}">
            @if (str_starts_with($row['url'], 'https://') && $isForceHttpsEnabled)
                <span>HTTP → HTTPS</span>
            @endif
            @if (in_array($rowDirection, ['www', 'non-www'], true))
                <span>{{ $rowDirection === 'www' ? 'non-www → www' : 'www → non-www' }}</span>
            @elseif (! str_starts_with($row['url'], 'https://') || ! $isForceHttpsEnabled)
                <span>{{ __('No redirects') }}</span>
            @endif
            <span>{{ filled($row['internal_port'] ?? null) ? __('Port :port', ['port' => $row['internal_port']]) : __('Port missing') }}</span>
            <span>{{ $isNoindexed ? __('Noindex') : __('Indexable') }}</span>
        </div>

        <div class="service-domain-dns flex min-w-0 items-center">
            @if ($row['dns_status'] === 'failed')
                <x-status-badge as="button" @click="$dispatch('open-dns-records-modal')" :status="$dnsLabel" :type="$dnsType"
                    :title="__('View DNS records to fix')" class="cursor-pointer hover:bg-neutral-200 dark:hover:bg-white/[0.1]" />
            @elseif ($row['dns_status'] === 'checking')
                <x-status-badge dynamic :title="$dnsMessage">
                    <x-loading compact :aria-label="__('Checking DNS')" />
                    <span class="truncate">{{ __('Checking DNS...') }}</span>
                </x-status-badge>
            @else
                <x-status-badge :status="$dnsLabel" :type="$dnsType"
                    :title="$row['dns_status'] === 'ok' ? null : $dnsMessage" />
            @endif
        </div>

        <div class="service-domain-actions flex items-center justify-end gap-1">
            @can('update', $application)
                @if ($labelsAreWritable || $isSuggested)
                    <button type="button" wire:click="checkDomainDns({{ $index }})"
                        wire:loading.attr="disabled"
                        wire:target="checkDomainDns({{ $index }}),checkAllDns"
                        class="icon-button shrink-0" title="{{ __('Check DNS') }}" aria-label="{{ __('Check DNS') }}">
                        <x-reicon name="refresh" class="size-3.5" />
                    </button>
                    @if ($isSuggested && ! $labelsAreWritable)
                        @if ($row['needs_force_add'] ?? false)
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isError class="h-7! px-2! text-[12px]!">
                                {{ __('Continue') }}
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isHighlighted class="h-7! shrink-0 px-2.5! text-[12px]!">
                                {{ __('Add domain') }}
                            </x-forms.button>
                        @endif
                    @endif
                @else
                    <x-table.row-actions label="Domain actions">
                        <x-table.row-action wire:click="checkDomainDns({{ $index }})"
                            wire:loading.attr="disabled"
                            wire:target="checkDomainDns({{ $index }}),checkAllDns">
                            Check DNS
                        </x-table.row-action>
                        <x-table.row-action
                            x-on:click="openEditDomain({{ \Illuminate\Support\Js::from($index) }}, {{ \Illuminate\Support\Js::from($row['url']) }}, {{ \Illuminate\Support\Js::from($editingParts) }}, {{ \Illuminate\Support\Js::from($row['service']) }}, {{ \Illuminate\Support\Js::from($isNoindexed ? 'noindex' : 'index') }}, {{ \Illuminate\Support\Js::from($rowDirection) }}, {{ \Illuminate\Support\Js::from($row['inherited_port'] ?? null) }})"
                            aria-label="Edit {{ $publicUrl }}">
                            Edit
                        </x-table.row-action>
                        <x-slot:danger>
                            <x-modal-confirmation buttonFullWidth title="Remove domain?" buttonTitle="Remove"
                                isErrorButton canGate="update" :canResource="$application"
                                submitAction="removeDomainByKey({{ $domainKey }})" :actions="[
                                    __('This domain will be removed from the application.'),
                                    __('Redeploy or restart may be required for proxy changes.'),
                                ]" :checkboxes="[['id' => 'deleteManagedDns', 'label' => __('Also delete the DNS record created by Coolify, if present.')]]"
                                :confirmWithPassword="false" :confirmWithText="false" :step2ButtonText="__('Remove domain')">
                                <x-slot:trigger>
                                    <x-table.row-action>Delete</x-table.row-action>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        </x-slot:danger>
                    </x-table.row-actions>
                @endif
            @endcan
        </div>
    </div>
</div>
