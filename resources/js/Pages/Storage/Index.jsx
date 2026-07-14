import { useState } from 'react';
import AddStorageModal from '../../Components/AddStorageModal';

export default function Index({ storages, canCreate, createUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);

    function openAddModal() {
        setShowAddModal(true);
    }

    function closeAddModal() {
        setShowAddModal(false);
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>S3 Storages</h1>
                {canCreate && (
                    <button type="button" onClick={openAddModal}>
                        + Add
                    </button>
                )}
            </div>
            <div className="subtitle">S3 storages for backups.</div>
            <div className="grid gap-4 lg:grid-cols-2 -mt-1">
                {storages.length === 0 && <div>No storage found.</div>}
                {storages.map((storage) => (
                    <a key={storage.uuid} href={storage.showUrl} className="gap-2 border cursor-pointer coolbox group">
                        <div className="flex flex-col justify-center mx-6">
                            <div className="box-title">{storage.name}</div>
                            <div className="box-description">{storage.description}</div>
                            {!storage.isUsable && (
                                <span className="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                                    Not Usable
                                </span>
                            )}
                        </div>
                    </a>
                ))}
            </div>

            {showAddModal && <AddStorageModal createUrl={createUrl} onClose={closeAddModal} />}
        </div>
    );
}
