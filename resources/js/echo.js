import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echoInstance = null;

// Lazily created so pages that never subscribe to anything (the vast majority,
// still) don't pay for a websocket connection. Config comes from the shared
// Inertia `echo` prop (see HandleInertiaRequests::share()), which mirrors the
// same constants.pusher.* values the Livewire/Alpine stack's inline <script>
// in layouts/base.blade.php has always used - same Soketi instance, same auth.
export function getEcho(config) {
    if (echoInstance) {
        return echoInstance;
    }

    echoInstance = new Echo({
        broadcaster: 'pusher',
        cluster: config.host,
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: false,
        encrypted: true,
        enableStats: false,
        enabledTransports: ['ws', 'wss'],
        disabledTransports: ['sockjs', 'xhr_streaming', 'xhr_polling'],
    });

    return echoInstance;
}
