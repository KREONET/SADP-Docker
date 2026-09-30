<?php

namespace App\Livewire\Platform\Concerns;

use Illuminate\Support\Facades\Gate;

trait AuthorizesPlatformAdmin
{
    /**
     * Every platform admin action reads or changes resources of all teams, so each
     * entry point re-checks the gate instead of trusting the rendered page.
     */
    protected function authorizePlatformAdmin(): void
    {
        abort_unless(auth()->check() && Gate::allows('accessPlatformAdmin'), 403);
    }
}
