<?php

namespace App\Rules;

use App\Models\PasswordHistory;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Rejects a new password that equals the user's current password or one of the
 * last `security.password.history` passwords.
 */
class NotRecentlyUsedPassword implements ValidationRule
{
    public function __construct(private ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null || ! is_string($value) || $value === '') {
            return;
        }

        $hashes = PasswordHistory::recentHashesFor($this->user->id)
            ->prepend($this->user->getAuthPassword())
            ->filter(fn (mixed $hash): bool => is_string($hash) && $hash !== '');

        foreach ($hashes as $hash) {
            if ($this->matches($value, $hash)) {
                $count = PasswordHistory::retainedCount();
                $fail($count > 0
                    ? __('The new password must differ from your current password and your last :count passwords.', ['count' => $count])
                    : __('The new password must differ from your current password.'));

                return;
            }
        }
    }

    /**
     * Hashes created by another algorithm make some hashers throw; treat them as non-matching.
     */
    private function matches(string $plain, string $hash): bool
    {
        try {
            return Hash::check($plain, $hash);
        } catch (Throwable) {
            return password_verify($plain, $hash);
        }
    }
}
