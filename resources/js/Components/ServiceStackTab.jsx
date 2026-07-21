import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DomainConflictModal from './DomainConflictModal';
import ResourceDetailsModal from './ResourceDetailsModal';
import { useTeamChannel } from '../hooks/useTeamChannel';

/**
 * React port of the Service Configuration General tab — Project\Service\StackForm (name/
 * description/compose editor/network checkbox/service-specific fields), Project\Service\
 * ResourceCard (per-child status cards with Settings/Backups/Restart and the EditDomain
 * modal), Project\Service\EditCompose (raw/deployable toggle, escape-labels checkbox,
 * SSH-backed Validate) and the service-scoped view of Project\Shared\ResourceDetails
 * (read-only identifiers modal). See ProjectServiceConfigurationController.
 *
 * The compose editor is a plain monospace textarea — the Livewire modal's Monaco editor
 * is a known v1 UX gap, consistent with earlier compose-editing conversions.
 */
function Modal({ title, onClose, children, wide = false }) {
    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div
                className={`relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base ${wide ? 'lg:max-w-4xl' : 'lg:max-w-xl'}`}
            >
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">{title}</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function Field({ label, helper, ...props }) {
    return (
        <label className="flex flex-col flex-1 gap-1">
            {label && <span title={helper}>{label}</span>}
            <input {...props} />
        </label>
    );
}

function EditComposeModal({ stackForm, form, generalUrls, canUpdate, onSaveRaw, onClose }) {
    const [raw, setRaw] = useState(stackForm.dockerComposeRaw ?? '');
    const [showDeployable, setShowDeployable] = useState(false);

    function toggleEscape(e) {
        router.patch(generalUrls.settings, { isContainerLabelEscapeEnabled: e.target.checked }, { preserveScroll: true });
    }

    function validate() {
        router.post(generalUrls.validateCompose, { dockerComposeRaw: raw }, { preserveScroll: true });
    }

    return (
        <Modal title="Edit Docker Compose" onClose={onClose} wide>
            <div className="pb-4 text-sm">
                Volume names are updated upon save. The service UUID will be added as a prefix to all volumes, to prevent name collision. To see the
                actual volume names, check the Deployable Compose file, or go to Storage menu.
            </div>
            <textarea
                id="service-stack-compose-raw"
                name="service-stack-compose-raw"
                rows={18}
                className="font-mono"
                readOnly={showDeployable || !canUpdate}
                value={showDeployable ? (stackForm.dockerCompose ?? '') : raw}
                onChange={(e) => setRaw(e.target.value)}
            />
            {canUpdate && (
                <label className="flex items-center gap-2 pt-2">
                    <input
                        id="isContainerLabelEscapeEnabled"
                        name="isContainerLabelEscapeEnabled"
                        type="checkbox"
                        defaultChecked={stackForm.isContainerLabelEscapeEnabled}
                        onChange={toggleEscape}
                    />
                    <span title="By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.">
                        Escape special characters in labels?
                    </span>
                </label>
            )}
            <div className="flex w-full gap-2 pt-4">
                <button type="button" className="w-64" onClick={() => setShowDeployable(!showDeployable)}>
                    {showDeployable ? 'Show Source Compose' : 'Show Deployable Compose'}
                </button>
                <div className="flex-1" />
                {canUpdate && stackForm.canValidateCompose && (
                    <button type="button" className="w-28" onClick={validate}>
                        Validate
                    </button>
                )}
                {canUpdate && (
                    <button type="button" className="w-28" onClick={() => onSaveRaw(raw, { ...form })}>
                        Save
                    </button>
                )}
            </div>
        </Modal>
    );
}

