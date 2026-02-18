/**
 * Stream Store - manages SSE connection lifecycle, event handling, and deduplication
 *
 * Sprint 3: Extracted from chat.blade.php chatApp()
 * This is a factory function that returns a plain object.
 * The store communicates with chatApp via callbacks for state updates.
 */

/**
 * Creates a stream store instance
 * @param {Object} callbacks - Callback functions for interacting with chatApp
 * @returns {Object} Stream store instance
 */
export function createStreamStore(callbacks) {
    return {
        // === Connection State ===
        isStreaming: false,
        lastEventIndex: 0,
        lastEventId: null,
        streamAbortController: null,
        _streamConnectNonce: 0,
        _streamRetryTimeoutId: null,
        _justCompletedStream: false,
        _wasStreamingBeforeHidden: false,
        _isReplaying: false,

        // Connection health (dead man's switch)
        _lastKeepaliveAt: null,
        _connectionHealthy: true,
        _keepaliveCheckInterval: null,

        // Stream phase tracking
        _streamPhase: 'idle',  // 'idle' | 'waiting' | 'tool_executing' | 'streaming'
        _phaseChangedAt: null,
        _pendingResponseWarning: false,
        _visibilityHandler: null,

        // Stream state (in-progress message tracking)
        _streamState: {
            thinkingBlocks: {},
            currentThinkingBlock: -1,
            textMsgIndex: -1,
            toolMsgIndex: -1,
            textContent: '',
            toolInput: '',
            turnCost: 0,
            turnInputTokens: 0,
            turnOutputTokens: 0,
            turnCacheCreationTokens: 0,
            turnCacheReadTokens: 0,
            toolInProgress: false,
            waitingForToolResults: new Set(),
            abortPending: false,
            abortSkipSync: false,
            startedAt: null,
        },

        // EVENT DEDUPLICATION (Sprint 3 feature)
        _processedEventIds: new Set(),
        _DEDUP_SET_MAX_SIZE: 200,

        // === Event Deduplication ===

        /**
         * Check if an event is a duplicate and register it if not
         * @param {string} eventId - The event ID to check
         * @returns {boolean} True if duplicate, false if new
         */
        _isEventDuplicate(eventId) {
            if (!eventId) return false;
            if (this._processedEventIds.has(eventId)) {
                console.debug('[Stream] Skipping duplicate event:', eventId);
                return true;
            }
            this._processedEventIds.add(eventId);
            // Prune set if it exceeds max size
            if (this._processedEventIds.size > this._DEDUP_SET_MAX_SIZE) {
                this._pruneEventIdSet();
            }
            return false;
        },

        /**
         * Prune the event ID set to max size (removes oldest entries)
         */
        _pruneEventIdSet() {
            const excess = this._processedEventIds.size - this._DEDUP_SET_MAX_SIZE;
            if (excess <= 0) return;

            const iterator = this._processedEventIds.values();
            for (let i = 0; i < excess; i++) {
                this._processedEventIds.delete(iterator.next().value);
            }
        },

        /**
         * Clear the event ID set (used on conversation change)
         */
        clearEventIdSet() {
            this._processedEventIds.clear();
        },

        // === Stream State Management ===

        /**
         * Reset stream state for a new stream
         */
        resetStreamState() {
            this._streamState = {
                thinkingBlocks: {},
                currentThinkingBlock: -1,
                textMsgIndex: -1,
                toolMsgIndex: -1,
                textContent: '',
                toolInput: '',
                turnCost: 0,
                turnInputTokens: 0,
                turnOutputTokens: 0,
                turnCacheCreationTokens: 0,
                turnCacheReadTokens: 0,
                toolInProgress: false,
                waitingForToolResults: new Set(),
                abortPending: false,
                abortSkipSync: false,
                startedAt: null,
            };
        },

        /**
         * Reset stream state for clean replay on page refresh
         */
        _resetStreamStateForReplay() {
            const uuid = callbacks.getConversationUuid();
            // Preserve startedAt for accurate elapsed time display
            const savedStartedAt = this._streamState.startedAt ?? (() => {
                if (!uuid) return null;
                try {
                    const saved = sessionStorage.getItem(`stream_state_${uuid}`);
                    return saved ? JSON.parse(saved).startedAt : null;
                } catch (_) {
                    return null;
                }
            })();

            this._streamState = {
                thinkingBlocks: {},
                currentThinkingBlock: -1,
                textMsgIndex: -1,
                toolMsgIndex: -1,
                textContent: '',
                toolInput: '',
                turnCost: 0,
                turnInputTokens: 0,
                turnOutputTokens: 0,
                turnCacheCreationTokens: 0,
                turnCacheReadTokens: 0,
                toolInProgress: false,
                waitingForToolResults: new Set(),
                abortPending: false,
                abortSkipSync: false,
                startedAt: savedStartedAt,
            };

            // Reset event tracking for fresh replay
            this.lastEventIndex = 0;
            this.lastEventId = null;
        },

        /**
         * Save stream state to sessionStorage for persistence across refresh
         */
        _saveStreamState(uuid) {
            if (!uuid) return;
            try {
                const state = {
                    thinkingBlocks: Object.fromEntries(
                        Object.entries(this._streamState.thinkingBlocks).map(([k, v]) => [k, {
                            msgIndex: v.msgIndex,
                            content: v.content,
                            complete: v.complete
                        }])
                    ),
                    currentThinkingBlock: this._streamState.currentThinkingBlock,
                    textMsgIndex: this._streamState.textMsgIndex,
                    toolMsgIndex: this._streamState.toolMsgIndex,
                    textContent: this._streamState.textContent,
                    toolInput: this._streamState.toolInput,
                    toolInProgress: this._streamState.toolInProgress,
                    waitingForToolResults: Array.from(this._streamState.waitingForToolResults || []),
                    abortPending: this._streamState.abortPending,
                    abortSkipSync: this._streamState.abortSkipSync,
                    turnCost: this._streamState.turnCost,
                    turnInputTokens: this._streamState.turnInputTokens,
                    turnOutputTokens: this._streamState.turnOutputTokens,
                    turnCacheCreationTokens: this._streamState.turnCacheCreationTokens,
                    turnCacheReadTokens: this._streamState.turnCacheReadTokens,
                    startedAt: this._streamState.startedAt,
                };
                sessionStorage.setItem(`stream_state_${uuid}`, JSON.stringify(state));
            } catch (e) {
                console.warn('[Stream] Failed to save stream state:', e);
            }
        },

        /**
         * Restore stream state from sessionStorage
         */
        _restoreStreamState(uuid) {
            if (!uuid) return;
            try {
                const saved = sessionStorage.getItem(`stream_state_${uuid}`);
                if (saved) {
                    const state = JSON.parse(saved);
                    if (state.thinkingBlocks) this._streamState.thinkingBlocks = state.thinkingBlocks;
                    if (state.currentThinkingBlock !== undefined) this._streamState.currentThinkingBlock = state.currentThinkingBlock;
                    if (state.textMsgIndex !== undefined) this._streamState.textMsgIndex = state.textMsgIndex;
                    if (state.toolMsgIndex !== undefined) this._streamState.toolMsgIndex = state.toolMsgIndex;
                    if (state.textContent !== undefined) this._streamState.textContent = state.textContent;
                    if (state.toolInput !== undefined) this._streamState.toolInput = state.toolInput;
                    if (state.toolInProgress !== undefined) this._streamState.toolInProgress = state.toolInProgress;
                    if (Array.isArray(state.waitingForToolResults)) {
                        this._streamState.waitingForToolResults = new Set(state.waitingForToolResults);
                    }
                    if (state.abortPending !== undefined) this._streamState.abortPending = state.abortPending;
                    if (state.abortSkipSync !== undefined) this._streamState.abortSkipSync = state.abortSkipSync;
                    if (state.turnCost !== undefined) this._streamState.turnCost = state.turnCost;
                    if (state.turnInputTokens !== undefined) this._streamState.turnInputTokens = state.turnInputTokens;
                    if (state.turnOutputTokens !== undefined) this._streamState.turnOutputTokens = state.turnOutputTokens;
                    if (state.turnCacheCreationTokens !== undefined) this._streamState.turnCacheCreationTokens = state.turnCacheCreationTokens;
                    if (state.turnCacheReadTokens !== undefined) this._streamState.turnCacheReadTokens = state.turnCacheReadTokens;
                    if (state.startedAt) this._streamState.startedAt = state.startedAt;
                    console.log('[Stream] Restored stream state from sessionStorage:', state);
                }
            } catch (e) {
                console.warn('[Stream] Failed to restore stream state:', e);
            }
        },

        /**
         * Clear stream-related sessionStorage for a conversation
         */
        _clearStreamStorage(uuid) {
            if (!uuid) return;
            try {
                sessionStorage.removeItem(`stream_index_${uuid}`);
                sessionStorage.removeItem(`stream_state_${uuid}`);
            } catch (e) {
                // sessionStorage might not be available
            }
        },

        // === Connection Lifecycle ===

        /**
         * Check for active stream and reconnect if found
         */
        async checkAndReconnectStream(uuid) {
            // Don't reconnect if we just finished streaming
            if (this._justCompletedStream) {
                return;
            }

            // Don't reconnect if existing connection is healthy
            if (this.isStreaming && this.streamAbortController &&
                !this.streamAbortController.signal.aborted &&
                this._connectionHealthy && !this._wasStreamingBeforeHidden) {
                console.log('[Stream] Skipping reconnect - existing connection appears healthy');
                return;
            }

            try {
                const response = await fetch(`/api/conversations/${uuid}/stream-status`);
                const data = await response.json();

                if (data.is_streaming) {
                    // Detect: page refresh vs timeout/tab-switch reconnect
                    const isPageRefresh = !callbacks.messageStore.hasActiveStreamingContent();
                    const isTabReturn = this._wasStreamingBeforeHidden;

                    let fromIndex;
                    if (isPageRefresh && !isTabReturn) {
                        // PAGE REFRESH: Always replay from 0 to rebuild lost content
                        // On page refresh, the messages array is reloaded from DB which
                        // doesn't include in-flight streaming content. We must replay
                        // all SSE events to rebuild the assistant response.
                        // Note: savedIndex tracks SSE events received in-memory, but those
                        // events built content that's now lost (page reload cleared JS state
                        // and DB doesn't have in-flight content). Resuming from savedIndex
                        // would skip events needed to rebuild.
                        this._resetStreamStateForReplay();
                        this._isReplaying = true;
                        fromIndex = 0;
                        console.log('[Stream] Page refresh - replaying from 0 to rebuild content');
                    } else {
                        // TIMEOUT RECONNECT or TAB RETURN
                        this._restoreStreamState(uuid);
                        const savedIndex = sessionStorage.getItem(`stream_index_${uuid}`);
                        fromIndex = savedIndex ? parseInt(savedIndex, 10) : this.lastEventIndex;
                        console.log('[Stream] Timeout/tab reconnect:', { uuid, fromIndex, isTabReturn, eventCount: data.event_count });
                    }

                    // Seed in-memory tracker
                    this.lastEventIndex = fromIndex;

                    // Connect to stream events
                    await this.connectToStreamEvents(fromIndex);
                } else {
                    // Stream is not active, clear any stale sessionStorage
                    this._clearStreamStorage(uuid);
                }
            } catch (err) {
                console.debug('[Stream] No active stream for reconnection:', err.message);
            }
        },

        /**
         * Connect to stream events SSE endpoint
         */
        async connectToStreamEvents(fromIndex = 0, startupRetryCount = 0, networkRetryCount = 0) {
            const uuid = callbacks.getConversationUuid();
            if (!uuid) return;

            const maxStartupRetries = 15;
            const maxNetworkRetries = 5;

            // Abort any existing connection
            this.disconnectFromStream();

            // Increment nonce to invalidate pending reconnection attempts
            const myNonce = ++this._streamConnectNonce;

            console.log('[Stream] connectToStreamEvents:', {
                uuid,
                fromIndex,
                startupRetryCount,
                networkRetryCount,
                nonce: myNonce,
                lastEventIndex: this.lastEventIndex,
                lastEventId: this.lastEventId,
            });

            this.isStreaming = true;
            callbacks.onStreamStart();

            // Restore stream phase after disconnect
            if (this._streamPhase === 'idle') {
                this._streamPhase = 'waiting';
                this._phaseChangedAt = Date.now();
                this._pendingResponseWarning = false;
            }

            // Start connection health check
            this._lastKeepaliveAt = Date.now();
            this._connectionHealthy = true;
            if (this._keepaliveCheckInterval) clearInterval(this._keepaliveCheckInterval);
            this._keepaliveCheckInterval = setInterval(() => {
                if (document.hidden) return;

                if (this._lastKeepaliveAt && Date.now() - this._lastKeepaliveAt > 45000) {
                    this._connectionHealthy = false;
                }
                if (this._streamPhase === 'waiting' && this._phaseChangedAt &&
                    Date.now() - this._phaseChangedAt > 15000) {
                    this._pendingResponseWarning = true;
                } else {
                    this._pendingResponseWarning = false;
                }
            }, 5000);

            // Reset keepalive timer when tab becomes visible
            this._visibilityHandler = () => {
                if (!document.hidden && this._lastKeepaliveAt) {
                    this._lastKeepaliveAt = Date.now();
                    this._connectionHealthy = true;
                }
            };
            document.addEventListener('visibilitychange', this._visibilityHandler);

            this.streamAbortController = new AbortController();
            let pendingRetry = false;

            try {
                const url = `/api/conversations/${uuid}/stream-events?from_index=${fromIndex}`;
                const response = await fetch(url, { signal: this.streamAbortController.signal });

                // Check if superseded
                if (myNonce !== this._streamConnectNonce) {
                    console.log('[Stream] Connection superseded by newer attempt');
                    return;
                }

                // Check for HTTP errors before reading stream
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;

                        try {
                            const event = JSON.parse(line.substring(6));

                            // Track event index and ID
                            if (event.index !== undefined) {
                                this.lastEventIndex = event.index + 1;
                                try {
                                    sessionStorage.setItem(`stream_index_${uuid}`, this.lastEventIndex);
                                } catch (e) {}
                            }
                            if (event.event_id) {
                                this.lastEventId = event.event_id;
                            }

                            // EVENT DEDUPLICATION (Sprint 3)
                            if (event.event_id && this._isEventDuplicate(event.event_id)) {
                                continue;
                            }

                            // Handle stream status events
                            if (event.type === 'stream_status') {
                                if (event.status === 'not_found') {
                                    if (startupRetryCount < maxStartupRetries) {
                                        if (myNonce === this._streamConnectNonce) {
                                            pendingRetry = true;
                                            this._streamRetryTimeoutId = setTimeout(
                                                () => this.connectToStreamEvents(0, startupRetryCount + 1, 0),
                                                200
                                            );
                                        }
                                        return;
                                    } else {
                                        this.isStreaming = false;
                                        callbacks.onStreamEnd('failed');
                                        callbacks.showError('Failed to connect to stream');
                                        return;
                                    }
                                }
                                if (event.status === 'completed' || event.status === 'failed') {
                                    this.isStreaming = false;
                                    this._isReplaying = false;
                                    callbacks.onStreamEnd(event.status);
                                    this._justCompletedStream = true;
                                    this._clearStreamStorage(uuid);
                                    setTimeout(() => { this._justCompletedStream = false; }, 3000);
                                }
                                continue;
                            }

                            if (event.type === 'timeout') {
                                if (myNonce === this._streamConnectNonce) {
                                    pendingRetry = true;
                                    this._streamRetryTimeoutId = setTimeout(
                                        () => this.connectToStreamEvents(this.lastEventIndex, 0, 0),
                                        100
                                    );
                                }
                                return;
                            }

                            // Handle keepalive
                            if (event.type === 'keepalive') {
                                this._lastKeepaliveAt = Date.now();
                                this._connectionHealthy = true;
                                continue;
                            }

                            // Handle regular stream events
                            this._lastKeepaliveAt = Date.now();
                            this._connectionHealthy = true;
                            this.handleStreamEvent(event);

                        } catch (parseErr) {
                            console.error('Parse error:', parseErr, line);
                        }
                    }
                }
            } catch (err) {
                if (err.name === 'AbortError') {
                    console.log('Stream connection aborted');
                } else {
                    console.error('Stream connection error:', err);
                    if (networkRetryCount < maxNetworkRetries && myNonce === this._streamConnectNonce) {
                        const delay = Math.min(1000 * Math.pow(2, networkRetryCount), 8000);
                        console.log(`[Stream] Network error, retrying in ${delay}ms`);
                        this._streamRetryTimeoutId = setTimeout(
                            () => this.connectToStreamEvents(this.lastEventIndex, 0, networkRetryCount + 1),
                            delay
                        );
                        pendingRetry = true;
                        return;
                    }
                }
            } finally {
                if (!pendingRetry && !this.streamAbortController?.signal.aborted &&
                    myNonce === this._streamConnectNonce) {
                    this.isStreaming = false;

                    if (this._keepaliveCheckInterval) {
                        clearInterval(this._keepaliveCheckInterval);
                        this._keepaliveCheckInterval = null;
                    }
                    if (this._visibilityHandler) {
                        document.removeEventListener('visibilitychange', this._visibilityHandler);
                        this._visibilityHandler = null;
                    }
                    this._connectionHealthy = true;
                    this._lastKeepaliveAt = null;
                }
            }
        },

        /**
         * Disconnect from stream
         */
        disconnectFromStream() {
            if (this._streamRetryTimeoutId) {
                clearTimeout(this._streamRetryTimeoutId);
                this._streamRetryTimeoutId = null;
            }

            if (this._keepaliveCheckInterval) {
                clearInterval(this._keepaliveCheckInterval);
                this._keepaliveCheckInterval = null;
            }
            if (this._visibilityHandler) {
                document.removeEventListener('visibilitychange', this._visibilityHandler);
                this._visibilityHandler = null;
            }
            this._connectionHealthy = true;
            this._lastKeepaliveAt = null;
            this._streamPhase = 'idle';
            this._phaseChangedAt = null;
            this._pendingResponseWarning = false;

            if (this.streamAbortController) {
                this.streamAbortController.abort();
                this.streamAbortController = null;
            }
        },

        /**
         * Abort the current stream (user clicked stop)
         */
        async abortStream() {
            const uuid = callbacks.getConversationUuid();
            if (!this.isStreaming || !uuid) return;

            const state = this._streamState;

            // Defer abort if tool parameters are being streamed
            if (state.toolInProgress) {
                console.log('Abort requested while streaming tool parameters - deferring');
                state.abortPending = true;
                return;
            }

            // Defer abort if waiting for tool results
            if (state.waitingForToolResults.size > 0) {
                console.log('Abort requested while waiting for tool results - deferring');
                state.abortPending = true;
                return;
            }

            const skipSync = state.abortSkipSync;

            try {
                const response = await fetch(`/api/conversations/${uuid}/abort`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ skipSync }),
                });

                state.abortSkipSync = false;

                if (!response.ok) {
                    console.error('Failed to abort stream:', await response.text());
                }

                // Clean up in-progress messages via callback
                callbacks.onAbortCleanup(state);

                this.disconnectFromStream();
                this.isStreaming = false;

            } catch (err) {
                console.error('Error aborting stream:', err);
                this.isStreaming = false;
            }
        },

        // === Event Handling ===

        /**
         * Handle a single stream event
         */
        handleStreamEvent(event) {
            const uuid = callbacks.getConversationUuid();

            // Validate event belongs to current conversation
            if (event.conversation_uuid && event.conversation_uuid !== uuid) {
                console.debug('Ignoring event for different conversation:', event.conversation_uuid);
                return;
            }

            const state = this._streamState;
            const messages = callbacks.getMessages();
            const messageStore = callbacks.messageStore;

            if (event.type !== 'usage') {
                console.log('SSE Event:', event.type, event.content ? String(event.content).substring(0, 50) : '(no content)');
            }

            switch (event.type) {
                case 'thinking_start': {
                    this._streamPhase = 'streaming';
                    this._phaseChangedAt = Date.now();
                    this._pendingResponseWarning = false;

                    const thinkingBlockIndex = event.block_index ?? 0;
                    state.currentThinkingBlock = thinkingBlockIndex;
                    state.thinkingBlocks[thinkingBlockIndex] = {
                        msgIndex: messages.length,
                        content: '',
                        complete: false
                    };
                    messageStore.pushMessage({
                        id: 'msg-' + Date.now() + '-thinking-' + thinkingBlockIndex + '-' + Math.random(),
                        role: 'thinking',
                        content: '',
                        timestamp: state.startedAt || new Date().toISOString(),
                        collapsed: false
                    });
                    callbacks.scrollToBottom();
                    this._saveStreamState(uuid);
                    break;
                }

                case 'thinking_delta': {
                    const blockIdx = event.block_index ?? state.currentThinkingBlock;
                    const block = state.thinkingBlocks[blockIdx];
                    if (block && event.content) {
                        block.content += event.content;
                        messageStore.updateMessage(block.msgIndex, { content: block.content });
                        callbacks.scrollToBottom();
                        this._saveStreamState(uuid);
                    }
                    break;
                }

                case 'thinking_signature': {
                    const blockIdx = event.block_index ?? state.currentThinkingBlock;
                    if (state.thinkingBlocks[blockIdx]) {
                        state.thinkingBlocks[blockIdx].complete = true;
                    }
                    break;
                }

                case 'thinking_stop': {
                    const blockIdx = event.block_index ?? state.currentThinkingBlock;
                    const block = state.thinkingBlocks[blockIdx];
                    if (block) {
                        messageStore.updateMessage(block.msgIndex, { collapsed: true });
                    }
                    break;
                }

                case 'text_start': {
                    this._streamPhase = 'streaming';
                    this._phaseChangedAt = Date.now();
                    this._pendingResponseWarning = false;

                    // Collapse all thinking blocks
                    for (const blockIdx in state.thinkingBlocks) {
                        const block = state.thinkingBlocks[blockIdx];
                        if (block && block.msgIndex >= 0) {
                            messageStore.updateMessage(block.msgIndex, { collapsed: true });
                        }
                    }

                    state.textMsgIndex = messages.length;
                    state.textContent = '';
                    state.turnCost = 0;
                    state.turnInputTokens = 0;
                    state.turnOutputTokens = 0;
                    state.turnCacheCreationTokens = 0;
                    state.turnCacheReadTokens = 0;

                    messageStore.pushMessage({
                        id: 'msg-' + Date.now() + '-text-' + Math.random(),
                        role: 'assistant',
                        content: '',
                        timestamp: state.startedAt || new Date().toISOString(),
                        collapsed: false
                    });
                    callbacks.scrollToBottom();
                    this._saveStreamState(uuid);
                    break;
                }

                case 'text_delta': {
                    if (state.textMsgIndex >= 0 && event.content) {
                        state.textContent += event.content;
                        messageStore.updateMessage(state.textMsgIndex, { content: state.textContent });
                        callbacks.scrollToBottom();
                        this._saveStreamState(uuid);
                    }
                    break;
                }

                case 'text_stop':
                    // Text block complete
                    break;

                case 'tool_use_start': {
                    this._streamPhase = 'tool_executing';
                    this._phaseChangedAt = Date.now();
                    this._pendingResponseWarning = false;

                    // Collapse all thinking blocks
                    for (const blockIdx in state.thinkingBlocks) {
                        const block = state.thinkingBlocks[blockIdx];
                        if (block && block.msgIndex >= 0) {
                            messageStore.updateMessage(block.msgIndex, { collapsed: true });
                        }
                    }

                    state.toolInProgress = true;
                    state.toolMsgIndex = messages.length;
                    state.toolInput = '';

                    const toolId = event.metadata?.tool_id;
                    if (toolId) {
                        state.waitingForToolResults.add(toolId);
                    }

                    messageStore.pushMessage({
                        id: 'msg-' + Date.now() + '-tool-' + Math.random(),
                        role: 'tool',
                        toolName: event.metadata?.tool_name || 'Tool',
                        toolId: toolId,
                        toolInput: '',
                        toolResult: null,
                        content: '',
                        timestamp: state.startedAt || new Date().toISOString(),
                        collapsed: false
                    });
                    callbacks.scrollToBottom();
                    this._saveStreamState(uuid);
                    break;
                }

                case 'tool_use_delta': {
                    if (state.toolMsgIndex >= 0 && event.content) {
                        state.toolInput += event.content;
                        messageStore.updateMessage(state.toolMsgIndex, {
                            toolInput: state.toolInput,
                            content: state.toolInput
                        });
                        callbacks.scrollToBottom();
                        this._saveStreamState(uuid);
                    }
                    break;
                }

                case 'tool_use_stop': {
                    state.toolInProgress = false;
                    if (state.toolMsgIndex >= 0) {
                        messageStore.updateMessage(state.toolMsgIndex, { collapsed: true });
                        state.toolMsgIndex = -1;
                        state.toolInput = '';
                    }
                    break;
                }

                case 'tool_result': {
                    this._streamPhase = 'waiting';
                    this._phaseChangedAt = Date.now();
                    this._pendingResponseWarning = false;

                    const toolResultId = event.metadata?.tool_id;
                    if (toolResultId) {
                        const toolMsgIndex = messageStore.findToolMessageIndex(toolResultId);
                        if (toolMsgIndex >= 0) {
                            messageStore.updateMessage(toolMsgIndex, { toolResult: event.content });
                        }
                        state.waitingForToolResults.delete(toolResultId);

                        // CLI provider panel detection
                        if (!this._isReplaying) {
                            let panelOutput = event.content;
                            if (typeof panelOutput === 'string') {
                                try {
                                    const parsed = JSON.parse(panelOutput);
                                    if (parsed.output) panelOutput = parsed.output;
                                } catch (e) {}
                            }
                            const outputStr = typeof panelOutput === 'string' ? panelOutput : '';
                            if (outputStr.startsWith("Opened panel '")) {
                                callbacks.refreshSessionScreens();
                                callbacks.dispatch('screen-added');
                            }
                        }
                    }

                    this._saveStreamState(uuid);

                    // Check deferred abort
                    if (state.abortPending && state.waitingForToolResults.size === 0 && !state.toolInProgress) {
                        console.log('All pending tools complete - triggering deferred abort');
                        state.abortPending = false;
                        state.abortSkipSync = true;
                        this.abortStream();
                    }
                    break;
                }

                case 'system_info': {
                    messageStore.pushMessage({
                        id: 'msg-' + Date.now() + '-' + Math.random(),
                        role: 'system',
                        content: event.content,
                        command: event.metadata?.command || null
                    });
                    callbacks.scrollToBottom();
                    break;
                }

                case 'usage': {
                    // Skip during replay to avoid double-counting tokens
                    if (this._isReplaying) break;
                    if (event.metadata) {
                        const input = event.metadata.input_tokens || 0;
                        const output = event.metadata.output_tokens || 0;
                        const cacheCreation = event.metadata.cache_creation_tokens || 0;
                        const cacheRead = event.metadata.cache_read_tokens || 0;
                        const cost = event.metadata.cost || 0;

                        messageStore.addTokens({ input, output, cacheCreation, cacheRead, cost });

                        // Update context window tracking
                        callbacks.updateContext({
                            contextWindowSize: event.metadata.context_window_size,
                            contextPercentage: event.metadata.context_percentage,
                            lastContextTokens: input + output
                        });

                        // Store in turn state
                        state.turnCost = cost;
                        state.turnInputTokens = input;
                        state.turnOutputTokens = output;
                        state.turnCacheCreationTokens = cacheCreation;
                        state.turnCacheReadTokens = cacheRead;
                    }
                    break;
                }

                case 'context_compacted': {
                    callbacks.debugLog('Context compacted (legacy)', event.metadata);
                    break;
                }

                case 'compaction_summary': {
                    this._streamPhase = 'waiting';
                    this._phaseChangedAt = Date.now();
                    this._pendingResponseWarning = false;

                    callbacks.debugLog('Compaction summary received', {
                        contentLength: event.content?.length,
                        preTokens: event.metadata?.pre_tokens,
                        trigger: event.metadata?.trigger
                    });

                    const compactPreTokens = event.metadata?.pre_tokens;
                    const compactPreTokensDisplay = compactPreTokens != null ? compactPreTokens.toLocaleString() : 'unknown';

                    messageStore.pushMessage({
                        id: 'msg-compaction-' + Date.now(),
                        role: 'compaction',
                        content: event.content || '',
                        preTokens: compactPreTokens,
                        preTokensDisplay: compactPreTokensDisplay,
                        trigger: event.metadata?.trigger ?? 'auto',
                        timestamp: event.timestamp || Date.now(),
                        collapsed: true
                    });
                    callbacks.scrollToBottom();
                    break;
                }

                case 'screen_created': {
                    if (!this._isReplaying) {
                        callbacks.refreshSessionScreens();
                        callbacks.dispatch('screen-added');
                    }
                    break;
                }

                case 'done': {
                    this._streamPhase = 'idle';
                    this._phaseChangedAt = null;
                    this._pendingResponseWarning = false;

                    if (state.textMsgIndex >= 0) {
                        messageStore.updateMessage(state.textMsgIndex, {
                            cost: state.turnCost,
                            inputTokens: state.turnInputTokens,
                            outputTokens: state.turnOutputTokens,
                            cacheCreationTokens: state.turnCacheCreationTokens,
                            cacheReadTokens: state.turnCacheReadTokens,
                            model: callbacks.getModel(),
                            agent: callbacks.getCurrentAgent()
                        });
                    }
                    break;
                }

                case 'error': {
                    if (event.content && event.content.startsWith('CLAUDE_CODE_AUTH_REQUIRED:')) {
                        callbacks.showClaudeCodeAuthModal();
                    } else {
                        callbacks.showError(event.content || 'Unknown error');
                    }
                    break;
                }

                case 'debug': {
                    console.log('Debug from server:', event.content, event.metadata);
                    break;
                }

                default:
                    console.log('Unknown event type:', event.type, event);
            }
        },

        // === Connection Indicator ===

        /**
         * Get connection indicator state
         * @returns {'hidden' | 'connected' | 'processing' | 'disconnected'}
         */
        getIndicatorState() {
            if (!this.isStreaming) return 'hidden';
            if (!this._connectionHealthy) return 'disconnected';
            if (this._pendingResponseWarning) return 'processing';
            return 'connected';
        }
    };
}
