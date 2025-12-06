/**
 * V2 Streaming Handler
 * Handles StreamEvent format from the multi-provider conversation API.
 */

export class V2StreamHandler {
    constructor(options = {}) {
        this.onThinkingStart = options.onThinkingStart || (() => {});
        this.onThinkingDelta = options.onThinkingDelta || (() => {});
        this.onThinkingStop = options.onThinkingStop || (() => {});
        this.onTextStart = options.onTextStart || (() => {});
        this.onTextDelta = options.onTextDelta || (() => {});
        this.onTextStop = options.onTextStop || (() => {});
        this.onToolUseStart = options.onToolUseStart || (() => {});
        this.onToolUseDelta = options.onToolUseDelta || (() => {});
        this.onToolUseStop = options.onToolUseStop || (() => {});
        this.onToolResult = options.onToolResult || (() => {});
        this.onUsage = options.onUsage || (() => {});
        this.onDone = options.onDone || (() => {});
        this.onError = options.onError || (() => {});

        // State tracking
        this.blocks = {};
        this.currentText = '';
        this.currentThinking = '';
    }

    /**
     * Stream a message to a conversation.
     *
     * @param {string} conversationUuid - The conversation UUID
     * @param {string} prompt - The user's message
     * @param {object} options - Additional options (thinking_level, max_tokens)
     * @returns {Promise<void>}
     */
    async stream(conversationUuid, prompt, options = {}) {
        const baseUrl = window.location.origin;
        const url = `${baseUrl}/api/v2/conversations/${conversationUuid}/stream`;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    prompt,
                    thinking_level: options.thinkingLevel || 0,
                    max_tokens: options.maxTokens || 8192,
                }),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            await this.processStream(response);
        } catch (error) {
            this.onError(error.message);
            throw error;
        }
    }

    /**
     * Process the SSE stream.
     */
    async processStream(response) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop(); // Keep incomplete line

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    try {
                        const event = JSON.parse(line.substring(6));
                        this.handleEvent(event);
                    } catch (e) {
                        console.error('Failed to parse SSE event:', e, line);
                    }
                }
            }
        }
    }

    /**
     * Handle a single StreamEvent.
     */
    handleEvent(event) {
        const { type, block_index, content, metadata } = event;

        switch (type) {
            case 'thinking_start':
                this.currentThinking = '';
                this.blocks[block_index] = { type: 'thinking', content: '' };
                this.onThinkingStart(block_index);
                break;

            case 'thinking_delta':
                this.currentThinking += content || '';
                if (this.blocks[block_index]) {
                    this.blocks[block_index].content = this.currentThinking;
                }
                this.onThinkingDelta(block_index, content, this.currentThinking);
                break;

            case 'thinking_stop':
                this.onThinkingStop(block_index, this.currentThinking);
                break;

            case 'text_start':
                this.currentText = '';
                this.blocks[block_index] = { type: 'text', content: '' };
                this.onTextStart(block_index);
                break;

            case 'text_delta':
                this.currentText += content || '';
                if (this.blocks[block_index]) {
                    this.blocks[block_index].content = this.currentText;
                }
                this.onTextDelta(block_index, content, this.currentText);
                break;

            case 'text_stop':
                this.onTextStop(block_index, this.currentText);
                break;

            case 'tool_use_start':
                this.blocks[block_index] = {
                    type: 'tool_use',
                    toolId: metadata?.tool_id,
                    toolName: metadata?.tool_name,
                    input: '',
                };
                this.onToolUseStart(block_index, metadata?.tool_id, metadata?.tool_name);
                break;

            case 'tool_use_delta':
                if (this.blocks[block_index]) {
                    this.blocks[block_index].input += content || '';
                }
                this.onToolUseDelta(block_index, content, this.blocks[block_index]?.input);
                break;

            case 'tool_use_stop':
                const block = this.blocks[block_index];
                let parsedInput = {};
                try {
                    parsedInput = JSON.parse(block?.input || '{}');
                } catch (e) {}
                this.onToolUseStop(block_index, block?.toolId, block?.toolName, parsedInput);
                break;

            case 'tool_result':
                this.onToolResult(metadata?.tool_id, content, metadata?.is_error);
                break;

            case 'usage':
                this.onUsage(metadata);
                break;

            case 'done':
                this.onDone(metadata?.stop_reason);
                break;

            case 'error':
                this.onError(content);
                break;

            default:
                console.warn('Unknown event type:', type);
        }
    }

    /**
     * Reset state for a new message.
     */
    reset() {
        this.blocks = {};
        this.currentText = '';
        this.currentThinking = '';
    }
}

/**
 * Create a new conversation.
 */
export async function createConversation(workingDirectory, options = {}) {
    const baseUrl = window.location.origin;
    const response = await fetch(`${baseUrl}/api/v2/conversations`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            working_directory: workingDirectory,
            provider_type: options.providerType || 'anthropic',
            model: options.model,
            title: options.title,
        }),
    });

    if (!response.ok) {
        throw new Error(`Failed to create conversation: ${response.statusText}`);
    }

    return response.json();
}

/**
 * Get available providers.
 */
export async function getProviders() {
    const baseUrl = window.location.origin;
    const response = await fetch(`${baseUrl}/api/v2/providers`);

    if (!response.ok) {
        throw new Error(`Failed to get providers: ${response.statusText}`);
    }

    return response.json();
}

/**
 * Get conversation with messages.
 */
export async function getConversation(uuid) {
    const baseUrl = window.location.origin;
    const response = await fetch(`${baseUrl}/api/v2/conversations/${uuid}`);

    if (!response.ok) {
        throw new Error(`Failed to get conversation: ${response.statusText}`);
    }

    return response.json();
}

/**
 * Get conversation status (tokens, context window).
 */
export async function getConversationStatus(uuid) {
    const baseUrl = window.location.origin;
    const response = await fetch(`${baseUrl}/api/v2/conversations/${uuid}/status`);

    if (!response.ok) {
        throw new Error(`Failed to get status: ${response.statusText}`);
    }

    return response.json();
}
