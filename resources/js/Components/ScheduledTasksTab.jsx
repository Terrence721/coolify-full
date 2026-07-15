import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import PasswordConfirmModal from './PasswordConfirmModal';
import { useTeamChannel } from '../hooks/useTeamChannel';

/**
 * React port of the scheduled-tasks tab family (Project\Shared\ScheduledTask\{All,Add,Show,
 * Executions}), scoped to services — see ManagesResourceScheduledTasks. One component serves
 * both views of the tab: the task list + Add modal (no `task` prop) and the task detail
 * (edit form, Execute Now / Enable / Delete actions, recent executions with click-to-expand
 * chunked log output — 100 lines per "Load More", matching the original's logsPerPage).
 *
 * Executions refresh via a partial reload every 5s (the blade's wire:poll.5000ms), tightened
 * to 1s while the expanded execution is running (the blade's Alpine interval), plus the
 * ScheduledTaskDone team broadcast.
 */
const LOGS_PER_PAGE = 100;

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

function Field({ label, helper, ...props }) {
    return (
        <label className="flex flex-col flex-1 gap-1">
            <span title={helper}>{label}</span>
            <input {...props} />
        </label>
    );
}

const CONTAINER_HELPER =
    'You can leave this empty if your resource only has one service in your stack. Otherwise use the stack name, without the random generated ID. So if you have a mysql service in your stack, use mysql.';

