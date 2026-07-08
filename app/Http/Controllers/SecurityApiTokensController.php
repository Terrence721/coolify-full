<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SecurityApiTokensController extends Controller
{
    use AuthorizesRequests;

    private const array EXPIRATION_OPTIONS = [
        7 => '7 days',
        30 => '30 days',
        60 => '60 days',
        90 => '90 days',
        365 => '1 year',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Security/ApiTokens', [
            'isApiEnabled' => InstanceSettings::get()->is_api_enabled,
            'canCreate' => Gate::forUser($user)->allows('create', PersonalAccessToken::class),
            'canUseRootPermissions' => Gate::forUser($user)->allows('useRootPermissions', PersonalAccessToken::class),
            'canUseWritePermissions' => Gate::forUser($user)->allows('useWritePermissions', PersonalAccessToken::class),
            'canViewCloudTokens' => Gate::forUser($user)->allows('viewAny', CloudProviderToken::class),
            'canViewCloudInitScripts' => Gate::forUser($user)->allows('viewAny', CloudInitScript::class),
            'expirationOptions' => self::EXPIRATION_OPTIONS,
            'tokens' => $user->tokens->sortByDesc('created_at')->values()->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'lastUsedAt' => $token->last_used_at?->diffForHumans(),
                'createdAt' => $token->created_at->diffForHumans(),
                'expiresAt' => $token->expires_at?->format('Y-m-d H:i:s'),
                'isExpired' => $token->expires_at?->isPast() ?? false,
                'ownedByCurrentUser' => $user->id === $token->tokenable_id,
                'revokeUrl' => route('security.api-tokens.destroy', ['id' => $token->id]),
            ]),
            'storeUrl' => route('security.api-tokens.store'),
            'newlyCreatedToken' => session('token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PersonalAccessToken::class);
        $user = $request->user();

        $validated = Validator::make($request->all(), [
            'description' => ['required', 'min:3', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'in:7,30,60,90,365'],
            'permissions' => ['required', 'array', 'min:1'],
        ])->validate();

        $permissions = $validated['permissions'];

        if (in_array('root', $permissions, true) && ! Gate::forUser($user)->allows('useRootPermissions', PersonalAccessToken::class)) {
            return back()->with('error', 'You do not have permission to create tokens with root permissions.');
        }

        if (array_intersect(['write', 'write:sensitive'], $permissions) && ! Gate::forUser($user)->allows('useWritePermissions', PersonalAccessToken::class)) {
            return back()->with('error', 'You do not have permission to create tokens with write permissions.');
        }

        $expiresAt = ! empty($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null;
        $token = $user->createToken($validated['description'], array_values($permissions), $expiresAt);

        return back()->with('token', $token->plainTextToken);
    }

    public function destroy(int $id): RedirectResponse
    {
        $token = Auth::user()->tokens()->where('id', $id)->firstOrFail();
        $this->authorize('delete', $token);
        $token->delete();

        return back()->with('success', 'API token revoked.');
    }
}
