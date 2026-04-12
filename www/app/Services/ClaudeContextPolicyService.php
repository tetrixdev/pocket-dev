<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Setting;

class ClaudeContextPolicyService
{
    public const MODE_SAFE = 'safe';
    public const MODE_ADAPTIVE = 'adaptive';
    public const MODE_FORCE_LEGACY = 'force_legacy';

    private const WORKSPACE_DEMOTIONS_KEY = 'claude_context_policy.workspace_demotions';

    /**
     * @return array{requested_mode:string,applied_mode:string,apply_overrides:bool,reason:string,effective_context_window:?int,probe_allows_adaptive:bool,workspace_demoted:bool,blocking_limit_override:?int}
     */
    public function evaluateForConversation(Conversation $conversation): array
    {
        $requestedMode = $this->getConfiguredMode();
        $effectiveContextWindow = $conversation->effective_context_window;
        $workspaceDemoted = $this->isWorkspaceDemoted($conversation->workspace_id);
        $probeAllowsAdaptive = $this->probeAllowsAdaptive();
        $adaptiveThreshold = $this->adaptiveThreshold();

        if ($requestedMode === self::MODE_FORCE_LEGACY) {
            return [
                'requested_mode' => $requestedMode,
                'applied_mode' => self::MODE_FORCE_LEGACY,
                'apply_overrides' => false,
                'reason' => 'force_legacy_mode',
                'effective_context_window' => $effectiveContextWindow,
                'probe_allows_adaptive' => $probeAllowsAdaptive,
                'workspace_demoted' => $workspaceDemoted,
                'blocking_limit_override' => null,
            ];
        }

        if ($requestedMode !== self::MODE_ADAPTIVE) {
            return [
                'requested_mode' => $requestedMode,
                'applied_mode' => self::MODE_SAFE,
                'apply_overrides' => false,
                'reason' => 'safe_mode',
                'effective_context_window' => $effectiveContextWindow,
                'probe_allows_adaptive' => $probeAllowsAdaptive,
                'workspace_demoted' => $workspaceDemoted,
                'blocking_limit_override' => null,
            ];
        }

        if (!$probeAllowsAdaptive) {
            return [
                'requested_mode' => $requestedMode,
                'applied_mode' => self::MODE_SAFE,
                'apply_overrides' => false,
                'reason' => 'probe_gate_blocked',
                'effective_context_window' => $effectiveContextWindow,
                'probe_allows_adaptive' => false,
                'workspace_demoted' => $workspaceDemoted,
                'blocking_limit_override' => null,
            ];
        }

        if ($workspaceDemoted) {
            return [
                'requested_mode' => $requestedMode,
                'applied_mode' => self::MODE_SAFE,
                'apply_overrides' => false,
                'reason' => 'workspace_auto_demoted',
                'effective_context_window' => $effectiveContextWindow,
                'probe_allows_adaptive' => true,
                'workspace_demoted' => true,
                'blocking_limit_override' => null,
            ];
        }

        if ($effectiveContextWindow === null || $effectiveContextWindow < $adaptiveThreshold) {
            return [
                'requested_mode' => $requestedMode,
                'applied_mode' => self::MODE_SAFE,
                'apply_overrides' => false,
                'reason' => 'effective_context_not_proven',
                'effective_context_window' => $effectiveContextWindow,
                'probe_allows_adaptive' => true,
                'workspace_demoted' => false,
                'blocking_limit_override' => null,
            ];
        }

        return [
            'requested_mode' => $requestedMode,
            'applied_mode' => self::MODE_ADAPTIVE,
            'apply_overrides' => true,
            'reason' => 'adaptive_enabled',
            'effective_context_window' => $effectiveContextWindow,
            'probe_allows_adaptive' => true,
            'workspace_demoted' => false,
            'blocking_limit_override' => $this->adaptiveBlockingLimitOverride(),
        ];
    }

    public function getConfiguredMode(): string
    {
        $mode = (string) config('ai.providers.claude_code.context_policy', self::MODE_SAFE);
        if (!in_array($mode, [self::MODE_SAFE, self::MODE_ADAPTIVE, self::MODE_FORCE_LEGACY], true)) {
            return self::MODE_SAFE;
        }

        return $mode;
    }

    public function probeAllowsAdaptive(): bool
    {
        return (bool) config('ai.providers.claude_code.adaptive_allowed', false);
    }

    public function adaptiveThreshold(): int
    {
        return max(1, (int) config('ai.providers.claude_code.adaptive_context_threshold', 900000));
    }

    public function adaptiveBlockingLimitOverride(): int
    {
        return max(1, (int) config('ai.providers.claude_code.adaptive_blocking_limit_override', 977000));
    }

    public function compactCommandEnabled(): bool
    {
        return (bool) config('ai.providers.claude_code.enable_compact_command', false);
    }

    public function isWorkspaceDemoted(?string $workspaceId): bool
    {
        if (!$workspaceId) {
            return false;
        }

        $demotions = $this->getWorkspaceDemotions();
        return isset($demotions[$workspaceId]);
    }

    public function demoteWorkspaceToSafe(?string $workspaceId, string $reason): bool
    {
        if (!$workspaceId) {
            return false;
        }

        $demotions = $this->getWorkspaceDemotions();
        if (isset($demotions[$workspaceId])) {
            return false;
        }

        $demotions[$workspaceId] = [
            'demoted_at' => now()->toIso8601String(),
            'reason' => $reason,
        ];

        Setting::set(self::WORKSPACE_DEMOTIONS_KEY, $demotions);

        return true;
    }

    public function isHardPromptLimitError(string $message): bool
    {
        $normalized = strtolower($message);

        $patterns = [
            'prompt is too long',
            'prompt too long',
            'context length exceeded',
            'context window exceeded',
            'maximum context length',
            'input is too long',
            'input length exceeds',
            'exceeds the maximum context',
            'exceeds model context',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{demoted_at:string,reason:string}>
     */
    private function getWorkspaceDemotions(): array
    {
        $value = Setting::get(self::WORKSPACE_DEMOTIONS_KEY, []);
        if (!is_array($value)) {
            return [];
        }

        return $value;
    }
}
