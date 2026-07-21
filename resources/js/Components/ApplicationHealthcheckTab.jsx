import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * React port of App\Livewire\Project\Shared\HealthChecks — the last consumer of that shared
 * component was Application's own Configuration router (Database moved to its own simplified
 * inline version in Phase 60, which lacks the HTTP/CMD type selector since databases have no
 * HTTP endpoint to probe). Reuses DatabaseHealthcheckTab.jsx's confirm-before-enable modal
 * pattern. submit()/instantSave() were identical full-form saves in the original — ported as
 * one endpoint.
 */
function Field({ label, ...props }) {
    return (
        <label className="flex flex-col flex-1 gap-1">
            {label}
            <input {...props} />
        </label>
    );
}

export default function ApplicationHealthcheckTab({ healthcheck, healthcheckUrls, canUpdate }) {
    const [form, setForm] = useState({
        healthCheckType: healthcheck.healthCheckType ?? 'http',
        healthCheckCommand: healthcheck.healthCheckCommand ?? '',
        healthCheckMethod: healthcheck.healthCheckMethod,
        healthCheckScheme: healthcheck.healthCheckScheme,
        healthCheckHost: healthcheck.healthCheckHost,
        healthCheckPort: healthcheck.healthCheckPort ?? '',
        healthCheckPath: healthcheck.healthCheckPath,
        healthCheckReturnCode: healthcheck.healthCheckReturnCode,
        healthCheckResponseText: healthcheck.healthCheckResponseText ?? '',
        healthCheckInterval: healthcheck.healthCheckInterval,
        healthCheckTimeout: healthcheck.healthCheckTimeout,
        healthCheckRetries: healthcheck.healthCheckRetries,
        healthCheckStartPeriod: healthcheck.healthCheckStartPeriod,
    });
    const [confirmingEnable, setConfirmingEnable] = useState(false);

    function submit(e) {
        e.preventDefault();
        router.patch(
            healthcheckUrls.update,
            { ...form, healthCheckEnabled: healthcheck.healthCheckEnabled, customHealthcheckFound: healthcheck.customHealthcheckFound },
            { preserveScroll: true },
        );
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
                        {healthcheck.healthCheckEnabled ? (
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
                {healthcheck.customHealthcheckFound && (
                    <div className="w-full p-3 text-sm rounded bg-warning/10 text-warning">
                        <strong>Caution.</strong> A custom health check has been detected. If you enable this health check, it will disable the custom
                        one and use this instead.
                    </div>
                )}

                <div className="flex gap-2">
                    <label className="flex flex-col gap-1">
                        Type
                        <select
                            id="healthcheck-type"
                            name="healthcheck-type"
                            disabled={!canUpdate}
                            value={form.healthCheckType}
                            onChange={(e) => setForm({ ...form, healthCheckType: e.target.value })}
                        >
                            <option value="http">HTTP</option>
                            <option value="cmd">CMD</option>
                        </select>
                    </label>
                </div>

                {form.healthCheckType === 'http' ? (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <label className="flex flex-col gap-1">
                                Method
                                <select
                                    id="healthcheck-method"
                                    name="healthcheck-method"
                                    disabled={!canUpdate}
                                    value={form.healthCheckMethod}
                                    onChange={(e) => setForm({ ...form, healthCheckMethod: e.target.value })}
                                >
                                    <option value="GET">GET</option>
                                    <option value="POST">POST</option>
                                </select>
                            </label>
                            <label className="flex flex-col gap-1">
                                Scheme
                                <select
                                    id="healthcheck-scheme"
                                    name="healthcheck-scheme"
                                    disabled={!canUpdate}
                                    value={form.healthCheckScheme}
                                    onChange={(e) => setForm({ ...form, healthCheckScheme: e.target.value })}
                                >
                                    <option value="http">http</option>
                                    <option value="https">https</option>
                                </select>
                            </label>
                            <Field
                                id="healthCheckHost"
                                name="healthCheckHost"
                                label="Host"
                                placeholder="localhost"
                                disabled={!canUpdate}
                                value={form.healthCheckHost}
                                onChange={(e) => setForm({ ...form, healthCheckHost: e.target.value })}
                            />
                            <Field
                                id="healthCheckPort"
                                name="healthCheckPort"
                                label="Port"
                                type="number"
                                placeholder="80"
                                disabled={!canUpdate}
                                value={form.healthCheckPort}
                                onChange={(e) => setForm({ ...form, healthCheckPort: e.target.value })}
                            />
                            <Field
                                id="healthCheckPath"
                                name="healthCheckPath"
                                label="Path"
                                placeholder="/health"
                                disabled={!canUpdate}
                                value={form.healthCheckPath}
                                onChange={(e) => setForm({ ...form, healthCheckPath: e.target.value })}
                            />
                        </div>
                        <div className="flex gap-2">
                            <Field
                                id="healthCheckReturnCode"
                                name="healthCheckReturnCode"
                                label="Return Code"
                                type="number"
                                placeholder="200"
                                disabled={!canUpdate}
                                value={form.healthCheckReturnCode}
                                onChange={(e) => setForm({ ...form, healthCheckReturnCode: e.target.value })}
                            />
                            <Field
                                id="healthCheckResponseText"
                                name="healthCheckResponseText"
                                label="Response Text"
                                placeholder="OK"
                                disabled={!canUpdate}
                                value={form.healthCheckResponseText}
                                onChange={(e) => setForm({ ...form, healthCheckResponseText: e.target.value })}
                            />
                        </div>
                    </>
                ) : (
                    <>
                        <div className="w-full p-3 text-sm rounded bg-warning/10 text-warning">
                            <strong>Caution.</strong> This command runs inside the container on every health check interval. Shell operators (;, |,
                            &amp;, $, &gt;, &lt;) are not allowed.
                        </div>
                        <Field
                            id="healthCheckCommand"
                            name="healthCheckCommand"
                            label="Command"
                            placeholder="pg_isready -U postgres"
                            disabled={!canUpdate}
                            value={form.healthCheckCommand}
                            onChange={(e) => setForm({ ...form, healthCheckCommand: e.target.value })}
                        />
                    </>
                )}

                <div className="flex flex-col gap-2 md:flex-row">
                    <Field
                        id="healthCheckInterval"
                        name="healthCheckInterval"
                        label="Interval (s)"
                        type="number"
                        min={1}
                        required
                        placeholder="30"
                        disabled={!canUpdate}
                        value={form.healthCheckInterval}
                        onChange={(e) => setForm({ ...form, healthCheckInterval: e.target.value })}
                    />
                    <Field
                        id="healthCheckTimeout"
                        name="healthCheckTimeout"
                        label="Timeout (s)"
                        type="number"
                        min={1}
                        required
                        placeholder="30"
                        disabled={!canUpdate}
                        value={form.healthCheckTimeout}
                        onChange={(e) => setForm({ ...form, healthCheckTimeout: e.target.value })}
                    />
                    <Field
                        id="healthCheckRetries"
                        name="healthCheckRetries"
                        label="Retries"
                        type="number"
                        min={1}
                        required
                        placeholder="3"
                        disabled={!canUpdate}
                        value={form.healthCheckRetries}
                        onChange={(e) => setForm({ ...form, healthCheckRetries: e.target.value })}
                    />
                    <Field
                        id="healthCheckStartPeriod"
                        name="healthCheckStartPeriod"
                        label="Start Period (s)"
                        type="number"
                        min={0}
                        required
                        placeholder="30"
                        disabled={!canUpdate}
                        value={form.healthCheckStartPeriod}
                        onChange={(e) => setForm({ ...form, healthCheckStartPeriod: e.target.value })}
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
                            <li>Enable healthcheck for this resource.</li>
                        </ul>
                        <div className="p-3 mb-4 text-sm rounded bg-warning/10 text-warning">
                            If the health check fails, your application will become inaccessible. Please review the{' '}
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
