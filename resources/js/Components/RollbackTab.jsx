import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COMMIT_SHA_PATTERN = /^[0-9a-f]{7,128}$/i;
const PR_TAG_PATTERN = /^pr-\d+$/;

/**
 * React port of App\Livewire\Project\Application\Rollback — the docker-images-to-keep setting
 * plus the local-image list with per-image rollback. Images are loaded lazily on mount (a POST
 * to rollbackUrls.loadImages, matching the original's `x-init="$wire.loadImages"` triggering
 * after hydration rather than being eagerly included in the initial page load), landing in the
 * flash payload (`rollbackImages`/`rollbackCurrentTag`) the same way DatabaseImportTab.jsx reads
 * its own SSH-check results back. Rollback/reload both genuinely touch SSH — the untested-happy-
 * path gap documented in docs/smoketest.md applies here same as everywhere else.
 */
export default function RollbackTab({ rollback, rollbackUrls }) {
    const [dockerImagesToKeep, setDockerImagesToKeep] = useState(rollback.dockerImagesToKeep);
    const [images, setImages] = useState([]);
    const [loading, setLoading] = useState(true);

    function loadImages(showToast = false) {
        setLoading(true);
        router.post(
            rollbackUrls.loadImages,
            { showToast },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    setImages(page.props.flash?.rollbackImages ?? []);
                    setLoading(false);
                },
                onError: () => setLoading(false),
            },
        );
    }

    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => loadImages(false), []);

    function saveSettings(e) {
        e.preventDefault();
        router.patch(rollbackUrls.saveSettings, { dockerImagesToKeep }, { preserveScroll: true });
    }

    function rollbackImage(tag) {
        router.post(rollbackUrls.deploy, { tag }, { preserveScroll: true });
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h2>Rollback</h2>
                <button type="button" onClick={() => loadImages(true)}>
                    Reload Available Images
                </button>
            </div>
            <div className="pb-4">You can easily rollback to a previously built (local) images quickly.</div>

            {rollback.serverRetentionDisabled && (
                <div className="w-full p-3 mb-4 text-sm rounded bg-warning/10 text-warning">
                    Image retention is disabled at the server level. This setting has no effect until the server administrator enables it.
                </div>
            )}

            <form onSubmit={saveSettings} className="pb-4 flex items-end gap-2 w-96">
                <label className="flex flex-col flex-1 gap-1">
                    Images to keep for rollback
                    <input
                        id="rollback-docker-images-to-keep"
                        name="rollback-docker-images-to-keep"
                        type="number"
                        min={0}
                        max={100}
                        disabled={rollback.serverRetentionDisabled}
                        value={dockerImagesToKeep}
                        onChange={(e) => setDockerImagesToKeep(e.target.value)}
                    />
                </label>
                <button type="submit" disabled={rollback.serverRetentionDisabled}>
                    Save
                </button>
            </form>

            {loading ? (
                <div>Loading available docker images...</div>
            ) : (
                <div className="flex flex-wrap">
                    {images.length === 0 && <div>No images found locally.</div>}
                    {images.map((image) => {
                        const isCommitSha = COMMIT_SHA_PATTERN.test(image.tag);
                        const isPrTag = PR_TAG_PATTERN.test(image.tag);
                        const isRollbackable = isCommitSha || isPrTag;

                        return (
                            <div key={image.tag} className="w-2/4 p-2">
                                <div className="bg-white border rounded-sm dark:border-coolgray-300 dark:bg-coolgray-100 border-neutral-200">
                                    <div className="p-2">
                                        <div>
                                            {image.isCurrent && <span className="font-bold dark:text-warning">LIVE | </span>}
                                            {isCommitSha ? `SHA: ${image.tag}` : isPrTag ? `PR: ${image.tag}` : `Tag: ${image.tag}`}
                                        </div>
                                        <div className="text-xs">{image.createdAt}</div>
                                    </div>
                                    <div className="flex justify-end p-2">
                                        {rollback.canDeploy && (
                                            <button
                                                type="button"
                                                disabled={image.isCurrent || !isRollbackable}
                                                title={
                                                    image.isCurrent
                                                        ? 'This image is currently running.'
                                                        : !isRollbackable
                                                          ? `Rollback not available for '${image.tag}' tag. Only commit-based tags support rollback. Re-deploy to create a rollback-enabled image.`
                                                          : undefined
                                                }
                                                onClick={() => rollbackImage(image.tag)}
                                            >
                                                Rollback
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
