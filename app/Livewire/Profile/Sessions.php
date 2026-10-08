<?php

namespace App\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Lists the signed-in user's browser sessions and logs out every other one.
 */
class Sessions extends Component
{
    private const MAX_PASSWORD_ATTEMPTS = 5;

    public string $password = '';

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function logoutOtherSessions(): void
    {
        $user = Auth::user();
        abort_unless($user !== null, 403);

        if (! $this->isSupported()) {
            $this->dispatch('error', __('Active sessions can only be managed with the database session driver.'));

            return;
        }

        if ($user->requiresPasswordConfirmation()) {
            $rateLimitKey = 'logout-other-sessions:'.$user->getKey();
            if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_PASSWORD_ATTEMPTS)) {
                throw ValidationException::withMessages([
                    'password' => __('Too many requests. Please try again in :seconds seconds.', [
                        'seconds' => RateLimiter::availableIn($rateLimitKey),
                    ]),
                ]);
            }

            try {
                $this->validate(['password' => ['required', 'string', 'current_password:web']]);
            } catch (ValidationException $exception) {
                RateLimiter::hit($rateLimitKey);
                $this->reset('password');
                auditLog('auth.session.others_logged_out', [
                    'user_id' => $user->getKey(),
                    'reason' => 'invalid_password',
                    'result' => 'failure',
                ], 'warning');

                throw $exception;
            }

            RateLimiter::clear($rateLimitKey);
        }

        $terminated = $this->sessionsQuery()
            ->where('id', '!=', session()->getId())
            ->delete();

        // Invalidates remember-me cookies issued before remember-me was disabled.
        Auth::guard('web')->getProvider()->updateRememberToken($user, Str::random(60));

        auditLog('auth.session.others_logged_out', [
            'user_id' => $user->getKey(),
            'terminated_sessions' => $terminated,
            'result' => 'success',
        ]);

        $this->reset('password');
        $this->dispatch('success', __('Logged out of all other sessions.'));
    }

    public function isSupported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return Collection<int, array{is_current: bool, ip_address: ?string, user_agent: string, last_active_at: Carbon}>
     */
    public function sessions(): Collection
    {
        if (! $this->isSupported()) {
            return collect();
        }

        $currentId = session()->getId();

        $sessions = $this->sessionsQuery()
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'is_current' => hash_equals($currentId, (string) $row->id),
                'ip_address' => $row->ip_address,
                'user_agent' => Str::limit((string) $row->user_agent, 160),
                'last_active_at' => Carbon::createFromTimestamp((int) $row->last_activity),
            ]);

        // The current session row is written at the end of the request, so it may not exist yet.
        if (! $sessions->contains('is_current', true)) {
            $sessions->prepend([
                'is_current' => true,
                'ip_address' => request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 160),
                'last_active_at' => now(),
            ]);
        }

        return $sessions->sortByDesc('is_current')->values();
    }

    public function render(): View
    {
        return view('livewire.profile.sessions', [
            'sessions' => $this->sessions(),
            'isSupported' => $this->isSupported(),
            'requiresPassword' => (bool) Auth::user()?->requiresPasswordConfirmation(),
        ]);
    }

    private function sessionsQuery(): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', Auth::id());
    }
}
