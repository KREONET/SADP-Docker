<?php

namespace App\Services\Security;

class TrivyReport
{
    /**
     * @param  array<int, array{target: string, id: string, package: string, installed_version: string, fixed_version: string, severity: string, title: string, url: string}>  $findings
     */
    private function __construct(
        public readonly bool $completed,
        public readonly array $findings,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  array<int, array{target: string, id: string, package: string, installed_version: string, fixed_version: string, severity: string, title: string, url: string}>  $findings
     */
    public static function completed(array $findings): self
    {
        $order = array_flip(TrivyScanner::SEVERITIES);
        usort($findings, fn (array $a, array $b) => [$order[$a['severity']] ?? 99, $a['id']] <=> [$order[$b['severity']] ?? 99, $b['id']]);

        return new self(true, $findings);
    }

    public static function failed(string $error): self
    {
        return new self(false, [], $error === '' ? 'Trivy did not complete the scan.' : $error);
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }

    /**
     * @return array{critical: int, high: int, medium: int, low: int, unknown: int}
     */
    public function counts(): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0];
        foreach ($this->findings as $finding) {
            $counts[strtolower($finding['severity'])]++;
        }

        return $counts;
    }

    public function summary(): string
    {
        return collect($this->counts())
            ->filter()
            ->map(fn (int $count, string $severity) => strtoupper($severity).": {$count}")
            ->implode(', ');
    }
}
