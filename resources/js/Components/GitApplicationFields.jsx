/**
 * The build-pack / port / static-site / docker-compose-location field cluster shared by the 3
 * git-based application creation flows (Project/New/PublicGitRepository, GithubPrivateRepository,
 * GithubPrivateRepositoryDeployKey). Ports the identical blade fragment each of the original
 * Livewire views duplicated, including its behaviors: build-pack changes adjust port/static
 * visibility, toggling "static site" swaps port 80 + /dist defaults in and out, and the compose
 * paths normalize on blur with a live computed-location preview.
 */
export function normalizeGitPath(path) {
    if (!path || path.trim() === '') return '/';
    let normalized = path.trim().replace(/\/+$/, '');
    if (!normalized.startsWith('/')) {
        normalized = '/' + normalized;
    }
    return normalized === '' ? '/' : normalized;
}

export default function GitApplicationFields({ data, setData, errors, children }) {
    const showIsStatic = data.build_pack === 'nixpacks' || data.build_pack === 'railpack';

    function setBuildPack(value) {
        const next = { ...data, build_pack: value };
        if (value === 'nixpacks' || value === 'railpack') {
            if (!data.is_static) next.port = 3000;
        } else if (value === 'static') {
            next.is_static = false;
            next.port = 80;
        } else {
            next.is_static = false;
        }
        setData(next);
    }

    function toggleIsStatic(checked) {
        setData({
            ...data,
            is_static: checked,
            port: checked ? 80 : 3000,
            publish_directory: checked ? '/dist' : '',
        });
    }

    const composePreview =
        (data.base_directory === '/' ? '' : data.base_directory) +
        (data.docker_compose_location.startsWith('/') ? data.docker_compose_location : '/' + data.docker_compose_location);

    return (
        <>
            <div className="flex gap-2">
                {children}
                <label className="flex flex-col flex-1 gap-1">
                    Build Pack
                    <select
                        id="git-fields-build-pack"
                        name="git-fields-build-pack"
                        required
                        value={data.build_pack}
                        onChange={(e) => setBuildPack(e.target.value)}
                    >
                        <option value="nixpacks">Nixpacks</option>
                        <option value="railpack">Railpack (Beta)</option>
                        <option value="static">Static</option>
                        <option value="dockerfile">Dockerfile</option>
                        <option value="dockercompose">Docker Compose</option>
                    </select>
                    {errors.build_pack && <span className="text-error">{errors.build_pack}</span>}
                </label>
                {data.is_static && (
                    <label className="flex flex-col flex-1 gap-1">
                        Publish Directory
                        <input
                            id="git-fields-publish-directory"
                            name="git-fields-publish-directory"
                            value={data.publish_directory ?? ''}
                            onChange={(e) => setData({ ...data, publish_directory: e.target.value })}
                            title="If there is a build process involved (like Svelte, React, Next, etc..), specify the output directory for the build assets."
                        />
                        {errors.publish_directory && <span className="text-error">{errors.publish_directory}</span>}
                    </label>
                )}
            </div>

            {data.build_pack === 'dockercompose' ? (
                <div className="flex flex-col gap-2">
                    <label className="flex flex-col gap-1">
                        Base Directory
                        <input
                            id="git-fields-base-directory"
                            name="git-fields-base-directory"
                            placeholder="/"
                            value={data.base_directory ?? ''}
                            onChange={(e) => setData({ ...data, base_directory: e.target.value })}
                            onBlur={(e) => setData({ ...data, base_directory: normalizeGitPath(e.target.value) })}
                            title="Directory to use as root. Useful for monorepos."
                        />
                        {errors.base_directory && <span className="text-error">{errors.base_directory}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Docker Compose Location
                        <input
                            id="git-fields-docker-compose-location"
                            name="git-fields-docker-compose-location"
                            placeholder="/docker-compose.yaml"
                            value={data.docker_compose_location ?? ''}
                            onChange={(e) => setData({ ...data, docker_compose_location: e.target.value })}
                            onBlur={(e) => setData({ ...data, docker_compose_location: normalizeGitPath(e.target.value) })}
                            title="It is calculated together with the Base Directory."
                        />
                        {errors.docker_compose_location && <span className="text-error">{errors.docker_compose_location}</span>}
                    </label>
                    <div className="pt-2">
                        <span>Compose file location in your repository: </span>
                        <span className="dark:text-warning">{composePreview}</span>
                    </div>
                </div>
            ) : (
                <label className="flex flex-col gap-1">
                    Base Directory
                    <input
                        id="git-fields-base-directory"
                        name="git-fields-base-directory"
                        value={data.base_directory ?? ''}
                        onChange={(e) => setData({ ...data, base_directory: e.target.value })}
                        title="Directory to use as root. Useful for monorepos."
                    />
                    {errors.base_directory && <span className="text-error">{errors.base_directory}</span>}
                </label>
            )}

            {showIsStatic && (
                <>
                    <label className="flex flex-col gap-1">
                        Port
                        <input
                            id="git-fields-port"
                            name="git-fields-port"
                            type="number"
                            value={data.port}
                            readOnly={data.is_static || data.build_pack === 'static'}
                            onChange={(e) => setData({ ...data, port: e.target.value })}
                            title="The port your application listens on."
                        />
                        {errors.port && <span className="text-error">{errors.port}</span>}
                    </label>
                    <div className="w-64">
                        <label className="flex gap-2 items-center">
                            <input
                                id="git-fields-is-static"
                                type="checkbox"
                                checked={data.is_static}
                                onChange={(e) => toggleIsStatic(e.target.checked)}
                            />
                            Is it a static site?
                        </label>
                    </div>
                </>
            )}
        </>
    );
}
