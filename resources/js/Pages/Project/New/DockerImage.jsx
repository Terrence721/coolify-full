import { useForm } from '@inertiajs/react';

/**
 * React port of App\Livewire\Project\New\DockerImage — one of the "Docker Based" creation flows
 * nested inside the "+ New" resource wizard (see Project/Resource/Create.jsx and
 * App\Http\Controllers\ProjectResourceCreateController).
 *
 * Non-goal: the original's "smart paste" auto-parser (splitting a pasted `nginx:alpine` or
 * `nginx@sha256:...` reference in `imageName` into the separate tag/sha256 fields as the user
 * types) is not ported — it's client-side convenience only; the server still authoritatively
 * parses/validates the composed reference on submit via the same App\Services\DockerImageParser.
 */
export default function DockerImage({ submitUrl }) {
    const { data, setData, post, processing, errors } = useForm({
        imageName: '',
        imageTag: '',
        imageSha256: '',
    });

    function submit(e) {
        e.preventDefault();
        post(submitUrl);
    }

    return (
        <form onSubmit={submit}>
            <h1>New Resource</h1>
            <div className="pb-4">Docker Image</div>

            <label className="flex flex-col gap-1">
                Image Name
                <input
                    id="docker-image-name"
                    name="docker-image-name"
                    required
                    autoFocus
                    placeholder="nginx"
                    value={data.imageName}
                    onChange={(e) => setData('imageName', e.target.value)}
                />
                {errors.imageName && <span className="text-error">{errors.imageName}</span>}
            </label>

            <div className="flex gap-2 pt-2">
                <label className="flex flex-col flex-1 gap-1">
                    Tag
                    <input id="docker-image-tag" name="docker-image-tag" placeholder="latest" value={data.imageTag} onChange={(e) => setData('imageTag', e.target.value)} />
                    {errors.imageTag && <span className="text-error">{errors.imageTag}</span>}
                </label>
                <div className="flex items-center pt-6">OR</div>
                <label className="flex flex-col flex-1 gap-1">
                    SHA256 Digest
                    <input
                        id="docker-image-sha256"
                        name="docker-image-sha256"
                        placeholder="sha256:..."
                        value={data.imageSha256}
                        onChange={(e) => setData('imageSha256', e.target.value)}
                    />
                    {errors.imageSha256 && <span className="text-error">{errors.imageSha256}</span>}
                </label>
            </div>

            <button type="submit" disabled={processing} className="mt-4">
                Save
            </button>
        </form>
    );
}
