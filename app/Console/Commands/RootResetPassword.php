<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\NotRecentlyUsedPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

class RootResetPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'root:reset-password';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset Root Password';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('You are about to reset the root password.');
        $password = password('Give me a new password for root user: ');
        $passwordAgain = password('Again');
        if ($password !== $passwordAgain) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user = User::find(0);
        if (! $user) {
            $this->error('Root user not found.');

            return self::FAILURE;
        }

        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::defaults(), new NotRecentlyUsedPassword($user)],
        ]);
        if ($validator->fails()) {
            $this->error('The new password does not meet the password policy:');
            foreach ($validator->errors()->all() as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $this->info('Updating root password...');
        try {
            $user->update(['password' => Hash::make($password)]);
            $user->unlockAccount();
            auditLog('platform.user.root_password_reset', [
                'resource' => 'user',
                'user_name' => $user->name,
                'source' => 'console',
            ], 'warning');
            $this->info('Root password updated successfully.');
        } catch (\Exception $e) {
            $this->error('Failed to update root password.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
