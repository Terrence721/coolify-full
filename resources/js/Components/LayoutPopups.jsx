import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { getEcho } from '../echo';
import { useTeamChannel } from '../hooks/useTeamChannel';

const NOTIFICATION_KEY = 'popupNotification';
const REALTIME_KEY = 'popupRealtime';

function shouldShowMonthlyPopup(storageKey) {
    const disabledTimestamp = localStorage.getItem(storageKey);
    if (!disabledTimestamp || disabledTimestamp === 'false') return true;
    const disabledTime = parseInt(disabledTimestamp, 10);
    if (Number.isNaN(disabledTime)) return true;
    const now = new Date();
    const disabledDate = new Date(disabledTime);

    return now.getMonth() !== disabledDate.getMonth() || now.getFullYear() !== disabledDate.getFullYear();
}

function Popup({ title, description, onAcknowledge, buttonText = 'Accept and Close' }) {
    return (
        <div className="fixed bottom-0 right-0 w-full h-auto sm:px-5 sm:pb-5 z-999">
            <div className="flex lg:items-center flex-col justify-between w-full h-full max-w-4xl p-6 mx-auto bg-white border shadow-lg lg:border-t dark:border-coolgray-300 border-neutral-200 dark:bg-coolgray-100 lg:p-8 lg:flex-row sm:rounded-sm">
                <div className="pt-6 lg:pt-0 pb-6 lg:pb-0 lg:pr-6 dark:text-neutral-300">
                    <h4 className="w-full mb-1 text-xl font-bold leading-none text-neutral-900 dark:text-white">{title}</h4>
                    <p className="text-xs">{description}</p>
                </div>
                <button
                    type="button"
                    onClick={onAcknowledge}
                    className="w-full px-4 py-2 text-sm font-medium tracking-wide transition-colors duration-200 rounded-md bg-neutral-100 hover:bg-neutral-200 dark:bg-coolgray-200 lg:w-auto dark:text-neutral-200 dark:hover:bg-coolgray-300"
                >
                    {buttonText}
                </button>
            </div>
        </div>
    );
}

/**
 * React port of the former App\Livewire\LayoutPopups (deleted, no longer exists) — the two
 * dismissible monthly-reminder popups (no-notification-channel-enabled,
 * real-time-service-unreachable) plus the TestEvent listener used by Settings' "Test Realtime"
 * button. Kept the same localStorage keys and monthly re-show logic as the original.
 */
export default function LayoutPopups() {
    const { props } = usePage();
    const isCloud = props.permissions?.isCloud;
    const notificationEnabled = props.currentTeam?.isAnyNotificationEnabled ?? true;

    const [showNotificationPopup, setShowNotificationPopup] = useState(false);
    const [showRealtimePopup, setShowRealtimePopup] = useState(false);

    useEffect(() => {
        setShowNotificationPopup(!notificationEnabled && shouldShowMonthlyPopup(NOTIFICATION_KEY));
    }, [notificationEnabled]);

    useEffect(() => {
        if (isCloud) return undefined;
        if (localStorage.getItem(REALTIME_KEY)) return undefined;
        if (!props.echo) return undefined;

        let checkNumber = 1;
        const echo = getEcho(props.echo);
        const interval = setInterval(() => {
            const state = echo.connector?.pusher?.connection?.state;
            if (state === 'connected') {
                clearInterval(interval);

                return;
            }
            checkNumber += 1;
            if (checkNumber > 5) {
                clearInterval(interval);
                setShowRealtimePopup(true);
                console.error(
                    'Coolify could not connect to its real-time service. This will cause unusual problems on the UI if not fixed! Please check the related documentation (https://coolify.io/docs/knowledge-base/cloudflare/tunnels/overview) or get help on Discord (https://coollabs.io/discord).',
                );
            }
        }, 2000);

        return () => clearInterval(interval);
         
    }, [isCloud, props.echo]);

    useTeamChannel(['TestEvent'], () => {
        window.toast?.('Success', { type: 'success', description: 'Realtime events configured!' });
    });

    return (
        <>
            {showRealtimePopup && !isCloud && (
                <Popup
                    title={
                        <>
                            <span className="font-bold text-red-500">WARNING: </span>Cannot connect to real-time service
                        </>
                    }
                    description={
                        <>
                            This will cause unusual problems on the UI!
                            <br />
                            <br />
                            Please ensure that you have opened the{' '}
                            <a className="underline" href="https://coolify.io/docs/knowledge-base/server/firewall" target="_blank" rel="noreferrer">
                                required ports
                            </a>{' '}
                            or get help on{' '}
                            <a className="underline" href="https://coollabs.io/discord" target="_blank" rel="noreferrer">
                                Discord
                            </a>
                            .
                        </>
                    }
                    buttonText="Acknowledge & Disable This Popup"
                    onAcknowledge={() => {
                        localStorage.setItem(REALTIME_KEY, 'disabled');
                        setShowRealtimePopup(false);
                    }}
                />
            )}
            {showNotificationPopup && (
                <Popup
                    title="No notifications enabled."
                    description={
                        <>
                            It is highly recommended to enable at least one notification channel to receive important alerts.
                            <br />
                            Visit{' '}
                            <a href="/notifications/email" className="underline dark:text-white">
                                /notification
                            </a>{' '}
                            to enable notifications.
                        </>
                    }
                    onAcknowledge={() => {
                        localStorage.setItem(NOTIFICATION_KEY, Date.now().toString());
                        setShowNotificationPopup(false);
                    }}
                />
            )}
        </>
    );
}
