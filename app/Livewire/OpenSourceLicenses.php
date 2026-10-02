<?php

namespace App\Livewire;

use App\Services\OpenSourceLicenses as OpenSourceLicenseInventory;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Open source notices: Coolify's own license and the third-party software it ships with.
 * Signed-in users only, because exact package versions help to fingerprint an instance.
 */
class OpenSourceLicenses extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $component = 'all';

    #[Url(except: 'all')]
    public string $license = 'all';

    public ?string $expandedKey = null;

    public bool $showCoolifyLicense = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedComponent(): void
    {
        $this->resetPage();
    }

    public function updatedLicense(): void
    {
        $this->resetPage();
    }

    public function toggle(string $key): void
    {
        $this->expandedKey = $this->expandedKey === $key ? null : $key;
    }

    public function render(): View
    {
        $all = collect(OpenSourceLicenseInventory::packages());
        $search = mb_strtolower(trim($this->search));

        $filtered = $all
            ->when($this->component !== 'all', fn ($packages) => $packages->where('component', $this->component))
            ->when($this->license !== 'all', fn ($packages) => $packages->filter(fn (array $package): bool => in_array($this->license, $package['licenses'], true)))
            ->when($search !== '', fn ($packages) => $packages->filter(fn (array $package): bool => str_contains(mb_strtolower($package['name']), $search)))
            ->values();

        $page = $this->getPage();
        $packages = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
        );

        return view('livewire.open-source-licenses', [
            'packages' => $packages,
            'total' => $all->count(),
            'licenseCounts' => $all->flatMap(fn (array $package): array => $package['licenses'] ?: ['Unknown'])->countBy()->sortDesc(),
            'componentLabels' => OpenSourceLicenseInventory::COMPONENTS,
            'coolifyLicense' => $this->showCoolifyLicense ? (string) @file_get_contents(base_path('LICENSE')) : null,
        ]);
    }
}
