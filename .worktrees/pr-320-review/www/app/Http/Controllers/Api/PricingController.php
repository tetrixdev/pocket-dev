<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ModelRepository;
use Illuminate\Http\JsonResponse;

class PricingController extends Controller
{
    public function __construct(
        private ModelRepository $models
    ) {}

    /**
     * Get all pricing data grouped by provider.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'pricing' => $this->models->getAllPricing(),
        ]);
    }

    /**
     * Get pricing for a specific model.
     */
    public function show(string $modelId): JsonResponse
    {
        $pricing = $this->models->getPricing($modelId);

        if (!$pricing) {
            return response()->json([
                'error' => 'Model not found',
                'model_id' => $modelId,
            ], 404);
        }

        $model = $this->models->findByModelId($modelId);

        return response()->json([
            'model_id' => $modelId,
            'name' => $pricing['name'],
            'provider' => $model['provider'],
            'input' => $pricing['input'],
            'output' => $pricing['output'],
            'cacheWrite' => $pricing['cacheWrite'],
            'cacheRead' => $pricing['cacheRead'],
        ]);
    }
}