function EditDomainModal({ resource, onClose }) {
    const { props } = usePage();
    const [fqdn, setFqdn] = useState(resource.fqdn ?? '');
    const [showPortWarning, setShowPortWarning] = useState(false);

    useEffect(() => {
        if (props.flash?.showPortWarningModal) {
            setShowPortWarning(true);
        }
    }, [props.flash?.showPortWarningModal]);

    function save(extra = {}) {
        router.patch(resource.urls.domain, { fqdn, ...extra }, { preserveScroll: true });
    }

    return (
        <Modal title="Edit Domains" onClose={onClose}>
            <form
                className="flex flex-col gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    save();
                }}
            >
                <Field
                    id={`service-resource-${resource.id}-domain`}
                    name={`service-resource-${resource.id}-domain`}
                    label="Domains"
                    placeholder="https://app.coolify.io"
                    value={fqdn}
                    onChange={(e) => setFqdn(e.target.value)}
                />
                <button type="submit">Save</button>
            </form>

            {props.flash?.showDomainConflictModal && (
                <DomainConflictModal
                    conflicts={props.flash?.domainConflicts ?? []}
                    onCancel={onClose}
                    onConfirm={() => save({ force_save_domains: true })}
                    consequences={[
                        'SSL certificates might not work correctly',
                        'Routing behavior will be unpredictable',
                        'Traffic may be routed to the wrong resource',
                    ]}
                />
            )}
            {showPortWarning && (
                <div className="mt-2 flex flex-col gap-2 p-3 bg-yellow-500/10 rounded-lg border border-yellow-500/20 text-sm">
                    <div>
                        This service requires port <strong>{props.flash?.requiredPort}</strong> to function correctly. One or more of your domains is
                        missing it. Continue without the port?
                    </div>
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setShowPortWarning(false)}>
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="text-error"
                            onClick={() => {
                                setShowPortWarning(false);
                                save({ force_remove_port: true });
                            }}
                        >
                            Continue without port
                        </button>
                    </div>
                </div>
            )}
        </Modal>
    );
}

function statusBorder(status) {
    if (status.includes('exited')) return 'border-l border-dashed border-red-500';
    if (status.includes('running')) return 'border-l border-dashed border-success';
    if (status.includes('starting') || status.includes('restarting')) return 'border-l border-dashed border-warning';
    return '';
}

function ResourceCard({ resource, canUpdate }) {
    const [editingDomain, setEditingDomain] = useState(false);
    const [confirmingRestart, setConfirmingRestart] = useState(false);

    return (
        <div
            className={`${statusBorder(resource.status)} flex gap-2 box-without-bg-without-border dark:bg-coolgray-100 bg-white dark:hover:text-neutral-300 group`}
        >
            <div className="flex flex-row w-full">
                <div className="flex flex-col flex-1">
                    <div className="pb-2">
                        {resource.name} <span className="text-xs">({resource.image})</span>
                    </div>
                    {resource.configurationRequired && <span className="text-xs text-error">(configuration required)</span>}
                    {resource.description && <span className="text-xs">{resource.description}</span>}
                    {resource.isApplication && resource.fqdn && (
                        <span className="flex gap-1 text-xs">
                            {resource.fqdn.length > 60 ? `${resource.fqdn.slice(0, 60)}…` : resource.fqdn}
                            {canUpdate && (
                                <button type="button" title="Edit Domains" onClick={() => setEditingDomain(true)}>
                                    ✏️
                                </button>
                            )}
                        </span>
                    )}
                    <div className={`text-xs${resource.isApplication ? ' pt-2' : ''}`}>{resource.statusFormatted}</div>
                </div>
                <div className="flex items-center px-4">
                    {resource.showBackups && (
                        <a className="mx-4 text-xs font-bold hover:underline" href={resource.urls.backups}>
                            Backups
                        </a>
                    )}
                    <a className="mx-4 text-xs font-bold hover:underline" href={resource.urls.settings}>
                        Settings
                    </a>
                    {resource.status.includes('running') && canUpdate && (
                        <button type="button" className="text-xs font-bold" onClick={() => setConfirmingRestart(true)}>
                            Restart
                        </button>
                    )}
                </div>
            </div>

            {editingDomain && <EditDomainModal resource={resource} onClose={() => setEditingDomain(false)} />}
            {confirmingRestart && (
                <Modal
                    title={resource.isApplication ? 'Confirm Service Application Restart?' : 'Confirm Service Database Restart?'}
                    onClose={() => setConfirmingRestart(false)}
                >
                    <ul className="list-disc pl-4 text-sm pb-4">
                        <li>
                            {resource.isApplication
                                ? 'The selected service application will be unavailable during the restart.'
                                : 'This service database will be unavailable during the restart.'}
                        </li>
                        <li>
                            {resource.isApplication
                                ? 'If the service application is currently in use data could be lost.'
                                : 'If the service database is currently in use data could be lost.'}
                        </li>
                    </ul>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setConfirmingRestart(false)}>
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="text-error"
                            onClick={() => {
                                setConfirmingRestart(false);
                                router.post(resource.urls.restart, {}, { preserveScroll: true });
                            }}
                        >
                            {resource.isApplication ? 'Restart Service Container' : 'Restart Database'}
                        </button>
                    </div>
                </Modal>
            )}
        </div>
    );
}

