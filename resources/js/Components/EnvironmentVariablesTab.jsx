import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * React port of the Project\Shared\EnvironmentVariable\{All,Add,Show,ShowHardcoded} Livewire
 * family, scoped to standalone databases and services (Phase 56) — Application-only modes
 * (preview variables, build secrets, sort-alphabetically) are not here. Search filters
 * client-side (the original's server-side search existed for Livewire round-trips). Known v1
 * gap: the original's `{{` shared-variable autocomplete dropdown is not ported — the
 * {{team.KEY}} syntax itself still works typed by hand, and the available keys are listed
 * under the Add form as plain text.
 *
 * `resourceType`: 'service' shows the service checkbox set (multiline/literal) and hardcoded
 * compose variables; anything else gets the buildtime/runtime/multiline/literal set.
 */
function PasswordInput({ id, name, value, onChange, disabled, required, placeholder }) {
    const [show, setShow] = useState(false);

    return (
        <div className="flex flex-1 gap-1">
            <input
                id={id}
                name={name}
                className="w-full"
                type={show ? 'text' : 'password'}
                value={value ?? ''}
                disabled={disabled}
                required={required}
                placeholder={placeholder}
                onChange={(e) => onChange?.(e.target.value)}
            />
            <button type="button" tabIndex={-1} onClick={() => setShow(!show)} title={show ? 'Hide' : 'Show'}>
                {show ? '🙈' : '👁'}
            </button>
        </div>
    );
}

function Checkbox({ id, label, checked, onChange, disabled, title }) {
    return (
        <label className="flex items-center gap-2" title={title}>
            <input id={id} type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange?.(e.target.checked)} />
            {label}
        </label>
    );
}

