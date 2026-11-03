<?php

namespace App\Http\Middleware;

use App\Models\WebhookClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Webhook-Token');

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Missing X-Webhook-Token header.',
            ], 401);
        }

        $client = WebhookClient::findActiveByToken($token);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Invalid webhook token.',
            ], 401);
        }

        $request->attributes->set('webhook_client', $client);

        return $next($request);
    }
}
