import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { getEcho } from '../echo';

/**
 * Subscribes to the current team's private broadcast channel for the
 * duration of the component's lifetime, matching Livewire's
 * `getListeners()` -> `"echo-private:team.{id},EventClass" => handler` pattern.
 *
 * None of these events override `broadcastAs()`, so the wire event name is the
 * fully-qualified class name (e.g. `App\Events\ProxyStatusChangedUI`), not just
 * the class basename. Pass the short name here - this hook adds the `App\Events\`
 * prefix and the leading dot Echo requires to treat it as an exact, non-namespaced
 * event name (see https://laravel.com/docs/broadcasting#listening-for-events).
 *
 * @param {string[]} events - broadcast event class basenames, e.g. ['ProxyStatusChangedUI']
 * @param {(eventName: string, payload: any) => void} onEvent
 */
export function useTeamChannel(events, onEvent) {
    const { currentTeam, echo: echoConfig } = usePage().props;
    const teamId = currentTeam?.id;

    useEffect(() => {
        if (!teamId || !echoConfig || events.length === 0) {
            return;
        }

        const echo = getEcho(echoConfig);
        const channel = echo.private(`team.${teamId}`);

        events.forEach((eventName) => {
            channel.listen(`.App\\Events\\${eventName}`, (payload) => onEvent(eventName, payload));
        });

        return () => {
            events.forEach((eventName) => {
                channel.stopListening(`.App\\Events\\${eventName}`);
            });
            echo.leave(`team.${teamId}`);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [teamId, echoConfig, events.join(',')]);
}
