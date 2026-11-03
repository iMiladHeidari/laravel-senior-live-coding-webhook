<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiatWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
        ]);
    }
}
