import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { getEcho } from '../echo';

/**
 * Subscribes to the current team's private broadcast channel for the
 * duration of the component's lifetime, matching Livewire's
 * `getListeners()` -> `"echo-private:team.{id},EventClass" => handler` pattern.
 *
 * Most of these events don't override `broadcastAs()`, so the wire event name is
 * the fully-qualified class name (e.g. `App\Events\ProxyStatusChangedUI`), not
 * just the class basename. Pass the short name here for those - this hook adds
 * the `App\Events\` prefix and the leading dot Echo requires to treat it as an
 * exact, non-namespaced event name (see
 * https://laravel.com/docs/broadcasting#listening-for-events).
 *
 * A handful of events (e.g. ServerValidated) *do* override `broadcastAs()` to a
 * bare name - for those, pass the name with a leading dot yourself (e.g.
 * '.ServerValidated') and this hook uses it exactly as given, skipping the
 * `App\Events\` prefix. Mixing this up is a real, silent bug: the listener
 * simply never fires, since the wire event name never matches.
 *
 * @param {string[]} events - broadcast event names; bare class basenames (no
 *   `broadcastAs()` override) or, for events with one, the exact name prefixed
 *   with a leading dot (e.g. '.ServerValidated')
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
        const listenNameFor = (eventName) => (eventName.startsWith('.') ? eventName : `.App\\Events\\${eventName}`);

        events.forEach((eventName) => {
            channel.listen(listenNameFor(eventName), (payload) => onEvent(eventName, payload));
        });

        return () => {
            events.forEach((eventName) => {
                channel.stopListening(listenNameFor(eventName));
            });
            echo.leave(`team.${teamId}`);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [teamId, echoConfig, events.join(',')]);
}
