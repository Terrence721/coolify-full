import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import MonacoEditor from '../../../Components/MonacoEditor';
import ServerNavbar from '../../../Components/ServerNavbar';
import ServerSidebar from '../../../Components/ServerSidebar';
import { useTeamChannel } from '../../../hooks/useTeamChannel';

const RESERVED_DISPLAY_NAMES = ['coolify.yaml', 'Caddyfile', 'coolify.caddy', 'default_redirect_503.yaml', 'default_redirect_503.caddy'];

function DynamicConfigurationModal({ initialFileName, initialValue, newFile, storeUrl, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        fileName: initialFileName ?? '',
        value: initialValue ?? '',
        newFile,
    });

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">{newFile ? 'New Dynamic Configuration' : 'Edit Configuration'}</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col gap-2" onSubmit={submit}>
                    <label className="flex flex-col gap-1">
                        Filename
                        <input
                            id="dynamic-configuration-filename"
                            name="dynamic-configuration-filename"
                            required
                            value={data.fileName}
                            onChange={(e) => setData('fileName', e.target.value)}
                        />
                        {errors.fileName && <span className="text-error">{errors.fileName}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Configuration
                        <MonacoEditor value={data.value} onChange={(value) => setData('value', value)} language="yaml" height="20rem" />
                        {errors.value && <span className="text-error">{errors.value}</span>}
                    </label>
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                </form>
            </div>
        </div>
    );
}

export default function DynamicConfigurations({ serverNavbar, sidebar, isFunctional, canUpdate, contents, storeUrl, deleteUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);
    const [editingFile, setEditingFile] = useState(null);

    useTeamChannel(['ProxyStatusChangedUI'], () => {
        router.reload({ only: ['contents'] });
    });

    function reload() {
        router.reload({ only: ['contents'] });
    }

    function deleteFile(fileName) {
        router.delete(deleteUrl, {
            data: { fileName },
            preserveScroll: true,
        });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                {isFunctional && (
                    <div className="w-full">
                        <div className="flex gap-2">
                            <h2>Dynamic Configurations</h2>
                            <button type="button" onClick={reload}>
                                Reload
                            </button>
                            {canUpdate && (
                                <button type="button" onClick={() => setShowAddModal(true)}>
                                    + Add
                                </button>
                            )}
                        </div>
                        <div className="pb-4">You can add dynamic proxy configurations here.</div>

                        <div className="flex flex-col gap-4">
                            {contents.length > 0 ? (
                                contents.map((item) => (
                                    <div key={item.fileName} className="flex flex-col gap-2 py-2">
                                        {RESERVED_DISPLAY_NAMES.includes(item.fileName) ? (
                                            <>
                                                <h3 className="dark:text-white">File: {item.fileName}</h3>
                                                <textarea
                                                    id={`dynamic-configuration-${item.fileName}`}
                                                    name={`dynamic-configuration-${item.fileName}`}
                                                    disabled
                                                    rows={5}
                                                    value={item.value}
                                                    readOnly
                                                />
                                            </>
                                        ) : (
                                            <>
                                                <div className="flex gap-2">
                                                    <h3 className="dark:text-white">File: {item.fileName}</h3>
                                                    {canUpdate && (
                                                        <div className="flex gap-2">
                                                            <button type="button" onClick={() => setEditingFile(item)}>
                                                                Edit
                                                            </button>
                                                            <button type="button" onClick={() => deleteFile(item.fileName)}>
                                                                Delete
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                                <MonacoEditor value={item.value} readOnly language="yaml" height="15rem" />
                                            </>
                                        )}
                                    </div>
                                ))
                            ) : (
                                <div>No dynamic configurations found.</div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {showAddModal && (
                <DynamicConfigurationModal
                    newFile
                    storeUrl={storeUrl}
                    onClose={() => setShowAddModal(false)}
                />
            )}
            {editingFile && (
                <DynamicConfigurationModal
                    initialFileName={editingFile.fileName}
                    initialValue={editingFile.value}
                    newFile={false}
                    storeUrl={storeUrl}
                    onClose={() => setEditingFile(null)}
                />
            )}
        </div>
    );
}
