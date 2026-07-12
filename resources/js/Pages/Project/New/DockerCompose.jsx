import { useForm } from '@inertiajs/react';
import MonacoEditor from '../../../Components/MonacoEditor';

/**
 * React port of App\Livewire\Project\New\DockerCompose — one of the "Docker Based" creation flows
 * nested inside the "+ New" resource wizard (see Project/Resource/Create.jsx and
 * App\Http\Controllers\ProjectResourceCreateController).
 *
 * Non-goal: the original's `envFile` property was never wired to any form field in its own blade
 * view (dead/unreachable in production), so it isn't ported here either.
 */
export default function DockerCompose({ defaultDockerComposeRaw, submitUrl }) {
    const { data, setData, post, processing, errors } = useForm({
        dockerComposeRaw: defaultDockerComposeRaw ?? '',
    });

    function submit(e) {
        e.preventDefault();
        post(submitUrl);
    }

    return (
        <form onSubmit={submit}>
            <h1>New Resource</h1>
            <div className="pb-4">Docker Compose</div>
            <MonacoEditor value={data.dockerComposeRaw} onChange={(value) => setData('dockerComposeRaw', value)} language="yaml" />
            {errors.dockerComposeRaw && <span className="text-error">{errors.dockerComposeRaw}</span>}
            <button type="submit" disabled={processing} className="mt-4">
                Save
            </button>
        </form>
    );
}
