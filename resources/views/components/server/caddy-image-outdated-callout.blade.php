@props(['image'])

@php use App\Models\Server; @endphp

<x-callout type="warning" :title="__('Caddy proxy image is outdated')" {{ $attributes }}>
    {!! __('The proxy configuration uses <span class="font-mono">:image</span>. Per-resource traffic analytics and current Caddy features need version 2.9 or newer. To fix this, change the image in the proxy configuration to <span class="font-mono">:recommended</span>, then restart the proxy.', ['image' => e($image), 'recommended' => e(Server::RECOMMENDED_CADDY_PROXY_IMAGE)]) !!}
</x-callout>
