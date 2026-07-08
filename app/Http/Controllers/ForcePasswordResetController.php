<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForcePasswordResetController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if ($request->user()->force_password_reset === false) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('ForcePasswordReset', [
            'email' => $request->user()->email,
            'updateUrl' => route('auth.force-password-reset.update'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($request->user()->force_password_reset === false) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->fill([
            'password' => Hash::make($validated['password']),
            'force_password_reset' => false,
        ])->save();

        return redirect()->route('dashboard');
    }
}
