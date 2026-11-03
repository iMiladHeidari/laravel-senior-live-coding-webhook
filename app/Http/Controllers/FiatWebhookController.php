<?php

namespace App\Http\Controllers;

use App\Http\Requests\FiatRatesRequest;
use App\Services\FiatRateUpdater;
use Illuminate\Http\JsonResponse;

class FiatWebhookController extends Controller
{
    public function handle(FiatRatesRequest $request, FiatRateUpdater $updater): JsonResponse
    {
        $result = $updater->apply($request->rates(), $request->sourceTimestamp());

        return response()->json([
            'success' => true,
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ], 202);
    }
}
