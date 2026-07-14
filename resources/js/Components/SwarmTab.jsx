import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * React port of App\Livewire\Project\Application\Swarm — the deprecated Swarm replicas/
 * placement-constraints/worker-nodes-only form. The original's "Only Start on Worker nodes"
 * checkbox instant-saves on change (wire:model.live + instantSave()) while the other two
 * fields wait for the Save button (submit()) — both methods do the exact same syncData(true)
 * save, so here the checkbox just submits the whole form immediately on change instead of
 * waiting for Save, matching the original's net effect with one endpoint instead of two.
 */
export default function SwarmTab({ swarm, swarmUpdateUrl, canUpdate }) {
    const [form, setForm] = useState({
        swarmReplicas: swarm.swarmReplicas ?? 1,
        swarmPlacementConstraints: swarm.swarmPlacementConstraints ?? '',
        isSwarmOnlyWorkerNodes: swarm.isSwarmOnlyWorkerNodes ?? false,
    });

    function submit(e, overrides = {}) {
        e?.preventDefault?.();
        const payload = { ...form, ...overrides };
        setForm(payload);
        router.patch(swarmUpdateUrl, payload, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-col">
            <div className="flex items-center gap-2">
                <h2>Swarm Configuration</h2>
                <span className="px-2 py-0.5 text-xs font-medium leading-normal rounded-full bg-warning/15 text-warning border border-warning/30">
                    Deprecated
                </span>
                <button type="submit" disabled={!canUpdate} title={!canUpdate ? "You don't have permission to update this application. Contact your team administrator for access." : undefined}>
                    Save
                </button>
            </div>
            <div className="w-full p-3 my-4 text-sm rounded bg-warning/10 text-warning">
                Docker Swarm is deprecated and will be removed in Coolify v5. Coolify v5 will be replacing Swarm with native
                Docker Compose replicas and our own scaling solution. Existing Swarm deployments will continue to work on v4
                as-is. We do not recommend setting up new Swarm deployments for the time being.
            </div>
            <div className="flex flex-col gap-2 py-4">
                <div className="flex flex-col items-end gap-2 xl:flex-row">
                    <label className="flex flex-col flex-1 gap-1">
                        Replicas
                        <input
                            type="number"
                            min={0}
                            required
                            disabled={!canUpdate}
                            value={form.swarmReplicas}
                            onChange={(e) => setForm({ ...form, swarmReplicas: e.target.value })}
                        />
                    </label>
                    <label className="flex items-center gap-2" title="If turned off, this resource will start on manager nodes too.">
                        <input
                            type="checkbox"
                            disabled={!canUpdate}
                            checked={form.isSwarmOnlyWorkerNodes}
                            onChange={(e) => submit(null, { isSwarmOnlyWorkerNodes: e.target.checked })}
                        />
                        Only Start on Worker nodes
                    </label>
                </div>
                <label className="flex flex-col gap-1">
                    Custom Placement Constraints
                    <textarea
                        rows={7}
                        disabled={!canUpdate}
                        placeholder={"placement:\n    constraints:\n        - 'node.role == worker'"}
                        value={form.swarmPlacementConstraints}
                        onChange={(e) => setForm({ ...form, swarmPlacementConstraints: e.target.value })}
                    />
                </label>
            </div>
        </form>
    );
}
