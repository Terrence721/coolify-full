<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Rules\SafeExternalUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class GithubController extends Controller
{
    /**
     * @return Collection<string, mixed>
     */
    private function removeSensitiveData(GithubApp $githubApp): Collection
    {
        // No makeHidden() call needed here - GithubApp::$hidden already always excludes
        // client_secret/webhook_secret from array/JSON conversion.
        return serializeApiResponse($githubApp);
    }

    /**
     * @return PrivateKey|JsonResponse a 404 response if the key doesn't exist or belongs to a different team
     */
    private function resolvePrivateKeyByUuid(string $uuid, int|string $teamId): PrivateKey|JsonResponse
    {
        $privateKey = PrivateKey::where('team_id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $privateKey) {
            return response()->json([
                'message' => 'Private key not found or does not belong to your team',
            ], 404);
        }

        return $privateKey;
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all GitHub apps.',
        path: '/github-apps',
        operationId: 'list-github-apps',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of GitHub apps.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'uuid', type: 'string'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'organization', type: 'string', nullable: true),
                                    new OA\Property(property: 'api_url', type: 'string'),
                                    new OA\Property(property: 'html_url', type: 'string'),
                                    new OA\Property(property: 'custom_user', type: 'string'),
                                    new OA\Property(property: 'custom_port', type: 'integer'),
                                    new OA\Property(property: 'app_id', type: 'integer'),
                                    new OA\Property(property: 'installation_id', type: 'integer'),
                                    new OA\Property(property: 'client_id', type: 'string'),
                                    new OA\Property(property: 'private_key_id', type: 'integer'),
                                    new OA\Property(property: 'is_system_wide', type: 'boolean'),
                                    new OA\Property(property: 'is_public', type: 'boolean'),
                                    new OA\Property(property: 'team_id', type: 'integer'),
                                    new OA\Property(property: 'type', type: 'string'),
                                ]
                            )
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function list_github_apps(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApps = GithubApp::where(function ($query) use ($teamId) {
                $query->where('team_id', $teamId)
                    ->orWhere('is_system_wide', true);
            })->get();

            $githubApps = $githubApps->map(function ($app) {
                return $this->removeSensitiveData($app);
            });

            return response()->json($githubApps);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in list_github_apps().', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to list GitHub apps: '.$e->getMessage()], 500);
        }
    }

    #[OA\Post(
        summary: 'Create GitHub App',
        description: 'Create a new GitHub app.',
        path: '/github-apps',
        operationId: 'create-github-app',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        requestBody: new OA\RequestBody(
            description: 'GitHub app creation payload.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'name', type: 'string', description: 'Name of the GitHub app.'),
                            new OA\Property(property: 'organization', type: 'string', nullable: true, description: 'Organization to associate the app with.'),
                            new OA\Property(property: 'api_url', type: 'string', description: 'API URL for the GitHub app (e.g., https://api.github.com).'),
                            new OA\Property(property: 'html_url', type: 'string', description: 'HTML URL for the GitHub app (e.g., https://github.com).'),
                            new OA\Property(property: 'custom_user', type: 'string', description: 'Custom user for SSH access (default: git).'),
                            new OA\Property(property: 'custom_port', type: 'integer', description: 'Custom port for SSH access (default: 22).'),
                            new OA\Property(property: 'app_id', type: 'integer', description: 'GitHub App ID from GitHub.'),
                            new OA\Property(property: 'installation_id', type: 'integer', description: 'GitHub Installation ID.'),
                            new OA\Property(property: 'client_id', type: 'string', description: 'GitHub OAuth App Client ID.'),
                            new OA\Property(property: 'client_secret', type: 'string', description: 'GitHub OAuth App Client Secret.'),
                            new OA\Property(property: 'webhook_secret', type: 'string', description: 'Webhook secret for GitHub webhooks.'),
                            new OA\Property(property: 'private_key_uuid', type: 'string', description: 'UUID of an existing private key for GitHub App authentication.'),
                            new OA\Property(property: 'is_system_wide', type: 'boolean', description: 'Is this app system-wide (cloud only).'),
                        ],
                        required: ['name', 'api_url', 'html_url', 'app_id', 'installation_id', 'client_id', 'client_secret', 'webhook_secret', 'private_key_uuid'],
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'GitHub app created successfully.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'uuid', type: 'string'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'organization', type: 'string', nullable: true),
                                new OA\Property(property: 'api_url', type: 'string'),
                                new OA\Property(property: 'html_url', type: 'string'),
                                new OA\Property(property: 'custom_user', type: 'string'),
                                new OA\Property(property: 'custom_port', type: 'integer'),
                                new OA\Property(property: 'app_id', type: 'integer'),
                                new OA\Property(property: 'installation_id', type: 'integer'),
                                new OA\Property(property: 'client_id', type: 'string'),
                                new OA\Property(property: 'private_key_id', type: 'integer'),
                                new OA\Property(property: 'is_system_wide', type: 'boolean'),
                                new OA\Property(property: 'team_id', type: 'integer'),
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_github_app(Request $request): mixed
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = [
            'name',
            'organization',
            'api_url',
            'html_url',
            'custom_user',
            'custom_port',
            'app_id',
            'installation_id',
            'client_id',
            'client_secret',
            'webhook_secret',
            'private_key_uuid',
            'is_system_wide',
        ];

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'organization' => 'nullable|string|max:255',
            'api_url' => ['required', 'string', 'url', new SafeExternalUrl],
            'html_url' => ['required', 'string', 'url', new SafeExternalUrl],
            'custom_user' => 'nullable|string|max:255',
            'custom_port' => 'nullable|integer|min:1|max:65535',
            'app_id' => 'required|integer',
            'installation_id' => 'required|integer',
            'client_id' => 'required|string|max:255',
            'client_secret' => 'required|string',
            'webhook_secret' => 'required|string',
            'private_key_uuid' => 'required|string',
            'is_system_wide' => 'boolean',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $privateKey = $this->resolvePrivateKeyByUuid($request->input('private_key_uuid'), $teamId);
            if ($privateKey instanceof JsonResponse) {
                return $privateKey;
            }

            $payload = [
                'uuid' => Str::uuid(),
                'name' => $request->input('name'),
                'organization' => $request->input('organization'),
                'api_url' => $request->input('api_url'),
                'html_url' => $request->input('html_url'),
                'custom_user' => $request->input('custom_user', 'git'),
                'custom_port' => $request->input('custom_port', 22),
                'app_id' => $request->input('app_id'),
                'installation_id' => $request->input('installation_id'),
                'client_id' => $request->input('client_id'),
                'client_secret' => $request->input('client_secret'),
                'webhook_secret' => $request->input('webhook_secret'),
                'private_key_id' => $privateKey->id,
                'is_public' => false,
                'team_id' => $teamId,
            ];

            if (! isCloud()) {
                $payload['is_system_wide'] = $request->input('is_system_wide', false);
            }

            $githubApp = GithubApp::create($payload);

            auditLog('api.github_app.created', [
                'team_id' => $teamId,
                'github_app_uuid' => $githubApp->uuid,
                'github_app_name' => $githubApp->name,
            ]);

            return response()->json($githubApp, 201);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in create_github_app().', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to create GitHub app: '.$e->getMessage()], 500);
        }
    }

    #[OA\Get(
        path: '/github-apps/{github_app_id}/repositories',
        summary: 'Load Repositories for a GitHub App',
        description: 'Fetch repositories from GitHub for a given GitHub app.',
        operationId: 'load-repositories',
        tags: ['GitHub Apps'],
        security: [
            ['bearerAuth' => []],
        ],
        parameters: [
            new OA\Parameter(
                name: 'github_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitHub App ID'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Repositories loaded successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(
                                property: 'repositories',
                                type: 'array',
                                items: new OA\Items(type: 'object')
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function load_repositories(string $github_app_id): mixed
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            $token = generateGithubInstallationToken($githubApp);
            $repositories = collect();
            $page = 1;
            $maxPages = 100; // Safety limit: max 10,000 repositories

            while ($page <= $maxPages) {
                $response = Http::GitHub($githubApp->api_url, $token)
                    ->timeout(20)
                    ->retry(3, 200, throw: false)
                    ->get('/installation/repositories', [
                        'per_page' => 100,
                        'page' => $page,
                    ]);

                if ($response->status() !== 200) {
                    return response()->json([
                        'message' => $response->json()['message'] ?? 'Failed to load repositories',
                    ], $response->status());
                }

                $json = $response->json();
                $repos = $json['repositories'] ?? [];

                if (empty($repos)) {
                    break; // No more repositories to load
                }

                $repositories = $repositories->concat($repos);
                $page++;
            }

            return response()->json([
                'repositories' => $repositories->sortBy('name')->values(),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'GitHub app not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in load_repositories().', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to load repositories: '.$e->getMessage()], 500);
        }
    }

    #[OA\Get(
        path: '/github-apps/{github_app_id}/repositories/{owner}/{repo}/branches',
        summary: 'Load Branches for a GitHub Repository',
        description: 'Fetch branches from GitHub for a given repository.',
        operationId: 'load-branches',
        tags: ['GitHub Apps'],
        security: [
            ['bearerAuth' => []],
        ],
        parameters: [
            new OA\Parameter(
                name: 'github_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitHub App ID'
            ),
            new OA\Parameter(
                name: 'owner',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Repository owner'
            ),
            new OA\Parameter(
                name: 'repo',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Repository name'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Branches loaded successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(
                                property: 'branches',
                                type: 'array',
                                items: new OA\Items(type: 'object')
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function load_branches(string $github_app_id, string $owner, string $repo): mixed
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            $token = generateGithubInstallationToken($githubApp);

            $response = Http::GitHub($githubApp->api_url, $token)
                ->timeout(20)
                ->retry(3, 200, throw: false)
                ->get("/repos/{$owner}/{$repo}/branches");

            if ($response->status() !== 200) {
                return response()->json([
                    'message' => 'Error loading branches from GitHub.',
                    'error' => $response->json('message'),
                ], $response->status());
            }

            $branches = $response->json();

            return response()->json([
                'branches' => $branches,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'GitHub app not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in load_branches().', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to load branches: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update a GitHub app.
     */
    #[OA\Patch(
        path: '/github-apps/{github_app_id}',
        operationId: 'updateGithubApp',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        summary: 'Update GitHub App',
        description: 'Update an existing GitHub app.',
        parameters: [
            new OA\Parameter(
                name: 'github_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitHub App ID'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', description: 'GitHub App name'),
                        new OA\Property(property: 'organization', type: 'string', nullable: true, description: 'GitHub organization'),
                        new OA\Property(property: 'api_url', type: 'string', description: 'GitHub API URL'),
                        new OA\Property(property: 'html_url', type: 'string', description: 'GitHub HTML URL'),
                        new OA\Property(property: 'custom_user', type: 'string', description: 'Custom user for SSH'),
                        new OA\Property(property: 'custom_port', type: 'integer', description: 'Custom port for SSH'),
                        new OA\Property(property: 'app_id', type: 'integer', description: 'GitHub App ID'),
                        new OA\Property(property: 'installation_id', type: 'integer', description: 'GitHub Installation ID'),
                        new OA\Property(property: 'client_id', type: 'string', description: 'GitHub Client ID'),
                        new OA\Property(property: 'client_secret', type: 'string', description: 'GitHub Client Secret'),
                        new OA\Property(property: 'webhook_secret', type: 'string', description: 'GitHub Webhook Secret'),
                        new OA\Property(property: 'private_key_uuid', type: 'string', description: 'Private key UUID'),
                        new OA\Property(property: 'is_system_wide', type: 'boolean', description: 'Is system wide (non-cloud instances only)'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'GitHub app updated successfully',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'GitHub app updated successfully'),
                            new OA\Property(property: 'data', type: 'object', description: 'Updated GitHub app data'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'GitHub app not found'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_github_app(Request $request, string $github_app_id): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            // Define allowed fields for update
            $allowedFields = [
                'name',
                'organization',
                'api_url',
                'html_url',
                'custom_user',
                'custom_port',
                'app_id',
                'installation_id',
                'client_id',
                'client_secret',
                'webhook_secret',
                'private_key_uuid',
            ];

            if (! isCloud()) {
                $allowedFields[] = 'is_system_wide';
            }

            $payload = $request->only($allowedFields);

            // Validate the request. array_key_exists(), not isset() - isset() is false for an
            // explicit JSON null, which would silently skip validation for a present key
            // instead of rejecting it, letting the null flow straight through to update()
            // and crash on any NOT NULL column instead of returning a clean 422.
            $rules = [];
            if (array_key_exists('name', $payload)) {
                $rules['name'] = 'string';
            }
            if (array_key_exists('organization', $payload)) {
                $rules['organization'] = 'nullable|string';
            }
            if (array_key_exists('api_url', $payload)) {
                $rules['api_url'] = ['url', new SafeExternalUrl];
            }
            if (array_key_exists('html_url', $payload)) {
                $rules['html_url'] = ['url', new SafeExternalUrl];
            }
            if (array_key_exists('custom_user', $payload)) {
                $rules['custom_user'] = 'string';
            }
            if (array_key_exists('custom_port', $payload)) {
                $rules['custom_port'] = 'integer|min:1|max:65535';
            }
            if (array_key_exists('app_id', $payload)) {
                $rules['app_id'] = 'nullable|integer';
            }
            if (array_key_exists('installation_id', $payload)) {
                $rules['installation_id'] = 'nullable|integer';
            }
            if (array_key_exists('client_id', $payload)) {
                $rules['client_id'] = 'nullable|string';
            }
            if (array_key_exists('client_secret', $payload)) {
                $rules['client_secret'] = 'nullable|string';
            }
            if (array_key_exists('webhook_secret', $payload)) {
                $rules['webhook_secret'] = 'nullable|string';
            }
            if (array_key_exists('private_key_uuid', $payload)) {
                $rules['private_key_uuid'] = 'string';
            }
            if (! isCloud() && array_key_exists('is_system_wide', $payload)) {
                $rules['is_system_wide'] = 'boolean';
            }

            $validator = customApiValidator($payload, $rules);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Handle private_key_uuid -> private_key_id conversion
            if (isset($payload['private_key_uuid'])) {
                $privateKey = $this->resolvePrivateKeyByUuid($payload['private_key_uuid'], $teamId);
                if ($privateKey instanceof JsonResponse) {
                    return $privateKey;
                }

                unset($payload['private_key_uuid']);
                $payload['private_key_id'] = $privateKey->id;
            }

            // Update the GitHub app
            $githubApp->update($payload);

            auditLog('api.github_app.updated', [
                'team_id' => $teamId,
                'github_app_uuid' => $githubApp->uuid,
                'github_app_name' => $githubApp->name,
                'changed_fields' => array_values(array_diff(array_keys($payload), ['client_secret', 'webhook_secret'])),
            ]);

            return response()->json([
                'message' => 'GitHub app updated successfully',
                'data' => $githubApp,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'GitHub app not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in update_github_app().', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to update GitHub app: '.$e->getMessage()], 500);
        }
    }

    /**
     * Delete a GitHub app.
     */
    #[OA\Delete(
        path: '/github-apps/{github_app_id}',
        operationId: 'deleteGithubApp',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        summary: 'Delete GitHub App',
        description: 'Delete a GitHub app if it\'s not being used by any applications.',
        parameters: [
            new OA\Parameter(
                name: 'github_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitHub App ID'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'GitHub app deleted successfully',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'GitHub app deleted successfully'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'GitHub app not found'),
            new OA\Response(
                response: 409,
                description: 'Conflict - GitHub app is in use',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'This GitHub app is being used by 5 application(s). Please delete all applications first.'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function delete_github_app(string $github_app_id): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            // Check if the GitHub app is being used by any applications
            if ($githubApp->applications->isNotEmpty()) {
                $count = $githubApp->applications->count();

                return response()->json([
                    'message' => "This GitHub app is being used by {$count} application(s). Please delete all applications first.",
                ], 409);
            }

            $deletedUuid = $githubApp->uuid;
            $deletedName = $githubApp->name;
            $githubApp->delete();

            auditLog('api.github_app.deleted', [
                'team_id' => $teamId,
                'github_app_uuid' => $deletedUuid,
                'github_app_name' => $deletedName,
            ]);

            return response()->json([
                'message' => 'GitHub app deleted successfully',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'GitHub app not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in delete_github_app().', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to delete GitHub app: '.$e->getMessage()], 500);
        }
    }
}
