class TerminalCommunication {
    constructor() {
        this.terminalFrame = document.getElementById('terminal-frame');
        this.terminalReady = false;
        this.setupMessageListener();
        this.setupFrameLoadListener();
    }

    setupMessageListener() {
        window.addEventListener('message', this.handleMessage.bind(this));
    }

    setupFrameLoadListener() {
        if (this.terminalFrame) {
            this.terminalFrame.addEventListener('load', () => {
                // Wait a bit for terminal to fully initialize
                setTimeout(() => {
                    this.injectTerminalIntegration();
                }, 1000);
            });
        }
    }

    handleMessage(event) {
        // Handle responses from terminal iframe
        if (event.data.type === 'terminal-ready') {
            console.log('Terminal is ready for input');
            this.terminalReady = true;
        } else if (event.data.type === 'terminal-error') {
            console.error('Terminal error:', event.data.message);
        }
    }

    injectTerminalIntegration() {
        if (!this.terminalFrame || !this.terminalFrame.contentWindow) {
            console.error('Terminal frame not available');
            return;
        }

        try {
            // Script to inject into TTYD iframe for better integration
            const integrationScript = `
                (function() {
                    console.log('PocketDev terminal integration loaded');
                    
                    function waitForTerminal() {
                        // Try multiple ways to access the terminal
                        const term = window.term || window.terminal || 
                                   (window.Terminal && window.Terminal.term) ||
                                   document.querySelector('.xterm')?.terminal;
                        
                        if (term && (term.write || term.paste || term.sendText)) {
                            setupTerminalIntegration(term);
                        } else {
                            // Keep trying for up to 10 seconds
                            setTimeout(waitForTerminal, 100);
                        }
                    }

                    function setupTerminalIntegration(terminal) {
                        console.log('Terminal found, setting up integration');
                        
                        // Listen for messages from parent window
                        window.addEventListener('message', function(event) {
                            if (event.origin !== window.location.origin && 
                                event.origin !== window.parent.location.origin) {
                                return; // Ignore messages from other origins
                            }
                            
                            if (event.data.type === 'keyboard-input') {
                                const text = event.data.text;
                                const pressEnter = event.data.pressEnter;
                                
                                console.log('Received text input:', text);
                                
                                try {
                                    // Try different methods to send text to terminal
                                    if (terminal.paste) {
                                        terminal.paste(text);
                                    } else if (terminal.write) {
                                        terminal.write(text);
                                    } else if (terminal.sendText) {
                                        terminal.sendText(text);
                                    } else {
                                        // Fallback: simulate key events
                                        simulateTyping(text);
                                    }
                                    
                                    // Press enter if requested
                                    if (pressEnter) {
                                        if (terminal.paste) {
                                            terminal.paste('\\r');
                                        } else if (terminal.write) {
                                            terminal.write('\\r');
                                        } else {
                                            // Simulate Enter key
                                            const enterEvent = new KeyboardEvent('keydown', {
                                                key: 'Enter',
                                                code: 'Enter',
                                                keyCode: 13
                                            });
                                            document.dispatchEvent(enterEvent);
                                        }
                                    }
                                } catch (error) {
                                    console.error('Failed to send text to terminal:', error);
                                    window.parent.postMessage({
                                        type: 'terminal-error',
                                        message: 'Failed to inject text: ' + error.message
                                    }, '*');
                                }
                            }
                        });

                        // Notify parent that terminal is ready
                        window.parent.postMessage({
                            type: 'terminal-ready'
                        }, '*');
                    }

                    function simulateTyping(text) {
                        // Fallback method: simulate typing character by character
                        for (let i = 0; i < text.length; i++) {
                            const char = text[i];
                            const keyEvent = new KeyboardEvent('keydown', {
                                key: char,
                                code: 'Key' + char.toUpperCase(),
                                keyCode: char.charCodeAt(0)
                            });
                            document.dispatchEvent(keyEvent);
                            
                            const inputEvent = new InputEvent('input', {
                                data: char,
                                inputType: 'insertText'
                            });
                            document.dispatchEvent(inputEvent);
                        }
                    }

                    // Start waiting for terminal
                    waitForTerminal();
                })();
            `;

            // Inject the script into the iframe
            const iframe = this.terminalFrame;
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            
            if (iframeDoc) {
                const script = iframeDoc.createElement('script');
                script.textContent = integrationScript;
                iframeDoc.head.appendChild(script);
            }
        } catch (error) {
            console.error('Failed to inject terminal integration:', error);
            // Cross-origin restrictions might prevent injection
            // In that case, we'll rely on postMessage only
        }
    }

