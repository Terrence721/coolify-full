<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\S3Storage;
use App\Rules\SafeWebhookUrl;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $storages = S3Storage::ownedByCurrentTeam()->get();

        return Inertia::render('Storage/Index', [
            'storages' => $storages->map(fn (S3Storage $storage) => [
                'uuid' => $storage->uuid,
                'name' => $storage->name,
                'description' => $storage->description,
                'isUsable' => $storage->is_usable,
                'showUrl' => route('storage.show', ['storage_uuid' => $storage->uuid]),
            ]),
            'canCreate' => auth()->user()?->can('create', S3Storage::class) ?? false,
            'createUrl' => route('storage.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', S3Storage::class);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
                'region' => ['required', 'max:255'],
                'key' => ['required', 'max:255'],
                'secret' => ['required', 'max:255'],
                'bucket' => ['required', 'max:255'],
                'endpoint' => ['nullable', 'max:255', new SafeWebhookUrl],
            ],
            ValidationPatterns::combinedMessages(),
            [
                'region' => 'Region',
                'key' => 'Access Key',
                'secret' => 'Secret Key',
                'bucket' => 'Bucket',
                'endpoint' => 'Endpoint',
            ],
        )->validate();

        $storage = new S3Storage;
        $storage->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'region' => $validated['region'],
            'key' => $validated['key'],
            'secret' => $validated['secret'],
            'bucket' => $validated['bucket'],
            'endpoint' => empty($validated['endpoint']) ? "https://s3.{$validated['region']}.amazonaws.com" : $validated['endpoint'],
            'team_id' => currentTeam()->id,
        ]);

        try {
            $storage->testConnection();
            $storage->save();
        } catch (\Throwable $e) {
            return back()->withErrors(['endpoint' => 'Failed to create storage: '.$e->getMessage()])->withInput();
        }

        return redirect()->route('storage.show', ['storage_uuid' => $storage->uuid]);
    }
}
