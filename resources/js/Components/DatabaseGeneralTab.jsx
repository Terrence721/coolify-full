import { router } from '@inertiajs/react';
import { useState } from 'react';
import ResourceDetailsModal from './ResourceDetailsModal';
import { useTeamChannel } from '../hooks/useTeamChannel';

/**
 * React port of the 8 per-engine General tabs (App\Livewire\Project\Database\{Postgresql,
 * Mysql,Mariadb,Mongodb,Redis,Keydb,Dragonfly,Clickhouse}\General + their StatusInfo
 * children + Postgres's InitScript family) — the last Database Configuration tab
 * (Phase 62), driven entirely by the `generalForm` prop's per-engine field list rather
 * than per-engine React components. See ManagesDatabaseGeneralForm.
 *
 * Known v1 gap: the original's "Proxy Logs" slide-over (the `-proxy` sibling container's
 * logs) has no ported equivalent here and is not linked — a real gap, not an oversight.
 */
function Field({ label, helper, ...props }) {
    return (
        <label className="flex flex-col flex-1 gap-1">
            {label && <span title={helper}>{label}</span>}
            <input {...props} />
        </label>
    );
}

function CredentialField({ id, field, value, onChange, canUpdate }) {
    return (
        <Field
            id={id}
            name={id}
            label={field.label}
            type={field.type}
            placeholder={field.placeholder}
            helper={field.helper}
            readOnly={field.readonly}
            disabled={!canUpdate}
            value={value}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

function StatusInfoSection({ statusInfo, sslUrls, canUpdate }) {
    const [enableSsl, setEnableSsl] = useState(statusInfo.enableSsl);
    const [sslMode, setSslMode] = useState(statusInfo.sslMode ?? '');
    const [confirmingRegenerate, setConfirmingRegenerate] = useState(false);

    function saveSsl(next) {
        router.patch(sslUrls.updateSsl, { enableSsl: next.enableSsl, sslMode: next.sslMode }, { preserveScroll: true });
    }

    function toggleSsl(checked) {
        setEnableSsl(checked);
        saveSsl({ enableSsl: checked, sslMode });
    }

    function changeSslMode(value) {
        setSslMode(value);
        saveSsl({ enableSsl, sslMode: value });
    }

    const canToggleSsl = statusInfo.isExited;
    const validUntil = statusInfo.certificateValidUntil ? new Date(statusInfo.certificateValidUntil) : null;
    const now = new Date();
    const expired = validUntil && now > validUntil;
    const expiringSoon = validUntil && !expired && new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000) > validUntil;

    return (
        <div className="flex flex-col gap-2">
            <Field
                id="db-general-status-url-internal"
                name="db-general-status-url-internal"
                label={`${statusInfo.label} URL (internal)`}
                type="password"
                readOnly
                value={statusInfo.dbUrl ?? ''}
                helper="If you change the user/password/port, this could be different. This is with the default values."
            />
            {statusInfo.dbUrlPublic ? (
                <Field
                    id="db-general-status-url-public"
                    name="db-general-status-url-public"
                    label={`${statusInfo.label} URL (public)`}
                    type="password"
                    readOnly
                    value={statusInfo.dbUrlPublic}
                />
            ) : (
                statusInfo.showPublicUrlPlaceholder && (
                    <Field
                        id="db-general-status-url-public"
                        name="db-general-status-url-public"
                        label={`${statusInfo.label} URL (public)`}
                        readOnly
                        value="Starting the database will generate this."
                    />
                )
            )}

            {statusInfo.supportsSsl && (
                <div className="flex flex-col gap-2 pt-4">
                    <div className="flex items-center justify-between py-2">
                        <h3>SSL Configuration</h3>
                        {enableSsl && validUntil && (
                            <button type="button" onClick={() => setConfirmingRegenerate(true)}>
                                Regenerate SSL Certificates
                            </button>
                        )}
                    </div>
                    {enableSsl && validUntil && (
                        <span className="text-sm">
                            Valid until:{' '}
                            <span className={expired || expiringSoon ? 'text-red-500' : ''}>
                                {validUntil.toLocaleString()}
                                {expired ? ' - Expired' : expiringSoon ? ' - Expiring soon' : ''}
                            </span>
                        </span>
                    )}
                    <div className="flex flex-col gap-2">
                        <label className="flex items-center gap-2 w-64">
                            <input
                                id="enableSsl"
                                name="enableSsl"
                                type="checkbox"
                                checked={enableSsl}
                                disabled={!canUpdate || !canToggleSsl}
                                onChange={(e) => toggleSsl(e.target.checked)}
                            />
                            <span title={!canToggleSsl ? 'Database should be stopped to change this setting.' : undefined}>Enable SSL</span>
                        </label>
                        {statusInfo.sslModeOptions && enableSsl && (
                            <label className="flex flex-col gap-1 mx-2">
                                <span title={statusInfo.sslModeHelper}>SSL Mode</span>
                                <select
                                    id="sslMode"
                                    name="sslMode"
                                    value={sslMode}
                                    disabled={!canUpdate || !canToggleSsl}
                                    onChange={(e) => changeSslMode(e.target.value)}
                                >
                                    {Object.entries(statusInfo.sslModeOptions).map(([value, option]) => (
                                        <option key={value} value={value} title={option.title}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                </div>
            )}

            {confirmingRegenerate && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setConfirmingRegenerate(false)} />
                    <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">Regenerate SSL Certificates</h3>
                            <button type="button" onClick={() => setConfirmingRegenerate(false)}>
                                ✕
                            </button>
                        </div>
                        <ul className="list-disc pl-4 text-sm pb-4">
                            <li>The SSL certificate of this database will be regenerated.</li>
                            <li>You must restart the database after regenerating the certificate to start using the new certificate.</li>
                        </ul>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setConfirmingRegenerate(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setConfirmingRegenerate(false);
                                    router.post(sslUrls.regenerateSsl, {}, { preserveScroll: true });
                                }}
                            >
                                Regenerate SSL Certificates
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

function ProxySection({ generalForm, generalUrls, canUpdate }) {
    const [isPublic, setIsPublic] = useState(generalForm.isPublic);
    const [publicPort, setPublicPort] = useState(generalForm.publicPort ?? '');
    const [publicPortTimeout, setPublicPortTimeout] = useState(generalForm.publicPortTimeout ?? 3600);

    function toggle(checked) {
        setIsPublic(checked);
        router.patch(generalUrls.updateProxy, { isPublic: checked, publicPort, publicPortTimeout }, { preserveScroll: true });
    }

    function saveConnection(e) {
        e.preventDefault();
        router.patch(generalUrls.updateProxy, { isPublic, publicPort, publicPortTimeout }, { preserveScroll: true });
    }

    return (
        <div className="flex flex-col gap-2">
            <h3 className="py-2">Proxy</h3>
            <label className="flex items-center gap-2 w-64">
                <input id="isPublic" name="isPublic" type="checkbox" checked={isPublic} disabled={!canUpdate} onChange={(e) => toggle(e.target.checked)} />
                Make it publicly available
            </label>
            <form onSubmit={saveConnection} className="flex flex-col gap-2">
                <Field
                    id="db-general-public-port"
                    name="db-general-public-port"
                    type="number"
                    placeholder="5432"
                    label="Public Port"
                    disabled={isPublic || !canUpdate}
                    value={publicPort}
                    onChange={(e) => setPublicPort(e.target.value)}
                />
                <Field
                    id="db-general-public-port-timeout"
                    name="db-general-public-port-timeout"
                    type="number"
                    placeholder="3600"
                    label="Proxy Timeout (seconds)"
                    helper="Timeout for the public TCP proxy connection in seconds. Default: 3600 (1 hour)."
                    disabled={isPublic || !canUpdate}
                    value={publicPortTimeout}
                    onChange={(e) => setPublicPortTimeout(e.target.value)}
                />
            </form>
        </div>
    );
}

function InitScriptCard({ script, onSave, onDelete, canUpdate }) {
    const [filename, setFilename] = useState(script.filename);
    const [content, setContent] = useState(script.content);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [confirmText, setConfirmText] = useState('');

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({ index: script.index, filename, content });
            }}
            className="flex flex-col gap-2 p-4 bg-white border dark:bg-coolgray-100 dark:border-coolgray-300 border-neutral-200"
        >
            <div className="flex items-end gap-2">
                <Field
                    id={`init-script-${script.index}-filename`}
                    name={`init-script-${script.index}-filename`}
                    label="Filename"
                    value={filename}
                    onChange={(e) => setFilename(e.target.value)}
                    disabled={!canUpdate}
                />
                {canUpdate && <button type="submit">Save</button>}
                {canUpdate && (
                    <button type="button" className="button-error" onClick={() => setConfirmingDelete(true)}>
                        Delete
                    </button>
                )}
            </div>
            <label className="flex flex-col gap-1">
                Content
                <textarea
                    id={`init-script-${script.index}-content`}
                    name={`init-script-${script.index}-content`}
                    rows={8}
                    className="font-mono"
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    disabled={!canUpdate}
                />
            </label>

            {confirmingDelete && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setConfirmingDelete(false)} />
                    <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <h3 className="text-2xl font-bold pb-4">Confirm init-script deletion?</h3>
                        <ul className="list-disc pl-4 text-sm pb-2">
                            <li>The init-script of this database will be permanently deleted from the database and the server.</li>
                            <li>If you are actively using this init-script, it could cause errors on redeployment.</li>
                        </ul>
                        <label className="flex flex-col gap-1 pt-2">
                            Please confirm the execution of the actions by entering the init-script name below
                            <input
                                id={`init-script-${script.index}-delete-confirm`}
                                name={`init-script-${script.index}-delete-confirm`}
                                value={confirmText}
                                onChange={(e) => setConfirmText(e.target.value)}
                                placeholder={filename}
                            />
                        </label>
                        <div className="flex justify-end gap-2 pt-4">
                            <button type="button" onClick={() => setConfirmingDelete(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="text-error"
                                disabled={confirmText !== filename}
                                onClick={() => {
                                    setConfirmingDelete(false);
                                    onDelete(filename);
                                }}
                            >
                                Permanently Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </form>
    );
}

function InitScriptsSection({ initScripts, generalUrls, canUpdate }) {
    const [adding, setAdding] = useState(false);
    const [newFilename, setNewFilename] = useState('');
    const [newContent, setNewContent] = useState('');

    function save(script) {
        router.post(generalUrls.initScriptStore, script, { preserveScroll: true });
    }

    function destroy(filename) {
        router.delete(generalUrls.initScriptDestroy, { data: { filename }, preserveScroll: true });
    }

    function addNew(e) {
        e.preventDefault();
        save({ filename: newFilename, content: newContent });
        setAdding(false);
        setNewFilename('');
        setNewContent('');
    }

    return (
        <div className="pb-16">
            <div className="flex items-center gap-2 pb-2">
                <h3>Initialization scripts</h3>
                {canUpdate && (
                    <button type="button" onClick={() => setAdding(true)}>
                        + Add
                    </button>
                )}
            </div>
            <div className="flex flex-col gap-2">
                {initScripts.length === 0 && <div>No initialization scripts found.</div>}
                {initScripts.map((script) => (
                    <InitScriptCard key={script.index} script={script} onSave={save} onDelete={destroy} canUpdate={canUpdate} />
                ))}
            </div>

            {adding && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setAdding(false)} />
                    <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-xl">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">New Init Script</h3>
                            <button type="button" onClick={() => setAdding(false)}>
                                ✕
                            </button>
                        </div>
                        <form className="flex flex-col w-full gap-2" onSubmit={addNew}>
                            <Field
                                id="init-script-new-filename"
                                name="init-script-new-filename"
                                label="Filename"
                                required
                                placeholder="create_test_db.sql"
                                value={newFilename}
                                onChange={(e) => setNewFilename(e.target.value)}
                            />
                            <label className="flex flex-col gap-1">
                                Content
                                <textarea
                                    id="init-script-new-content"
                                    name="init-script-new-content"
                                    rows={12}
                                    required
                                    className="font-mono"
                                    placeholder="CREATE DATABASE test;"
                                    value={newContent}
                                    onChange={(e) => setNewContent(e.target.value)}
                                />
                            </label>
                            <button type="submit">Save</button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function DatabaseGeneralTab({ generalForm, generalUrls, resourceDetails }) {
    const canUpdate = generalForm.canUpdate;
    const [form, setForm] = useState({
        name: generalForm.name ?? '',
        description: generalForm.description ?? '',
        image: generalForm.image ?? '',
        customDockerRunOptions: generalForm.customDockerRunOptions ?? '',
        portsMappings: generalForm.portsMappings ?? '',
        configValue: generalForm.configField?.value ?? '',
    });
    const [credentials, setCredentials] = useState(() => Object.fromEntries(generalForm.credentials.map((f) => [f.prop, f.value ?? ''])));
    const [showingDetails, setShowingDetails] = useState(false);
    const [isLogDrainEnabled, setIsLogDrainEnabled] = useState(generalForm.isLogDrainEnabled);

    useTeamChannel(['ServiceStatusChanged', 'ServiceChecked'], () => {
        router.reload({ only: ['generalForm'], preserveScroll: true });
    });

    function submit(e) {
        e.preventDefault();
        const payload = { ...form, ...credentials };
        if (generalForm.configField) {
            payload[generalForm.configField.prop] = form.configValue;
        }
        router.patch(generalUrls.update, payload, { preserveScroll: true });
    }

    function toggleLogDrain(checked) {
        setIsLogDrainEnabled(checked);
        router.patch(generalUrls.updateAdvanced, { isLogDrainEnabled: checked }, { preserveScroll: true });
    }

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col gap-2">
                <div className="flex items-center gap-2">
                    <h2>General</h2>
                    {canUpdate && <button type="submit">Save</button>}
                    <button type="button" onClick={() => setShowingDetails(true)}>
                        Details
                    </button>
                </div>
                <div className="flex flex-wrap gap-2 sm:flex-nowrap">
                    <Field
                        id="db-general-name"
                        name="db-general-name"
                        label="Name"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        disabled={!canUpdate}
                    />
                    <Field
                        id="db-general-description"
                        name="db-general-description"
                        label="Description"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        disabled={!canUpdate}
                    />
                    <Field
                        id="db-general-image"
                        name="db-general-image"
                        label="Image"
                        required
                        helper={generalForm.dockerHubUrl ? `For all available images, check here: ${generalForm.dockerHubUrl}` : undefined}
                        value={form.image}
                        onChange={(e) => setForm({ ...form, image: e.target.value })}
                        disabled={!canUpdate}
                    />
                </div>
                <div className="pt-2 dark:text-warning">
                    If you change the values in the database, please sync it here, otherwise automations (like backups) won't work.
                </div>

                <div className="flex xl:flex-row flex-col gap-2">
                    {generalForm.credentials.map((field) => (
                        <CredentialField
                            key={field.prop}
                            id={`db-general-credential-${field.prop}`}
                            field={field}
                            value={credentials[field.prop]}
                            onChange={(value) => setCredentials({ ...credentials, [field.prop]: value })}
                            canUpdate={canUpdate}
                        />
                    ))}
                </div>

                <Field
                    id="db-general-custom-docker-run-options"
                    name="db-general-custom-docker-run-options"
                    label="Custom Docker Options"
                    helper="You can add custom docker run options that will be used when your container is started. Not all options are supported."
                    placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                    value={form.customDockerRunOptions}
                    onChange={(e) => setForm({ ...form, customDockerRunOptions: e.target.value })}
                    disabled={!canUpdate}
                />

                <div className="flex flex-col gap-2">
                    <h3 className="py-2">Network</h3>
                    <Field
                        id="db-general-ports-mappings"
                        name="db-general-ports-mappings"
                        placeholder="3000:5432"
                        label="Ports Mappings"
                        helper="A comma separated list of ports you would like to map to the host system. Example: 3000:5432,3002:5433"
                        value={form.portsMappings}
                        onChange={(e) => setForm({ ...form, portsMappings: e.target.value })}
                        disabled={!canUpdate}
                    />
                </div>

                <StatusInfoSection statusInfo={generalForm.statusInfo} sslUrls={generalUrls} canUpdate={canUpdate} />
                <ProxySection generalForm={generalForm} generalUrls={generalUrls} canUpdate={canUpdate} />

                {generalForm.configField && (
                    <label className="flex flex-col gap-1">
                        <span title={generalForm.configField.helper}>{generalForm.configField.label}</span>
                        <textarea
                            id="db-general-config-value"
                            name="db-general-config-value"
                            rows={10}
                            className="font-mono"
                            value={form.configValue}
                            onChange={(e) => setForm({ ...form, configValue: e.target.value })}
                            disabled={!canUpdate}
                        />
                    </label>
                )}
            </form>

            <div className="flex flex-col gap-4 pt-4">
                <h3>Advanced</h3>
                <label className="flex items-center gap-2 w-64">
                    <input id="isLogDrainEnabled" name="isLogDrainEnabled" type="checkbox" checked={isLogDrainEnabled} disabled={!canUpdate} onChange={(e) => toggleLogDrain(e.target.checked)} />
                    <span title="Drain logs to your configured log drain endpoint in your Server settings.">Drain Logs</span>
                </label>

                {generalForm.initScripts !== null && (
                    <InitScriptsSection initScripts={generalForm.initScripts} generalUrls={generalUrls} canUpdate={canUpdate} />
                )}
            </div>

            {showingDetails && <ResourceDetailsModal details={resourceDetails} onClose={() => setShowingDetails(false)} />}
        </div>
    );
}
