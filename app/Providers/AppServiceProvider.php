<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerGate();
        $this->registerRateLimiters();
        $this->registerApiDocumentation();
    }

    /**
     * OpenAPI metadata: the admin API is Sanctum bearer authenticated, the agent
     * API uses the HMAC headers described in App\Support\SignatureVerifier.
     */
    private function registerApiDocumentation(): void
    {
        Scramble::extendOpenApi(static function (OpenApi $document): void {
            $document->info->title = 'Hysteria Control Plane API';
            $document->info->description = implode("\n", [
                'Two surfaces:',
                '',
                '* `/api/agent/v1/*` — called by hysteria-agent nodes. Every request carries',
                '  `X-Node-Id`, `X-Timestamp`, `X-Nonce` and `X-Node-Signature`, where the signature is',
                '  `HMAC-SHA256(secret, METHOD + "\\n" + PATH + "\\n" + sha256(rawBody) + "\\n" + X-Timestamp + "\\n" + X-Nonce)`.',
                '  The path is the URL path only (the query string is not signed) and the timestamp must be',
                '  within `AGENT_SIGNATURE_TTL` seconds (default 300). Nonces are single use.',
                '',
                '* `/api/admin/v1/*` — operator/integration surface, Sanctum bearer tokens.',
                '  Create a token with `POST /api/admin/v1/auth/login`.',
            ]);
            $document->secure(SecurityScheme::http('bearer'));
        });
    }

    private function registerGate(): void
    {
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->hasRole('super-admin') ? true : null;
        });
    }

    private function registerRateLimiters(): void
    {
        // Enrollment: cheap protection from token brute force.
        RateLimiter::for('agent-enroll', static fn (Request $request) => [
            Limit::perMinute(20)->by('enroll:ip:' . $request->ip()),
            Limit::perMinute(600)->by('enroll:global'),
        ]);

        // Signed agent traffic: per-node budget, generous enough for 1s heartbeats.
        RateLimiter::for('agent', static fn (Request $request) => Limit::perMinute(1200)
            ->by('agent:' . (string) $request->header('X-Node-Id', (string) $request->ip())));

        RateLimiter::for('admin-api', static fn (Request $request) => Limit::perMinute(600)
            ->by('admin-api:' . (string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('admin-login', static fn (Request $request) => Limit::perMinute(10)
            ->by('admin-login:' . $request->ip()));

        // /api/v1/agent/{token,control} — keyed by credential when present.
        RateLimiter::for('agent-v1', static fn (Request $request) => Limit::perMinute(300)
            ->by('agent-v1:' . (string) ($request->bearerToken() ?: $request->ip())));
    }
}
