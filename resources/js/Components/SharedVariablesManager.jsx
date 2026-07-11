import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function DeleteVariableConfirmModal({ variableKey, onClose, onConfirm }) {
    const [confirmation, setConfirmation] = useState('');

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <h3 className="text-2xl font-bold pb-4">Confirm Environment Variable Deletion?</h3>
                <ul className="list-disc pl-4 pb-4 text-sm">
                    <li>The selected environment variable will be permanently deleted.</li>
                </ul>
                <label className="flex flex-col gap-1 pb-4">
                    Please confirm the execution of the actions by entering the Environment Variable Name below
                    <input value={confirmation} onChange={(e) => setConfirmation(e.target.value)} placeholder={variableKey} />
                </label>
                <div className="flex gap-2 justify-end">
                    <button type="button" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" disabled={confirmation !== variableKey} onClick={onConfirm}>
                        Permanently Delete
                    </button>
                </div>
            </div>
        </div>
    );
}

function VariableRow({ variable, canUpdate }) {
    const [key, setKey] = useState(variable.key);
    const [value, setValue] = useState(variable.value);
    const [comment, setComment] = useState(variable.comment ?? '');
    const [isMultiline, setIsMultiline] = useState(variable.isMultiline);
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        setKey(variable.key);
        setValue(variable.value);
        setComment(variable.comment ?? '');
        setIsMultiline(variable.isMultiline);
    }, [variable.key, variable.value, variable.comment, variable.isMultiline]);

    function submitUpdate(e) {
        e.preventDefault();
        setProcessing(true);
        router.put(
            variable.updateUrl,
            { key, value, comment, is_multiline: isMultiline, is_literal: variable.isLiteral, is_shown_once: variable.isShownOnce },
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    }

    function toggleMultiline(checked) {
        setIsMultiline(checked);
        router.put(
            variable.updateUrl,
            { key, value, comment, is_multiline: checked, is_literal: variable.isLiteral, is_shown_once: variable.isShownOnce },
            { preserveScroll: true },
        );
    }

    function lock() {
        router.post(variable.lockUrl, {}, { preserveScroll: true });
    }

    function destroy() {
        setShowDeleteModal(false);
        router.delete(variable.deleteUrl, { preserveScroll: true });
    }

    if (variable.isShownOnce) {
        return (
            <div className="flex flex-col gap-2 p-4 bg-white border dark:border-coolgray-300 border-neutral-200 dark:bg-base lg:flex-row lg:items-end">
                <div className="flex flex-1 gap-2">
                    <input disabled value={key} className="flex-1" />
                    {canUpdate && (
                        <button type="button" onClick={() => setShowDeleteModal(true)}>
                            Delete
                        </button>
                    )}
                </div>
                {canUpdate && (
                    <form onSubmit={submitUpdate} className="flex flex-1 items-end gap-2">
                        <label className="flex flex-1 flex-col gap-1">
                            Comment
                            <input
                                value={comment}
                                onChange={(e) => setComment(e.target.value)}
                                maxLength={256}
                                placeholder="Add a note to document what this environment variable is used for."
                            />
                        </label>
                        <button type="submit" disabled={processing}>
                            Update
                        </button>
                    </form>
                )}
                {showDeleteModal && (
                    <DeleteVariableConfirmModal variableKey={key} onClose={() => setShowDeleteModal(false)} onConfirm={destroy} />
                )}
            </div>
        );
    }

    return (
        <form
            onSubmit={submitUpdate}
            className="flex flex-col gap-2 p-4 bg-white border dark:border-coolgray-300 border-neutral-200 dark:bg-base lg:items-start"
        >
            <div className="flex flex-col w-full gap-2 lg:flex-row">
                <input value={key} onChange={(e) => setKey(e.target.value)} disabled={!canUpdate} className="flex-1" required />
                {isMultiline ? (
                    <textarea
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        disabled={!canUpdate}
                        className="flex-1 font-mono"
                        rows={4}
                    />
                ) : (
                    <input
                        type="password"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        disabled={!canUpdate}
                        className="flex-1 font-mono"
                    />
                )}
            </div>
            <label className="flex w-full flex-col gap-1">
                Comment
                <input
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    disabled={!canUpdate}
                    maxLength={256}
                    placeholder="Add a note to document what this environment variable is used for."
                />
            </label>
            {canUpdate && (
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={isMultiline} onChange={(e) => toggleMultiline(e.target.checked)} />
                    Is Multiline?
                </label>
            )}
            {canUpdate && (
                <div className="flex w-full justify-end gap-2">
                    <button type="submit" disabled={processing}>
                        Update
                    </button>
                    <button type="button" onClick={lock}>
                        Lock
                    </button>
                    <button type="button" onClick={() => setShowDeleteModal(true)}>
                        Delete
                    </button>
                </div>
            )}
            {showDeleteModal && <DeleteVariableConfirmModal variableKey={key} onClose={() => setShowDeleteModal(false)} onConfirm={destroy} />}
        </form>
    );
}

