import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTeamChannel } from '../hooks/useTeamChannel';

/**
 * Shared across every resource type (Application, Database, Service) — mirrors
 * App\Livewire\Project\Shared\ConfigurationChecker, which broadcasts the same
 * ApplicationConfigurationChanged event name regardless of resource type. First
 * built for Project/Application/Deployment/Index (Phase 7); moved here in Phase 40
 * once Project/Database/Backup/Index became a second consumer.
 */
export default function ConfigurationChecker({ configurationChecker }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [expandedRows, setExpandedRows] = useState({});

    useTeamChannel(['ApplicationConfigurationChanged'], () => {
        router.reload({ only: ['configurationChecker'] });
    });

    const { isConfigurationChanged, isExited, configHash, diff } = configurationChecker;

    if (!isConfigurationChanged || configHash === null || isExited) {
        return null;
    }

    const changes = diff?.changes ?? [];
    const count = changes.length;
    const requiresBuild = changes.some((c) => c.impact === 'build');

    const groups = changes.reduce((acc, change) => {
        const label = change.section_label ?? 'Other';
        (acc[label] ??= []).push(change);

        return acc;
    }, {});

    function toggleRow(key) {
        setExpandedRows((prev) => ({ ...prev, [key]: !prev[key] }));
    }

    return (
        <div>
            <div className="flex items-center gap-2 rounded-md border border-warning/30 bg-warning/10 p-3 text-sm">
                <span>
                    {count > 0 ? (
                        <>
                            {count} unapplied configuration {count === 1 ? 'change' : 'changes'} detected.{' '}
                            {requiresBuild ? 'A rebuild is required.' : 'Please redeploy to apply the new configuration.'}{' '}
                            <button type="button" className="font-semibold underline" onClick={() => setModalOpen(true)}>
                                View changes
                            </button>
                        </>
                    ) : (
                        'Please redeploy to apply the new configuration.'
                    )}
                </span>
            </div>

            {count > 0 && modalOpen && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setModalOpen(false)} />
                    <div className="relative flex max-h-[85vh] w-full flex-col rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                        <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                            <div>
                                <h3 className="text-2xl font-bold">Configuration changes</h3>
                                <p className="mt-1 text-sm opacity-70">These changes are not applied to the latest deployment yet.</p>
                            </div>
                            <button type="button" onClick={() => setModalOpen(false)}>
                                ✕
                            </button>
                        </div>
                        <div className="overflow-y-auto p-6">
                            <div className="mb-2 flex flex-wrap items-center gap-2 font-semibold">
                                <span>
                                    {count} configuration {count === 1 ? 'change' : 'changes'}
                                </span>
                                <span
                                    className={`rounded-sm px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase leading-none ${
                                        requiresBuild ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'
                                    }`}
                                >
                                    {requiresBuild ? 'Rebuild required' : 'Redeploy required'}
                                </span>
                            </div>
                            <div className="space-y-4">
                                {Object.entries(groups).map(([sectionLabel, sectionChanges]) => (
                                    <div key={sectionLabel}>
                                        <div className="mb-0.5 text-[0.65rem] font-semibold uppercase tracking-wide opacity-60">
                                            {sectionLabel}
                                        </div>
                                        <div className="rounded-sm border border-neutral-300 dark:border-coolgray-200">
                                            <div className="grid grid-cols-[12rem_1fr_1.5rem_1fr] items-center gap-2 bg-neutral-100 px-3 py-1.5 text-[0.65rem] font-semibold uppercase tracking-wide dark:bg-coolgray-200">
                                                <div>Field</div>
                                                <div>From</div>
                                                <div></div>
                                                <div>To</div>
                                            </div>
                                            <div className="divide-y divide-neutral-300 dark:divide-coolgray-200">
                                                {sectionChanges.map((change) => {
                                                    const key = String(change.key);
                                                    const expandable = !!change.expandable;
                                                    const labelTruncated = (change.label ?? '').length > 20;
                                                    const rowExpandable = expandable || labelTruncated;
                                                    const isExpanded = !!expandedRows[key];

                                                    return (
                                                        <div key={key} className="grid grid-cols-[12rem_1fr_1.5rem_1fr] items-start gap-2 px-3 py-1.5 text-sm">
                                                            <div className="min-w-0 shrink-0 font-medium">
                                                                <div className={isExpanded ? 'wrap-break-word' : 'truncate'}>
                                                                    {isExpanded ? change.label : String(change.label ?? '').slice(0, 20)}
                                                                </div>
                                                            </div>
                                                            <div className="min-w-0 text-red-700 dark:text-red-400/80">
                                                                <div className={isExpanded ? 'wrap-break-word whitespace-pre-wrap' : 'truncate'}>
                                                                    {isExpanded ? (change.old_full_value ?? change.old_display_value) : change.old_display_value}
                                                                </div>
                                                            </div>
                                                            <div className="text-center opacity-50">→</div>
                                                            <div className="flex min-w-0 items-start gap-1 text-green-700 dark:text-green-500">
                                                                <div className="min-w-0 flex-1">
                                                                    <div className={isExpanded ? 'wrap-break-word whitespace-pre-wrap' : 'truncate'}>
                                                                        {isExpanded ? (change.new_full_value ?? change.new_display_value) : change.new_display_value}
                                                                    </div>
                                                                </div>
                                                                {rowExpandable && (
                                                                    <button type="button" title="Toggle full value" onClick={() => toggleRow(key)}>
                                                                        {isExpanded ? '−' : '+'}
                                                                    </button>
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
