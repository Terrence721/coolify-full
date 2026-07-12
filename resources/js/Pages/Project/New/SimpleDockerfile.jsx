import { useForm } from '@inertiajs/react';
import MonacoEditor from '../../../Components/MonacoEditor';

/**
 * React port of App\Livewire\Project\New\SimpleDockerfile — one of the "Docker Based" creation
 * flows nested inside the "+ New" resource wizard (see Project/Resource/Create.jsx and
 * App\Http\Controllers\ProjectResourceCreateController).
 */
export default function SimpleDockerfile({ defaultDockerfile, submitUrl }) {
    const { data, setData, post, processing, errors } = useForm({
        dockerfile: defaultDockerfile ?? '',
    });

    function submit(e) {
        e.preventDefault();
        post(submitUrl);
    }

    return (
        <form onSubmit={submit}>
            <div className="flex items-center gap-2">
                <h1>New Resource</h1>
            </div>
            <div className="pb-4">Dockerfile</div>
            <MonacoEditor value={data.dockerfile} onChange={(value) => setData('dockerfile', value)} language="dockerfile" />
            {errors.dockerfile && <span className="text-error">{errors.dockerfile}</span>}
            <button type="submit" disabled={processing} className="mt-4">
                Save
            </button>
        </form>
    );
}
