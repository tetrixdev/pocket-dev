<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Services\ClaudeContextPolicyService;
use Tests\TestCase;

class ClaudeContextPolicyServiceTest extends TestCase
{
    public function test_safe_policy_never_applies_overrides(): void
    {
        config()->set('ai.providers.claude_code.context_policy', 'safe');
        config()->set('ai.providers.claude_code.adaptive_allowed', true);

        $conversation = new Conversation([
            'provider_type' => 'claude_code',
            'effective_context_window' => 1000000,
        ]);

        $decision = app(ClaudeContextPolicyService::class)->evaluateForConversation($conversation);

        $this->assertSame('safe', $decision['applied_mode']);
        $this->assertFalse($decision['apply_overrides']);
    }

    public function test_adaptive_applies_only_when_probe_and_effective_window_allow_it(): void
    {
        config()->set('ai.providers.claude_code.context_policy', 'adaptive');
        config()->set('ai.providers.claude_code.adaptive_allowed', true);
        config()->set('ai.providers.claude_code.adaptive_context_threshold', 900000);

        $conversation = new Conversation([
            'provider_type' => 'claude_code',
            'effective_context_window' => 1000000,
        ]);

        $decision = app(ClaudeContextPolicyService::class)->evaluateForConversation($conversation);

        $this->assertSame('adaptive', $decision['applied_mode']);
        $this->assertTrue($decision['apply_overrides']);
        $this->assertSame(977000, $decision['blocking_limit_override']);
    }

    public function test_adaptive_falls_back_to_safe_when_probe_gate_is_closed(): void
    {
        config()->set('ai.providers.claude_code.context_policy', 'adaptive');
        config()->set('ai.providers.claude_code.adaptive_allowed', false);

        $conversation = new Conversation([
            'provider_type' => 'claude_code',
            'effective_context_window' => 1000000,
        ]);

        $decision = app(ClaudeContextPolicyService::class)->evaluateForConversation($conversation);

        $this->assertSame('safe', $decision['applied_mode']);
        $this->assertFalse($decision['apply_overrides']);
        $this->assertSame('probe_gate_blocked', $decision['reason']);
    }

    public function test_hard_prompt_limit_pattern_detection(): void
    {
        $service = app(ClaudeContextPolicyService::class);

        $this->assertTrue($service->isHardPromptLimitError('API Error: Prompt is too long for this model.'));
        $this->assertFalse($service->isHardPromptLimitError('Temporary network error while reading stream'));
    }
}
