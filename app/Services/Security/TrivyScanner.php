<?php

namespace App\Services\Security;

use App\Models\InstanceSettings;
use InvalidArgumentException;

/**
 * Builds the Trivy filesystem scan that runs against a cloned repository and parses its output.
 *
 * The scan runs inside the deployment helper container, which already has the Docker socket:
 * the checkout is streamed as a tar archive into a throwaway Trivy container, so nothing is
 * written to the host filesystem and non-root SSH users only need sudo for "docker exec".
 */
class TrivyScanner
{
    public const COMPLETION_MARKER = '__COOLIFY_TRIVY_SCAN_COMPLETED__';

    public const SEVERITIES = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'];

    public const CACHE_VOLUME = 'coolify-trivy-cache';

    public const IMAGE_REFERENCE_PATTERN = '/\A[a-z0-9][a-z0-9._\/:@-]{0,254}\z/';

    /**
     * One JSON object per vulnerability keeps the output compact (Trivy's full JSON report
     * embeds long descriptions) and lets the parser ignore any non-JSON log lines.
     */
    private const REPORT_TEMPLATE = <<<'TPL'
{{- range .Results -}}{{- $target := .Target -}}{{- range .Vulnerabilities }}
{{ dict "target" $target "id" .VulnerabilityID "pkg" .PkgName "installed" .InstalledVersion "fixed" .FixedVersion "severity" .Severity "title" .Title "url" .PrimaryURL | toJson }}
{{- end -}}{{- end }}
TPL;

    public function __construct(
        private readonly string $image,
        private readonly array $severities,
        private readonly bool $ignoreUnfixed,
    ) {
        if (! preg_match(self::IMAGE_REFERENCE_PATTERN, $image)) {
            throw new InvalidArgumentException("Invalid Trivy image reference: {$image}");
        }
    }

    public static function fromSettings(InstanceSettings $settings): self
    {
        return new self(
            image: filled($settings->trivy_image) ? trim($settings->trivy_image) : 'aquasec/trivy:latest',
            severities: self::parseSeverities($settings->trivy_severities),
            ignoreUnfixed: (bool) $settings->trivy_ignore_unfixed,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function parseSeverities(?string $raw): array
    {
        $severities = str((string) $raw)
            ->upper()
            ->replace([' ', ';'], ',')
            ->explode(',')
            ->map(fn (string $severity) => trim($severity))
            ->filter(fn (string $severity) => in_array($severity, self::SEVERITIES, true))
            ->unique()
            ->values()
            ->all();

        return $severities === [] ? ['HIGH', 'CRITICAL'] : $severities;
    }

    /**
     * @return array<int, string>
     */
    public function severities(): array
    {
        return $this->severities;
    }

    /**
     * The command to run inside the helper container (wrap it with executeInDocker()).
     */
    public function scanCommand(string $sourceDirectory): string
    {
        if (! str_starts_with($sourceDirectory, '/') || preg_match('/[^A-Za-z0-9_\/.-]/', $sourceDirectory) || str_contains($sourceDirectory, '..')) {
            throw new InvalidArgumentException('Invalid source directory for the vulnerability scan.');
        }

        $trivyArguments = collect([
            'trivy', 'fs',
            '--quiet',
            '--scanners', 'vuln',
            '--severity', implode(',', $this->severities),
            '--format', 'template',
            '--template', '@/tmp/coolify-report.tpl',
        ])
            ->when($this->ignoreUnfixed, fn ($arguments) => $arguments->push('--ignore-unfixed'))
            ->push('/src')
            ->implode(' ');

        $script = 'set -e; mkdir -p /src; tar -C /src -xf -; '
            .'printf %s "$COOLIFY_TRIVY_TEMPLATE" | base64 -d > /tmp/coolify-report.tpl; '
            .$trivyArguments.'; '
            .'echo '.self::COMPLETION_MARKER;

        $template = base64_encode(self::REPORT_TEMPLATE);

        return 'tar -C '.escapeshellarg($sourceDirectory).' --exclude=.git -cf - . | docker run --rm -i'
            .' -e COOLIFY_TRIVY_TEMPLATE='.$template
            .' -v '.self::CACHE_VOLUME.':/root/.cache/trivy'
            .' --entrypoint sh '.escapeshellarg($this->image)
            .' -c '.escapeshellarg($script);
    }

    public function parseOutput(?string $output): TrivyReport
    {
        $output = (string) $output;

        if (! str_contains($output, self::COMPLETION_MARKER)) {
            return TrivyReport::failed($this->lastLines($output));
        }

        $findings = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded) || ! isset($decoded['id'])) {
                continue;
            }

            $severity = strtoupper((string) ($decoded['severity'] ?? 'UNKNOWN'));
            $findings[] = [
                'target' => (string) ($decoded['target'] ?? ''),
                'id' => (string) $decoded['id'],
                'package' => (string) ($decoded['pkg'] ?? ''),
                'installed_version' => (string) ($decoded['installed'] ?? ''),
                'fixed_version' => (string) ($decoded['fixed'] ?? ''),
                'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'UNKNOWN',
                'title' => (string) ($decoded['title'] ?? ''),
                'url' => (string) ($decoded['url'] ?? ''),
            ];
        }

        return TrivyReport::completed($findings);
    }

    private function lastLines(string $output, int $lines = 20): string
    {
        return collect(preg_split('/\R/', trim($output)))->take(-$lines)->implode("\n");
    }
}
