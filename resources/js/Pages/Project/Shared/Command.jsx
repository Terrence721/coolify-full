import { Head } from '@inertiajs/react';
import { useState } from 'react';
import TerminalWindow from '../../../Components/TerminalWindow';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Port of livewire/project/shared/execute-container-command.blade.php's
 * application/database/service branches (the `server` branch is Server/Command.jsx instead,
 * since it uses ServerNavbar chrome rather than a project/environment/resource breadcrumb).
 * Reuses TerminalWindow.jsx (built in Phase 8 for the standalone /terminal page) unchanged.
 *
 * Deliberate v1 simplification: the original also nests ConfigurationChecker and a
 * Heading/DatabaseHeading/ServiceHeading variant above the picker. Left out here to keep this
 * page as lean as the standalone Terminal page (which has never shown per-resource
 * configuration/heading info either) — that information is one click away on the resource's
 * own Logs/Configuration page.
 */
export default function Command({ title, containers, terminalConfig, connectUrl }) {
    const [selectedContainer, setSelectedContainer] = useState('default');
    const [connecting, setConnecting] = useState(false);
    const [connectError, setConnectError] = useState(null);
    const [noShell, setNoShell] = useState(false);
    const [pendingCommand, setPendingCommand] = useState(null);

    async function connect(e) {
        e.preventDefault();
        if (selectedContainer === 'default') {
            setConnectError('Please select a container.');
            return;
        }

        setConnecting(true);
        setConnectError(null);
        setNoShell(false);

        try {
            const response = await fetch(connectUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ selected_container: selectedContainer }),
            });
            const data = await response.json();

            if (!response.ok) {
                setConnectError(data.error || 'Failed to connect.');
                setNoShell(data.reason === 'no-shell');
                return;
            }

            setPendingCommand({ command: data.command, key: Date.now() });
        } catch (error) {
            setConnectError('Failed to connect.');
        } finally {
            setConnecting(false);
        }
    }

    return (
        <div>
            <Head title={`${title} > Commands`} />
            <h1>Terminal</h1>
            <h2 className="pb-4">Terminal</h2>

            {connectError && <div className="text-error pt-2">{connectError}</div>}

            {containers.length === 0 ? (
                <div>No containers are running or terminal access is disabled on this server.</div>
            ) : (
                <form className="w-96 min-w-fit flex gap-2 items-end" onSubmit={connect}>
                    <select
                        id="container"
                        required
                        value={selectedContainer}
                        onChange={(e) => setSelectedContainer(e.target.value)}
                    >
                        <option disabled value="default">Select a container</option>
                        {containers.map((container) => (
                            <option key={container.name} value={container.name}>
                                {container.name} ({container.serverName})
                            </option>
                        ))}
                    </select>
                    <button type="submit" disabled={connecting}>
                        {connecting ? 'Connecting...' : 'Connect'}
                    </button>
                </form>
            )}

            <div className="mx-auto w-full">
                <TerminalWindow terminalConfig={terminalConfig} pendingCommand={pendingCommand} noShell={noShell} />
            </div>
        </div>
    );
}