function EnvCard({ env, resourceType, canManage, problematicVariables }) {
    const [form, setForm] = useState({
        key: env.key,
        value: env.value ?? '',
        comment: env.comment ?? '',
        is_multiline: env.isMultiline,
        is_literal: env.isLiteral,
        is_runtime: env.isRuntime,
        is_buildtime: env.isBuildtime,
    });
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleteConfirmation, setDeleteConfirmation] = useState('');

    const editable = canManage && !env.isMagic && !env.isLocked;
    const problem = problematicVariables?.[form.key];

    function set(key, value) {
        setForm((prev) => ({ ...prev, [key]: value }));
    }

    function update(overrides = {}) {
        router.patch(env.urls.update, { ...form, ...overrides }, { preserveScroll: true });
    }

    function toggle(key, value) {
        set(key, value);
        update({ [key]: value });
    }

    function lock() {
        router.post(env.urls.lock, {}, { preserveScroll: true });
    }

    function destroy() {
        router.delete(env.urls.destroy, { preserveScroll: true });
    }

    const checkboxes =
        resourceType === 'service' ? (
            !env.isMagic && (
                <>
                    <Checkbox
                        id={`env-${env.id}-multiline`}
                        label="Is Multiline?"
                        checked={form.is_multiline}
                        disabled={!editable}
                        onChange={(v) => toggle('is_multiline', v)}
                    />
                    <Checkbox
                        id={`env-${env.id}-literal`}
                        label="Is Literal?"
                        checked={form.is_literal}
                        disabled={!editable}
                        onChange={(v) => toggle('is_literal', v)}
                    />
                </>
            )
        ) : (
            <>
                {!env.isBuildpackControl && (
                    <Checkbox
                        id={`env-${env.id}-buildtime`}
                        label="Available at Buildtime"
                        title="Make this variable available during the Docker build process."
                        checked={form.is_buildtime}
                        disabled={!editable}
                        onChange={(v) => toggle('is_buildtime', v)}
                    />
                )}
                <Checkbox
                    id={`env-${env.id}-runtime`}
                    label="Available at Runtime"
                    title="Make this variable available in the running container."
                    checked={form.is_runtime}
                    disabled={!editable}
                    onChange={(v) => toggle('is_runtime', v)}
                />
                {!env.isBuildpackControl && (
                    <>
                        <Checkbox
                            id={`env-${env.id}-multiline`}
                            label="Is Multiline?"
                            checked={form.is_multiline}
                            disabled={!editable}
                            onChange={(v) => toggle('is_multiline', v)}
                        />
                        {!form.is_multiline && (
                            <Checkbox
                                id={`env-${env.id}-literal`}
                                label="Is Literal?"
                                checked={form.is_literal}
                                disabled={!editable}
                                onChange={(v) => toggle('is_literal', v)}
                            />
                        )}
                    </>
                )}
            </>
        );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                update();
            }}
            className={`flex flex-col gap-3 p-4 bg-white border dark:bg-base ${
                env.isReallyRequired ? 'border-error' : 'dark:border-coolgray-300 border-neutral-200'
            }`}
        >
            <div className="flex flex-col w-full gap-2 lg:flex-row">
                <input
                    id={`env-${env.id}-key`}
                    name={`env-${env.id}-key`}
                    className="flex-1"
                    value={form.key}
                    disabled={!editable || env.isRedisCredential}
                    onChange={(e) => set('key', e.target.value)}
                />
                {env.isLocked ? (
                    <div className="flex items-center gap-1 text-sm dark:text-warning" title="Locked secret — delete and add again to change">
                        🔒 Locked
                    </div>
                ) : form.is_multiline ? (
                    <textarea
                        id={`env-${env.id}-value`}
                        name={`env-${env.id}-value`}
                        className="flex-1 font-mono"
                        rows={4}
                        value={form.value}
                        disabled={!editable}
                        onChange={(e) => set('value', e.target.value)}
                    />
                ) : (
                    <PasswordInput
                        id={`env-${env.id}-value`}
                        name={`env-${env.id}-value`}
                        value={form.value}
                        disabled={!editable}
                        required={env.isRedisCredential || env.isRequired}
                        placeholder={env.isMagic ? 'Handled by Coolify.' : ''}
                        onChange={(v) => set('value', v)}
                    />
                )}
                {env.isShared && !env.isLocked && (
                    <PasswordInput
                        id={`env-${env.id}-resolved-value`}
                        name={`env-${env.id}-resolved-value`}
                        value={env.realValue ?? ''}
                        disabled
                        placeholder="(resolved value)"
                    />
                )}
            </div>
            <input
                id={`env-${env.id}-comment`}
                name={`env-${env.id}-comment`}
                placeholder={env.isMagic ? 'This env cannot be edited manually, it is handled by Coolify.' : 'Comment'}
                maxLength={256}
                value={form.comment}
                disabled={!canManage}
                onChange={(e) => set('comment', e.target.value)}
                title="Add a note to document what this environment variable is used for."
            />
            <div className="flex flex-wrap items-center gap-4">{!env.isRedisCredential && checkboxes}</div>
            {problem && form.is_buildtime && (
                <div className="text-sm dark:text-warning">⚠ {problem.issue}</div>
            )}
            {canManage && (
                <div className="flex w-full justify-end gap-2">
                    {(!env.isMagic || env.isLocked) && (
                        <button type="submit">Update</button>
                    )}
                    {!env.isLocked && <button type="button" onClick={lock}>Lock</button>}
                    {!confirmingDelete ? (
                        <button type="button" className="button-error" onClick={() => setConfirmingDelete(true)}>
                            Delete
                        </button>
                    ) : (
                        <span className="flex items-center gap-2">
                            <input
                                id={`env-${env.id}-delete-confirm`}
                                name={`env-${env.id}-delete-confirm`}
                                placeholder={`Type "${env.key}" to confirm`}
                                value={deleteConfirmation}
                                onChange={(e) => setDeleteConfirmation(e.target.value)}
                            />
                            <button type="button" className="button-error" disabled={deleteConfirmation !== env.key} onClick={destroy}>
                                Permanently Delete
                            </button>
                            <button type="button" onClick={() => setConfirmingDelete(false)}>
                                Cancel
                            </button>
                        </span>
                    )}
                </div>
            )}
        </form>
    );
}

