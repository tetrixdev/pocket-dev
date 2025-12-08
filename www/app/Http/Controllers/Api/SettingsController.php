<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Get chat default settings.
     */
    public function chatDefaults(): JsonResponse
    {
        $defaults = Setting::getMany([
            'chat.default_provider',
            'chat.default_model',
            'chat.thinking_level',
            'chat.response_level',
        ], [
            'chat.default_provider' => config('ai.default_provider', 'anthropic'),
            'chat.default_model' => config('ai.providers.anthropic.default_model', 'claude-sonnet-4-5-20250929'),
            'chat.thinking_level' => 0,
            'chat.response_level' => 1,
        ]);

        return response()->json([
            'provider' => $defaults['chat.default_provider'],
            'model' => $defaults['chat.default_model'],
            'thinking_level' => (int) $defaults['chat.thinking_level'],
            'response_level' => (int) $defaults['chat.response_level'],
        ]);
    }

    /**
     * Update chat default settings.
     */
    public function updateChatDefaults(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'nullable|string|in:anthropic,openai',
            'model' => 'nullable|string|max:100',
            'thinking_level' => 'nullable|integer|min:0|max:4',
            'response_level' => 'nullable|integer|min:0|max:3',
        ]);

        $settings = [];

        if (isset($validated['provider'])) {
            $settings['chat.default_provider'] = $validated['provider'];
        }
        if (isset($validated['model'])) {
            $settings['chat.default_model'] = $validated['model'];
        }
        if (isset($validated['thinking_level'])) {
            $settings['chat.thinking_level'] = $validated['thinking_level'];
        }
        if (isset($validated['response_level'])) {
            $settings['chat.response_level'] = $validated['response_level'];
        }

        if (!empty($settings)) {
            Setting::setMany($settings);
        }

        return response()->json(['success' => true]);
    }
}
