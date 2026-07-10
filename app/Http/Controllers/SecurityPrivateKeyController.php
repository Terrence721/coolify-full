<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SecurityPrivateKeyController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $privateKeys = PrivateKey::ownedByCurrentTeam(['name', 'uuid', 'is_git_related', 'description', 'team_id'])->get();

        return Inertia::render('Security/PrivateKey/Index', [
            'privateKeys' => $privateKeys->map(fn (PrivateKey $key) => [
                'uuid' => $key->uuid,
                'name' => $key->name,
                'description' => $key->description,
                'isInUse' => $key->isInUse(),
                'canView' => request()->user()->can('view', $key),
                'showUrl' => route('security.private-key.show', ['private_key_uuid' => $key->uuid]),
            ]),
            'canCreate' => request()->user()->can('create', PrivateKey::class),
            'createKeyUrl' => route('security.private-key.store'),
            'generateKeyUrl' => route('security.private-key.generate'),
            'cleanupUnusedKeysUrl' => route('security.private-key.cleanup'),
        ]);
    }

    public function cleanupUnusedKeys(): RedirectResponse
    {
        $this->authorize('create', PrivateKey::class);
        PrivateKey::cleanupUnusedKeys();

        return back()->with('success', 'Unused keys have been cleaned up.');
    }

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

    /**
     * Backs the "+ Add" modal ported inline wherever App\Livewire\Security\PrivateKey\Create's
     * modal_mode=true usage is being replaced (Server\PrivateKey\Show is the first) — the shared
     * Livewire component itself is untouched, still used by security.private-key.index,
     * server.new.by-hetzner, GlobalSearch, and Dashboard.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', PrivateKey::class);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
                'value' => ['required', 'string'],
            ],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $validation = PrivateKey::validateAndExtractPublicKey($validated['value']);
        if (! $validation['isValid']) {
            return back()->withErrors(['value' => 'Invalid private key']);
        }

        $privateKey = PrivateKey::createAndStore([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'private_key' => trim($validated['value'])."\n",
            'team_id' => currentTeam()->id,
        ]);

        if ($request->boolean('modal_mode')) {
            return back()->with(['success' => 'Private key created successfully.', 'createdPrivateKeyId' => $privateKey->id]);
        }

        return redirect()->route('security.private-key.show', ['private_key_uuid' => $privateKey->uuid]);
    }

    /**
     * JSON endpoint for the "+ Add" modal's Generate RSA/ED25519 key buttons.
     */
    public function generateKey(Request $request): JsonResponse
    {
        $this->authorize('create', PrivateKey::class);

        $validated = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:rsa,ed25519'],
        ])->validate();

        $keyData = PrivateKey::generateNewKeyPair($validated['type']);

        return response()->json([
            'name' => $keyData['name'],
            'description' => $keyData['description'],
            'value' => $keyData['private_key'],
            'publicKey' => $keyData['public_key'],
        ]);
    }
}
