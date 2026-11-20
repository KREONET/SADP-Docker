<?php

use App\Livewire\SettingsDropdown;
use App\Services\ChangelogService;

it('opens and closes the changelog modal state', function () {
    // The changelog is opt-in; enable it for this state test.
    app()->instance(ChangelogService::class, new class extends ChangelogService
    {
        public function isEnabled(): bool
        {
            return true;
        }
    });

    $component = new SettingsDropdown;
    $component->trigger = 'changelog-sidebar';

    expect($component->trigger)->toBe('changelog-sidebar')
        ->and($component->showWhatsNewModal)->toBeFalse();

    $component->openWhatsNewModal();

    expect($component->showWhatsNewModal)->toBeTrue();

    $component->closeWhatsNewModal();

    expect($component->showWhatsNewModal)->toBeFalse();
});