    sendTextToTerminal(text) {
        if (!this.terminalFrame || !this.terminalFrame.contentWindow) {
            console.error('Terminal frame not available');
            return false;
        }

        try {
            // Send text as if it were typed (without auto-execution)
            this.terminalFrame.contentWindow.postMessage({
                type: 'keyboard-input',
                text: text,
                pressEnter: false
            }, '*');
            return true;
        } catch (error) {
            console.error('Failed to send text to terminal:', error);
            return false;
        }
    }

    sendCommandToTerminal(command) {
        if (!this.terminalFrame || !this.terminalFrame.contentWindow) {
            console.error('Terminal frame not available');
            return false;
        }

        try {
            // Send command and execute immediately
            this.terminalFrame.contentWindow.postMessage({
                type: 'keyboard-input',
                text: command,
                pressEnter: true
            }, '*');
            return true;
        } catch (error) {
            console.error('Failed to send command to terminal:', error);
            return false;
        }
    }

    focusTerminal() {
        if (this.terminalFrame && this.terminalFrame.contentWindow) {
            try {
                this.terminalFrame.contentWindow.focus();
                this.terminalFrame.focus();
                
                // Add visual indicator
                this.terminalFrame.style.border = '2px solid #007AFF';
                setTimeout(() => {
                    this.terminalFrame.style.border = 'none';
                }, 1000);
                
                return true;
            } catch (error) {
                console.error('Failed to focus terminal:', error);
                return false;
            }
        }
        return false;
    }
}

// Usage tracking class
class UsageTracker {
    constructor() {
        this.sessionUsage = 0;
        this.totalMinutes = 0;
        this.loadFromStorage();
    }

    loadFromStorage() {
        const stored = localStorage.getItem('voice_usage');
        if (stored) {
            try {
                const data = JSON.parse(stored);
                this.totalMinutes = data.totalMinutes || 0;
            } catch (error) {
                console.error('Failed to load usage data:', error);
            }
        }
    }

    saveToStorage() {
        try {
            localStorage.setItem('voice_usage', JSON.stringify({
                totalMinutes: this.totalMinutes,
                lastUpdated: Date.now()
            }));
        } catch (error) {
            console.error('Failed to save usage data:', error);
        }
    }

    trackUsage(durationSeconds) {
        const minutes = durationSeconds / 60;
        this.sessionUsage += minutes * 0.006; // $0.006 per minute
        this.totalMinutes += minutes;
        
        this.updateUsageDisplay();
        this.saveToStorage();
    }

    updateUsageDisplay() {
        const usageDiv = document.getElementById('usage-info');
        if (usageDiv) {
            usageDiv.textContent = `Session: $${this.sessionUsage.toFixed(3)} | Total: ${this.totalMinutes.toFixed(1)}min`;
        }
    }

    resetSession() {
        this.sessionUsage = 0;
        this.updateUsageDisplay();
    }
}

// Error handler class
class ErrorHandler {
    constructor() {
        this.setupGlobalErrorHandling();
    }

    setupGlobalErrorHandling() {
        window.addEventListener('error', this.handleError.bind(this));
        window.addEventListener('unhandledrejection', this.handlePromiseRejection.bind(this));
    }

    handleError(event) {
        console.error('Global error:', event.error);
        this.showUserError('Something went wrong. Please refresh and try again.');
    }

    handlePromiseRejection(event) {
        console.error('Unhandled promise rejection:', event.reason);
        
        if (event.reason && event.reason.message) {
            if (event.reason.message.includes('API')) {
                this.showUserError('Voice service temporarily unavailable');
            } else if (event.reason.message.includes('network')) {
                this.showUserError('Network connection issue');
            }
        }
    }

    showUserError(message) {
        const statusDiv = document.getElementById('status');
        if (statusDiv) {
            statusDiv.textContent = `❌ ${message}`;
            statusDiv.className = 'status-display error';
            
            setTimeout(() => {
                statusDiv.textContent = '';
                statusDiv.className = 'status-display';
            }, 5000);
        }
    }
}