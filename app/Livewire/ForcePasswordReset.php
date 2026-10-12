<?php

namespace App\Livewire;

use App\Rules\NotRecentlyUsedPassword;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class ForcePasswordReset extends Component
{
    use WithRateLimiting;

    public string $email;

    public string $password;

    public string $password_confirmation;

    /**
     * True when the user is here because the password reached its maximum age.
     */
    public bool $passwordExpired = false;

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', Password::defaults(), 'confirmed', new NotRecentlyUsedPassword(auth()->user())],
        ];
    }

    public function mount()
    {
        if (! $this->mustChangePassword()) {
            return redirect()->route('dashboard');
        }
        $this->email = auth()->user()->email;
        $this->passwordExpired = auth()->user()->force_password_reset !== true;
    }

    public function render()
    {
        return view('livewire.force-password-reset')->layout('layouts.simple');
    }

    public function submit()
    {
        if (! $this->mustChangePassword()) {
            return redirect()->route('dashboard');
        }

        try {
            $this->rateLimit(10);
            $this->validate();
            $user = auth()->user();
            $user->forceFill([
                'password' => Hash::make($this->password),
                'force_password_reset' => false,
                'local_password_set_at' => now(),
            ])->save();
            auditLog('ui.user.password_changed', [
                'team_id' => $user->currentTeam()?->id,
                'resource' => 'user',
                'user_name' => $user->name,
                'reason' => $this->passwordExpired ? 'expired' : 'forced',
            ]);

            return redirect()->route('dashboard');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * An administrator demanded a new password, or the current one has expired.
     */
    private function mustChangePassword(): bool
    {
        $user = auth()->user();

        return $user->force_password_reset === true || $user->passwordHasExpired();
    }
}
