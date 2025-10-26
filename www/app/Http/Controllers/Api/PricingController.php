<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModelPricing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PricingController extends Controller
{
    /**
     * Get pricing for a specific model.
     */
    public function show(string $modelName): JsonResponse
    {
        $pricing = ModelPricing::where('model_name', $modelName)->first();

        if (!$pricing) {
            return response()->json([
                'model_name' => $modelName,
                'input_price_per_million' => null,
                'cache_write_multiplier' => 1.25,
                'cache_read_multiplier' => 0.1,
                'output_price_per_million' => null,
            ]);
        }

        return response()->json($pricing);
    }

    /**
     * Save or update pricing for a model.
     */
    public function store(Request $request, string $modelName): JsonResponse
    {
        $validated = $request->validate([
            'input_price_per_million' => 'required|numeric|min:0',
            'cache_write_multiplier' => 'nullable|numeric|min:0',
            'cache_read_multiplier' => 'nullable|numeric|min:0',
            'output_price_per_million' => 'required|numeric|min:0',
        ]);

        $pricing = ModelPricing::updateOrCreate(
            ['model_name' => $modelName],
            $validated
        );

        return response()->json($pricing);
    }
}
