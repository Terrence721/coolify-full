import { router, useForm } from '@inertiajs/react';
import BackupEditForm from '../Components/BackupEditForm';
import BackupExecutionsList from '../Components/BackupExecutionsList';

export default function SettingsBackup({
    server,
    serverFunctional,
    database,
    backup,
    s3Storages,
    executions,
    executionsCount,
    skip,
    defaultTake,
    currentPage,
    showNext,
    showPrev,
    identityUpdateUrl,
    urls,
}) {
    const identity = useForm({
        name: database?.name ?? '',
        description: database?.description ?? '',
        postgres_user: database?.postgresUser ?? '',
        postgres_password: database?.postgresPassword ?? '',
    });

    function submitIdentity(e) {
        e.preventDefault();
        identity.put(identityUpdateUrl);
    }

    function addDatabase() {
        if (!urls?.addDatabase) return;
        router.post(urls.addDatabase, {}, { preserveScroll: true });
    }

    const showBackupPanels = Boolean(database) && Boolean(backup);

    return (
        <div>
            <div className="pb-5">
                <h1>Settings</h1>
                <div className="subtitle">Instance wide settings for Coolify.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10 whitespace-nowrap">
                        <a href="/settings">Configuration</a>
                        <a href="/settings/backup" className="dark:text-white">
                            Backup
                        </a>
                        <a href="/settings/email">Transactional Email</a>
                        <a href="/settings/oauth">OAuth</a>
                        <a href="/settings/scheduled-jobs">Scheduled Jobs</a>
                    </nav>
                </div>
            </div>

            <div className="flex flex-col">
                <div className="flex items-center gap-2 pb-2">
                    <h2>Backup</h2>
                    {showBackupPanels && serverFunctional && (
                        <button type="submit" form="settings-backup-identity-form" disabled={identity.processing}>
                            Save
                        </button>
                    )}
                </div>
                <div className="pb-4">Backup configuration for Coolify instance.</div>

                {serverFunctional ? (
                    showBackupPanels ? (
                        <>
                            <form id="settings-backup-identity-form" onSubmit={submitIdentity} className="flex flex-col gap-3 pb-4">
                                <div className="flex gap-2">
                                    <label className="flex flex-col gap-1">
                                        UUID
                                        <input id="settings-backup-uuid" name="settings-backup-uuid" readOnly value={database.uuid} />
                                    </label>
                                    <label className="flex flex-col gap-1">
                                        Name
                                        <input id="settings-backup-name" name="settings-backup-name" readOnly value={identity.data.name} />
                                    </label>
                                    <label className="flex flex-col gap-1">
                                        Description
                                        <input
                                            id="settings-backup-description"
                                            name="settings-backup-description"
                                            value={identity.data.description ?? ''}
                                            onChange={(e) => identity.setData('description', e.target.value)}
                                        />
                                        {identity.errors.description && <span className="text-error">{identity.errors.description}</span>}
                                    </label>
                                </div>
                                <div className="flex gap-2">
                                    <label className="flex flex-col gap-1">
                                        User
                                        <input
                                            id="settings-backup-postgres-user"
                                            name="settings-backup-postgres-user"
                                            readOnly
                                            value={identity.data.postgres_user}
                                        />
                                    </label>
                                    <label className="flex flex-col gap-1">
                                        Password
                                        <input
                                            id="settings-backup-postgres-password"
                                            name="settings-backup-postgres-password"
                                            type="password"
                                            readOnly
                                            value={identity.data.postgres_password}
                                        />
                                    </label>
                                </div>
                            </form>

                            <BackupEditForm backup={backup} s3Storages={s3Storages} urls={urls} />

                            <div className="py-4">
                                <BackupExecutionsList
                                    executions={executions}
                                    executionsCount={executionsCount}
                                    skip={skip}
                                    defaultTake={defaultTake}
                                    currentPage={currentPage}
                                    showNext={showNext}
                                    showPrev={showPrev}
                                    urls={urls}
                                />
                            </div>
                        </>
                    ) : (
                        <div>
                            To configure automatic backup for your Coolify instance, you first need to add a database resource into Coolify.
                            <button type="button" className="mt-2" onClick={addDatabase}>
                                Configure Backup
                            </button>
                        </div>
                    )
                ) : (
                    <div className="p-6 bg-red-500/10 rounded-lg border border-red-500/20">
                        <div className="text-red-500 font-medium mb-4">
                            Instance Backup is currently disabled because the localhost server is not properly validated. Please validate your server
                            to enable Instance Backup.
                        </div>
                        <a
                            href={`/server/${server.uuid}`}
                            className="text-black hover:text-gray-700 dark:text-white dark:hover:text-gray-200 underline"
                        >
                            Go to Server Settings to Validate
                        </a>
                    </div>
                )}
            </div>
        </div>
    );
}
