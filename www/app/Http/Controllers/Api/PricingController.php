<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModelPricing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PricingController extends Controller
{
    /**
     * Get all pricing data grouped by provider.
     */
    public function index(): JsonResponse
    {
        $pricing = ModelPricing::all()
            ->groupBy('provider')
            ->map(function ($models) {
                return $models->keyBy('model_id')->map(function ($model) {
                    return [
                        'name' => $model->name,
                        'input' => (float) $model->input_price,
                        'output' => (float) $model->output_price,
                        'cacheWrite' => (float) $model->cache_write_price,
                        'cacheRead' => (float) $model->cache_read_price,
                    ];
                });
            });

        return response()->json(['pricing' => $pricing]);
    }

    /**
     * Get pricing for a specific model.
     */
    public function show(string $modelId): JsonResponse
    {
        $pricing = ModelPricing::where('model_id', $modelId)->first();

        if (! $pricing) {
            return response()->json([
                'error' => 'Model not found',
                'model_id' => $modelId,
            ], 404);
        }

        return response()->json([
            'model_id' => $pricing->model_id,
            'name' => $pricing->name,
            'provider' => $pricing->provider,
            'input' => (float) $pricing->input_price,
            'output' => (float) $pricing->output_price,
            'cacheWrite' => (float) $pricing->cache_write_price,
            'cacheRead' => (float) $pricing->cache_read_price,
        ]);
    }

    /**
     * Save or update pricing for a model.
     */
    public function store(Request $request, string $modelId): JsonResponse
    {
        $validated = $request->validate([
            'input' => 'required|numeric|min:0',
            'output' => 'required|numeric|min:0',
            'cacheWrite' => 'required|numeric|min:0',
            'cacheRead' => 'required|numeric|min:0',
        ]);

        $pricing = ModelPricing::where('model_id', $modelId)->first();

        if (! $pricing) {
            return response()->json([
                'error' => 'Model not found',
                'model_id' => $modelId,
            ], 404);
        }

        $pricing->update([
            'input_price' => $validated['input'],
            'output_price' => $validated['output'],
            'cache_write_price' => $validated['cacheWrite'],
            'cache_read_price' => $validated['cacheRead'],
        ]);

        return response()->json([
            'model_id' => $pricing->model_id,
            'name' => $pricing->name,
            'provider' => $pricing->provider,
            'input' => (float) $pricing->input_price,
            'output' => (float) $pricing->output_price,
            'cacheWrite' => (float) $pricing->cache_write_price,
            'cacheRead' => (float) $pricing->cache_read_price,
        ]);
    }
}
