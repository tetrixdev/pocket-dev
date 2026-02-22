/**
 * Message Store - manages message array and DB-to-UI conversion
 *
 * Sprint 3: Extracted from chat.blade.php chatApp()
 * This is a factory function that returns a plain object (not Alpine.store)
 * to maintain tight integration with chatApp's reactive state.
 */

/**
 * Creates a message store instance
 * @param {Object} options
 * @param {Function} options.getCurrentModel - Returns current model name
 * @param {Function} options.getCurrentAgent - Returns current agent name
 * @returns {Object} Message store instance
 */
export function createMessageStore(options = {}) {
    const { getCurrentModel, getCurrentAgent } = options;

    return {
        // === State ===
        messages: [],
        inputTokens: 0,
        outputTokens: 0,
        cacheCreationTokens: 0,
        cacheReadTokens: 0,
        totalTokens: 0,
        sessionCost: 0,

        // === Core Message Management ===

        /**
         * Push a new message to the array
         */
        pushMessage(msg) {
            this.messages.push(msg);
        },

        /**
         * Update a message at a specific index
         * Uses spread to ensure Alpine reactivity
         */
        updateMessage(index, updates) {
            if (index >= 0 && index < this.messages.length) {
                this.messages[index] = {
                    ...this.messages[index],
                    ...updates
                };
            }
        },

        /**
         * Find a tool message by toolId
         * @returns {number} Index or -1 if not found
         */
        findToolMessageIndex(toolId) {
            return this.messages.findIndex(m => m.role === 'tool' && m.toolId === toolId);
        },

        /**
         * Splice messages array (remove elements)
         */
        spliceMessages(start, deleteCount) {
            return this.messages.splice(start, deleteCount);
        },

        /**
         * Clear all messages (mutates in-place to preserve shared reference)
         */
        clearMessages() {
            this.messages.length = 0;
        },

        /**
         * Set messages array (mutates in-place to preserve shared reference)
         */
        setMessages(msgs) {
            this.messages.length = 0;
            this.messages.push(...msgs);
        },

        /**
         * Prepend messages to the beginning
         */
        prependMessages(msgs) {
            this.messages.unshift(...msgs);
        },

        // === Token/Cost Tracking ===

        /**
         * Reset all token counters
         */
        resetTokenCounters() {
            this.inputTokens = 0;
            this.outputTokens = 0;
            this.cacheCreationTokens = 0;
            this.cacheReadTokens = 0;
            this.totalTokens = 0;
            this.sessionCost = 0;
        },

        /**
         * Add to token counters
         */
        addTokens({ input = 0, output = 0, cacheCreation = 0, cacheRead = 0, cost = 0 }) {
            this.inputTokens += input;
            this.outputTokens += output;
            this.cacheCreationTokens += cacheCreation;
            this.cacheReadTokens += cacheRead;
            this.totalTokens += input + output;
            this.sessionCost += cost;
        },

        /**
         * Sum tokens from DB messages (for initial load)
         */
        sumTokensFromDbMessages(dbMessages) {
            let input = 0, output = 0, cacheCreation = 0, cacheRead = 0, cost = 0;

            for (const msg of dbMessages) {
                input += msg.input_tokens || 0;
                output += msg.output_tokens || 0;
                cacheCreation += msg.cache_creation_tokens || 0;
                cacheRead += msg.cache_read_tokens || 0;
                cost += msg.cost || 0;
            }

            return { input, output, cacheCreation, cacheRead, cost };
        },

        /**
         * Subtract token contributions of stripped messages
         * Used when messages are removed (e.g., during page refresh replay)
         */
        subtractTokensForMessages(msgs) {
            for (const msg of msgs) {
                if (msg.inputTokens) this.inputTokens -= msg.inputTokens;
                if (msg.outputTokens) this.outputTokens -= msg.outputTokens;
                if (msg.cacheCreationTokens) this.cacheCreationTokens -= msg.cacheCreationTokens;
                if (msg.cacheReadTokens) this.cacheReadTokens -= msg.cacheReadTokens;
                if (msg.cost) this.sessionCost -= msg.cost;
            }
            // Floor at zero to prevent negative display values
            this.inputTokens = Math.max(0, this.inputTokens);
            this.outputTokens = Math.max(0, this.outputTokens);
            this.cacheCreationTokens = Math.max(0, this.cacheCreationTokens);
            this.cacheReadTokens = Math.max(0, this.cacheReadTokens);
            this.sessionCost = Math.max(0, this.sessionCost);
            this.totalTokens = this.inputTokens + this.outputTokens;
        },

        // === DB Message Conversion ===

        /**
         * Generate a unique message ID
         */
        _generateMsgId() {
            return 'msg-' + Date.now() + '-' + Math.random();
        },

        /**
         * Converts a DB message to UI format. Returns array (content blocks expand to multiple).
         * @param {Object} dbMsg - The database message to convert
         * @param {Array} pendingToolResults - Optional array to collect tool_results that couldn't be linked
         * @returns {Array} Array of UI message objects
         */
        convertDbMessageToUi(dbMsg, pendingToolResults = null) {
            const result = [];
            const content = dbMsg.content;
            const msgInputTokens = dbMsg.input_tokens || 0;
            const msgOutputTokens = dbMsg.output_tokens || 0;
            const msgCacheCreation = dbMsg.cache_creation_tokens || 0;
            const msgCacheRead = dbMsg.cache_read_tokens || 0;
            const msgModel = dbMsg.model || (getCurrentModel ? getCurrentModel() : null);
            const msgCost = dbMsg.cost ?? null;

            // Handle compaction messages specially
            if (dbMsg.role === 'compaction') {
                const preTokens = content?.pre_tokens;
                const preTokensDisplay = preTokens != null ? preTokens.toLocaleString() : 'unknown';
                result.push({
                    id: this._generateMsgId(),
                    role: 'compaction',
                    content: content?.summary || '',
                    preTokens: preTokens,
                    preTokensDisplay: preTokensDisplay,
                    trigger: content?.trigger ?? 'auto',
                    timestamp: dbMsg.created_at,
                    collapsed: true,
                    turn_number: dbMsg.turn_number
                });
                return result;
            }

            if (typeof content === 'string') {
                result.push({
                    id: this._generateMsgId(),
                    role: dbMsg.role,
                    content: content,
                    timestamp: dbMsg.created_at,
                    collapsed: false,
                    cost: msgCost,
                    model: msgModel,
                    inputTokens: msgInputTokens,
                    outputTokens: msgOutputTokens,
                    cacheCreationTokens: msgCacheCreation,
                    cacheReadTokens: msgCacheRead,
                    agent: dbMsg.agent,
                    turn_number: dbMsg.turn_number
                });
            } else if (Array.isArray(content)) {
                if (content.length === 0) {
                    if (msgCost && dbMsg.role === 'assistant') {
                        result.push({
                            id: this._generateMsgId(),
                            role: 'empty-response',
                            content: null,
                            timestamp: dbMsg.created_at,
                            collapsed: false,
                            cost: msgCost,
                            model: msgModel,
                            inputTokens: msgInputTokens,
                            outputTokens: msgOutputTokens,
                            cacheCreationTokens: msgCacheCreation,
                            cacheReadTokens: msgCacheRead,
                            agent: dbMsg.agent,
                            turn_number: dbMsg.turn_number
                        });
                    }
                    return result;
                }

                const lastBlockIndex = content.length - 1;

                for (let i = 0; i < content.length; i++) {
                    const block = content[i];
                    const isLast = (i === lastBlockIndex);

                    if (block.type === 'text') {
                        result.push({
                            id: this._generateMsgId(),
                            role: 'assistant',
                            content: block.text,
                            timestamp: dbMsg.created_at,
                            collapsed: false,
                            cost: isLast ? msgCost : null,
                            model: isLast ? msgModel : null,
                            inputTokens: isLast ? msgInputTokens : null,
                            outputTokens: isLast ? msgOutputTokens : null,
                            cacheCreationTokens: isLast ? msgCacheCreation : null,
                            cacheReadTokens: isLast ? msgCacheRead : null,
                            agent: isLast ? dbMsg.agent : null,
                            turn_number: dbMsg.turn_number
                        });
                    } else if (block.type === 'thinking') {
                        result.push({
                            id: this._generateMsgId(),
                            role: 'thinking',
                            content: block.thinking,
                            timestamp: dbMsg.created_at,
                            collapsed: true,
                            cost: isLast ? msgCost : null,
                            model: isLast ? msgModel : null,
                            inputTokens: isLast ? msgInputTokens : null,
                            outputTokens: isLast ? msgOutputTokens : null,
                            cacheCreationTokens: isLast ? msgCacheCreation : null,
                            cacheReadTokens: isLast ? msgCacheRead : null,
                            agent: isLast ? dbMsg.agent : null,
                            turn_number: dbMsg.turn_number
                        });
                    } else if (block.type === 'tool_use') {
                        result.push({
                            id: this._generateMsgId(),
                            role: 'tool',
                            toolName: block.name,
                            toolId: block.id,
                            toolInput: block.input,
                            toolResult: null,
                            content: JSON.stringify(block.input, null, 2),
                            timestamp: dbMsg.created_at,
                            collapsed: true,
                            cost: isLast ? msgCost : null,
                            model: isLast ? msgModel : null,
                            inputTokens: isLast ? msgInputTokens : null,
                            outputTokens: isLast ? msgOutputTokens : null,
                            cacheCreationTokens: isLast ? msgCacheCreation : null,
                            cacheReadTokens: isLast ? msgCacheRead : null,
                            agent: isLast ? dbMsg.agent : null,
                            turn_number: dbMsg.turn_number
                        });
                    } else if (block.type === 'tool_result' && block.tool_use_id) {
                        // Link result to the corresponding tool message in result array
                        const toolMsgIndex = result.findIndex(m => m.role === 'tool' && m.toolId === block.tool_use_id);
                        if (toolMsgIndex >= 0) {
                            result[toolMsgIndex] = {
                                ...result[toolMsgIndex],
                                toolResult: block.content
                            };
                        } else if (pendingToolResults) {
                            // Tool not found in this message - collect for post-processing
                            pendingToolResults.push({
                                tool_use_id: block.tool_use_id,
                                content: block.content
                            });
                        }
                    } else if (block.type === 'interrupted') {
                        result.push({
                            id: this._generateMsgId(),
                            role: 'interrupted',
                            content: 'Response interrupted',
                            timestamp: dbMsg.created_at,
                            turn_number: dbMsg.turn_number
                        });
                    } else if (block.type === 'error') {
                        result.push({
                            id: this._generateMsgId(),
                            role: 'error',
                            content: block.message || 'An unexpected error occurred',
                            timestamp: dbMsg.created_at,
                            turn_number: dbMsg.turn_number
                        });
                    } else if (block.type === 'system') {
                        result.push({
                            id: this._generateMsgId(),
                            role: 'system',
                            content: block.content,
                            subtype: block.subtype,
                            timestamp: dbMsg.created_at,
                            collapsed: false,
                            cost: isLast ? msgCost : null,
                            model: isLast ? msgModel : null,
                            inputTokens: isLast ? msgInputTokens : null,
                            outputTokens: isLast ? msgOutputTokens : null,
                            cacheCreationTokens: isLast ? msgCacheCreation : null,
                            cacheReadTokens: isLast ? msgCacheRead : null,
                            agent: isLast ? dbMsg.agent : null,
                            turn_number: dbMsg.turn_number
                        });
                    }
                }
            }
            return result;
        },

        /**
         * Convert DB messages to UI format and link pending tool results.
         * @param {Array} dbMessages - Array of DB messages
         * @returns {Array} Array of UI-formatted messages
         */
        _convertAllDbMessages(dbMessages) {
            const allUiMessages = [];
            const pendingToolResults = [];

            for (const msg of dbMessages) {
                const converted = this.convertDbMessageToUi(msg, pendingToolResults);
                allUiMessages.push(...converted);
            }

            // Post-process: link any pending tool_results to their tool_use messages
            for (const pending of pendingToolResults) {
                const toolMsgIndex = allUiMessages.findIndex(m => m.role === 'tool' && m.toolId === pending.tool_use_id);
                if (toolMsgIndex >= 0) {
                    allUiMessages[toolMsgIndex] = {
                        ...allUiMessages[toolMsgIndex],
                        toolResult: pending.content
                    };
                }
            }

            return allUiMessages;
        },

        /**
         * Load messages from DB format into the store
         * Handles conversion and tool result linking
         * @param {Array} dbMessages - Array of DB messages
         * @returns {Array} Array of UI messages (also stored in this.messages)
         */
        loadFromDb(dbMessages) {
            const allUiMessages = this._convertAllDbMessages(dbMessages);
            this.messages.length = 0;
            this.messages.push(...allUiMessages);
            return allUiMessages;
        },

        // === Progressive Loading ===

        /**
         * Loads all messages at once behind the loading overlay, scrolls to position, then reveals.
         * For normal load: scrolls to bottom. For search: scrolls to targetTurn.
         *
         * @param {Array} dbMessages - Array of DB messages to load
         * @param {number|null} targetTurn - Target turn number for search (null for normal load)
         * @param {string|null} loadUuid - UUID of conversation being loaded (for race condition guard)
         * @param {Object} callbacks - Callback functions
         * @param {Function} callbacks.getCurrentUuid - Returns current conversation UUID
         * @param {Function} callbacks.nextTick - Alpine's $nextTick equivalent
         * @param {Function} callbacks.scrollToTurn - Scroll to a specific turn
         * @param {Function} callbacks.scrollToBottom - Scroll to bottom
         * @param {Function} callbacks.setLoadingConversation - Set loading state
         */
        async loadMessagesProgressively(dbMessages, targetTurn, loadUuid, callbacks) {
            const allUiMessages = this._convertAllDbMessages(dbMessages);

            if (allUiMessages.length === 0) {
                callbacks.setLoadingConversation(false);
                return;
            }

            // Guard: abort if user switched to different conversation during processing
            if (loadUuid && callbacks.getCurrentUuid() !== loadUuid) {
                return;
            }

            // Render all messages at once behind the loading overlay
            this.messages.length = 0;
            this.messages.push(...allUiMessages);

            // Wait for DOM render, scroll to position, then reveal
            await new Promise(resolve => {
                callbacks.nextTick(() => {
                    if (targetTurn !== null) {
                        callbacks.scrollToTurn(targetTurn);
                    } else {
                        callbacks.scrollToBottom();
                    }
                    resolve();

                    // Hide loading overlay AFTER scroll has painted
                    requestAnimationFrame(() => {
                        if (!loadUuid || callbacks.getCurrentUuid() === loadUuid) {
                            callbacks.setLoadingConversation(false);
                        }
                    });
                });
            });
        },

        // === Stream Message Helpers ===

        /**
         * Check if messages array has content from active streaming
         * Used to distinguish page refresh (no streaming content) from timeout reconnect
         */
        hasActiveStreamingContent() {
            // Streaming messages have client-generated IDs: msg-{timestamp}-thinking, msg-{timestamp}-text, msg-{timestamp}-tool
            // DB-loaded messages have different ID patterns (numeric or UUID from database)
            return this.messages.some(msg => {
                if (msg.id && typeof msg.id === 'string') {
                    return /^msg-\d+-(thinking|text|tool)/.test(msg.id);
                }
                return false;
            });
        },

        /**
         * Find the index of the last user message with string content (typed prompt)
         * Used for stripping stream messages on page refresh
         * @returns {number} Index or -1 if not found
         */
        findLastUserPromptIndex() {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const msg = this.messages[i];
                if (msg.role === 'user' && typeof msg.content === 'string') {
                    return i;
                }
            }
            return -1;
        }
    };
}
