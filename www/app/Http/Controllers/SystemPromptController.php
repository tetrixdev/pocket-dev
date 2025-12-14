<?php

namespace App\Http\Controllers;

use App\Services\SystemPromptService;
use Illuminate\Http\Request;

class SystemPromptController extends Controller
{
    public function __construct(
        private SystemPromptService $systemPromptService
    ) {}

    /**
     * Show the system prompt settings (read-only view).
     */
    public function show(Request $request)
    {
        $request->session()->put('config_last_section', 'system-prompt');

        return view('config.system-prompt.show', [
            'coreContent' => $this->systemPromptService->getCore(),
            'additionalContent' => $this->systemPromptService->getAdditional(),
            'isCoreOverridden' => $this->systemPromptService->isCoreOverridden(),
            'isAdditionalOverridden' => $this->systemPromptService->isAdditionalOverridden(),
            'hasAdditionalDefault' => !empty($this->systemPromptService->getAdditionalDefault()),
        ]);
    }

    /**
     * Show the edit form for the additional system prompt.
     */
    public function editAdditional(Request $request)
    {
        $request->session()->put('config_last_section', 'system-prompt');

        return view('config.system-prompt.edit-additional', [
            'content' => $this->systemPromptService->getAdditional(),
            'isOverridden' => $this->systemPromptService->isAdditionalOverridden(),
            'hasDefault' => !empty($this->systemPromptService->getAdditionalDefault()),
        ]);
    }

    /**
     * Save the additional system prompt.
     */
    public function saveAdditional(Request $request)
    {
        $validated = $request->validate([
            'content' => 'nullable|string',
        ]);

        $content = $validated['content'] ?? '';

        if (empty(trim($content))) {
            // If empty, reset to default instead of saving empty override
            $this->systemPromptService->resetAdditionalToDefault();
        } else {
            $this->systemPromptService->saveAdditionalOverride($content);
        }

        return redirect()
            ->route('config.system-prompt')
            ->with('success', 'Additional system prompt saved successfully.');
    }

    /**
     * Reset the additional system prompt to default.
     */
    public function resetAdditional()
    {
        $this->systemPromptService->resetAdditionalToDefault();

        return redirect()
            ->route('config.system-prompt')
            ->with('success', 'Additional system prompt reset to default.');
    }

    /**
     * Show the edit form for the core system prompt.
     */
    public function editCore(Request $request)
    {
        $request->session()->put('config_last_section', 'system-prompt');

        return view('config.system-prompt.edit-core', [
            'content' => $this->systemPromptService->getCore(),
            'isOverridden' => $this->systemPromptService->isCoreOverridden(),
        ]);
    }

    /**
     * Save the core system prompt override.
     */
    public function saveCore(Request $request)
    {
        $validated = $request->validate([
            'content' => 'required|string|min:10',
        ]);

        $this->systemPromptService->saveCoreOverride($validated['content']);

        return redirect()
            ->route('config.system-prompt')
            ->with('success', 'Core system prompt saved successfully.');
    }

    /**
     * Reset the core system prompt to default.
     */
    public function resetCore()
    {
        $this->systemPromptService->resetCoreToDefault();

        return redirect()
            ->route('config.system-prompt')
            ->with('success', 'Core system prompt reset to default.');
    }
}
