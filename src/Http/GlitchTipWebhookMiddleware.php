<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class GlitchTipWebhookMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config()->string('telegram-alerts.glitchtip_webhook_secret');
        $token = $request->string('token')->toString();

        if ($secret === '' || ! hash_equals($secret, $token)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
