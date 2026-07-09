import { router } from '@inertiajs/react';
import ServerNavbar from '../../../Components/ServerNavbar';
import ServerSidebar from '../../../Components/ServerSidebar';

export default function TerminalAccess({ serverNavbar, sidebar, serverName, isTerminalEnabled, isAdmin, toggleUrl }) {
    function toggle() {
        const action = isTerminalEnabled ? 'disable' : 'enable';
        const confirmation = window.prompt(
            `This will ${action} terminal access for this server and all its containers. Type the server name to confirm:`,
        );
        if (confirmation !== serverName) return;

        const password = window.prompt('Enter your password to confirm:');
        if (!password) return;

        router.put(toggleUrl, { password }, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <div className="flex items-center gap-2">
                        <h2>Terminal Access</h2>
                        <span
                            className="cursor-help text-xs text-neutral-500"
                            title="Decide if users (including admins and the owner) can access the terminal for this server and its containers from the dashboard. Only team administrators and owners can change this setting."
                        >
                            (?)
                        </span>
                        {isAdmin && (
                            <button type="button" onClick={toggle}>
                                {isTerminalEnabled ? 'Disable Terminal' : 'Enable Terminal'}
                            </button>
                        )}
                    </div>
                    <div className="mb-4">Manage terminal access to this server and its containers.</div>

                    <div className="flex items-center gap-2">
                        <h3>Terminal Status:</h3>
                        {isTerminalEnabled ? (
                            <span className="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                                Operational
                            </span>
                        ) : (
                            <span className="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                                Disabled
                            </span>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
