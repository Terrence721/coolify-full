import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import TerminalWindow from '../../Components/TerminalWindow';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Port of livewire/project/shared/execute-container-command.blade.php's `server` branch —
 * a direct shell on the server itself (no container picker), reached from ServerNavbar's
 * "Terminal" link. Reuses TerminalWindow.jsx unchanged.
 */
export default function Command({ serverNavbar, isFunctional, isTerminalEnabled, terminalConfig, connectUrl }) {
    const [connecting, setConnecting] = useState(false);
    const [connectError, setConnectError] = useState(null);
    const [noShell, setNoShell] = useState(false);
    const [pendingCommand, setPendingCommand] = useState(null);

    async function connect(e) {
        e.preventDefault();
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

    const canConnect = isFunctional && isTerminalEnabled;

    return (
        <div>
            <Head title="Terminal" />
            <ServerNavbar serverNavbar={serverNavbar} />

            {canConnect ? (
                <>
                    <form className="w-full flex gap-2 items-start" onSubmit={connect}>
                        <h2 className="pb-4">Terminal</h2>
                        <button type="submit" disabled={connecting}>
                            {connecting ? 'Connecting...' : 'Connect'}
                        </button>
                    </form>
                    {connectError && <div className="text-error pt-2">{connectError}</div>}
                    <div className="mx-auto w-full">
                        <TerminalWindow terminalConfig={terminalConfig} pendingCommand={pendingCommand} noShell={noShell} />
                    </div>
                </>
            ) : (
                <div>Server is not functional or terminal access is disabled.</div>
            )}
        </div>
    );
}
