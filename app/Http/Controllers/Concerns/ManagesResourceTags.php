<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * The Tags tab (App\Livewire\Project\Shared\Tags) — extracted from ProjectDatabaseConfiguration
 * Controller and ProjectServiceConfigurationController's byte-identical inline implementations
 * on their third consumer, ProjectApplicationConfigurationController (Phase 63). Space-separated
 * create+attach, quick-add by id, detach with the original's orphan-pruning quirk kept as-is
 * (prunes a tag when no applications/services reference it, ignoring standalone databases —
 * same pre-existing scope gap as before, now shared rather than triplicated).
 */
trait ManagesResourceTags
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function tagsTabProps(Model $resource, array $parameters, string $routePrefix): array
    {
        $resourceTags = $this->resourceTagsCollection($resource);

        return [
            'tags' => $resourceTags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'destroyUrl' => route("{$routePrefix}.tags.destroy", [...$parameters, 'tag_id' => $tag->id]),
            ])->values(),
            'availableTags' => Tag::ownedByCurrentTeam()->get()
                ->reject(fn (Tag $tag) => $resourceTags->contains($tag))
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                ->values(),
            'tagsStoreUrl' => route("{$routePrefix}.tags.store", $parameters),
        ];
    }

    private function storeResourceTag(Request $request, Model $resource): RedirectResponse
    {
        $this->authorize('update', $resource);
        $tagsRelation = $this->resourceTagsRelation($resource);
        if ($tagsRelation === null) {
            return back()->with('error', 'Tags are not available for this resource.');
        }

        $validated = Validator::make($request->all(), [
            'tags' => 'required_without:tag_id|nullable|string|min:2',
            'tag_id' => 'required_without:tags|nullable|integer',
        ])->validate();

        if (filled($validated['tag_id'] ?? null)) {
            $tag = Tag::ownedByCurrentTeam()->findOrFail((int) $validated['tag_id']);
            if ($tagsRelation->where('id', $tag->id)->exists()) {
                return back()->with('error', "Tag {$tag->name} already added.");
            }
            $tagsRelation->attach($tag->id);

            return back()->with('success', 'Tag added.');
        }

        $skipped = [];
        foreach (str($validated['tags'])->trim()->explode(' ') as $name) {
            $name = strip_tags($name);
            if (strlen($name) < 2) {
                $skipped[] = "Tag {$name} is invalid (min length is 2).";

                continue;
            }
            if ($tagsRelation->where('name', $name)->exists()) {
                $skipped[] = "Tag {$name} already added.";

                continue;
            }
            $tag = Tag::ownedByCurrentTeam()->where('name', $name)->first()
                ?? Tag::create(['name' => $name, 'team_id' => currentTeam()->id]);
            $tagsRelation->attach($tag->id);
        }

        if ($skipped !== []) {
            return back()->with('error', implode(' ', $skipped));
        }

        return back()->with('success', 'Tags added.');
    }

    private function destroyResourceTag(Model $resource, string $tagId): RedirectResponse
    {
        $this->authorize('update', $resource);
        $tagsRelation = $this->resourceTagsRelation($resource);
        if ($tagsRelation === null) {
            return back()->with('error', 'Tags are not available for this resource.');
        }

        $tagsRelation->detach($tagId);
        $tag = Tag::ownedByCurrentTeam()->find($tagId);
        if ($tag && $tag->applications()->count() == 0 && $tag->services()->count() == 0) {
            $tag->delete();
        }

        return back()->with('success', 'Tag deleted.');
    }

    private function resourceTagsRelation(Model $resource): mixed
    {
        return method_exists($resource, 'tags') ? $resource->tags() : null;
    }

    /**
     * @return Collection<int, Tag>
     */
    private function resourceTagsCollection(Model $resource): Collection
    {
        $value = data_get($resource, 'tags', []);

        if ($value instanceof Collection) {
            /** @var Collection<int, Tag> $value */
            return $value;
        }

        if (is_array($value)) {
            /** @var Collection<int, Tag> $collection */
            $collection = collect($value);

            return $collection;
        }

        return collect();
    }
}