function DevView({ devViewText, bulkUpdateUrl, canUpdate, scope }) {
    const [value, setValue] = useState(devViewText);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        setValue(devViewText);
    }, [devViewText]);

    function submit(e) {
        e.preventDefault();
        setProcessing(true);
        router.post(bulkUpdateUrl, { variables: value }, { preserveScroll: true, onFinish: () => setProcessing(false) });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <label className="flex flex-col gap-1">
                {scope.charAt(0).toUpperCase() + scope.slice(1)} Shared Variables
                <textarea
                    rows={20}
                    className="whitespace-pre-wrap font-mono"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    disabled={!canUpdate}
                />
            </label>
            {canUpdate && (
                <button type="submit" disabled={processing}>
                    Save All Environment Variables
                </button>
            )}
        </form>
    );
}

function AddVariableModal({ open, onClose, storeUrl }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        key: '',
        value: '',
        comment: '',
        is_multiline: false,
        is_literal: false,
    });

    function handleClose() {
        reset();
        clearErrors();
        onClose();
    }

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                clearErrors();
                onClose();
            },
        });
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={handleClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New Shared Variable</h3>
                    <button type="button" onClick={handleClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col gap-2" onSubmit={submit}>
                    <label className="flex flex-col gap-1">
                        Name
                        <input placeholder="NODE_ENV" required value={data.key} onChange={(e) => setData('key', e.target.value)} />
                        {errors.key && <span className="text-error">{errors.key}</span>}
                    </label>
                    {data.is_multiline ? (
                        <label className="flex flex-col gap-1">
                            Value
                            <textarea
                                required
                                rows={6}
                                className="font-sans"
                                value={data.value}
                                onChange={(e) => setData('value', e.target.value)}
                            />
                            {errors.value && <span className="text-error">{errors.value}</span>}
                        </label>
                    ) : (
                        <label className="flex flex-col gap-1">
                            Value
                            <input placeholder="production" required value={data.value} onChange={(e) => setData('value', e.target.value)} />
                            {errors.value && <span className="text-error">{errors.value}</span>}
                        </label>
                    )}
                    <label className="flex flex-col gap-1">
                        Comment
                        <input
                            maxLength={256}
                            value={data.comment}
                            onChange={(e) => setData('comment', e.target.value)}
                            placeholder="Add a note to document what this environment variable is used for."
                        />
                        {errors.comment && <span className="text-error">{errors.comment}</span>}
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.is_multiline}
                            onChange={(e) => setData('is_multiline', e.target.checked)}
                        />
                        Is Multiline?
                    </label>
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                </form>
            </div>
        </div>
    );
}

export default function SharedVariablesManager({ label, scope, canUpdate, variables, devViewText, storeUrl, bulkUpdateUrl }) {
    const [view, setView] = useState('normal');
    const [showAddModal, setShowAddModal] = useState(false);

    const heading = scope === 'team' ? 'Team Shared Variables' : `Shared Variables for ${label}`;

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>{heading}</h1>
                {canUpdate && (
                    <button type="button" onClick={() => setShowAddModal(true)}>
                        + Add
                    </button>
                )}
                {canUpdate && (
                    <button type="button" onClick={() => setView(view === 'normal' ? 'dev' : 'normal')}>
                        {view === 'normal' ? 'Developer view' : 'Normal view'}
                    </button>
                )}
            </div>
            <div className="flex items-center gap-1 subtitle">
                You can use these variables anywhere with{' '}
                <span className="dark:text-warning text-coollabs">{`{{ ${scope}.VARIABLENAME }}`}</span>
            </div>

            {view === 'normal' ? (
                <div className="flex flex-col gap-2">
                    {variables.length === 0 && <div>No environment variables found.</div>}
                    {variables.map((variable) => (
                        <VariableRow key={variable.id} variable={variable} canUpdate={canUpdate} />
                    ))}
                </div>
            ) : (
                <DevView devViewText={devViewText} bulkUpdateUrl={bulkUpdateUrl} canUpdate={canUpdate} scope={scope} />
            )}

            <AddVariableModal open={showAddModal} onClose={() => setShowAddModal(false)} storeUrl={storeUrl} />
        </div>
    );
}
