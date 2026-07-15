import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ActivityLog from './ActivityLog';
import PasswordConfirmModal from './PasswordConfirmModal';
import { useTeamChannel } from '../hooks/useTeamChannel';

/**
 * React port of the Import Backup tab (Project\Database\{Import,ImportForm}) shared by the
 * database Configuration router and the service-database import page — see
 * ManagesDatabaseImport. Uploads keep going to the pre-existing chunked `upload.backup`
 * endpoint; this component replicates Dropzone's chunk parameters with plain fetch()
 * (10 MB chunks, like the original's Dropzone options). Restores stream through the
 * activity monitor via the `database-import` activityContext flash.
 */
const CHUNK_SIZE = 10_000_000;

async function uploadInChunks(url, file, onProgress) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    const uuid = `${Date.now()}-${Math.random().toString(36).slice(2)}`;

    for (let index = 0; index < totalChunks; index++) {
        const start = index * CHUNK_SIZE;
        const chunk = file.slice(start, Math.min(start + CHUNK_SIZE, file.size));
        const formData = new FormData();
        formData.append('_token', csrf);
        formData.append('dzuuid', uuid);
        formData.append('dzchunkindex', String(index));
        formData.append('dztotalfilesize', String(file.size));
        formData.append('dzchunksize', String(CHUNK_SIZE));
        formData.append('dztotalchunkcount', String(totalChunks));
        formData.append('dzchunkbyteoffset', String(start));
        formData.append('dzTotalFilesize', String(file.size));
        formData.append('file', chunk, file.name);

        const response = await fetch(url, { method: 'POST', body: formData, headers: { Accept: 'application/json' } });
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.error ?? `Upload failed (HTTP ${response.status}).`);
        }
        onProgress(Math.round(((index + 1) / totalChunks) * 100));
    }
}

