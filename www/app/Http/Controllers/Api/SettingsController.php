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
        $provider = config('ai.default_provider', 'anthropic');

        $defaults = Setting::getMany([
            'chat.default_provider',
            'chat.default_model',
            'chat.response_level',
            // Provider-specific reasoning defaults
            'chat.anthropic_thinking_budget',
            'chat.openai_reasoning_effort',
            'chat.claude_code_thinking_tokens',
            'chat.claude_code_allowed_tools',
            // Legacy
            'chat.thinking_level',
        ], [
            'chat.default_provider' => $provider,
            'chat.default_model' => config("ai.providers.{$provider}.default_model"),
            'chat.response_level' => 1,
            'chat.anthropic_thinking_budget' => 0,
            'chat.openai_reasoning_effort' => 'none',
            'chat.claude_code_thinking_tokens' => 0,
            'chat.claude_code_allowed_tools' => [], // Empty array = all tools allowed
            'chat.thinking_level' => 0,
        ]);

        // Setting::getMany already decodes JSON, so allowed_tools is already an array
        $allowedTools = $defaults['chat.claude_code_allowed_tools'];
        if (!is_array($allowedTools)) {
            $allowedTools = [];
        }

        return response()->json([
            'provider' => $defaults['chat.default_provider'],
            'model' => $defaults['chat.default_model'],
            'response_level' => (int) $defaults['chat.response_level'],
            // Provider-specific
            'anthropic_thinking_budget' => (int) $defaults['chat.anthropic_thinking_budget'],
            'openai_reasoning_effort' => $defaults['chat.openai_reasoning_effort'],
            'claude_code_thinking_tokens' => (int) $defaults['chat.claude_code_thinking_tokens'],
            'claude_code_allowed_tools' => $allowedTools,
            // Legacy (for backwards compatibility)
            'thinking_level' => (int) $defaults['chat.thinking_level'],
        ]);
    }

    /**
     * Update chat default settings.
     */
    public function updateChatDefaults(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'nullable|string|in:anthropic,openai,claude_code',
            'model' => 'nullable|string|max:100',
            'response_level' => 'nullable|integer|min:0|max:3',
            // Provider-specific reasoning settings
            'anthropic_thinking_budget' => 'nullable|integer|min:0|max:128000',
            'openai_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
            'claude_code_thinking_tokens' => 'nullable|integer|min:0|max:128000',
            'claude_code_allowed_tools' => 'nullable|array',
            'claude_code_allowed_tools.*' => 'string|max:50',
            // Legacy
            'thinking_level' => 'nullable|integer|min:0|max:4',
        ]);

        $settings = [];

        if (isset($validated['provider'])) {
            $settings['chat.default_provider'] = $validated['provider'];
        }
        if (isset($validated['model'])) {
            $settings['chat.default_model'] = $validated['model'];
        }
        if (isset($validated['response_level'])) {
            $settings['chat.response_level'] = $validated['response_level'];
        }
        // Provider-specific
        if (isset($validated['anthropic_thinking_budget'])) {
            $settings['chat.anthropic_thinking_budget'] = $validated['anthropic_thinking_budget'];
        }
        if (isset($validated['openai_reasoning_effort'])) {
            $settings['chat.openai_reasoning_effort'] = $validated['openai_reasoning_effort'];
        }
        if (isset($validated['claude_code_thinking_tokens'])) {
            $settings['chat.claude_code_thinking_tokens'] = $validated['claude_code_thinking_tokens'];
        }
        if (array_key_exists('claude_code_allowed_tools', $validated)) {
            $settings['chat.claude_code_allowed_tools'] = json_encode($validated['claude_code_allowed_tools'] ?? []);
        }
        // Legacy
        if (isset($validated['thinking_level'])) {
            $settings['chat.thinking_level'] = $validated['thinking_level'];
        }

        if (!empty($settings)) {
            Setting::setMany($settings);
        }

        return response()->json(['success' => true]);
    }
}
