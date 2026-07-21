import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * React port of the database Healthcheck tab (Project\Database\Health) — the four Docker
 * probe numbers plus the enable/disable toggle, with the original's confirm-before-enable
 * modal and disabled-state warning callout. See
 * ProjectDatabaseConfigurationController::updateHealthcheck()/toggleHealthcheck().
 */
function Field({ label, ...props }) {
    return (
        <label className="flex flex-col flex-1 gap-1">
            {label}
            <input {...props} />
        </label>
    );
}

export default function DatabaseHealthcheckTab({ healthcheck, healthcheckUrls, canUpdate }) {
    const [form, setForm] = useState({
        interval: healthcheck.interval ?? 15,
        timeout: healthcheck.timeout ?? 5,
        retries: healthcheck.retries ?? 5,
        startPeriod: healthcheck.startPeriod ?? 5,
    });
    const [confirmingEnable, setConfirmingEnable] = useState(false);

    function submit(e) {
        e.preventDefault();
        router.patch(healthcheckUrls.update, { ...form, enabled: healthcheck.enabled }, { preserveScroll: true });
    }

    function toggle() {
        setConfirmingEnable(false);
        router.post(healthcheckUrls.toggle, {}, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-col">
            <div className="flex items-center gap-2 flex-wrap">
                <h2>Healthcheck</h2>
                {canUpdate && (
                    <>
                        <button type="submit">Save</button>
                        {healthcheck.enabled ? (
                            <button type="button" onClick={toggle}>
                                Disable Healthcheck
                            </button>
                        ) : (
                            <button type="button" onClick={() => setConfirmingEnable(true)}>
                                Enable Healthcheck
                            </button>
                        )}
                    </>
                )}
            </div>
            <div className="mt-1 pb-4">Define how your resource&apos;s health should be checked.</div>
            <div className="flex flex-col gap-4">
                {!healthcheck.enabled && (
                    <div className="w-full p-3 text-sm rounded bg-warning/10 text-warning">
                        <strong>Healthcheck disabled.</strong> Docker runs no healthcheck probe for this database and Coolify can no longer report a
                        healthy/unhealthy state.
                    </div>
                )}
                <div className="flex flex-col gap-2 md:flex-row">
                    <Field
                        id="db-healthcheck-interval"
                        name="db-healthcheck-interval"
                        label="Interval (s)"
                        type="number"
                        min={1}
                        required
                        placeholder="15"
                        value={form.interval}
                        onChange={(e) => setForm({ ...form, interval: e.target.value })}
                        disabled={!canUpdate}
                    />
                    <Field
                        id="db-healthcheck-timeout"
                        name="db-healthcheck-timeout"
                        label="Timeout (s)"
                        type="number"
                        min={1}
                        required
                        placeholder="5"
                        value={form.timeout}
                        onChange={(e) => setForm({ ...form, timeout: e.target.value })}
                        disabled={!canUpdate}
                    />
                    <Field
                        id="db-healthcheck-retries"
                        name="db-healthcheck-retries"
                        label="Retries"
                        type="number"
                        min={1}
                        required
                        placeholder="5"
                        value={form.retries}
                        onChange={(e) => setForm({ ...form, retries: e.target.value })}
                        disabled={!canUpdate}
                    />
                    <Field
                        id="db-healthcheck-start-period"
                        name="db-healthcheck-start-period"
                        label="Start Period (s)"
                        type="number"
                        min={0}
                        required
                        placeholder="5"
                        value={form.startPeriod}
                        onChange={(e) => setForm({ ...form, startPeriod: e.target.value })}
                        disabled={!canUpdate}
                    />
                </div>
            </div>

            {confirmingEnable && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setConfirmingEnable(false)} />
                    <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">Confirm Healthcheck Enable?</h3>
                            <button type="button" onClick={() => setConfirmingEnable(false)}>
                                ✕
                            </button>
                        </div>
                        <ul className="list-disc pl-4 text-sm pb-2">
                            <li>Enable healthcheck for this database.</li>
                        </ul>
                        <div className="p-3 mb-4 text-sm rounded bg-warning/10 text-warning">
                            If the health check fails, this database will be marked unhealthy. Please review the{' '}
                            <a href="https://coolify.io/docs/knowledge-base/health-checks" target="_blank" rel="noreferrer" className="underline">
                                Health Checks
                            </a>{' '}
                            guide before proceeding!
                        </div>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setConfirmingEnable(false)}>
                                Cancel
                            </button>
                            <button type="button" onClick={toggle}>
                                Enable Healthcheck
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </form>
    );
}