function MethodTile({ active, onClick, title, description, icon }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex-1 p-6 border-2 rounded-sm cursor-pointer transition-all text-left ${active ? 'border-warning bg-warning/10' : 'border-neutral-200 dark:border-neutral-800 hover:border-warning/50'}`}
        >
            <div className="flex flex-col gap-2">
                <span className="text-2xl">{icon}</span>
                <h4 className="text-lg font-bold">{title}</h4>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">{description}</p>
            </div>
        </button>
    );
}

export default function DatabaseImportTab({ importTab, flash }) {
    const { unsupported, running, commands, s3Storages, canUpdate, urls } = importTab;

    const [restoreType, setRestoreType] = useState(null);
    const [dumpAll, setDumpAll] = useState(false);
    const [restoreCommand, setRestoreCommand] = useState(commands.default);
    const [customLocation, setCustomLocation] = useState('');
    const [uploadedFile, setUploadedFile] = useState(null);
    const [uploadProgress, setUploadProgress] = useState(null);
    const [uploadError, setUploadError] = useState(null);
    const [s3StorageId, setS3StorageId] = useState('');
    const [s3Path, setS3Path] = useState('');
    const [s3Checked, setS3Checked] = useState(false);
    const [confirming, setConfirming] = useState(null);
    const [activityId, setActivityId] = useState(null);
    const fileInputRef = useRef(null);

    useTeamChannel(['ServiceChecked', 'ServiceStatusChanged'], () => {
        router.reload({ only: ['importTab'], preserveScroll: true });
    });

    useEffect(() => {
        if (flash?.activityContext === 'database-import' && flash?.activityId) {
            setActivityId(flash.activityId);
        }
    }, [flash?.activityId, flash?.activityContext]);

    if (unsupported) {
        return (
            <div>
                <h2>Import Backup</h2>
                <div className="pt-2">Database restore is not supported.</div>
            </div>
        );
    }
    if (!running) {
        return (
            <div>
                <h2>Import Backup</h2>
                <div className="pt-2">Database must be running to restore a backup.</div>
            </div>
        );
    }

    function toggleDumpAll(checked) {
        setDumpAll(checked);
        setRestoreCommand(checked ? (commands.dumpAll ?? commands.default) : commands.default);
    }

    async function handleFileSelected(e) {
        const file = e.target.files?.[0];
        if (!file) return;
        setUploadError(null);
        setUploadedFile(null);
        setCustomLocation('');
        setUploadProgress(0);
        try {
            await uploadInChunks(urls.upload, file, setUploadProgress);
            setUploadedFile({ name: file.name, size: `${(file.size / 1024 / 1024).toFixed(2)} MB` });
        } catch (err) {
            setUploadError(err.message);
        } finally {
            setUploadProgress(null);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    }

    function checkFile() {
        router.post(urls.checkFile, { customLocation }, { preserveScroll: true });
    }

    function checkS3() {
        router.post(
            urls.checkS3,
            { s3StorageId, s3Path },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (page.props.flash?.success) setS3Checked(true);
                },
            },
        );
    }

    const restorePayload =
        confirming === 's3'
            ? { s3StorageId, s3Path, restoreCommand, dumpAll }
            : { customLocation: uploadedFile ? '' : customLocation, restoreCommand, dumpAll };

    return (
        <div>
            <h2>Import Backup</h2>

            <div className="mt-2 flex items-center gap-2 p-3 rounded-sm bg-red-500/10 border border-red-500/20 text-sm">
                <span>⚠️</span>
                <span>This is a destructive action, existing data will be replaced!</span>
            </div>

            {commands.dumpAll !== null && (
                <div className="pt-4 flex flex-col gap-2">
                    {dumpAll ? (
                        <label className="flex flex-col gap-1">
                            Custom Import Command
                            <textarea id="db-import-command-readonly" name="db-import-command-readonly" rows={8} readOnly className="font-mono" value={`${restoreCommand}${commands.dumpAllSuffix ?? ''}`} />
                        </label>
                    ) : (
                        <>
                            <label className="flex flex-col gap-1">
                                Custom Import Command
                                <input id="db-import-command" name="db-import-command" value={restoreCommand} onChange={(e) => setRestoreCommand(e.target.value)} disabled={!canUpdate} />
                            </label>
                            {importTab.dbType === 'standalone-postgresql' && (
                                <div className="flex flex-col gap-1 pt-1 text-xs">
                                    <span>You can add "--clean" to drop objects before creating them, avoiding conflicts.</span>
                                    <span>You can add "--verbose" to log more things.</span>
                                </div>
                            )}
                        </>
                    )}
                    <label className="flex items-center gap-2 w-64 pt-2">
                        <input id="dumpAll" name="dumpAll" type="checkbox" checked={dumpAll} onChange={(e) => toggleDumpAll(e.target.checked)} disabled={!canUpdate} />
                        Backup includes all databases
                    </label>
                </div>
            )}

            <h3 className="pt-6">Choose Restore Method</h3>
            <div className="flex gap-4 pt-2">
                <MethodTile
                    active={restoreType === 'file'}
                    onClick={() => setRestoreType('file')}
                    icon="📄"
                    title="Restore from File"
                    description="Upload a backup file or specify a file path on the server"
                />
                {s3Storages.length > 0 && (
                    <MethodTile
                        active={restoreType === 's3'}
                        onClick={() => setRestoreType('s3')}
                        icon="☁️"
                        title="Restore from S3"
                        description="Download and restore a backup from S3 storage"
                    />
                )}
            </div>

            {canUpdate && restoreType === 'file' && (
                <div className="pt-6">
                    <h3>Backup File</h3>
                    <div className="flex gap-2 items-end pt-2">
                        <label className="flex flex-col flex-1 gap-1">
                            Location of the backup file on the server
                            <input
                                id="db-import-custom-location"
                                name="db-import-custom-location"
                                placeholder="e.g. /home/user/backup.sql.gz"
                                value={customLocation}
                                onChange={(e) => setCustomLocation(e.target.value)}
                            />
                        </label>
                        <button type="button" disabled={!customLocation} onClick={checkFile}>
                            Check File
                        </button>
                    </div>
                    <div className="pt-2 text-center text-xl font-bold">Or</div>
                    <label className="flex flex-col items-center justify-center gap-2 p-8 border-2 border-dashed rounded-sm cursor-pointer border-neutral-300 dark:border-coolgray-300 hover:border-warning/50">
                        <span>Select a backup file to upload.</span>
                        <input id="db-import-file" name="db-import-file" ref={fileInputRef} type="file" className="hidden" onChange={handleFileSelected} />
                    </label>
                    {uploadProgress !== null && <progress max="100" value={uploadProgress} className="w-full" />}
                    {uploadError && <div className="pt-2 text-error text-sm">{uploadError}</div>}

                    {(uploadedFile || customLocation) && !uploadError && (
                        <div className="pt-6">
                            <h3>File Information</h3>
                            <div className="pt-2">
                                Location: {uploadedFile ? `${uploadedFile.name} / ${uploadedFile.size}` : customLocation}
                            </div>
                            <div className="pt-2">
                                <button type="button" className="button-error" onClick={() => setConfirming('file')}>
                                    Restore Database from File
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {canUpdate && restoreType === 's3' && s3Storages.length > 0 && (
                <div className="pt-6">
                    <h3>Restore from S3</h3>
                    <div className="flex flex-col gap-2 pt-2">
                        <label className="flex flex-col gap-1">
                            S3 Storage
                            <select
                                id="s3StorageId"
                                name="s3StorageId"
                                value={s3StorageId}
                                onChange={(e) => {
                                    setS3StorageId(e.target.value);
                                    setS3Checked(false);
                                }}
                            >
                                <option value="">Select S3 Storage</option>
                                {s3Storages.map((storage) => (
                                    <option key={storage.id} value={storage.id}>
                                        {storage.name}
                                        {storage.description ? ` - ${storage.description}` : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-1">
                            <span title="Path to the backup file in your S3 bucket, e.g., /backups/database-2025-01-15.gz">S3 File Path (within bucket)</span>
                            <input
                                id="db-import-s3-path"
                                name="db-import-s3-path"
                                placeholder="/backups/database-backup.gz"
                                value={s3Path}
                                onChange={(e) => {
                                    setS3Path(e.target.value);
                                    setS3Checked(false);
                                }}
                                onKeyDown={(e) => e.key === 'Enter' && checkS3()}
                            />
                        </label>
                        <button type="button" className="w-full" disabled={!s3StorageId || !s3Path} onClick={checkS3}>
                            Check File
                        </button>

                        {s3Checked && (
                            <div className="pt-6">
                                <h3>File Information</h3>
                                <div className="pt-2">Location: {s3Path}</div>
                                <div className="pt-2">
                                    <button type="button" className="button-error" onClick={() => setConfirming('s3')}>
                                        Restore Database from S3
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {activityId && (
                <div className="pt-6">
                    <ActivityLog activityId={activityId} header="Database Restore Output" />
                </div>
            )}

            {confirming && (
                <PasswordConfirmModal
                    title={confirming === 's3' ? 'Restore Database from S3?' : 'Restore Database from File?'}
                    action={{ method: 'post', url: confirming === 's3' ? urls.restoreS3 : urls.run, data: restorePayload }}
                    actions={[
                        ...(confirming === 's3' ? ['Download backup from S3 storage'] : []),
                        'Copy backup file to database container',
                        'Execute restore command',
                        'WARNING: This will REPLACE all existing data!',
                    ]}
                    onClose={() => setConfirming(null)}
                    onDone={() => setConfirming(null)}
                />
            )}
        </div>
    );
}
