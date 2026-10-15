<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Pusher\Pusher;

/**
 * Signs the agent into its own private channel (private-node.{node_id}).
 *
 * Reverb speaks the Pusher protocol, so the HMAC produced here is the standard
 * pusher socket auth signature: sha256(socket_id:channel) keyed with the app secret.
 */
class BroadcastingController extends Controller
{
    use InteractsWithAgent;

    public function __invoke(Request $request): Response
    {
        $node = $this->node($request);
        $socketId = (string) $request->input('socket_id');
        $channel = (string) $request->input('channel_name', 'private-node.' . $node->node_id);

        if ($socketId === '') {
            return response()->json(['error' => 'missing_socket_id'], 422);
        }

        if ($channel !== 'private-node.' . $node->node_id) {
            return response()->json([
                'error' => 'forbidden_channel',
                'message' => 'An agent may only subscribe to its own channel.',
            ], 403);
        }

        /** @var array<string, mixed> $config */
        $config = config('broadcasting.connections.reverb');

        $pusher = new Pusher(
            (string) $config['key'],
            (string) $config['secret'],
            (string) $config['app_id'],
            [
                'host' => $config['options']['host'] ?? '127.0.0.1',
                'port' => $config['options']['port'] ?? 8080,
                'scheme' => $config['options']['scheme'] ?? 'http',
                'useTLS' => ($config['options']['scheme'] ?? 'http') === 'https',
                'timeout' => 5,
            ]
        );

        // pusher-php-server returns an already JSON encoded string.
        $auth = $pusher->socketAuth($channel, $socketId);

        return response($auth, 200, ['Content-Type' => 'application/json']);
    }
}
