<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Server\CreateHetznerServer;
use App\Exceptions\RateLimitException;
use App\Http\Controllers\Controller;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\HetznerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class HetznerController extends Controller
{
    /**
     * Get cloud provider token UUID from request.
     * Prefers cloud_provider_token_uuid over deprecated cloud_provider_token_id.
     */
    private function getCloudProviderTokenUuid(Request $request): ?string
    {
        return $request->cloud_provider_token_uuid ?? $request->cloud_provider_token_id;
    }

    #[OA\Get(
        summary: 'Get Hetzner Locations',
        description: 'Get all available Hetzner datacenter locations.',
        path: '/hetzner/locations',
        operationId: 'get-hetzner-locations',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner locations.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'description', type: 'string'),
                                    new OA\Property(property: 'country', type: 'string'),
                                    new OA\Property(property: 'city', type: 'string'),
                                    new OA\Property(property: 'latitude', type: 'number'),
                                    new OA\Property(property: 'longitude', type: 'number'),
                                ]
                            )
                        )
                    ),
                ]),
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
    public function locations(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $locations = $hetznerService->getLocations();

            return response()->json($locations);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch Hetzner locations.'], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Hetzner Server Types',
        description: 'Get all available Hetzner server types (instance sizes).',
        path: '/hetzner/server-types',
        operationId: 'get-hetzner-server-types',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner server types.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'description', type: 'string'),
                                    new OA\Property(property: 'cores', type: 'integer'),
                                    new OA\Property(property: 'memory', type: 'number'),
                                    new OA\Property(property: 'disk', type: 'integer'),
                                    new OA\Property(property: 'prices', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'location', type: 'string', description: 'Datacenter location name'), new OA\Property(property: 'price_hourly', type: 'object', properties: [new OA\Property(property: 'net', type: 'string'), new OA\Property(property: 'gross', type: 'string')]), new OA\Property(property: 'price_monthly', type: 'object', properties: [new OA\Property(property: 'net', type: 'string'), new OA\Property(property: 'gross', type: 'string')])])),
                                ]
                            )
                        )
                    ),
                ]),
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
    public function serverTypes(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $serverTypes = $hetznerService->getServerTypes();

            return response()->json($serverTypes);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch Hetzner server types.'], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Hetzner Images',
        description: 'Get all available Hetzner system images (operating systems).',
        path: '/hetzner/images',
        operationId: 'get-hetzner-images',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner images.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'description', type: 'string'),
                                    new OA\Property(property: 'type', type: 'string'),
                                    new OA\Property(property: 'os_flavor', type: 'string'),
                                    new OA\Property(property: 'os_version', type: 'string'),
                                    new OA\Property(property: 'architecture', type: 'string'),
                                ]
                            )
                        )
                    ),
                ]),
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
    public function images(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $images = $hetznerService->getImages();

            // Filter out deprecated images (same as UI)
            $filtered = array_filter($images, function ($image) {
                if (isset($image['type']) && $image['type'] !== 'system') {
                    return false;
                }

                if (isset($image['deprecated']) && $image['deprecated'] === true) {
                    return false;
                }

                return true;
            });

            return response()->json(array_values($filtered));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch Hetzner images.'], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Hetzner SSH Keys',
        description: 'Get all SSH keys stored in the Hetzner account.',
        path: '/hetzner/ssh-keys',
        operationId: 'get-hetzner-ssh-keys',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner SSH keys.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'fingerprint', type: 'string'),
                                    new OA\Property(property: 'public_key', type: 'string'),
                                ]
                            )
                        )
                    ),
                ]),
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
    public function sshKeys(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $sshKeys = $hetznerService->getSshKeys();

            return response()->json($sshKeys);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch Hetzner SSH keys.'], 500);
        }
    }

    #[OA\Post(
        summary: 'Create Hetzner Server',
        description: 'Create a new server on Hetzner and register it in Coolify.',
        path: '/servers/hetzner',
        operationId: 'create-hetzner-server',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Hetzner server creation parameters',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['location', 'server_type', 'image', 'private_key_uuid'],
                    properties: [
                        new OA\Property(property: 'cloud_provider_token_uuid', type: 'string', example: 'abc123', description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.'),
                        new OA\Property(property: 'cloud_provider_token_id', type: 'string', example: 'abc123', description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.', deprecated: true),
                        new OA\Property(property: 'location', type: 'string', example: 'nbg1', description: 'Hetzner location name'),
                        new OA\Property(property: 'server_type', type: 'string', example: 'cx11', description: 'Hetzner server type name'),
                        new OA\Property(property: 'image', type: 'integer', example: 15512617, description: 'Hetzner image ID'),
                        new OA\Property(property: 'name', type: 'string', example: 'my-server', description: 'Server name (auto-generated if not provided)'),
                        new OA\Property(property: 'private_key_uuid', type: 'string', example: 'xyz789', description: 'Private key UUID'),
                        new OA\Property(property: 'enable_ipv4', type: 'boolean', example: true, description: 'Enable IPv4 (default: true)'),
                        new OA\Property(property: 'enable_ipv6', type: 'boolean', example: true, description: 'Enable IPv6 (default: true)'),
                        new OA\Property(property: 'hetzner_ssh_key_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Additional Hetzner SSH key IDs'),
                        new OA\Property(property: 'cloud_init_script', type: 'string', description: 'Cloud-init YAML script (optional)'),
                        new OA\Property(property: 'instant_validate', type: 'boolean', example: false, description: 'Validate server immediately after creation'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Hetzner server created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'uuid', type: 'string', example: 'og888os', description: 'The UUID of the server.'),
                                new OA\Property(property: 'hetzner_server_id', type: 'integer', description: 'The Hetzner server ID.'),
                                new OA\Property(property: 'ip', type: 'string', description: 'The server IP address.'),
                            ]
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
            new OA\Response(
                response: 429,
                ref: '#/components/responses/429',
            ),
        ]
    )]
    public function createServer(Request $request): JsonResponse
    {
        $allowedFields = [
            'cloud_provider_token_uuid',
            'cloud_provider_token_id',
            'location',
            'server_type',
            'image',
            'name',
            'private_key_uuid',
            'enable_ipv4',
            'enable_ipv6',
            'hetzner_ssh_key_ids',
            'cloud_init_script',
            'instant_validate',
        ];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
            'location' => 'required|string',
            'server_type' => 'required|string',
            'image' => 'required|integer',
            'name' => ['nullable', 'string', 'max:253', new ValidHostname],
            'private_key_uuid' => 'required|string',
            'enable_ipv4' => 'nullable|boolean',
            'enable_ipv6' => 'nullable|boolean',
            'hetzner_ssh_key_ids' => 'nullable|array',
            'hetzner_ssh_key_ids.*' => 'integer',
            'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
            'instant_validate' => 'nullable|boolean',
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

        // Set defaults
        if (! $request->name) {
            $request->offsetSet('name', generate_random_name());
        }
        if (is_null($request->enable_ipv4)) {
            $request->offsetSet('enable_ipv4', true);
        }
        if (is_null($request->enable_ipv6)) {
            $request->offsetSet('enable_ipv6', true);
        }
        if (is_null($request->hetzner_ssh_key_ids)) {
            $request->offsetSet('hetzner_ssh_key_ids', []);
        }
        if (is_null($request->instant_validate)) {
            $request->offsetSet('instant_validate', false);
        }

        // Validate cloud provider token
        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        // Validate private key
        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }

        try {
            $server = CreateHetznerServer::run(
                token: $token,
                privateKey: $privateKey,
                teamId: $teamId,
                location: $request->location,
                serverType: $request->server_type,
                image: $request->image,
                name: $request->name,
                enableIpv4: $request->enable_ipv4,
                enableIpv6: $request->enable_ipv6,
                hetznerSshKeyIds: $request->hetzner_ssh_key_ids,
                cloudInitScript: $request->cloud_init_script,
                instantValidate: $request->instant_validate,
            );

            auditLog('api.hetzner_server.created', [
                'team_id' => $teamId,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'hetzner_server_id' => $server->hetzner_server_id,
                'ip' => $server->ip,
            ]);

            return response()->json([
                'uuid' => $server->uuid,
                'hetzner_server_id' => $server->hetzner_server_id,
                'ip' => $server->ip,
            ])->setStatusCode(201);
        } catch (RateLimitException $e) {
            $response = response()->json(['message' => $e->getMessage()], 429);
            if ($e->retryAfter !== null) {
                $response->header('Retry-After', (string) $e->retryAfter);
            }

            return $response;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to create Hetzner server.'], 500);
        }
    }
}
