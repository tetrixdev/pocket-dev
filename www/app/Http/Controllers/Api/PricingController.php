<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Services\ModelRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $pricing = AiModel::active()
            ->ordered()
            ->get()
            ->groupBy('provider')
            ->map(function ($models) {
                return $models->keyBy('model_id')->map(function (AiModel $model) {
                    return [
                        'name' => $model->display_name,
                        'input' => (float) $model->input_price_per_million,
                        'output' => (float) $model->output_price_per_million,
                        'cacheWrite' => (float) ($model->cache_write_price_per_million ?? 0),
                        'cacheRead' => (float) ($model->cache_read_price_per_million ?? 0),
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
        $model = $this->models->findByModelId($modelId);

        if (!$model) {
            return response()->json([
                'error' => 'Model not found',
                'model_id' => $modelId,
            ], 404);
        }

        return response()->json([
            'model_id' => $model->model_id,
            'name' => $model->display_name,
            'provider' => $model->provider,
            'input' => (float) $model->input_price_per_million,
            'output' => (float) $model->output_price_per_million,
            'cacheWrite' => (float) ($model->cache_write_price_per_million ?? 0),
            'cacheRead' => (float) ($model->cache_read_price_per_million ?? 0),
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
            'cacheWrite' => 'nullable|numeric|min:0',
            'cacheRead' => 'nullable|numeric|min:0',
        ]);

        $model = AiModel::where('model_id', $modelId)->first();

        if (!$model) {
            return response()->json([
                'error' => 'Model not found',
                'model_id' => $modelId,
            ], 404);
        }

        $model->update([
            'input_price_per_million' => $validated['input'],
            'output_price_per_million' => $validated['output'],
            'cache_write_price_per_million' => $validated['cacheWrite'] ?? null,
            'cache_read_price_per_million' => $validated['cacheRead'] ?? null,
        ]);

        // Clear cache after update
        $this->models->clearCache();

        return response()->json([
            'model_id' => $model->model_id,
            'name' => $model->display_name,
            'provider' => $model->provider,
            'input' => (float) $model->input_price_per_million,
            'output' => (float) $model->output_price_per_million,
            'cacheWrite' => (float) ($model->cache_write_price_per_million ?? 0),
            'cacheRead' => (float) ($model->cache_read_price_per_million ?? 0),
        ]);
    }
}
