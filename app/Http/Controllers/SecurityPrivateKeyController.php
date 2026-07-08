<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SecurityPrivateKeyController extends Controller
{
    use AuthorizesRequests;

    public function show(string $private_key_uuid): Response
    {
        $privateKey = PrivateKey::ownedByCurrentTeam()->whereUuid($private_key_uuid)->firstOrFail();
        $this->authorize('view', $privateKey);

        return Inertia::render('Security/PrivateKey/Show', [
            'privateKey' => [
                'id' => $privateKey->id,
                'uuid' => $privateKey->uuid,
                'name' => $privateKey->name,
                'description' => $privateKey->description,
                'privateKeyValue' => $privateKey->private_key,
                'publicKey' => $privateKey->getPublicKey(),
                'isGitRelated' => $privateKey->is_git_related,
            ],
            'canUpdate' => request()->user()->can('update', $privateKey),
            'canDelete' => request()->user()->can('delete', $privateKey),
            'updateUrl' => route('security.private-key.update', ['private_key_uuid' => $privateKey->uuid]),
            'deleteUrl' => route('security.private-key.destroy', ['private_key_uuid' => $privateKey->uuid]),
        ]);
    }

    public function update(Request $request, string $private_key_uuid): RedirectResponse
    {
        $privateKey = PrivateKey::ownedByCurrentTeam()->whereUuid($private_key_uuid)->firstOrFail();
        $this->authorize('update', $privateKey);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
                'privateKeyValue' => ['required', 'string'],
                'isGitRelated' => ['nullable', 'boolean'],
            ],
            ValidationPatterns::combinedMessages(),
            [
                'name' => 'name',
                'description' => 'description',
                'privateKeyValue' => 'private key',
            ]
        )->validate();

        $privateKey->updatePrivateKey([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'private_key' => formatPrivateKey($validated['privateKeyValue']),
            'is_git_related' => $validated['isGitRelated'] ?? false,
        ]);
        refresh_server_connection($privateKey);

        return back()->with('success', 'Private key updated.');
    }

    public function destroy(string $private_key_uuid): RedirectResponse
    {
        $privateKey = PrivateKey::ownedByCurrentTeam()->whereUuid($private_key_uuid)->firstOrFail();
        $this->authorize('delete', $privateKey);

        if (! $privateKey->safeDelete()) {
            return back()->with('error', 'Private key is in use and cannot be deleted.');
        }

        return redirect()->route('security.private-key.index');
    }
}