function HardcodedEnvCard({ env, idBase }) {
    return (
        <div className="flex flex-col gap-2 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
            <div className="flex flex-wrap items-center gap-2">
                <span className="px-2 py-0.5 text-xs rounded dark:bg-coolgray-400/50 bg-neutral-200 dark:text-neutral-400">Hardcoded env</span>
                {env.service_name && (
                    <span className="px-2 py-0.5 text-xs rounded dark:bg-coolgray-400/50 bg-neutral-200 dark:text-neutral-400">
                        Service: {env.service_name}
                    </span>
                )}
            </div>
            <div className="flex flex-col w-full gap-2 lg:flex-row">
                <input id={`${idBase}-key`} name={`${idBase}-key`} className="flex-1" disabled value={env.key} />
                {env.value ? (
                    <PasswordInput id={`${idBase}-value`} name={`${idBase}-value`} value={env.value} disabled />
                ) : (
                    <input id={`${idBase}-value`} name={`${idBase}-value`} className="flex-1" disabled value="(inherited from host)" />
                )}
            </div>
        </div>
    );
}

function AddModal({ open, onClose, storeUrl, resourceType, availableSharedVariables }) {
    const [form, setForm] = useState({
        key: '',
        value: '',
        comment: '',
        is_multiline: false,
        is_literal: false,
        is_runtime: true,
        is_buildtime: true,
    });
    const [processing, setProcessing] = useState(false);

    const sharedKeys = [
        ...(availableSharedVariables?.team ?? []).map((k) => `{{team.${k}}}`),
        ...(availableSharedVariables?.project ?? []).map((k) => `{{project.${k}}}`),
        ...(availableSharedVariables?.environment ?? []).map((k) => `{{environment.${k}}}`),
        ...(availableSharedVariables?.server ?? []).map((k) => `{{server.${k}}}`),
    ];

    function submit(e) {
        e.preventDefault();
        setProcessing(true);
        router.post(storeUrl, form, {
            preserveScroll: true,
            onSuccess: () => {
                setForm({ key: '', value: '', comment: '', is_multiline: false, is_literal: false, is_runtime: true, is_buildtime: true });
                onClose();
            },
            onFinish: () => setProcessing(false),
        });
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New Environment Variable</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col w-full gap-2" onSubmit={submit}>
                    <label className="flex flex-col gap-1">
                        Name
                        <input
                            id="env-add-key"
                            name="env-add-key"
                            required
                            placeholder="NODE_ENV"
                            value={form.key}
                            onChange={(e) => setForm({ ...form, key: e.target.value })}
                        />
                    </label>
                    <label className="flex flex-col gap-1">
                        Value
                        {form.is_multiline ? (
                            <textarea
                                id="env-add-value"
                                name="env-add-value"
                                required
                                rows={5}
                                className="font-mono"
                                value={form.value}
                                onChange={(e) => setForm({ ...form, value: e.target.value })}
                            />
                        ) : (
                            <input
                                id="env-add-value"
                                name="env-add-value"
                                required
                                placeholder="production"
                                value={form.value}
                                onChange={(e) => setForm({ ...form, value: e.target.value })}
                            />
                        )}
                    </label>
                    {sharedKeys.length > 0 && (
                        <div className="text-xs dark:text-neutral-400">
                            Shared variables you can reference: <span className="font-mono">{sharedKeys.join(', ')}</span>
                        </div>
                    )}
                    <label className="flex flex-col gap-1">
                        Comment
                        <input
                            id="env-add-comment"
                            name="env-add-comment"
                            maxLength={256}
                            value={form.comment}
                            onChange={(e) => setForm({ ...form, comment: e.target.value })}
                        />
                    </label>
                    {resourceType !== 'service' && (
                        <>
                            <Checkbox
                                id="env-add-buildtime"
                                label="Available at Buildtime"
                                checked={form.is_buildtime}
                                onChange={(v) => setForm({ ...form, is_buildtime: v })}
                            />
                            <Checkbox
                                id="env-add-runtime"
                                label="Available at Runtime"
                                checked={form.is_runtime}
                                onChange={(v) => setForm({ ...form, is_runtime: v })}
                            />
                            <Checkbox
                                id="env-add-literal"
                                label="Is Literal?"
                                checked={form.is_literal}
                                onChange={(v) => setForm({ ...form, is_literal: v })}
                            />
                        </>
                    )}
                    <Checkbox
                        id="env-add-multiline"
                        label="Is Multiline?"
                        checked={form.is_multiline}
                        onChange={(v) => setForm({ ...form, is_multiline: v })}
                    />
                    <button type="submit" className="mt-2" disabled={processing}>
                        Save
                    </button>
                </form>
            </div>
        </div>
    );
}

