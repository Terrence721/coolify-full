import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AddProjectModal from './AddProjectModal';
import AddServerModal from './AddServerModal';
import AddStorageModal from './AddStorageModal';
import AddTeamModal from './AddTeamModal';
import PrivateKeyCreateModal from './PrivateKeyCreateModal';

const JSON_HEADERS = { Accept: 'application/json' };

// Kept as a fixed list rather than derived from creatableItems, matching the original Alpine
// component's own hardcoded list exactly (resources/views/livewire/global-search.blade.php) —
// this gates when a fully-typed "new X" command auto-navigates instead of just filtering results,
// so it must only fire on an exact, complete phrase, never on a partial/prefix match.
const EXACT_MATCH_COMMANDS = [
    'new project', 'new server', 'new team', 'new storage', 'new s3',
    'new private key', 'new privatekey', 'new key',
    'new github app', 'new github', 'new source',
    'new public', 'new public git', 'new public repo', 'new public repository',
    'new private github', 'new private gh', 'new private deploy', 'new deploy key',
    'new dockerfile', 'new docker compose', 'new compose', 'new docker image', 'new image',
    'new postgresql', 'new postgres', 'new mysql', 'new mariadb',
    'new redis', 'new keydb', 'new dragonfly', 'new mongodb', 'new mongo', 'new clickhouse',
];

function ChevronRight({ className = 'h-5 w-5' }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
        </svg>
    );
}

function Spinner() {
    return (
        <svg className="h-5 w-5 animate-spin text-warning-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>
    );
}

function typeLabel(result) {
    switch (result.type) {
        case 'navigation':
            return 'Navigation';
        case 'application':
            return 'Application';
        case 'service':
            return 'Service';
        case 'database':
            return result.subtype ? result.subtype.charAt(0).toUpperCase() + result.subtype.slice(1) : 'Database';
        case 'server':
            return 'Server';
        case 'project':
            return 'Project';
        case 'environment':
            return 'Environment';
        default:
            return null;
    }
}

/**
 * React port of App\Livewire\GlobalSearch. The Livewire version stays in place (unchanged) for
 * Boarding\Index/Server\Show, the two pages still rendered through layouts/app.blade.php.
 *
 * Investigating the original before porting turned up something worth recording: the search
 * input only ever used Alpine's `x-model` (client-side), never `wire:model` — so the elaborate
 * word-boundary-regex/match-priority `search()`/`updatedSearchQuery()`/`detectSpecificResource()`
 * PHP methods are never actually invoked by typing in the box; the live behavior is Alpine's own
 * simple substring filtering over data fetched once at modal-open, plus a separate hardcoded
 * "exact phrase" list that calls `$wire.navigateToResource()` directly. This port replicates the
 * *actual* live behavior (substring search + the same exact-phrase list) rather than the
 * unreachable server-side path, which is still available in GlobalSearchService for whatever
 * eventually decides its fate (see todo.md's cleanup-opportunities note).
 */
