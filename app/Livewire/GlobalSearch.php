<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Project;
use App\Models\Server;
use App\Services\GlobalSearchService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class GlobalSearch extends Component
{
    public $searchQuery = '';

    private $previousTrimmedQuery = '';

    public $isModalOpen = false;

    public $searchResults = [];

    public $allSearchableItems = [];

    public $isCreateMode = false;

    public $creatableItems = [];

    public $autoOpenResource = null;

    // Resource selection state
    public $isSelectingResource = false;

    public $selectedResourceType = null;

    public $loadingServers = false;

    public $loadingProjects = false;

    public $loadingEnvironments = false;

    public $availableServers = [];

    public $availableProjects = [];

    public $availableEnvironments = [];

    public $selectedServerId = null;

    public $selectedDestinationUuid = null;

    public $selectedProjectUuid = null;

    public $selectedEnvironmentUuid = null;

    public $availableDestinations = [];

    public $loadingDestinations = false;

    public function mount()
    {
        $this->searchQuery = '';
        $this->isModalOpen = false;
        $this->searchResults = [];
        $this->allSearchableItems = [];
        $this->isCreateMode = false;
        $this->creatableItems = [];
        $this->autoOpenResource = null;
        $this->isSelectingResource = false;
    }

    public function openSearchModal()
    {
        $this->isModalOpen = true;
        $this->loadSearchableItems();
        $this->loadCreatableItems();
        $this->dispatch('search-modal-opened');
    }

    public function closeSearchModal()
    {
        $this->isModalOpen = false;
        $this->searchQuery = '';
        $this->previousTrimmedQuery = '';
        $this->searchResults = [];
    }

    public static function getCacheKey($teamId)
    {
        return GlobalSearchService::getCacheKey($teamId);
    }

    public static function clearTeamCache($teamId)
    {
        GlobalSearchService::clearTeamCache($teamId);
    }

    public function updatedSearchQuery()
    {
        $trimmedQuery = trim($this->searchQuery);

        // If only spaces were added/removed, don't trigger a search
        if ($trimmedQuery === $this->previousTrimmedQuery) {
            return;
        }

        $this->previousTrimmedQuery = $trimmedQuery;

        // If search query is empty, just clear results without processing
        if (empty($trimmedQuery)) {
            $this->searchResults = [];
            $this->isCreateMode = false;
            $this->creatableItems = [];
            $this->autoOpenResource = null;
            $this->isSelectingResource = false;
            $this->cancelResourceSelection();

            return;
        }

        $query = strtolower($trimmedQuery);

        // Reset keyboard navigation index
        $this->dispatch('reset-selected-index');

        // Only enter create mode if query is exactly "new" or starts with "new " (space after)
        if ($query === 'new' || str_starts_with($query, 'new ')) {
            $this->isCreateMode = true;
            $this->loadCreatableItems();

            // Check for sub-commands like "new project", "new server", etc.
            $detectedType = $this->detectSpecificResource($query);
            if ($detectedType) {
                $this->navigateToResource($detectedType);
            } else {
                // If no specific resource detected, reset selection state
                $this->cancelResourceSelection();
            }

            // Also search for existing resources that match the query
            // This allows users to find resources with "new" in their name
            $this->search();
        } else {
            $this->isCreateMode = false;
            $this->creatableItems = [];
            $this->autoOpenResource = null;
            $this->isSelectingResource = false;
            $this->search();
        }
    }

    private function detectSpecificResource(string $query): ?string
    {
        return app(GlobalSearchService::class)->detectSpecificResource($query, auth()->user());
    }

    private function loadSearchableItems()
    {
        $this->allSearchableItems = app(GlobalSearchService::class)->loadSearchableItems(currentTeam());
    }

    private function search()
    {
        if (strlen($this->searchQuery) < 1) {
            $this->searchResults = [];

            return;
        }

        $query = strtolower($this->searchQuery);

        // Detect resource category queries
        $categoryMapping = [
            'server' => ['server', 'type' => 'server'],
            'servers' => ['server', 'type' => 'server'],
            'app' => ['application', 'type' => 'application'],
            'apps' => ['application', 'type' => 'application'],
            'application' => ['application', 'type' => 'application'],
            'applications' => ['application', 'type' => 'application'],
            'db' => ['database', 'type' => 'standalone-postgresql'],
            'database' => ['database', 'type' => 'standalone-postgresql'],
            'databases' => ['database', 'type' => 'standalone-postgresql'],
            'service' => ['service', 'category' => 'Services'],
            'services' => ['service', 'category' => 'Services'],
            'project' => ['project', 'type' => 'project'],
            'projects' => ['project', 'type' => 'project'],
        ];

        $priorityCreatableItem = null;

        // Check if query matches a resource category
        if (isset($categoryMapping[$query])) {
            $this->loadCreatableItems();
            $mapping = $categoryMapping[$query];

            // Find the matching creatable item
            $priorityCreatableItem = collect($this->creatableItems)
                ->first(function ($item) use ($mapping) {
                    if (isset($mapping['type'])) {
                        return $item['type'] === $mapping['type'];
                    }
                    if (isset($mapping['category'])) {
                        return isset($item['category']) && $item['category'] === $mapping['category'];
                    }

                    return false;
                });

            if ($priorityCreatableItem) {
                $priorityCreatableItem['is_creatable_suggestion'] = true;
            }
        }

        // Search for matching creatable resources to show as suggestions (if no priority item)
        if (! $priorityCreatableItem) {
            $this->loadCreatableItems();

            // Search in regular creatable items (apps, databases, quick actions)
            $creatableSuggestions = collect($this->creatableItems)
                ->filter(function ($item) use ($query) {
                    $searchText = strtolower($item['name'].' '.$item['description'].' '.($item['type'] ?? ''));

                    // Use word boundary matching to avoid substring matches (e.g., "wordpress" shouldn't match "classicpress")
                    return preg_match('/\b'.preg_quote($query, '/').'/i', $searchText);
                })
                ->map(function ($item) use ($query) {
                    // Calculate match priority: name > type > description
                    $name = strtolower($item['name']);
                    $type = strtolower($item['type'] ?? '');
                    $description = strtolower($item['description']);

                    if (preg_match('/\b'.preg_quote($query, '/').'/i', $name)) {
                        $item['match_priority'] = 1;
                    } elseif (preg_match('/\b'.preg_quote($query, '/').'/i', $type)) {
                        $item['match_priority'] = 2;
                    } else {
                        $item['match_priority'] = 3;
                    }

                    $item['is_creatable_suggestion'] = true;

                    return $item;
                });

            // Also search in services (loaded on-demand)
            $serviceSuggestions = collect($this->services)
                ->filter(function ($item) use ($query) {
                    $searchText = strtolower($item['name'].' '.$item['description'].' '.($item['type'] ?? ''));

                    return preg_match('/\b'.preg_quote($query, '/').'/i', $searchText);
                })
                ->map(function ($item) use ($query) {
                    // Calculate match priority: name > type > description
                    $name = strtolower($item['name']);
                    $type = strtolower($item['type'] ?? '');
                    $description = strtolower($item['description']);

                    if (preg_match('/\b'.preg_quote($query, '/').'/i', $name)) {
                        $item['match_priority'] = 1;
                    } elseif (preg_match('/\b'.preg_quote($query, '/').'/i', $type)) {
                        $item['match_priority'] = 2;
                    } else {
                        $item['match_priority'] = 3;
                    }

                    $item['is_creatable_suggestion'] = true;

                    return $item;
                });

            // Merge and sort all suggestions
            $creatableSuggestions = $creatableSuggestions
                ->merge($serviceSuggestions)
                ->sortBy('match_priority')
                ->take(10)
                ->values()
                ->toArray();
        } else {
            $creatableSuggestions = [];
        }

        // Case-insensitive search in existing resources
        $existingResults = collect($this->allSearchableItems)
            ->filter(function ($item) use ($query) {
                // Use word boundary matching to avoid substring matches (e.g., "wordpress" shouldn't match "classicpress")
                return preg_match('/\b'.preg_quote($query, '/').'/i', $item['search_text']);
            })
            ->map(function ($item) use ($query) {
                // Calculate match priority: name > type > description
                $name = strtolower($item['name'] ?? '');
                $type = strtolower($item['type'] ?? '');
                $description = strtolower($item['description'] ?? '');

                if (preg_match('/\b'.preg_quote($query, '/').'/i', $name)) {
                    $item['match_priority'] = 1;
                } elseif (preg_match('/\b'.preg_quote($query, '/').'/i', $type)) {
                    $item['match_priority'] = 2;
                } else {
                    $item['match_priority'] = 3;
                }

                return $item;
            })
            ->sortBy('match_priority')
            ->take(20)
            ->values()
            ->toArray();

        // Merge results: existing resources first, then priority create item, then other creatable suggestions
        $results = [];

        // If we have existing results, show them first
        $results = array_merge($results, $existingResults);

        // Then show the priority "Create New" item (if exists)
        if ($priorityCreatableItem) {
            $results[] = $priorityCreatableItem;
        }

        // Finally show other creatable suggestions
        $results = array_merge($results, $creatableSuggestions);

        $this->searchResults = $results;
    }

    private function loadCreatableItems()
    {
        $this->creatableItems = app(GlobalSearchService::class)->loadCreatableItems(auth()->user(), $this->services);
    }

    public function navigateToResource($type)
    {
        // Find the item by type - check regular items first, then services
        $item = collect($this->creatableItems)->firstWhere('type', $type);

        if (! $item) {
            $item = collect($this->services)->firstWhere('type', $type);
        }

        if (! $item) {
            return;
        }

        // Link-based quick actions navigate to a full page (e.g. the React Sources page)
        if (isset($item['link'])) {
            $this->dispatch('closeSearchModal');

            return $this->redirect($item['link']);
        }

        // If it has a component, it's a modal-based resource
        // Close search modal and open the appropriate creation modal
        if (isset($item['component'])) {
            $this->dispatch('closeSearchModal');
            $this->dispatch('open-create-modal-'.$type);

            return;
        }

        // For applications, databases, and services, navigate to resource creation
        // with smart defaults (auto-select if only 1 server/project/environment)
        if (isset($item['resourceType'])) {
            $this->navigateToResourceCreation($type);
        }
    }

    private function navigateToResourceCreation($type)
    {
        // Start the selection flow
        $this->selectedResourceType = $type;
        $this->isSelectingResource = true;

        // Clear search query to show selection UI instead of creatable items
        $this->searchQuery = '';

        // Reset selections
        $this->selectedServerId = null;
        $this->selectedDestinationUuid = null;
        $this->selectedProjectUuid = null;
        $this->selectedEnvironmentUuid = null;

        // Start loading servers first (in order: servers -> destinations -> projects -> environments)
        $this->loadServers();
    }

    public function loadServers()
    {
        $this->loadingServers = true;
        $servers = Server::isUsable()->get()->sortBy('name');
        $this->availableServers = $servers->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
        ])->toArray();
        $this->loadingServers = false;

        // Auto-select if only one server
        if (count($this->availableServers) === 1) {
            $this->selectServer($this->availableServers[0]['id']);
        }
    }

    public function selectServer($serverId, $shouldProgress = true)
    {
        $this->selectedServerId = $serverId;

        if ($shouldProgress) {
            $this->loadDestinations();
        }
    }

    public function loadDestinations()
    {
        $this->loadingDestinations = true;
        $server = Server::ownedByCurrentTeam()->find($this->selectedServerId);

        if (! $server) {
            $this->loadingDestinations = false;

            return $this->dispatch('error', message: 'Server not found');
        }

        $destinations = $server->destinations();

        if ($destinations->isEmpty()) {
            $this->loadingDestinations = false;

            return $this->dispatch('error', message: 'No destinations found on this server');
        }

        $this->availableDestinations = $destinations->map(fn ($d) => [
            'uuid' => $d->uuid,
            'name' => $d->name,
            'network' => $d->network ?? 'default',
        ])->toArray();

        $this->loadingDestinations = false;

        // Auto-select if only one destination
        if (count($this->availableDestinations) === 1) {
            $this->selectDestination($this->availableDestinations[0]['uuid']);
        }
    }

    public function selectDestination($destinationUuid, $shouldProgress = true)
    {
        $this->selectedDestinationUuid = $destinationUuid;

        if ($shouldProgress) {
            $this->loadProjects();
        }
    }

    public function loadProjects()
    {
        $this->loadingProjects = true;
        $user = auth()->user();
        $team = $user->currentTeam();
        $projects = Project::where('team_id', $team->id)->get();

        if ($projects->isEmpty()) {
            $this->loadingProjects = false;

            return $this->dispatch('error', message: 'Please create a project first');
        }

        $this->availableProjects = $projects->map(fn ($p) => [
            'uuid' => $p->uuid,
            'name' => $p->name,
            'description' => $p->description,
        ])->toArray();
        $this->loadingProjects = false;

        // Auto-select if only one project
        if (count($this->availableProjects) === 1) {
            $this->selectProject($this->availableProjects[0]['uuid']);
        }
    }

    public function selectProject($projectUuid, $shouldProgress = true)
    {
        $this->selectedProjectUuid = $projectUuid;

        if ($shouldProgress) {
            $this->loadEnvironments();
        }
    }

    public function loadEnvironments()
    {
        $this->loadingEnvironments = true;
        $project = Project::ownedByCurrentTeam()->where('uuid', $this->selectedProjectUuid)->first();

        if (! $project) {
            $this->loadingEnvironments = false;

            return;
        }

        $environments = $project->environments;

        if ($environments->isEmpty()) {
            $this->loadingEnvironments = false;

            return $this->dispatch('error', message: 'No environments found in project');
        }

        $this->availableEnvironments = $environments->map(fn ($e) => [
            'uuid' => $e->uuid,
            'name' => $e->name,
            'description' => $e->description,
        ])->toArray();
        $this->loadingEnvironments = false;

        // Auto-select if only one environment
        if (count($this->availableEnvironments) === 1) {
            $this->selectEnvironment($this->availableEnvironments[0]['uuid']);
        }
    }

    public function selectEnvironment($environmentUuid, $shouldProgress = true)
    {
        $this->selectedEnvironmentUuid = $environmentUuid;

        if ($shouldProgress) {
            $this->completeResourceCreation();
        }
    }

    private function completeResourceCreation()
    {
        // All selections made - navigate to resource creation
        if ($this->selectedProjectUuid && $this->selectedEnvironmentUuid && $this->selectedResourceType && $this->selectedServerId !== null && $this->selectedDestinationUuid) {
            $queryParams = [
                'type' => $this->selectedResourceType,
                'destination' => $this->selectedDestinationUuid,
                'server_id' => $this->selectedServerId,
            ];

            redirectRoute($this, 'project.resource.create', [
                'project_uuid' => $this->selectedProjectUuid,
                'environment_uuid' => $this->selectedEnvironmentUuid,
            ] + $queryParams);
        }
    }

    public function cancelResourceSelection()
    {
        $this->isSelectingResource = false;
        $this->selectedResourceType = null;
        $this->selectedServerId = null;
        $this->selectedDestinationUuid = null;
        $this->selectedProjectUuid = null;
        $this->selectedEnvironmentUuid = null;
        $this->availableServers = [];
        $this->availableDestinations = [];
        $this->availableProjects = [];
        $this->availableEnvironments = [];
        $this->autoOpenResource = null;
    }

    public function goBack()
    {
        // From Environment Selection → go back to Project (if multiple) or further
        if ($this->selectedProjectUuid !== null) {
            $this->selectedProjectUuid = null;
            $this->selectedEnvironmentUuid = null;
            if (count($this->availableProjects) > 1) {
                return; // Stop here - user can choose a project
            }
        }

        // From Project Selection → go back to Destination (if multiple) or further
        if ($this->selectedDestinationUuid !== null) {
            $this->selectedDestinationUuid = null;
            $this->selectedProjectUuid = null;
            $this->selectedEnvironmentUuid = null;
            if (count($this->availableDestinations) > 1) {
                return; // Stop here - user can choose a destination
            }
        }

        // From Destination Selection → go back to Server (if multiple) or cancel
        if ($this->selectedServerId !== null) {
            $this->selectedServerId = null;
            $this->selectedDestinationUuid = null;
            $this->selectedProjectUuid = null;
            $this->selectedEnvironmentUuid = null;
            if (count($this->availableServers) > 1) {
                return; // Stop here - user can choose a server
            }
        }

        // All previous steps were auto-selected, cancel entirely
        $this->cancelResourceSelection();
    }

    public function getFilteredCreatableItemsProperty()
    {
        $query = strtolower(trim($this->searchQuery));

        // Check if query matches a category keyword
        $categoryKeywords = ['server', 'servers', 'app', 'apps', 'application', 'applications', 'db', 'database', 'databases', 'service', 'services', 'project', 'projects'];
        if (in_array($query, $categoryKeywords)) {
            return $this->filterCreatableItemsByCategory($query);
        }

        // Extract search term - everything after "new "
        if (str_starts_with($query, 'new ')) {
            $searchTerm = trim(substr($query, strlen('new ')));

            if (empty($searchTerm)) {
                return $this->creatableItems;
            }

            // Filter items by name or description
            return collect($this->creatableItems)->filter(function ($item) use ($searchTerm) {
                $searchText = strtolower($item['name'].' '.$item['description'].' '.$item['category']);

                return str_contains($searchText, $searchTerm);
            })->values()->toArray();
        }

        return $this->creatableItems;
    }

    private function filterCreatableItemsByCategory($categoryKeyword)
    {
        // Map keywords to category names
        $categoryMap = [
            'server' => 'Quick Actions',
            'servers' => 'Quick Actions',
            'app' => 'Applications',
            'apps' => 'Applications',
            'application' => 'Applications',
            'applications' => 'Applications',
            'db' => 'Databases',
            'database' => 'Databases',
            'databases' => 'Databases',
            'service' => 'Services',
            'services' => 'Services',
            'project' => 'Applications',
            'projects' => 'Applications',
        ];

        $category = $categoryMap[$categoryKeyword] ?? null;

        if (! $category) {
            return [];
        }

        return collect($this->creatableItems)
            ->filter(fn ($item) => $item['category'] === $category)
            ->values()
            ->toArray();
    }

    public function getSelectedResourceNameProperty()
    {
        if (! $this->selectedResourceType) {
            return null;
        }

        // Load creatable items if not loaded yet
        if (empty($this->creatableItems)) {
            $this->loadCreatableItems();
        }

        // Find the item by type - check regular items first, then services
        $item = collect($this->creatableItems)->firstWhere('type', $this->selectedResourceType);

        if (! $item) {
            $item = collect($this->services)->firstWhere('type', $this->selectedResourceType);
        }

        return $item ? $item['name'] : null;
    }

    public function getServicesProperty()
    {
        // Cache in a static property to avoid reloading on every access within the same request
        static $cachedServices = null;

        if ($cachedServices !== null) {
            return $cachedServices;
        }

        return $cachedServices = app(GlobalSearchService::class)->loadServices(auth()->user());
    }

    public function render(): Factory|View
    {
        return view('livewire.global-search');
    }
}