export default function ServiceStackTab({ stackForm, resources, resourceDetails, generalUrls, canUpdate }) {
    const [form, setForm] = useState({
        name: stackForm.name ?? '',
        description: stackForm.description ?? '',
        fields: Object.fromEntries((stackForm.fields ?? []).map((f) => [f.key, f.value ?? ''])),
    });
    const [editingCompose, setEditingCompose] = useState(false);
    const [showingDetails, setShowingDetails] = useState(false);

    useTeamChannel(['ServiceChecked'], () => {
        router.reload({ only: ['resources'], preserveScroll: true });
    });

    function submit(e, rawOverride = null) {
        e?.preventDefault();
        router.patch(
            generalUrls.update,
            {
                name: form.name,
                description: form.description,
                dockerComposeRaw: rawOverride ?? stackForm.dockerComposeRaw,
                fields: form.fields,
            },
            { preserveScroll: true, onSuccess: () => setEditingCompose(false) },
        );
    }

    function toggleNetwork(e) {
        router.patch(generalUrls.settings, { connectToDockerNetwork: e.target.checked }, { preserveScroll: true });
    }

    const applications = resources.filter((r) => r.isApplication);
    const databases = resources.filter((r) => !r.isApplication);

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col gap-4 pb-2">
                <div>
                    <div className="flex gap-2 items-center">
                        <h2>Service Stack</h2>
                        {stackForm.composeParsingVersion != null && <div>{stackForm.composeParsingVersion}</div>}
                        {canUpdate && (
                            <>
                                <button type="submit">Save</button>
                                <button type="button" onClick={() => setEditingCompose(true)}>
                                    Edit Compose File
                                </button>
                            </>
                        )}
                        <button type="button" onClick={() => setShowingDetails(true)}>
                            Details
                        </button>
                    </div>
                    <div>Configuration</div>
                </div>
                <div className="flex flex-col gap-2 md:flex-row">
                    <Field
                        id="service-stack-name"
                        name="service-stack-name"
                        label="Service Name"
                        required
                        placeholder="My super WordPress site"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        disabled={!canUpdate}
                    />
                    <Field
                        id="service-stack-description"
                        name="service-stack-description"
                        label="Description"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        disabled={!canUpdate}
                    />
                </div>
                <div>
                    <h3>Network</h3>
                </div>
                <div className="w-full sm:w-96">
                    <label className="flex items-center gap-2">
                        <input
                            id="connectToDockerNetwork"
                            name="connectToDockerNetwork"
                            type="checkbox"
                            defaultChecked={stackForm.connectToDockerNetwork}
                            onChange={toggleNetwork}
                            disabled={!canUpdate}
                        />
                        <span title="By default, you do not reach the Coolify defined networks. Starting a docker compose based resource will have an internal network. If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource.">
                            Connect To Predefined Network
                        </span>
                    </label>
                </div>
                {(stackForm.fields ?? []).length > 0 && (
                    <>
                        <div>
                            <h3>Service Specific Configuration</h3>
                        </div>
                        <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                            {stackForm.fields.map((field) => (
                                <div key={field.key} className="contents">
                                    <div className="flex items-center gap-2" title={field.customHelper || `Variable name: ${field.key}`}>
                                        <span className="font-bold">{field.serviceName}</span>
                                        {field.name}
                                    </div>
                                    <Field
                                        type={field.isPassword ? 'password' : 'text'}
                                        required={field.required}
                                        id={`fields.${field.key}`}
                                        name={`fields.${field.key}`}
                                        value={form.fields[field.key] ?? ''}
                                        onChange={(e) => setForm({ ...form, fields: { ...form.fields, [field.key]: e.target.value } })}
                                        disabled={!canUpdate}
                                    />
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </form>

            <h3>Services</h3>
            <div className="grid grid-cols-1 gap-2 pt-4">
                {applications.length === 0 && databases.length === 0 && (
                    <div className="p-4 text-sm text-neutral-500">No services defined in this Docker Compose file.</div>
                )}
                {applications.length === 0 && databases.length > 0 && (
                    <div className="p-4 text-sm text-neutral-500">No applications with domains defined. Only database services are available.</div>
                )}
                {resources.map((resource) => (
                    <ResourceCard key={resource.uuid} resource={resource} canUpdate={canUpdate} />
                ))}
            </div>

            {editingCompose && (
                <EditComposeModal
                    stackForm={stackForm}
                    form={form}
                    generalUrls={generalUrls}
                    canUpdate={canUpdate}
                    onSaveRaw={(raw) => submit(null, raw)}
                    onClose={() => setEditingCompose(false)}
                />
            )}
            {showingDetails && <ResourceDetailsModal details={resourceDetails} onClose={() => setShowingDetails(false)} />}
        </div>
    );
}
