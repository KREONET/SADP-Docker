<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Which domains a team may use on a server another team shared with it.
 *
 * Traefik routes a host to whichever container claims it, and the domain conflict checks only look inside
 * one team. On a shared server a team could otherwise claim the domain of another team's resource or of this
 * instance. A team that deploys to a server it does not own may therefore only add generated addresses:
 * "<random id>.<wildcard domain of the server>" (an optional "name-" prefix, as generated for services, is
 * allowed), and only when no other resource uses that host. Previews may use subdomains of their
 * application's own domains. Platform administrators and system jobs (no signed-in user) are not limited.
 * Domains that a resource already has are kept, so other settings can still be saved.
 */
class SharedServerDomainPolicy
{
    /**
     * A generated label: new_public_id() (24 lowercase letters and digits), optionally after a "name-" prefix.
     */
    private const GENERATED_LABEL = '/^(?:[a-z0-9]+(?:-[a-z0-9]+)*-)?[a-z0-9]{20,}$/';

    public static function assertApplicationAllowed(Application $application): void
    {
        $server = $application->destination?->server;
        $teamId = $application->environment?->project?->team_id;
        if (! self::applies($server, $teamId)) {
            return;
        }

        $previous = (new Application)->forceFill([
            'fqdn' => $application->getOriginal('fqdn'),
            'docker_compose_domains' => $application->getRawOriginal('docker_compose_domains'),
            'build_pack' => $application->getOriginal('build_pack'),
        ]);

        self::assertDomainsAllowed(
            $server,
            applicationDomainEntries($application)->pluck('domain'),
            $application->exists ? applicationDomainEntries($previous)->pluck('domain') : collect(),
            $application,
        );
    }

    public static function assertServiceApplicationAllowed(ServiceApplication $serviceApplication): void
    {
        $server = $serviceApplication->service?->server;
        $teamId = $serviceApplication->service?->environment?->project?->team_id;
        if (! self::applies($server, $teamId)) {
            return;
        }

        self::assertDomainsAllowed(
            $server,
            self::splitDomains($serviceApplication->fqdn),
            $serviceApplication->exists ? self::splitDomains($serviceApplication->getOriginal('fqdn')) : collect(),
            $serviceApplication,
        );
    }

    public static function assertPreviewAllowed(ApplicationPreview $preview): void
    {
        $application = $preview->application;
        $server = $application?->destination?->server;
        $teamId = $application?->environment?->project?->team_id;
        if (! self::applies($server, $teamId)) {
            return;
        }

        $parentHosts = applicationDomainEntries($application)
            ->map(fn (string $domain) => self::host($domain))
            ->filter()
            ->values();

        self::assertDomainsAllowed(
            $server,
            self::splitDomains($preview->fqdn),
            $preview->exists ? self::splitDomains($preview->getOriginal('fqdn')) : collect(),
            $preview,
            $parentHosts,
        );
    }

    /**
     * Whether the signed-in user deploys a resource of a team to a server that team does not own.
     */
    public static function applies(?Server $server, ?int $resourceTeamId, ?User $user = null): bool
    {
        // Check the server first: loading the user's teams caches them on the signed-in user.
        if ($server?->is_shared !== true || $resourceTeamId === null || (int) $server->team_id === (int) $resourceTeamId) {
            return false;
        }

        $user ??= Auth::user();

        return $user instanceof User && ! $user->isInstanceAdmin();
    }

    /**
     * The host that generated addresses on this server end with, for example "apps.example.com".
     */
    public static function baseHost(Server $server): string
    {
        return (string) str((string) parse_url(generateUrl(server: $server, random: 'x'), PHP_URL_HOST))->after('x.');
    }

    /**
     * @param  Collection<int, string>  $domains
     * @param  Collection<int, string>  $previousDomains
     * @param  Collection<int, string>  $parentHosts
     */
    private static function assertDomainsAllowed(Server $server, Collection $domains, Collection $previousDomains, Model $resource, ?Collection $parentHosts = null): void
    {
        $previousHosts = $previousDomains->map(fn (string $domain) => self::host($domain))->filter();
        $baseHost = self::baseHost($server);

        foreach ($domains as $domain) {
            $host = self::host($domain);
            if ($host === null || $previousHosts->contains($host)) {
                continue;
            }

            if (! self::isGeneratedHost($host, $baseHost, $parentHosts ?? collect())) {
                throw ValidationException::withMessages([
                    'fqdn' => __('On a shared server you can only use generated addresses under :base. Use Generate domain instead of :domain.', [
                        'base' => $baseHost,
                        'domain' => $host,
                    ]),
                ]);
            }

            if (self::isHostUsedElsewhere($host, $resource)) {
                throw ValidationException::withMessages([
                    'fqdn' => __('The address :domain is already used by another resource. Generate a new domain.', ['domain' => $host]),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, string>  $parentHosts
     */
    private static function isGeneratedHost(string $host, string $baseHost, Collection $parentHosts): bool
    {
        if ($parentHosts->contains(fn (string $parentHost) => str_ends_with($host, '.'.$parentHost))) {
            return true;
        }

        if ($baseHost === '' || ! str_ends_with($host, '.'.$baseHost)) {
            return false;
        }

        $label = substr($host, 0, -strlen('.'.$baseHost));

        return preg_match(self::GENERATED_LABEL, $label) === 1;
    }

    private static function isHostUsedElsewhere(string $host, Model $resource): bool
    {
        $isOtherResource = fn (Model $candidate): bool => ! ($candidate::class === $resource::class && $candidate->getKey() === $resource->getKey());

        $applicationHosts = Application::query()
            ->withoutGlobalScope('withRelations')
            ->get(['id', 'fqdn', 'docker_compose_domains', 'build_pack'])
            ->filter($isOtherResource)
            ->flatMap(fn (Application $application) => applicationDomainEntries($application)->pluck('domain'));

        $serviceHosts = ServiceApplication::query()
            ->whereNotNull('fqdn')
            ->get(['id', 'fqdn'])
            ->filter($isOtherResource)
            ->flatMap(fn (ServiceApplication $serviceApplication) => self::splitDomains($serviceApplication->fqdn));

        $previewHosts = ApplicationPreview::query()
            ->whereNotNull('fqdn')
            ->get(['id', 'fqdn'])
            ->filter($isOtherResource)
            ->flatMap(fn (ApplicationPreview $preview) => self::splitDomains($preview->fqdn));

        return $applicationHosts
            ->concat($serviceHosts)
            ->concat($previewHosts)
            ->push((string) data_get(instanceSettings(), 'fqdn'))
            ->map(fn (string $domain) => self::host($domain))
            ->contains($host);
    }

    /**
     * @return Collection<int, string>
     */
    private static function splitDomains(?string $domains): Collection
    {
        return collect(explode(',', (string) $domains))
            ->map(fn (string $domain) => trim($domain))
            ->filter(fn (string $domain) => $domain !== '')
            ->values();
    }

    private static function host(string $domain): ?string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $domain)) {
            $domain = 'http://'.$domain;
        }
        $host = parse_url($domain, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
