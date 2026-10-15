/**
 * Laravel Echo / Reverb client for the admin panel.
 *
 * The panel already refreshes on a 5s poll (Filament's ->poll('5s')); this adds
 * the push path: subscribing to private-node.{node_id} / private-fleet delivers
 * command acks, config publications and status changes the moment they happen.
 *
 * Channel authorization reuses the panel session (routes/channels.php); agents
 * authenticate with their HMAC signature instead, see
 * POST /api/agent/v1/broadcasting/auth.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

window.cpSubscribeFleet = function (handlers = {}) {
    if (! window.Echo) { return null; }

    return window.Echo.private('fleet')
        .listen('.command.issued', (event) => handlers.command && handlers.command(event))
        .listen('.config.published', (event) => handlers.config && handlers.config(event))
        .listen('.status.changed', (event) => handlers.status && handlers.status(event));
};

window.cpSubscribeNode = function (nodeId, handlers = {}) {
    if (! window.Echo) { return null; }

    return window.Echo.private(`node.${nodeId}`)
        .listen('.command.issued', (event) => handlers.command && handlers.command(event))
        .listen('.config.published', (event) => handlers.config && handlers.config(event));
};
