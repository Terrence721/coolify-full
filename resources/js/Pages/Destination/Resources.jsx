import { useState } from 'react';

export default function Resources({ destination, resources, showUrl }) {
    const [search, setSearch] = useState('');

    const filtered = resources.filter(
        (row) =>
            search === '' ||
            [row.type, row.name, row.project, row.environment].filter(Boolean).join(' ').toLowerCase().includes(search.toLowerCase()),
    );

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Destination</h1>
            </div>
            <div className="subtitle">Resources deployed to this Docker network.</div>

            <div className="navbar-main">
                <nav className="flex shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
                    <a href={showUrl}>General</a>
                    <a className="dark:text-white" href={`/destination/${destination.uuid}/resources`}>
                        Resources
                    </a>
                </nav>
            </div>

            <div className="pt-4">
                {resources.length === 0 ? (
                    <div className="py-4 text-sm opacity-70">No resources are using this destination.</div>
                ) : (
                    <>
                        <input
                            id="destination-resources-search"
                            name="destination-resources-search"
                            placeholder="Search resources..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <div className="overflow-x-auto pt-4">
                            <table className="min-w-full">
                                <thead>
                                    <tr>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Project</th>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Environment</th>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Type</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {filtered.map((row) => (
                                        <tr
                                            key={`destination-resource-${row.type}-${row.uuid}`}
                                            className="dark:hover:bg-coolgray-300 hover:bg-neutral-100"
                                        >
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{row.project}</td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{row.environment}</td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">
                                                {row.url ? <a href={row.url}>{row.name}</a> : <span>{row.name}</span>}
                                            </td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">
                                                {row.type.charAt(0).toUpperCase() + row.type.slice(1)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
