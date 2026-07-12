import { Deferred, Head } from '@inertiajs/react';
import { useState } from 'react';
import TerminalWindow from '../../Components/TerminalWindow';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export default function Index({ servers, containers, terminalConfig, connectUrl }) {
    const [selectedUuid, setSelectedUuid] = useState('default');
    const [connecting, setConnecting] = useState(false);
    const [connectError, setConnectError] = useState(null);
    const [noShell, setNoShell] = useState(false);
    const [pendingCommand, setPendingCommand] = useState(null);

    async function connect(e) {
        e.preventDefault();
        if (selectedUuid === 'default') {
            setConnectError('Please select a server or a container.');
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
                body: JSON.stringify({ selected_uuid: selectedUuid }),
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
            <Head title="Terminal" />
            <h1>Terminal</h1>
            <div className="flex gap-2 items-end subtitle">
                <div>Execute commands on your servers and containers without leaving the browser.</div>
                <span
                    className="cursor-help text-xs text-neutral-500"
                    title="If you're having trouble connecting to your server, make sure that the port is open. See https://coolify.io/docs/knowledge-base/server/firewall/#terminal"
                >
                    (?)
                </span>
            </div>

            {connectError && <div className="text-error pt-2">{connectError}</div>}

            <Deferred data="containers" fallback={<div className="pt-1">Loading servers and containers...</div>}>
                {servers.length > 0 ? (
                    <form className="flex flex-col gap-2 justify-center xl:items-end xl:flex-row" onSubmit={connect}>
                        <select
                            id="selected_uuid"
                            required
                            value={selectedUuid}
                            onChange={(e) => setSelectedUuid(e.target.value)}
                        >
                            <option value="default">Select a server or container</option>
                            {servers.map((server) => (
                                <optgroup key={server.uuid} label={server.name}>
                                    <option value={server.uuid}>{server.name}</option>
                                    {(containers || [])
                                        .filter((container) => container.server_uuid === server.uuid)
                                        .map((container) => (
                                            <option key={container.uuid} value={container.uuid}>
                                                {server.name} -&gt; {container.name}
                                            </option>
                                        ))}
                                </optgroup>
                            ))}
                        </select>
                        <button type="submit" disabled={connecting}>
                            Connect
                        </button>
                    </form>
                ) : (
                    <div>No servers with terminal access found.</div>
                )}
            </Deferred>

            <TerminalWindow terminalConfig={terminalConfig} pendingCommand={pendingCommand} noShell={noShell} />
        </div>
    );
}
