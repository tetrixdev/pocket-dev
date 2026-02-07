<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Screen;
use App\Models\Session;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

/**
 * Centralized factory for creating conversations.
 *
 * This is the SINGLE SOURCE OF TRUTH for conversation creation.
 * All controllers should use this factory to ensure:
 * - All reasoning/thinking settings are properly copied from agents
 * - Consistent behavior across all creation paths
 * - Proper Session/Screen structure when needed
 */
class ConversationFactory
{
    public function __construct(
        private ProviderFactory $providerFactory,
    ) {}

    /**
     * Create a conversation from an agent.
     * This is the primary method - copies ALL agent settings including reasoning.
     *
     * @param Agent $agent The agent to create the conversation for
     * @param string $workingDirectory The working directory path
     * @param string|null $workspaceId Optional workspace ID
     * @param string|null $title Optional conversation title
     * @return Conversation
     */
    public function createFromAgent(
        Agent $agent,
        string $workingDirectory,
        ?string $workspaceId = null,
        ?string $title = null,
    ): Conversation {
        $conversation = Conversation::create([
            // Core identifiers
            'workspace_id' => $workspaceId,
            'agent_id' => $agent->id,

            // Provider and model from agent
            'provider_type' => $agent->provider,
            'model' => $agent->model,

            // Metadata
            'title' => $title,
            'working_directory' => $workingDirectory,

            // Response level
            'response_level' => $agent->response_level,

            // ALL reasoning/thinking settings from agent
            'anthropic_thinking_budget' => $agent->anthropic_thinking_budget,
            'openai_reasoning_effort' => $agent->openai_reasoning_effort,
            'openai_compatible_reasoning_effort' => $agent->openai_compatible_reasoning_effort ?? 'none',
            'claude_code_thinking_tokens' => $agent->claude_code_thinking_tokens,
            // TODO: Add codex_reasoning_effort once the conversations table has this column
            // Currently the column exists on agents but not on conversations (missing migration)
        ]);

        // Initialize context window size from provider
        $this->initializeContextWindow($conversation);

        return $conversation;
    }

    /**
     * Create a conversation with default settings (no agent).
     * This is the fallback method for legacy scenarios or when no agent is available.
     *
     * @param string $workingDirectory The working directory path
     * @param string|null $workspaceId Optional workspace ID
     * @param string|null $title Optional conversation title
     * @param string|null $providerType Optional provider type (defaults to config)
     * @param string|null $model Optional model (defaults to provider's default)
     * @return Conversation
     */
    public function createWithDefaults(
        string $workingDirectory,
        ?string $workspaceId = null,
        ?string $title = null,
        ?string $providerType = null,
        ?string $model = null,
    ): Conversation {
        // Determine provider
        $providerType = $providerType ?? config('ai.default_provider', 'anthropic');
        if (!in_array($providerType, $this->providerFactory->availableTypes(), true)) {
            $providerType = 'anthropic';
        }

        // Determine model
        $model = $model ?? config("ai.providers.{$providerType}.default_model");

        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'provider_type' => $providerType,
            'model' => $model,
            'title' => $title,
            'working_directory' => $workingDirectory,
            'response_level' => config('ai.response.default_level', 1),
            // Reasoning settings default to null/none - no extended thinking without agent
        ]);

        // Initialize context window size from provider
        $this->initializeContextWindow($conversation);

        return $conversation;
    }

    /**
     * Create a conversation for a screen within a session.
     * Uses the agent if provided, otherwise falls back to workspace default or config defaults.
     *
     * @param Session $session The session to create the conversation in
     * @param Agent|null $agent Optional agent to use
     * @param string|null $title Optional conversation title
     * @return Conversation
     */
    public function createForScreen(
        Session $session,
        ?Agent $agent = null,
        ?string $title = null,
    ): Conversation {
        $workingDirectory = $session->workspace->getWorkingDirectoryPath();
        $workspaceId = $session->workspace_id;

        // If agent provided, use it
        if ($agent && $agent->enabled) {
            return $this->createFromAgent($agent, $workingDirectory, $workspaceId, $title);
        }

        // Try to get workspace default agent
        $defaultAgent = $this->getWorkspaceDefaultAgent($session->workspace);
        if ($defaultAgent) {
            return $this->createFromAgent($defaultAgent, $workingDirectory, $workspaceId, $title);
        }

        // Fall back to defaults
        return $this->createWithDefaults($workingDirectory, $workspaceId, $title);
    }

    /**
     * Create a conversation with a full Session and Screen structure.
     * Used when creating a standalone conversation that needs session/screen hierarchy.
     *
     * @param Agent $agent The agent to use
     * @param Workspace $workspace The workspace
     * @param string|null $title Optional conversation title
     * @return array{conversation: Conversation, session: Session, screen: Screen}
     */
    public function createWithSessionStructure(
        Agent $agent,
        Workspace $workspace,
        ?string $title = null,
    ): array {
        // Create conversation
        $conversation = $this->createFromAgent(
            $agent,
            $workspace->getWorkingDirectoryPath(),
            $workspace->id,
            $title
        );

        // Create session
        $session = Session::create([
            'workspace_id' => $workspace->id,
            'name' => $title ?? 'New Session',
            'screen_order' => [],
        ]);

        // Create screen
        $screen = Screen::createChatScreen($session, $conversation);

        // Update session with screen order
        $session->update([
            'screen_order' => [$screen->id],
            'last_active_screen_id' => $screen->id,
        ]);

        // Load relationships for response
        $conversation->load('screen.session.screens.conversation');

        return [
            'conversation' => $conversation,
            'session' => $session,
            'screen' => $screen,
        ];
    }

    /**
     * Initialize the context window size from the provider.
     */
    private function initializeContextWindow(Conversation $conversation): void
    {
        try {
            $provider = $this->providerFactory->make($conversation->provider_type);
            $contextWindow = $provider->getContextWindow($conversation->model);
            $conversation->update(['context_window_size' => $contextWindow]);
        } catch (\Exception $e) {
            Log::warning('Failed to initialize context window', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the default agent for a workspace.
     */
    private function getWorkspaceDefaultAgent(Workspace $workspace): ?Agent
    {
        return Agent::where('workspace_id', $workspace->id)
            ->where('enabled', true)
            ->where('is_default', true)
            ->first()
            ?? Agent::where('workspace_id', $workspace->id)
                ->where('enabled', true)
                ->orderBy('created_at')
                ->first();
    }
}
