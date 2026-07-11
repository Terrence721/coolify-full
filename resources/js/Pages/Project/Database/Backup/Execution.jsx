import BackupEditForm from '../../../../Components/BackupEditForm';
import BackupExecutionsList from '../../../../Components/BackupExecutionsList';
import ConfigurationChecker from '../../../../Components/ConfigurationChecker';
import DatabaseHeading from '../../../../Components/DatabaseHeading';

export default function Execution({
    heading,
    configurationChecker,
    backup,
    s3Storages,
    executions,
    executionsCount,
    skip,
    defaultTake,
    currentPage,
    showNext,
    showPrev,
    urls,
}) {
    return (
        <div>
            <h1>Backups</h1>
            <ConfigurationChecker configurationChecker={configurationChecker} />
            <DatabaseHeading heading={heading} urls={urls} />

            <BackupEditForm backup={backup} s3Storages={s3Storages} urls={urls} />

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
    );
}
