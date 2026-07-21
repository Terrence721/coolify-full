import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import BackupEditForm from '../../../Components/BackupEditForm';
import BackupExecutionsList from '../../../Components/BackupExecutionsList';
import ConfigurationChecker from '../../../Components/ConfigurationChecker';
import CreateBackupModal from '../../../Components/CreateBackupModal';
import ServiceHeading from '../../../Components/ServiceHeading';

const STATUS_LABELS = {
    running: 'In Progress',
    failed: 'Failed',
    success: 'Success',
};

const STATUS_BORDER = {
    running: 'border-blue-500/50 border-dashed',
    failed: 'border-error',
    success: 'border-success',
};

const STATUS_BADGE = {
    running: 'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
    success: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
};

function BackupCard({ backup, onSelect }) {
    return (
        <div
            onClick={() => onSelect(backup.id)}
            className={
                'flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white' +
                (backup.selected ? ' bg-gray-200 dark:bg-coolgray-200 border-coollabs' : ' ') +
                (backup.status
                    ? ` ${STATUS_BORDER[backup.status] ?? 'border-gray-200 dark:border-coolgray-300'}`
                    : ' border-gray-200 dark:border-coolgray-300')
            }
        >
            <div className="flex items-center gap-2 mb-2">
                {backup.status ? (
                    <span className={`px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs ${STATUS_BADGE[backup.status] ?? ''}`}>
                        {STATUS_LABELS[backup.status] ?? backup.status}
                    </span>
                ) : (
                    <span className="px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs bg-gray-100 text-gray-800 dark:bg-neutral-800 dark:text-gray-200">
                        No executions yet
                    </span>
                )}
                <h3 className="font-semibold">{backup.frequency} Backup</h3>
            </div>
            <div className="text-gray-600 dark:text-gray-400 text-sm">
                {backup.timingText ? (
                    <>
                        {backup.timingText}
                        {backup.sizeText && <> • Size: {backup.sizeText}</>}
                        {backup.saveS3 && <> • S3: Enabled</>}
                        <br />
                        Total Executions: {backup.totalExecutions}
                        {backup.successRate !== null && (
                            <>
                                {' '}
                                • Success Rate:{' '}
                                <span
                                    className={
                                        backup.successRate >= 80
                                            ? 'font-medium text-green-600'
                                            : backup.successRate >= 50
                                              ? 'font-medium text-warning-600'
                                              : 'font-medium text-red-600'
                                    }
                                >
                                    {backup.successRate}%
                                </span>
                            </>
                        )}
                    </>
                ) : (
                    <>Last Run: Never • Total Executions: 0{backup.saveS3 && <> • S3: Enabled</>}</>
                )}
            </div>
        </div>
    );
}

function SetTypeForm({ setTypeUrl }) {
    const { data, setData, post, processing } = useForm({ custom_type: 'mysql' });

    function submit(e) {
        e.preventDefault();
        post(setTypeUrl, { preserveScroll: true });
    }

    return (
        <div>
            <div>Select the type of database to enable automated backups.</div>
            <div className="pb-4">If your database is not listed, automated backups are not supported.</div>
            <form onSubmit={submit} className="flex gap-2 items-end">
                <label className="flex flex-col gap-1 w-96">
                    Type
                    <select
                        id="database-backups-set-type"
                        name="database-backups-set-type"
                        value={data.custom_type}
                        onChange={(e) => setData('custom_type', e.target.value)}
                    >
                        <option value="mysql">MySQL</option>
                        <option value="mariadb">MariaDB</option>
                        <option value="postgresql">PostgreSQL</option>
                        <option value="mongodb">MongoDB</option>
                    </select>
                </label>
                <button type="submit" disabled={processing}>
                    Set
                </button>
            </form>
        </div>
    );
}

export default function DatabaseBackups({
    service,
    configurationChecker,
    needsCustomType,
    scheduledBackups,
    selectedBackup,
    s3Storages,
    executions,
    executionsCount,
    skip,
    defaultTake,
    currentPage,
    showNext,
    showPrev,
    parameters,
    urls,
    setTypeUrl,
}) {
    const [showAddModal, setShowAddModal] = useState(false);
    const base = `/project/${parameters.project_uuid}/environment/${parameters.environment_uuid}/service/${parameters.service_uuid}`;
    const sidebarBase = `${base}/${parameters.stack_service_uuid}`;

    function selectBackup(id) {
        const params = new URLSearchParams(window.location.search);
        params.set('selectedBackupId', id);
        params.delete('skip');
        router.get(`${window.location.pathname}?${params.toString()}`, {}, { preserveScroll: true });
    }

    return (
        <div>
            <ServiceHeading service={service} parameters={parameters} urls={urls} />
            <ConfigurationChecker configurationChecker={configurationChecker} />

            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <div className="sub-menu-wrapper">
                    <a className="sub-menu-item" href={base}>
                        <span className="menu-item-label">Back</span>
                    </a>
                    <a className="sub-menu-item" href={sidebarBase}>
                        <span className="menu-item-label">General</span>
                    </a>
                    <a className="sub-menu-item" href={`${sidebarBase}/advanced`}>
                        <span className="menu-item-label">Advanced</span>
                    </a>
                    <a className="sub-menu-item menu-item-active" href={`${sidebarBase}/backups`}>
                        <span className="menu-item-label">Backups</span>
                    </a>
                </div>

                <div className="w-full">
                    <h2 className="pb-4">Scheduled Backups</h2>

                    {needsCustomType ? (
                        <SetTypeForm setTypeUrl={setTypeUrl} />
                    ) : (
                        <>
                            <div className="flex gap-2 pb-4">
                                <button type="button" onClick={() => setShowAddModal(true)}>
                                    + Add
                                </button>
                            </div>

                            <div className="flex flex-col gap-2">
                                {scheduledBackups.length === 0 && <div>No scheduled backups configured.</div>}
                                {scheduledBackups.map((backup) => (
                                    <BackupCard key={backup.id} backup={backup} onSelect={selectBackup} />
                                ))}
                            </div>

                            {selectedBackup && (
                                <div className="pt-10">
                                    <BackupEditForm backup={selectedBackup} s3Storages={s3Storages} urls={urls} />
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
                                </div>
                            )}

                            <CreateBackupModal
                                open={showAddModal}
                                onClose={() => setShowAddModal(false)}
                                storeUrl={urls.store}
                                s3Storages={s3Storages}
                            />
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