export default function GlobalSearchModal() {
    const [open, setOpen] = useState(false);
    const [loadingInitial, setLoadingInitial] = useState(false);
    const [query, setQuery] = useState('');
    const [selectedIndex, setSelectedIndex] = useState(-1);
    const [searchableItems, setSearchableItems] = useState([]);
    const [creatableItems, setCreatableItems] = useState([]);
    const [createUrls, setCreateUrls] = useState({});

    const [isSelecting, setIsSelecting] = useState(false);
    const [selectedType, setSelectedType] = useState(null);
    const [servers, setServers] = useState([]);
    const [destinations, setDestinations] = useState([]);
    const [projects, setProjects] = useState([]);
    const [environments, setEnvironments] = useState([]);
    const [selectedServerId, setSelectedServerId] = useState(null);
    const [selectedDestinationUuid, setSelectedDestinationUuid] = useState(null);
    const [selectedProjectUuid, setSelectedProjectUuid] = useState(null);
    const [loadingServers, setLoadingServers] = useState(false);
    const [loadingDestinations, setLoadingDestinations] = useState(false);
    const [loadingProjects, setLoadingProjects] = useState(false);
    const [loadingEnvironments, setLoadingEnvironments] = useState(false);

    const [activeModal, setActiveModal] = useState(null);
    const [serverCreateData, setServerCreateData] = useState(null);

    const inputRef = useRef(null);
    const modalRef = useRef(null);
    const openRef = useRef(open);
    const queryRef = useRef(query);
    const isSelectingRef = useRef(isSelecting);

    useEffect(() => {
        openRef.current = open;
    }, [open]);
    useEffect(() => {
        queryRef.current = query;
    }, [query]);
    useEffect(() => {
        isSelectingRef.current = isSelecting;
    }, [isSelecting]);

    function cancelWizard() {
        setIsSelecting(false);
        setSelectedType(null);
        setSelectedServerId(null);
        setSelectedDestinationUuid(null);
        setSelectedProjectUuid(null);
        setServers([]);
        setDestinations([]);
        setProjects([]);
        setEnvironments([]);
    }

    function closeModal() {
        setOpen(false);
        setSelectedIndex(-1);
        setQuery('');
        cancelWizard();
    }

    async function openModal() {
        setOpen(true);
        setSelectedIndex(-1);
        setQuery('');
        setLoadingInitial(true);
        try {
            const res = await fetch('/search/data', { headers: JSON_HEADERS });
            const data = await res.json();
            setSearchableItems(data.searchableItems ?? []);
            setCreatableItems(data.creatableItems ?? []);
            setCreateUrls(data.createUrls ?? {});
        } finally {
            setLoadingInitial(false);
            setTimeout(() => inputRef.current?.focus(), 50);
        }
    }

    function navigateResults(direction) {
        const container = modalRef.current;
        if (!container) return;
        const results = container.querySelectorAll('.search-result-item');
        if (results.length === 0) return;

        setSelectedIndex((prev) => {
            const next = direction === 'down' ? Math.min(prev + 1, results.length - 1) : Math.max(prev - 1, -1);
            if (next >= 0 && next < results.length) {
                results[next].focus();
                results[next].scrollIntoView({ block: 'nearest' });
            } else if (next === -1) {
                inputRef.current?.focus();
            }

            return next;
        });
    }

    useEffect(() => {
        function onOpenEvent() {
            openModal();
        }
        function onKeydown(e) {
            if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
                e.preventDefault();
                if (!openRef.current) {
                    openModal();
                } else {
                    inputRef.current?.focus();
                    setSelectedIndex(-1);
                }
            } else if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (openRef.current) {
                    inputRef.current?.focus();
                    setSelectedIndex(-1);
                } else {
                    openModal();
                }
            } else if (e.key === 'Escape' && openRef.current) {
                if (!queryRef.current) {
                    if (isSelectingRef.current) {
                        cancelWizard();
                        setTimeout(() => inputRef.current?.focus(), 100);
                    } else {
                        closeModal();
                    }
                } else {
                    setQuery('');
                    setTimeout(() => inputRef.current?.focus(), 100);
                }
            } else if (e.key === 'ArrowDown' && openRef.current) {
                e.preventDefault();
                navigateResults('down');
            } else if (e.key === 'ArrowUp' && openRef.current) {
                e.preventDefault();
                navigateResults('up');
            }
        }

        window.addEventListener('open-global-search', onOpenEvent);
        document.addEventListener('keydown', onKeydown);

        return () => {
            window.removeEventListener('open-global-search', onOpenEvent);
            document.removeEventListener('keydown', onKeydown);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        setSelectedIndex(-1);
        const trimmed = query.trim().toLowerCase();

        if (trimmed === '') {
            if (isSelecting) {
                cancelWizard();
            }

            return;
        }

        if (!EXACT_MATCH_COMMANDS.includes(trimmed)) return;

        const matchingItem = creatableItems.find((item) => {
            const itemSearchText = `new ${item.name}`.toLowerCase();
            const itemType = item.type ? `new ${item.type}`.toLowerCase() : '';
            const itemTypeWithSpaces = item.type ? `new ${item.type.replace(/-/g, ' ')}` : '';

            return (
                itemSearchText === trimmed ||
                itemType === trimmed ||
                itemTypeWithSpaces === trimmed ||
                (item.quickcommand && item.quickcommand.toLowerCase().includes(trimmed))
            );
        });

        if (matchingItem) {
            navigateToResource(matchingItem);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const isCreateMode = query.trim().toLowerCase() === 'new' || query.trim().toLowerCase().startsWith('new ');

    const searchResults = useMemo(() => {
        if (loadingInitial || query.trim().length < 1) return [];
        const q = query.toLowerCase().trim();

        return searchableItems.filter((item) => item.search_text?.toLowerCase().includes(q)).slice(0, 20);
    }, [query, searchableItems, loadingInitial]);

    const filteredCreatableItems = useMemo(() => {
        if (loadingInitial || query.trim().length < 1) return [];
        const q = query.toLowerCase().trim();
        if (q === 'new') return creatableItems;

        return creatableItems.filter((item) => {
            const searchText = `${item.name} ${item.description} ${item.type ?? ''} ${item.category ?? ''}`.toLowerCase();
            if (q.startsWith('new ')) {
                const withoutNew = q.slice(4);

                return searchText.includes(withoutNew) || searchText.includes(q);
            }

            return searchText.includes(q);
        });
    }, [query, creatableItems, loadingInitial]);

    const groupedCreatableItems = useMemo(() => {
        const grouped = {};
        filteredCreatableItems.forEach((item) => {
            const category = item.category || 'Other';
            if (!grouped[category]) grouped[category] = [];
            grouped[category].push(item);
        });

        return grouped;
    }, [filteredCreatableItems]);

    function openCreateComponent(component) {
        setOpen(false);
        setQuery('');
        switch (component) {
            case 'project.add-empty':
                setActiveModal('project');
                break;
            case 'server.create':
                openServerModal();
                break;
            case 'team.create':
                setActiveModal('team');
                break;
            case 'storage.create':
                setActiveModal('storage');
                break;
            case 'security.private-key.create':
                setActiveModal('privateKey');
                break;
            default:
                break;
        }
    }

    async function openServerModal() {
        const res = await fetch('/search/server-create-data', { headers: JSON_HEADERS });
        const data = await res.json();
        setServerCreateData(data);
        setActiveModal('server');
    }

    async function startWizard(type) {
        setOpen(false);
        setIsSelecting(true);
        setSelectedType(type);
        setSelectedServerId(null);
        setSelectedDestinationUuid(null);
        setSelectedProjectUuid(null);
        setOpen(true);
        setLoadingServers(true);
        const res = await fetch('/search/servers', { headers: JSON_HEADERS });
        const data = await res.json();
        const list = data.servers ?? [];
        setServers(list);
        setLoadingServers(false);
        if (list.length === 1) {
            await selectServer(list[0].id);
        }
    }

    async function selectServer(id) {
        setSelectedServerId(id);
        setLoadingDestinations(true);
        const res = await fetch(`/search/destinations?server_id=${id}`, { headers: JSON_HEADERS });
        if (!res.ok) {
            const err = await res.json();
            window.toast?.('Error', { type: 'danger', description: err.message });
            setLoadingDestinations(false);

            return;
        }
        const data = await res.json();
        const list = data.destinations ?? [];
        setDestinations(list);
        setLoadingDestinations(false);
        if (list.length === 1) {
            await selectDestination(list[0].uuid);
        }
    }

    async function selectDestination(uuid) {
        setSelectedDestinationUuid(uuid);
        setLoadingProjects(true);
        const res = await fetch('/search/projects', { headers: JSON_HEADERS });
        if (!res.ok) {
            const err = await res.json();
            window.toast?.('Error', { type: 'danger', description: err.message });
            setLoadingProjects(false);

            return;
        }
        const data = await res.json();
        const list = data.projects ?? [];
        setProjects(list);
        setLoadingProjects(false);
        if (list.length === 1) {
            await selectProject(list[0].uuid);
        }
    }

    async function selectProject(uuid) {
        setSelectedProjectUuid(uuid);
        setLoadingEnvironments(true);
        const res = await fetch(`/search/environments?project_uuid=${uuid}`, { headers: JSON_HEADERS });
        if (!res.ok) {
            const err = await res.json();
            window.toast?.('Error', { type: 'danger', description: err.message });
            setLoadingEnvironments(false);

            return;
        }
        const data = await res.json();
        const list = data.environments ?? [];
        setEnvironments(list);
        setLoadingEnvironments(false);
        if (list.length === 1) {
            selectEnvironment(list[0].uuid);
        }
    }

    function selectEnvironment(environmentUuid) {
        if (selectedProjectUuid && environmentUuid && selectedType && selectedServerId != null && selectedDestinationUuid) {
            const params = new URLSearchParams({
                type: selectedType,
                destination: selectedDestinationUuid,
                server_id: selectedServerId,
            });
            router.get(`/project/${selectedProjectUuid}/environment/${environmentUuid}/new?${params.toString()}`);
        }
    }

    function goBack() {
        if (selectedProjectUuid !== null) {
            setSelectedProjectUuid(null);
            if (projects.length > 1) return;
        }
        if (selectedDestinationUuid !== null) {
            setSelectedDestinationUuid(null);
            setSelectedProjectUuid(null);
            if (destinations.length > 1) return;
        }
        if (selectedServerId !== null) {
            setSelectedServerId(null);
            setSelectedDestinationUuid(null);
            setSelectedProjectUuid(null);
            if (servers.length > 1) return;
        }
        cancelWizard();
    }

    function navigateToResource(item) {
        if (item.link) {
            closeModal();
            window.location.href = item.link;

            return;
        }
        if (item.component) {
            openCreateComponent(item.component);

            return;
        }
        if (item.resourceType) {
            startWizard(item.type);
        }
    }

    const selectedResourceName = useMemo(() => {
        if (!selectedType) return null;
        const item = creatableItems.find((i) => i.type === selectedType);

        return item ? item.name : null;
    }, [selectedType, creatableItems]);

    return (
        <>
            {open && (
                <div className="fixed top-0 left-0 z-99 flex items-start justify-center w-screen h-screen pt-[10vh]" ref={modalRef}>
                    <div onClick={closeModal} className="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-sm" />
                    <div className="relative w-full max-w-2xl mx-4" onClick={(e) => e.stopPropagation()}>
                        <div className="relative">
                            <div className="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                                {loadingInitial ? (
                                    <span className="text-warning">
                                        <Spinner />
                                    </span>
                                ) : (
                                    <svg className="w-5 h-5 text-neutral-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                )}
                            </div>
                            <input
                                id="global-search-input"
                                name="global-search"
                                type="text"
                                autoComplete="off"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Search resources, paths, everything (type new for create)..."
                                ref={inputRef}
                                className="w-full pl-12 pr-32 py-4 text-base bg-white dark:bg-coolgray-100 border-none rounded-lg shadow-xl ring-1 ring-neutral-200 dark:ring-coolgray-300 dark:text-white placeholder-neutral-400 dark:placeholder-neutral-500 focus-visible:outline-none focus-visible:border-l-4 focus-visible:border-l-coollabs dark:focus-visible:border-l-warning"
                            />
                            <div className="absolute inset-y-0 right-2 flex items-center gap-2">
                                <span className="text-xs font-medium text-neutral-400 dark:text-neutral-500">/ or ⌘K to focus</span>
                                <button
                                    type="button"
                                    onClick={closeModal}
                                    className="px-2 py-1 text-xs font-medium text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded"
                                >
                                    ESC
                                </button>
                            </div>
                        </div>

                        {query.length >= 1 && (
                            <div className="mt-2 bg-white dark:bg-coolgray-100 rounded-lg shadow-xl ring-1 ring-neutral-200 dark:ring-coolgray-300 overflow-hidden">
                                <div className="max-h-[60vh] overflow-y-auto scrollbar">
                                    {isSelecting ? (
                                        <div className="p-6">
                                            {selectedServerId === null && (
                                                <SelectionStep
                                                    title="Select Server"
                                                    subtitle={selectedResourceName}
                                                    loading={loadingServers}
                                                    items={servers.map((s) => ({ key: s.id, name: s.name, description: s.description }))}
                                                    emptyMessage="No servers available"
                                                    onBack={goBack}
                                                    onSelect={(key) => selectServer(key)}
                                                />
                                            )}
                                            {selectedServerId !== null && selectedDestinationUuid === null && (
                                                <SelectionStep
                                                    title="Select Destination"
                                                    subtitle={selectedResourceName}
                                                    loading={loadingDestinations}
                                                    items={destinations.map((d) => ({ key: d.uuid, name: d.name, description: `Network: ${d.network}` }))}
                                                    emptyMessage="No destinations available"
                                                    onBack={goBack}
                                                    onSelect={(key) => selectDestination(key)}
                                                />
                                            )}
                                            {selectedDestinationUuid !== null && selectedProjectUuid === null && (
                                                <SelectionStep
                                                    title="Select Project"
                                                    subtitle={selectedResourceName}
                                                    loading={loadingProjects}
                                                    items={projects.map((p) => ({ key: p.uuid, name: p.name, description: p.description }))}
                                                    emptyMessage="No projects available"
                                                    onBack={goBack}
                                                    onSelect={(key) => selectProject(key)}
                                                />
                                            )}
                                            {selectedProjectUuid !== null && (
                                                <SelectionStep
                                                    title="Select Environment"
                                                    subtitle={selectedResourceName}
                                                    loading={loadingEnvironments}
                                                    items={environments.map((e) => ({ key: e.uuid, name: e.name, description: e.description }))}
                                                    emptyMessage="No environments available"
                                                    onBack={goBack}
                                                    onSelect={(key) => selectEnvironment(key)}
                                                />
                                            )}
                                        </div>
                                    ) : isCreateMode && filteredCreatableItems.length > 0 ? (
                                        <div className="py-2">
                                            {searchResults.length > 0 && (
                                                <>
                                                    <div className="px-4 pt-3 pb-1">
                                                        <h4 className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">
                                                            Existing Resources
                                                        </h4>
                                                    </div>
                                                    {searchResults.map((result) => (
                                                        <ExistingResourceRow key={`${result.type}-${result.id ?? result.uuid ?? result.name}`} result={result} />
                                                    ))}
                                                </>
                                            )}

                                            {Object.entries(groupedCreatableItems).map(([category, items]) => (
                                                <div key={category}>
                                                    <div className="px-4 pt-3 pb-1">
                                                        <h4 className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">
                                                            {category}
                                                        </h4>
                                                    </div>
                                                    {items.map((item) => (
                                                        <CreatableRow key={item.type} item={item} onClick={() => navigateToResource(item)} />
                                                    ))}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <>
                                            {searchResults.length > 0 && (
                                                <div className="py-2">
                                                    <div className="px-4 pt-3 pb-1">
                                                        <h4 className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">
                                                            Existing Resources
                                                        </h4>
                                                    </div>
                                                    {searchResults.map((result) => (
                                                        <ExistingResourceRow key={`${result.type}-${result.id ?? result.uuid ?? result.name}`} result={result} />
                                                    ))}
                                                </div>
                                            )}

                                            {filteredCreatableItems.length > 0 && (
                                                <div className="py-2">
                                                    {Object.entries(groupedCreatableItems).map(([category, items]) => (
                                                        <div key={category}>
                                                            <div className="px-4 pt-3 pb-1">
                                                                <h4 className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">
                                                                    {category}
                                                                </h4>
                                                            </div>
                                                            {items.map((item) => (
                                                                <CreatableRow key={item.type} item={item} onClick={() => navigateToResource(item)} />
                                                            ))}
                                                        </div>
                                                    ))}
                                                </div>
                                            )}

                                            {query.trim().length >= 2 &&
                                                searchResults.length === 0 &&
                                                filteredCreatableItems.length === 0 &&
                                                !loadingInitial && (
                                                    <div className="flex items-center justify-center py-12 px-4">
                                                        <div className="text-center">
                                                            <p className="mt-4 text-sm font-medium text-neutral-900 dark:text-white">No results found</p>
                                                            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                                                Try different keywords or check the spelling
                                                            </p>
                                                        </div>
                                                    </div>
                                                )}
                                        </>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {activeModal === 'project' && <AddProjectModal createUrl={createUrls.project} onClose={() => setActiveModal(null)} />}
            {activeModal === 'team' && <AddTeamModal createUrl={createUrls.team} onClose={() => setActiveModal(null)} />}
            {activeModal === 'storage' && <AddStorageModal createUrl={createUrls.storage} onClose={() => setActiveModal(null)} />}
            {activeModal === 'privateKey' && (
                <PrivateKeyCreateModal
                    open
                    onClose={() => setActiveModal(null)}
                    createKeyUrl={createUrls.privateKey}
                    generateKeyUrl={createUrls.privateKeyGenerate}
                    onCreated={() => setActiveModal(null)}
                />
            )}
            {activeModal === 'server' && serverCreateData && (
                <AddServerModal
                    privateKeys={serverCreateData.privateKeys}
                    defaultPrivateKeyId={serverCreateData.defaultPrivateKeyId}
                    defaultName={serverCreateData.defaultName}
                    storeUrl={serverCreateData.storeUrl}
                    onClose={() => setActiveModal(null)}
                />
            )}
        </>
    );
}

function SelectionStep({ title, subtitle, loading, items, emptyMessage, onBack, onSelect }) {
    return (
        <div className="mb-4">
            <div className="flex items-center gap-3 mb-3">
                <button type="button" onClick={onBack} className="text-neutral-600 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <div>
                    <h2 className="text-base font-semibold text-neutral-900 dark:text-white">{title}</h2>
                    {subtitle && <div className="text-xs text-neutral-500 dark:text-neutral-400">for {subtitle}</div>}
                </div>
            </div>
            {loading ? (
                <div className="flex items-center gap-3 p-3 bg-neutral-50 dark:bg-coolgray-200 rounded-lg">
                    <Spinner />
                    <span className="text-sm text-neutral-600 dark:text-neutral-400">Loading...</span>
                </div>
            ) : items.length > 0 ? (
                items.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.key)}
                        className="search-result-item w-full text-left block px-4 py-3 min-h-[4rem] hover:bg-warning-50 dark:hover:bg-warning-900/20 transition-colors focus:outline-none focus:bg-warning-100 dark:focus:bg-warning-900/30 border-b border-neutral-100 dark:border-coolgray-300 last:border-0"
                    >
                        <div className="flex items-center justify-between gap-3 min-h-[2.5rem]">
                            <div className="flex-1 min-w-0">
                                <div className="font-medium text-neutral-900 dark:text-white">{item.name}</div>
                                {item.description && <div className="text-xs text-neutral-500 dark:text-neutral-400">{item.description}</div>}
                            </div>
                            <ChevronRight className="shrink-0 h-5 w-5 text-warning-500 dark:text-warning-400" />
                        </div>
                    </button>
                ))
            ) : (
                <div className="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                    <p className="text-sm text-red-800 dark:text-red-200">{emptyMessage}</p>
                </div>
            )}
        </div>
    );
}

function ExistingResourceRow({ result }) {
    return (
        <a
            href={result.link || '#'}
            className="search-result-item block px-4 py-3 hover:bg-neutral-50 dark:hover:bg-coolgray-200 transition-colors focus:outline-none focus:bg-warning-50 dark:focus:bg-warning-900/20"
        >
            <div className="flex items-center justify-between gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                        <span className="font-medium text-neutral-900 dark:text-white truncate">{result.name}</span>
                        {typeLabel(result) && (
                            <span className="px-2 py-0.5 text-xs rounded-full bg-neutral-100 dark:bg-coolgray-300 text-neutral-700 dark:text-neutral-300 shrink-0">
                                {typeLabel(result)}
                            </span>
                        )}
                    </div>
                    {result.project && result.environment && (
                        <div className="text-xs text-neutral-500 dark:text-neutral-400 mb-1">
                            {result.project} / {result.environment}
                        </div>
                    )}
                    {result.description && (
                        <div className="text-sm text-neutral-600 dark:text-neutral-400">
                            {result.description.length > 80 ? `${result.description.substring(0, 80)}...` : result.description}
                        </div>
                    )}
                </div>
                <ChevronRight className="shrink-0 h-5 w-5 text-neutral-300 dark:text-neutral-600 self-center" />
            </div>
        </a>
    );
}

function CreatableRow({ item, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="search-result-item w-full text-left block px-4 py-3 hover:bg-warning-50 dark:hover:bg-warning-900/20 transition-colors focus:outline-none focus:bg-warning-100 dark:focus:bg-warning-900/30"
        >
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3 flex-1 min-w-0">
                    {item.logo ? (
                        <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-neutral-100 dark:bg-neutral-800 flex items-center justify-center overflow-hidden">
                            <img src={`/${item.logo}`} alt={item.name} className="w-7 h-7 object-contain" />
                        </div>
                    ) : (
                        <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-warning-100 dark:bg-warning-900/40 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-warning-600 dark:text-warning-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </div>
                    )}
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                            <div className="font-medium text-neutral-900 dark:text-white truncate">{item.name}</div>
                            {item.amd_only && (
                                <span className="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200 shrink-0">
                                    AMD only
                                </span>
                            )}
                            {item.arm_only && (
                                <span className="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200 shrink-0">
                                    ARM only
                                </span>
                            )}
                            {item.quickcommand && <span className="text-xs text-neutral-500 dark:text-neutral-400 shrink-0">{item.quickcommand}</span>}
                        </div>
                        <div className="text-sm text-neutral-600 dark:text-neutral-400 truncate">{item.description}</div>
                    </div>
                </div>
                <ChevronRight className="shrink-0 h-5 w-5 text-warning-500 dark:text-warning-400 self-center" />
            </div>
        </button>
    );
}