export default function EnvironmentVariablesTab({
    envs,
    hardcodedEnvs,
    devEnvs,
    canManageEnvironment,
    problematicVariables,
    availableSharedVariables,
    envUrls,
    resourceType,
}) {
    const [view, setView] = useState('normal');
    const [search, setSearch] = useState('');
    const [addOpen, setAddOpen] = useState(false);
    const [devText, setDevText] = useState(devEnvs ?? '');

    const term = search.trim().toLowerCase();
    const filteredEnvs = useMemo(() => (term ? envs.filter((env) => env.key.toLowerCase().includes(term)) : envs), [envs, term]);
    const filteredHardcoded = useMemo(
        () => (term ? hardcodedEnvs.filter((env) => env.key.toLowerCase().includes(term)) : hardcodedEnvs),
        [hardcodedEnvs, term],
    );

    function saveDev(e) {
        e.preventDefault();
        router.patch(envUrls.bulkUpdate, { variables: devText }, { preserveScroll: true });
    }

    return (
        <div className="flex flex-col gap-4">
            <div>
                <div className="flex items-center gap-2">
                    <h2>Environment Variables</h2>
                    {canManageEnvironment && (
                        <>
                            <button type="button" onClick={() => setAddOpen(true)}>
                                + Add
                            </button>
                            <button type="button" onClick={() => setView(view === 'normal' ? 'dev' : 'normal')}>
                                {view === 'normal' ? 'Developer view' : 'Normal view'}
                            </button>
                        </>
                    )}
                </div>
                <div>Environment variables (secrets) for this resource.</div>
            </div>

            {view === 'normal' ? (
                <>
                    <input
                        id="env-search"
                        name="env-search"
                        type="search"
                        className="w-full md:w-96"
                        placeholder="Search"
                        aria-label="Search environment variables"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    {filteredEnvs.length === 0 && filteredHardcoded.length === 0 ? (
                        <div>No environment variables found.</div>
                    ) : (
                        <>
                            <div>
                                <h3>Production Environment Variables</h3>
                                <div>Environment (secrets) variables for Production.</div>
                            </div>
                            {filteredEnvs.map((env) => (
                                <EnvCard
                                    key={env.id}
                                    env={env}
                                    resourceType={resourceType}
                                    canManage={canManageEnvironment}
                                    problematicVariables={problematicVariables}
                                />
                            ))}
                            {filteredHardcoded.map((env, index) => {
                                const idBase = `hardcoded-env-${env.key}-${env.service_name ?? 'default'}-${index}`;
                                return <HardcodedEnvCard key={idBase} env={env} idBase={idBase} />;
                            })}
                        </>
                    )}
                </>
            ) : (
                <form onSubmit={saveDev} className="flex flex-col gap-2">
                    <div className="text-sm dark:text-neutral-400">
                        Note: inline comments with a space before # (e.g. <span className="font-mono">KEY=value #comment</span>) are stripped.
                    </div>
                    <label className="flex flex-col gap-1">
                        Production Environment Variables
                        <textarea
                            id="env-dev-view"
                            name="env-dev-view"
                            rows={10}
                            className="whitespace-pre-wrap font-mono"
                            value={devText}
                            disabled={!canManageEnvironment}
                            onChange={(e) => setDevText(e.target.value)}
                        />
                    </label>
                    {canManageEnvironment && <button type="submit">Save All Environment Variables</button>}
                </form>
            )}

            <AddModal
                open={addOpen}
                onClose={() => setAddOpen(false)}
                storeUrl={envUrls?.store}
                resourceType={resourceType}
                availableSharedVariables={availableSharedVariables}
            />
        </div>
    );
}
