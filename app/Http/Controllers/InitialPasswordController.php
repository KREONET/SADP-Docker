<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Lets an SSO user set their first local password from the password confirmation page.
 *
 * SSO accounts get a random password nobody knows, so Fortify's password confirmation in front
 * of the two-factor setup could never be passed. The signed-in SSO session proves the identity
 * instead, and the new password counts as confirmed for this session.
 *
 * [한국어] SSO 사용자가 2FA 설정 전 비밀번호 확인 화면에서 처음으로 비밀번호를 설정합니다.
 */
class InitialPasswordController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->needsInitialPassword(), 403);

        $validated = $request->validateWithBag('initialPassword', [
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'local_password_set_at' => now(),
        ])->save();

        $request->session()->passwordConfirmed();
        auditLog('ui.user.initial_password_set', [
            'team_id' => $user->currentTeam()?->id,
            'resource' => 'user',
            'user_name' => $user->name,
        ], 'warning');

        return redirect()->intended(route('profile'))->with('status', 'initial-password-set');
    }
}