function AddTaskModal({ containerNames, storeUrl, onClose }) {
    const [form, setForm] = useState({
        name: '',
        command: '',
        frequency: '',
        timeout: 300,
        container: containerNames[0] ?? '',
    });

    function submit(e) {
        e.preventDefault();
        router.post(storeUrl, form, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal title="New Scheduled Task" onClose={onClose}>
            <form className="flex flex-col w-full gap-2" onSubmit={submit}>
                <Field
                    id="scheduled-task-add-name"
                    name="scheduled-task-add-name"
                    label="Name"
                    required
                    placeholder="Run cron"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
                <Field
                    id="scheduled-task-add-command"
                    name="scheduled-task-add-command"
                    label="Command"
                    required
                    placeholder="php artisan schedule:run"
                    value={form.command}
                    onChange={(e) => setForm({ ...form, command: e.target.value })}
                />
                <Field
                    id="scheduled-task-add-frequency"
                    name="scheduled-task-add-frequency"
                    label="Frequency"
                    required
                    placeholder="0 0 * * * or daily"
                    helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression."
                    value={form.frequency}
                    onChange={(e) => setForm({ ...form, frequency: e.target.value })}
                />
                <Field
                    id="scheduled-task-add-timeout"
                    name="scheduled-task-add-timeout"
                    label="Timeout (seconds)"
                    type="number"
                    required
                    placeholder="300"
                    helper="Maximum execution time in seconds (60-36000). Default is 300 seconds (5 minutes)."
                    value={form.timeout}
                    onChange={(e) => setForm({ ...form, timeout: e.target.value })}
                />
                <label className="flex flex-col flex-1 gap-1">
                    Container name
                    <select id="container" name="container" value={form.container} onChange={(e) => setForm({ ...form, container: e.target.value })}>
                        {containerNames.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </select>
                </label>
                <button type="submit">Save</button>
            </form>
        </Modal>
    );
}

function TaskList({ tasks, containerNames, taskUrls, canUpdate }) {
    const [adding, setAdding] = useState(false);

    return (
        <div>
            <div className="flex gap-2">
                <h2>Scheduled Tasks</h2>
                {canUpdate && (
                    <button type="button" onClick={() => setAdding(true)}>
                        + Add
                    </button>
                )}
            </div>
            <div className="flex flex-col flex-wrap gap-2 pt-4">
                {tasks.length === 0 && <div>No scheduled tasks configured.</div>}
                {tasks.map((task) => (
                    <a key={task.uuid} className="coolbox" href={task.href}>
                        <span className="flex flex-col">
                            <span className="text-lg font-bold">
                                {task.name} {task.container && <span className="text-xs font-normal">({task.container})</span>}
                            </span>
                            <span>Frequency: {task.frequency}</span>
                            <span>Last run: {task.lastRunStatus}</span>
                        </span>
                    </a>
                ))}
            </div>
            {adding && <AddTaskModal containerNames={containerNames} storeUrl={taskUrls.store} onClose={() => setAdding(false)} />}
        </div>
    );
}

const STATUS_CHIP = {
    running: 'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
    success: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
};
const STATUS_BORDER = {
    running: 'border-blue-500/50 border-dashed',
    failed: 'border-error',
    success: 'border-success',
};
const STATUS_TEXT = { running: 'In Progress', failed: 'Failed', success: 'Success' };

function ExecutionEntry({ execution, selected, onSelect }) {
    const [pages, setPages] = useState(1);

    const lines = useMemo(() => (execution.message ? execution.message.split('\n') : []), [execution.message]);
    const visibleLines = lines.length > 0 ? lines.slice(0, pages * LOGS_PER_PAGE) : ['Waiting for task output...'];
    const hasMore = lines.length > pages * LOGS_PER_PAGE;

    return (
        <div className="flex flex-col gap-2">
            <button
                type="button"
                onClick={onSelect}
                className={`relative flex flex-col border-l-2 transition-colors p-4 cursor-pointer text-left bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white ${STATUS_BORDER[execution.status] ?? ''} ${selected ? 'bg-gray-200 dark:bg-coolgray-200' : ''}`}
            >
                <div className="flex items-center gap-2 mb-2">
                    <span className={`px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs ${STATUS_CHIP[execution.status] ?? ''}`}>
                        {STATUS_TEXT[execution.status] ?? execution.status}
                    </span>
                </div>
                <div className="text-gray-600 dark:text-gray-400 text-sm">
                    Started: {execution.createdAt}
                    {execution.status !== 'running' && execution.finishedAt && (
                        <>
                            <br />
                            Ended: {execution.finishedAt}
                            <br />
                            Duration: {execution.duration}
                            <br />
                            Finished {execution.finishedAgo}
                        </>
                    )}
                </div>
            </button>
            {execution.downloadUrl && (
                <a href={execution.downloadUrl} className="button text-center">
                    Download Logs
                </a>
            )}
            {selected && (
                <div className="p-4 mb-2 bg-gray-100 dark:bg-coolgray-200 rounded-sm">
                    {execution.status === 'running' && <div className="mb-2">Task is running...</div>}
                    <div className="max-h-150 overflow-y-auto border border-gray-200 dark:border-coolgray-300 rounded p-4 bg-gray-50 dark:bg-coolgray-100 scrollbar">
                        <pre className="whitespace-pre-wrap">{visibleLines.join('\n')}</pre>
                    </div>
                    {hasMore && (
                        <div className="flex gap-2 mt-4">
                            <button type="button" onClick={() => setPages(pages + 1)}>
                                Load More
                            </button>
                            <button type="button" onClick={() => setPages(Math.ceil(lines.length / LOGS_PER_PAGE))}>
                                Load All
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function TaskDetail({ task, executions, isResourceRunning, taskUrls, canUpdate }) {
    const [form, setForm] = useState({
        name: task.name,
        command: task.command,
        frequency: task.frequency,
        container: task.container ?? '',
        timeout: task.timeout,
    });
    const [selectedId, setSelectedId] = useState(null);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const pollRef = useRef(null);

    useTeamChannel(['ScheduledTaskDone'], () => {
        router.reload({ only: ['executions'], preserveScroll: true });
    });

    const selectedExecution = executions.find((e) => e.id === selectedId);
    const fastPoll = selectedExecution?.status === 'running';

    useEffect(() => {
        pollRef.current = setInterval(
            () => router.reload({ only: ['executions'], preserveScroll: true }),
            fastPoll ? 1000 : 5000,
        );

        return () => clearInterval(pollRef.current);
    }, [fastPoll]);

    function submit(e) {
        e.preventDefault();
        router.patch(taskUrls.update, { ...form, enabled: task.enabled }, { preserveScroll: true });
    }

    return (
        <div>
            <form onSubmit={submit} className="w-full">
                <div className="flex flex-col gap-2 pb-2">
                    <div className="flex gap-2 items-end flex-wrap">
                        <h2>Task {task.name}</h2>
                        {canUpdate && (
                            <>
                                <button type="submit">Save</button>
                                {isResourceRunning && (
                                    <button type="button" onClick={() => router.post(taskUrls.execute, {}, { preserveScroll: true })}>
                                        Execute Now
                                    </button>
                                )}
                                <button type="button" onClick={() => router.post(taskUrls.toggle, {}, { preserveScroll: true })}>
                                    {task.enabled ? 'Disable Task' : 'Enable Task'}
                                </button>
                                <button type="button" className="button-error" onClick={() => setConfirmingDelete(true)}>
                                    Delete
                                </button>
                            </>
                        )}
                    </div>
                    <h3 className="pt-4">Configuration</h3>
                    <div className="flex flex-col gap-2 w-full md:flex-row">
                        <Field
                            id="scheduled-task-detail-name"
                            name="scheduled-task-detail-name"
                            label="Name"
                            required
                            placeholder="Name"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            disabled={!canUpdate}
                        />
                        <Field
                            id="scheduled-task-detail-frequency"
                            name="scheduled-task-detail-frequency"
                            label="Frequency"
                            required
                            placeholder="0 0 * * * or daily"
                            helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression."
                            value={form.frequency}
                            onChange={(e) => setForm({ ...form, frequency: e.target.value })}
                            disabled={!canUpdate}
                        />
                        <Field
                            id="scheduled-task-detail-timeout"
                            name="scheduled-task-detail-timeout"
                            label="Timeout (seconds)"
                            type="number"
                            required
                            placeholder="300"
                            helper="Maximum execution time in seconds (60-36000)."
                            value={form.timeout}
                            onChange={(e) => setForm({ ...form, timeout: e.target.value })}
                            disabled={!canUpdate}
                        />
                        <Field
                            id="scheduled-task-detail-container"
                            name="scheduled-task-detail-container"
                            label="Service name"
                            placeholder="php"
                            helper={CONTAINER_HELPER}
                            value={form.container}
                            onChange={(e) => setForm({ ...form, container: e.target.value })}
                            disabled={!canUpdate}
                        />
                    </div>
                    <Field
                        id="scheduled-task-detail-command"
                        name="scheduled-task-detail-command"
                        label="Command"
                        required
                        placeholder="php artisan schedule:run"
                        value={form.command}
                        onChange={(e) => setForm({ ...form, command: e.target.value })}
                        disabled={!canUpdate}
                    />
                </div>
            </form>

            <div className="pt-4">
                <h3 className="py-4">
                    Recent executions <span className="text-xs text-neutral-500">(click to check output)</span>
                </h3>
                <div className="flex flex-col gap-2">
                    {executions.length === 0 && <div className="p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm">No executions found.</div>}
                    {executions.map((execution) => (
                        <ExecutionEntry
                            key={execution.id}
                            execution={execution}
                            selected={execution.id === selectedId}
                            onSelect={() => setSelectedId(execution.id === selectedId ? null : execution.id)}
                        />
                    ))}
                </div>
            </div>

            {confirmingDelete && (
                <PasswordConfirmModal
                    title="Confirm Scheduled Task Deletion?"
                    action={{ method: 'delete', url: taskUrls.destroy }}
                    actions={['The selected scheduled task will be permanently deleted.']}
                    confirmationText={task.name}
                    confirmationLabel="Please confirm the execution of the actions by entering the Scheduled Task Name below"
                    withPassword={false}
                    onClose={() => setConfirmingDelete(false)}
                    onDone={() => setConfirmingDelete(false)}
                />
            )}
        </div>
    );
}

export default function ScheduledTasksTab({ task, tasks, executions, containerNames, isResourceRunning, taskUrls, canUpdate }) {
    if (task) {
        return <TaskDetail task={task} executions={executions} isResourceRunning={isResourceRunning} taskUrls={taskUrls} canUpdate={canUpdate} />;
    }

    return <TaskList tasks={tasks} containerNames={containerNames} taskUrls={taskUrls} canUpdate={canUpdate} />;
}
