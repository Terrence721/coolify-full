<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ResourcesController extends Controller
{
    #[OA\Get(
        summary: 'List',
        description: 'Get all resources.',
        path: '/resources',
        operationId: 'list-resources',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Resources'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all resources',
                content: new OA\JsonContent(
                    type: 'string',
                    example: 'Content is very complex. Will be implemented later.',
                ),
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
    public function resources(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // General authorization check for viewing resources - using Project as base resource type
        $this->authorize('viewAny', Project::class);

        $projects = Project::where('team_id', $teamId)->get();
        $resources = collect();
        $resources->push($projects->pluck('applications')->flatten());
        $resources->push($projects->pluck('services')->flatten());
        foreach (collect(DATABASE_TYPES) as $db) {
            $resources->push($projects->pluck(str($db)->plural(2)->value())->flatten());
        }
        $resources = $resources->flatten();
        $resources = $resources->map(function ($resource) {
            $payload = $this->removeSensitiveData($resource);
            $payload['status'] = $resource->status;
            $payload['type'] = $resource->type();

            return $payload;
        });

        return response()->json(serializeApiResponse($resources));
    }

    /**
     * Same redaction the dedicated Applications/Databases/Services controllers already apply to
     * their own single-type responses - this endpoint returns all 3 kinds mixed together, so it
     * dispatches on $resource->type() and pulls the exact same per-type field lists from each
     * controller's own sensitiveFieldLists() rather than keeping a fourth, unlinked copy of them
     * (a real drift risk: a future field added to one controller's list could easily be forgotten
     * here, since nothing previously tied the two together).
     * Only applies makeHidden(), not the trait's full redactApiFields()/serializeApiResponse()
     * pass - status/type still need to be merged in afterward, and the caller runs the one real
     * serializeApiResponse() pass over the whole collection at the end, matching this endpoint's
     * existing array-building shape.
     *
     * @return array<string, mixed>
     */
    private function removeSensitiveData(mixed $resource): array
    {
        $type = $resource->type();

        $fields = match ($type) {
            'application' => ApplicationsController::sensitiveFieldLists(),
            'service' => ServicesController::sensitiveFieldLists(),
            default => DatabasesController::sensitiveFieldLists(),
        };

        $resource->makeHidden($fields['always']);
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $resource->makeHidden($fields['sensitive']);
        }

        return $resource->toArray();
    }
}
