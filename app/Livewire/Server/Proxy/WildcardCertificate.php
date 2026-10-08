<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Server\ApplyDnsChallenge;
use App\Enums\DnsChallengeProvider;
use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Rules\ValidHostname;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Wildcard TLS certificate for the server's Traefik proxy through ACME DNS-01. Secret credential
 * values are write-only: they are never sent back to the browser, and a blank secret field keeps
 * the stored value.
 */
class WildcardCertificate extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isEnabled = false;

    public ?string $domain = null;

    public string $provider = 'cloudflare';

    public ?string $otherProviderCode = null;

    /** @var array<string, string|null> */
    public array $credentials = [];

    public ?string $otherCredentials = null;

    public ?string $email = null;

    public ?string $resolvers = null;

    /** @var array<int, string> */
    public array $storedCredentialKeys = [];

    public function mount(): void
    {
        $this->authorize('view', $this->server);
        $this->syncFromSettings();
    }

    public function save(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->persist();
            $this->dispatch('success', __('Wildcard certificate settings saved. Apply them to restart the proxy with the new configuration.'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function applyAndRestart(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->persist();
            $restarted = ApplyDnsChallenge::run($this->server);
            auditLog('ui.proxy.dns_challenge_applied', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'enabled' => $this->isEnabled,
                'provider' => $this->server->settings->dns_challenge_provider,
            ]);
            $this->dispatch('success', $restarted
                ? __('Configuration saved. The proxy is restarting to apply it.')
                : __('Configuration saved. It applies the next time the proxy starts.'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function persist(): void
    {
        $this->domain = $this->normalizeDomain($this->domain);
        $this->email = filled($this->email) ? trim($this->email) : null;
        $this->resolvers = filled($this->resolvers) ? preg_replace('/\s+/', '', $this->resolvers) : null;

        $this->validate([
            'isEnabled' => ['boolean'],
            'domain' => [$this->isEnabled ? 'required' : 'nullable', 'string', 'max:253', 'regex:/\./', new ValidHostname],
            'provider' => ['required', 'in:'.implode(',', array_column(DnsChallengeProvider::cases(), 'value'))],
            'otherProviderCode' => [$this->isEnabled && $this->provider === DnsChallengeProvider::Other->value ? 'required' : 'nullable', 'string', 'max:50', 'regex:/^[a-z0-9]+$/', 'not_in:other'],
            'email' => ['nullable', 'email', 'max:255'],
            'resolvers' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9.\-\[\]:]+:\d{1,5}(,[A-Za-z0-9.\-\[\]:]+:\d{1,5})*$/'],
        ], [
            'domain.regex' => __('Enter a base domain such as example.com.'),
            'otherProviderCode.regex' => __('The provider code may contain lowercase letters and digits only.'),
            'resolvers.regex' => __('Enter DNS resolvers as host:port, separated by commas.'),
        ]);

        $settings = $this->server->settings;
        $providerCode = $this->provider === DnsChallengeProvider::Other->value ? $this->otherProviderCode : $this->provider;
        $sameProvider = $providerCode === $settings->dns_challenge_provider;
        $stored = $sameProvider ? (array) ($settings->dns_challenge_credentials ?? []) : [];

        $credentials = $this->provider === DnsChallengeProvider::Other->value
            ? $this->otherProviderCredentials($stored)
            : $this->knownProviderCredentials(DnsChallengeProvider::from($this->provider), $stored);

        $settings->forceFill([
            'is_dns_challenge_enabled' => $this->isEnabled,
            'dns_challenge_domain' => $this->domain,
            'dns_challenge_provider' => filled($providerCode) ? $providerCode : null,
            'dns_challenge_credentials' => $credentials === [] ? null : $credentials,
            'dns_challenge_email' => $this->email,
            'dns_challenge_resolvers' => $this->resolvers,
        ])->save();
        $this->server->refresh();

        $this->syncFromSettings();
    }

    /**
     * @param  array<string, string>  $stored
     * @return array<string, string>
     */
    private function knownProviderCredentials(DnsChallengeProvider $provider, array $stored): array
    {
        $credentials = [];
        $errors = [];
        foreach ($provider->credentialFields() as $field) {
            $key = $field['key'];
            $value = trim((string) ($this->credentials[$key] ?? ''));
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                $errors["credentials.{$key}"] = __('The value must be a single line.');

                continue;
            }
            if ($value === '' && $field['secret'] && filled($stored[$key] ?? null)) {
                $value = $stored[$key];
            }
            if ($value === '' && isset($field['default'])) {
                $value = $field['default'];
            }
            if ($value === '') {
                if ($this->isEnabled && $field['required']) {
                    $errors["credentials.{$key}"] = __(':field is required.', ['field' => $field['label']]);
                }

                continue;
            }
            $credentials[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $credentials;
    }

    /**
     * `KEY=value` lines for a provider without a form. A blank field keeps the stored values.
     *
     * @param  array<string, string>  $stored
     * @return array<string, string>
     */
    private function otherProviderCredentials(array $stored): array
    {
        if (blank($this->otherCredentials)) {
            if ($this->isEnabled && $stored === []) {
                throw ValidationException::withMessages(['otherCredentials' => __('Enter the environment variables of the DNS provider.')]);
            }

            return $stored;
        }

        $credentials = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($this->otherCredentials)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $key = trim((string) $key);
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || $value === null || trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw ValidationException::withMessages(['otherCredentials' => __('Enter one KEY=value per line. Keys use uppercase letters, digits, and underscores.')]);
            }
            $credentials[$key] = trim($value);
        }

        return $credentials;
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if (blank($domain)) {
            return null;
        }

        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = (string) preg_replace('#[/:].*$#', '', $domain);
        $domain = preg_replace('/^\*\./', '', $domain);

        return trim((string) $domain, '.') ?: null;
    }

    private function syncFromSettings(): void
    {
        $settings = $this->server->settings;
        $stored = (array) ($settings->dns_challenge_credentials ?? []);
        $provider = DnsChallengeProvider::fromCode($settings->dns_challenge_provider ?? 'cloudflare');

        $this->isEnabled = (bool) $settings->is_dns_challenge_enabled;
        $this->domain = $settings->dns_challenge_domain ?? $this->normalizeDomain(data_get($settings, 'wildcard_domain'));
        $this->provider = $provider->value;
        $this->otherProviderCode = $provider === DnsChallengeProvider::Other ? $settings->dns_challenge_provider : null;
        $this->email = $settings->dns_challenge_email ?? auth()->user()?->email;
        $this->resolvers = $settings->dns_challenge_resolvers;
        $this->storedCredentialKeys = array_keys($stored);
        $this->otherCredentials = null;

        // Only non-secret values go back to the browser.
        $this->credentials = [];
        foreach (DnsChallengeProvider::cases() as $case) {
            foreach ($case->credentialFields() as $field) {
                $this->credentials[$field['key']] = ($case === $provider && ! $field['secret']) ? ($stored[$field['key']] ?? null) : null;
            }
        }
    }

    public function render()
    {
        return view('livewire.server.proxy.wildcard-certificate', [
            'isTraefik' => $this->server->proxyType() === ProxyTypes::TRAEFIK->value,
            'selectedProvider' => DnsChallengeProvider::from($this->provider),
            'providerOptions' => DnsChallengeProvider::options(),
            'certificateDomain' => $this->domain ?: 'example.com',
        ]);
    }
}
