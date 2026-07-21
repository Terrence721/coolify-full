import { router } from '@inertiajs/react';
import { useState } from 'react';
import PasswordConfirmModal from './PasswordConfirmModal';

/**
 * React port of the persistent-storage tab family (Project\Service\Storage +
 * Project\Shared\Storages\{All,Show} + Project\Service\FileStorage), scoped to standalone
 * databases and services — see ManagesResourceStorages. Databases get the Add dropdown
 * (volume/file/directory mounts) and editable volume cards; services render one read-mostly
 * section per compose child, with volumes read-only and file/directory mounts still
 * editable/loadable/convertible/deletable.
 */
function Modal({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-xl">
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

function Field({ label, ...props }) {
    return (
        <label className="flex flex-col flex-1 gap-1">
            {label}
            <input {...props} />
        </label>
    );
}

function VolumeCard({ volume, canUpdate }) {
    const [form, setForm] = useState({ name: volume.name, mount_path: volume.mountPath, host_path: volume.hostPath ?? '' });
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const editable = canUpdate && !volume.isReadOnly;

    function update(e) {
        e.preventDefault();
        router.patch(volume.urls.update, form, { preserveScroll: true });
    }

    return (
        <form onSubmit={update} className="flex flex-col gap-3 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
            {volume.isReadOnly && (
                <div className="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                    This volume is defined by the compose file / initial configuration and is read-only in the UI.
                </div>
            )}
            <div className="flex flex-col gap-2 items-end w-full md:flex-row">
                <Field
                    id={`volume-${volume.id}-name`}
                    name={`volume-${volume.id}-name`}
                    label={volume.isFirst ? 'Volume Name' : undefined}
                    required
                    disabled={!editable}
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
                <Field
                    id={`volume-${volume.id}-host-path`}
                    name={`volume-${volume.id}-host-path`}
                    label={volume.isFirst ? 'Source Path (host)' : undefined}
                    disabled={!editable}
                    value={form.host_path}
                    onChange={(e) => setForm({ ...form, host_path: e.target.value })}
                />
                <Field
                    id={`volume-${volume.id}-mount-path`}
                    name={`volume-${volume.id}-mount-path`}
                    label={volume.isFirst ? 'Destination Path (container)' : undefined}
                    required
                    disabled={!editable}
                    value={form.mount_path}
                    onChange={(e) => setForm({ ...form, mount_path: e.target.value })}
                />
            </div>
            {editable && (
                <div className="flex gap-2">
                    <button type="submit">Update</button>
                    <button type="button" className="button-error" onClick={() => setConfirmingDelete(true)}>
                        Delete
                    </button>
                </div>
            )}
            {confirmingDelete && (
                <PasswordConfirmModal
                    title="Confirm persistent storage deletion?"
                    action={{ method: 'delete', url: volume.urls.destroy }}
                    actions={[
                        'The selected persistent storage/volume will be permanently deleted.',
                        'If the persistent storage/volume is actively used by a resource, data will be lost.',
                    ]}
                    confirmationText={volume.name}
                    confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                    onClose={() => setConfirmingDelete(false)}
                    onDone={() => setConfirmingDelete(false)}
                />
            )}
        </form>
    );
}

function FileCard({ file, canUpdate }) {
    const [content, setContent] = useState(file.content ?? '');
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [convertConfirmation, setConvertConfirmation] = useState(null);
    const editable = canUpdate && !file.isReadOnly;

    function save(e) {
        e.preventDefault();
        router.patch(file.urls.update, { content }, { preserveScroll: true });
    }

    function load() {
        router.post(file.urls.load, {}, { preserveScroll: true });
    }

    function convert() {
        router.post(file.urls.convert, {}, { preserveScroll: true, onFinish: () => setConvertConfirmation(null) });
    }

    return (
        <div className="flex flex-col gap-3 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
            {file.isTooLarge && (
                <div className="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                    File on server exceeds 5 MB and cannot be edited from the UI. Edit it directly on the server.
                </div>
            )}
            {!file.isTooLarge && file.isReadOnly && (
                <div className="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                    This {file.isDirectory ? 'directory' : 'file'} is mounted as read-only and cannot be modified from the UI.
                </div>
            )}
            <div className="flex flex-col gap-2 md:flex-row">
                <Field id={`file-${file.id}-fs-path`} name={`file-${file.id}-fs-path`} label="Source Path" readOnly value={file.fsPath} />
                <Field
                    id={`file-${file.id}-mount-path`}
                    name={`file-${file.id}-mount-path`}
                    label="Destination Path"
                    readOnly
                    value={file.mountPath}
                />
            </div>
            {canUpdate && (
                <div className="flex flex-wrap gap-2">
                    {!file.isDirectory && (
                        <button type="button" onClick={load}>
                            Load from server
                        </button>
                    )}
                    {editable && !file.isBinary && !file.isTooLarge && (
                        <button type="button" onClick={() => setConvertConfirmation('')}>
                            {file.isDirectory ? 'Convert to file' : 'Convert to directory'}
                        </button>
                    )}
                    {editable && (
                        <button type="button" className="button-error" onClick={() => setConfirmingDelete(true)}>
                            Delete
                        </button>
                    )}
                </div>
            )}
            {convertConfirmation !== null && (
                <div className="flex items-center gap-2">
                    <input
                        id={`file-${file.id}-convert-confirm`}
                        name={`file-${file.id}-convert-confirm`}
                        placeholder={`Type "${file.fsPath}" to confirm conversion`}
                        className="flex-1"
                        value={convertConfirmation}
                        onChange={(e) => setConvertConfirmation(e.target.value)}
                    />
                    <button type="button" disabled={convertConfirmation !== file.fsPath} onClick={convert}>
                        {file.isDirectory ? 'Convert to file' : 'Convert to directory'}
                    </button>
                    <button type="button" onClick={() => setConvertConfirmation(null)}>
                        Cancel
                    </button>
                </div>
            )}
            {!file.isDirectory && (
                <form onSubmit={save} className="flex flex-col gap-2">
                    <label className="flex flex-col gap-1">
                        Content
                        <textarea
                            id={`file-${file.id}-content`}
                            name={`file-${file.id}-content`}
                            rows={12}
                            className="font-mono"
                            title="The content shown may be outdated. Click 'Load from server' to fetch the latest version."
                            value={content}
                            readOnly={!editable || file.isBinary}
                            onChange={(e) => setContent(e.target.value)}
                        />
                    </label>
                    {editable && !file.isBinary && (
                        <button type="submit" className="w-full">
                            Save
                        </button>
                    )}
                </form>
            )}
            {confirmingDelete && (
                <PasswordConfirmModal
                    title={file.isDirectory ? 'Confirm Directory Deletion?' : 'Confirm File Deletion?'}
                    action={{ method: 'delete', url: file.urls.destroy }}
                    actions={[
                        `The selected ${file.isDirectory ? 'directory and all its contents' : 'file'} will be permanently deleted from the container.`,
                    ]}
                    checkboxes={[
                        {
                            id: 'permanently_delete',
                            label: `The selected ${file.isDirectory ? 'directory and all its contents' : 'file'} will be permanently deleted from the server.`,
                            default: true,
                        },
                    ]}
                    confirmationText={file.fsPath}
                    confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                    onClose={() => setConfirmingDelete(false)}
                    onDone={() => setConfirmingDelete(false)}
                />
            )}
        </div>
    );
}

function AddDropdown({ storageUrls, sourceDirPlaceholder }) {
    const [open, setOpen] = useState(false);
    const [modal, setModal] = useState(null);
    const [volume, setVolume] = useState({ name: '', host_path: '', mount_path: '' });
    const [file, setFile] = useState({ file_storage_path: '', file_storage_content: '' });
    const [directory, setDirectory] = useState({ source: sourceDirPlaceholder ?? '', destination: '' });

    function submit(e, url, data) {
        e.preventDefault();
        router.post(url, data, { preserveScroll: true, onSuccess: () => setModal(null) });
    }

    return (
        <div className="relative">
            <button type="button" onClick={() => setOpen(!open)}>
                + Add ▾
            </button>
            {open && (
                <div className="absolute top-full z-50 mt-1 min-w-max p-1 bg-white border rounded-sm shadow-sm dark:bg-coolgray-200 dark:border-coolgray-300 border-neutral-300">
                    <div className="flex flex-col gap-1">
                        {['volume', 'file', 'directory'].map((kind) => (
                            <button
                                key={kind}
                                type="button"
                                className="dropdown-item text-left"
                                onClick={() => {
                                    setModal(kind);
                                    setOpen(false);
                                }}
                            >
                                {kind === 'volume' ? 'Volume Mount' : kind === 'file' ? 'File Mount' : 'Directory Mount'}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {modal === 'volume' && (
                <Modal title="Add Volume Mount" onClose={() => setModal(null)}>
                    <form className="flex flex-col gap-2" onSubmit={(e) => submit(e, storageUrls.volumeStore, volume)}>
                        <div>Docker Volumes mounted to the container.</div>
                        <Field
                            id="storage-add-volume-name"
                            name="storage-add-volume-name"
                            label="Name"
                            required
                            placeholder="pv-name"
                            value={volume.name}
                            onChange={(e) => setVolume({ ...volume, name: e.target.value })}
                        />
                        <Field
                            id="storage-add-volume-host-path"
                            name="storage-add-volume-host-path"
                            label="Source Path (host)"
                            placeholder="/root"
                            value={volume.host_path}
                            onChange={(e) => setVolume({ ...volume, host_path: e.target.value })}
                        />
                        <Field
                            id="storage-add-volume-mount-path"
                            name="storage-add-volume-mount-path"
                            label="Destination Path (container)"
                            required
                            placeholder="/tmp/root"
                            value={volume.mount_path}
                            onChange={(e) => setVolume({ ...volume, mount_path: e.target.value })}
                        />
                        <button type="submit">Add</button>
                    </form>
                </Modal>
            )}
            {modal === 'file' && (
                <Modal title="Add File Mount" onClose={() => setModal(null)}>
                    <form className="flex flex-col gap-2" onSubmit={(e) => submit(e, storageUrls.fileStore, file)}>
                        <div>Actual file mounted from the host system to the container.</div>
                        <Field
                            id="storage-add-file-path"
                            name="storage-add-file-path"
                            label="Destination Path (inside the container)"
                            required
                            placeholder="/etc/nginx/nginx.conf"
                            value={file.file_storage_path}
                            onChange={(e) => setFile({ ...file, file_storage_path: e.target.value })}
                        />
                        <label className="flex flex-col gap-1">
                            Content
                            <textarea
                                id="storage-add-file-content"
                                name="storage-add-file-content"
                                rows={8}
                                className="font-mono"
                                value={file.file_storage_content}
                                onChange={(e) => setFile({ ...file, file_storage_content: e.target.value })}
                            />
                        </label>
                        <button type="submit">Add</button>
                    </form>
                </Modal>
            )}
            {modal === 'directory' && (
                <Modal title="Add Directory Mount" onClose={() => setModal(null)}>
                    <form className="flex flex-col gap-2" onSubmit={(e) => submit(e, storageUrls.directoryStore, directory)}>
                        <div>Directory mounted from the host system to the container.</div>
                        <Field
                            id="storage-add-directory-source"
                            name="storage-add-directory-source"
                            label="Source Directory (host)"
                            required
                            placeholder={sourceDirPlaceholder ?? '/etc/nginx'}
                            value={directory.source}
                            onChange={(e) => setDirectory({ ...directory, source: e.target.value })}
                        />
                        <Field
                            id="storage-add-directory-destination"
                            name="storage-add-directory-destination"
                            label="Destination Directory (container)"
                            required
                            placeholder="/etc/nginx"
                            value={directory.destination}
                            onChange={(e) => setDirectory({ ...directory, destination: e.target.value })}
                        />
                        <button type="submit">Add</button>
                    </form>
                </Modal>
            )}
        </div>
    );
}

function StorageSection({ section, canUpdate }) {
    const volumes = section.volumes;
    const files = section.files.filter((f) => !f.isDirectory);
    const directories = section.files.filter((f) => f.isDirectory);
    const defaultTab = volumes.length > 0 ? 'volumes' : files.length > 0 ? 'files' : 'directories';
    const [activeTab, setActiveTab] = useState(defaultTab);

    if (volumes.length === 0 && files.length === 0 && directories.length === 0) {
        return (
            <div className="flex flex-col gap-2">
                {section.name && <h2>{section.name}</h2>}
                <div>No storage found.</div>
            </div>
        );
    }

    const tabs = [
        ['volumes', `Volumes (${volumes.length})`, volumes.length > 0],
        ['files', `Files (${files.length})`, files.length > 0],
        ['directories', `Directories (${directories.length})`, directories.length > 0],
    ];

    return (
        <div className="flex flex-col gap-2">
            {section.name && <h2>{section.name}</h2>}
            <div className="flex gap-2 border-b dark:border-coolgray-300 border-neutral-200">
                {tabs.map(([id, label, enabled]) => (
                    <button
                        key={id}
                        type="button"
                        disabled={!enabled}
                        onClick={() => setActiveTab(id)}
                        className={`px-4 py-2 -mb-px font-medium ${activeTab === id ? 'border-b-2 dark:border-white border-black' : 'border-b-2 border-transparent'} ${enabled ? '' : 'opacity-50 cursor-not-allowed'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <div className="flex flex-col gap-4 pt-2">
                {activeTab === 'volumes' && volumes.map((volume) => <VolumeCard key={volume.id} volume={volume} canUpdate={canUpdate} />)}
                {activeTab === 'files' && files.map((file) => <FileCard key={file.id} file={file} canUpdate={canUpdate} />)}
                {activeTab === 'directories' && directories.map((file) => <FileCard key={file.id} file={file} canUpdate={canUpdate} />)}
            </div>
        </div>
    );
}

export default function StoragesTab({ sections, isService, canAddMounts, canUpdate, storageUrls, sourceDirPlaceholder }) {
    return (
        <div className="flex flex-col gap-4">
            <div>
                <div className="flex items-center gap-2">
                    <h2>{isService ? 'Storages' : 'Storages'}</h2>
                    {canAddMounts && canUpdate && storageUrls && (
                        <AddDropdown storageUrls={storageUrls} sourceDirPlaceholder={sourceDirPlaceholder} />
                    )}
                </div>
                <div>Persistent storage to preserve data between deployments.</div>
                {isService && (
                    <div className="mt-2 w-full p-2 text-sm rounded bg-warning/10 text-warning">
                        For docker compose based resources, volume mounts are read-only in the dashboard. To add, modify, or manage volumes, edit your
                        Docker Compose file and reload it.
                    </div>
                )}
            </div>
            {sections.map((section, index) => (
                <StorageSection key={section.name ?? index} section={section} canUpdate={canUpdate} />
            ))}
        </div>
    );
}
