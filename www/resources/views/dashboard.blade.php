<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PocketDev - Development Environment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* Custom styles for mobile optimization */
        .terminal-iframe {
            border: none;
            width: 100%;
            height: 100%;
            background: #000;
        }
        
        /* Mobile-friendly button styles */
        .control-btn {
            min-height: 60px;
            font-size: 1.1rem;
            touch-action: manipulation;
            user-select: none;
        }

        /* Responsive layout adjustments */
        @media (orientation: landscape) and (max-width: 1024px) {
            .mobile-container {
                flex-direction: row !important;
            }
            .terminal-container {
                flex: 0.75 !important;
            }
            .control-panel {
                flex: 0.25 !important;
                max-width: 300px;
            }
        }
    </style>
</head>
<body class="bg-gray-900 text-white overflow-hidden">
    <div class="mobile-container h-screen flex flex-col" x-data="terminalApp()">
        <!-- Terminal Container (70% height) -->
        <div class="terminal-container flex-[0.7] bg-black">
            <iframe 
                id="terminal-frame" 
                class="terminal-iframe"
                src="/terminal/"
                x-ref="terminalFrame"
            ></iframe>
        </div>

        <!-- Control Panel (30% height) -->
        <div class="control-panel flex-[0.3] bg-gray-800 p-4 flex flex-col gap-3">
            <!-- Status Display -->
            <div class="status-display">
                <div 
                    class="text-sm p-2 rounded mb-2 transition-all duration-300"
                    :class="statusClass"
                    x-text="status || defaultStatus"
                ></div>
                
                <!-- Keyboard shortcuts help -->
                <div x-show="!status && !isRecording && !isProcessing" class="text-xs text-gray-400 mb-2">
                    💡 Shortcuts: Ctrl+Enter (send), Ctrl+Shift+C (clear), Esc (stop)
                </div>
                
                <!-- Transcription Preview -->
                <div x-show="transcription" class="bg-gray-700 p-2 rounded text-sm mb-2">
                    <div class="text-gray-300 text-xs mb-1">Preview: (Ctrl+Enter to send)</div>
                    <div 
                        x-text="transcription" 
                        class="text-green-300"
                        tabindex="0"
                        @keydown.ctrl.enter.prevent="sendToTerminal()"
                    ></div>
                </div>
            </div>

            <!-- Control Buttons Grid -->
            <div class="grid grid-cols-2 gap-3">
                <!-- Record/Stop Button -->
                <button 
                    @click="toggleRecording()"
                    class="control-btn px-4 py-3 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-4"
                    :class="recordingButtonClass"
                    :disabled="isProcessing"
                    x-text="recordingButtonText"
                ></button>

                <!-- Manual Send Button (backup) -->
                <button 
                    @click="sendToTerminal()"
                    class="control-btn bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-4 py-3 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-green-300"
                    :disabled="!transcription || isProcessing"
                    x-show="transcription"
                >
                    🚀 Send Now
                </button>

                <!-- Clear Terminal Line -->
                <button 
                    @click="clearTerminalLine()"
                    class="control-btn bg-yellow-600 hover:bg-yellow-700 px-4 py-3 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-yellow-300"
                >
                    🗑️ Clear Line
                </button>

                <!-- New Line Button -->
                <button 
                    @click="sendNewLine()"
                    class="control-btn bg-purple-600 hover:bg-purple-700 px-4 py-3 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-purple-300"
                >
                    ↵ New Line
                </button>
            </div>

        </div>
    </div>

    <script>
        // Terminal Integration Class - Working implementation from pocket-dev2
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
                                            if (terminal.input) {
                                                terminal.input(text);
                                            } else if (terminal.paste) {
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
                                                if (terminal.input) {
                                                    terminal.input('\\r');
                                                } else if (terminal.paste) {
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
                                    } else if (event.data.type === 'key') {
                                        // Handle key events (Escape, Enter, Ctrl+C, Alt+Enter)
                                        try {
                                            if (event.data.key === 'Escape') {
                                                // Send escape character using input() for actual input
                                                if (terminal.input) {
                                                    terminal.input('\\x1b');
                                                } else {
                                                    terminal.write('\\x1b');
                                                }
                                            } else if (event.data.key === 'Enter') {
                                                if (event.data.altKey) {
                                                    // Alt+Enter - send escape + carriage return (\\e\\r)
                                                    if (terminal.input) {
                                                        terminal.input('\\x1b\\r');
                                                    } else {
                                                        terminal.write('\\x1b\\r');
                                                    }
                                                } else {
                                                    // Regular Enter
                                                    if (terminal.input) {
                                                        terminal.input('\\r');
                                                    } else {
                                                        terminal.write('\\r');
                                                    }
                                                }
                                            } else if (event.data.ctrlKey && event.data.key === 'c') {
                                                // Ctrl+C
                                                if (terminal.input) {
                                                    terminal.input('\\x03');
                                                } else {
                                                    terminal.write('\\x03');
                                                }
                                            } else if (event.data.key === 'clear-line') {
                                                // Clear current line: Try multiple approaches
                                                console.log('Clearing terminal line...');
                                                
                                                // Approach 1: Ctrl+U (clear line from cursor to beginning)
                                                if (terminal.input) {
                                                    terminal.input('\\x15'); // Ctrl+U
                                                } else {
                                                    terminal.write('\\x15');
                                                }
                                                
                                                // Approach 2: If that doesn't work, try Ctrl+A then Ctrl+K
                                                setTimeout(() => {
                                                    if (terminal.input) {
                                                        terminal.input('\\x01'); // Ctrl+A (beginning of line)
                                                        terminal.input('\\x0b'); // Ctrl+K (kill to end of line)
                                                    } else {
                                                        terminal.write('\\x01');
                                                        terminal.write('\\x0b');
                                                    }
                                                }, 50);
                                            }
                                        } catch (error) {
                                            console.error('Failed to send key to terminal:', error);
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

            sendKeyToTerminal(keyType) {
                if (!this.terminalFrame || !this.terminalFrame.contentWindow) {
                    console.error('Terminal frame not available');
                    return false;
                }

                try {
                    switch(keyType) {
                        case 'escape':
                            this.terminalFrame.contentWindow.postMessage({
                                type: 'key',
                                key: 'Escape'
                            }, '*');
                            break;
                        case 'enter':
                            this.terminalFrame.contentWindow.postMessage({
                                type: 'key',
                                key: 'Enter'
                            }, '*');
                            break;
                        case 'alt-enter':
                            this.terminalFrame.contentWindow.postMessage({
                                type: 'key',
                                key: 'Enter',
                                altKey: true
                            }, '*');
                            break;
                        case 'interrupt':
                            this.terminalFrame.contentWindow.postMessage({
                                type: 'key',
                                key: 'c',
                                ctrlKey: true
                            }, '*');
                            break;
                        case 'clear-line':
                            this.terminalFrame.contentWindow.postMessage({
                                type: 'key',
                                key: 'clear-line'
                            }, '*');
                            break;
                    }
                    return true;
                } catch (error) {
                    console.error('Failed to send key to terminal:', error);
                    return false;
                }
            }
        }

        // Initialize terminal communication
        window.terminalCommunication = null;
        document.addEventListener('DOMContentLoaded', function() {
            window.terminalCommunication = new TerminalCommunication();
        });

        function terminalApp() {
            return {
                // State
                isRecording: false,
                isProcessing: false,
                transcription: '',
                status: '',
                statusType: '', // 'success', 'error', 'info'
                
                
                // Recording
                mediaRecorder: null,
                audioChunks: [],
                stream: null,

                // Initialize
                init() {
                    this.setupGlobalKeyboardShortcuts();
                },
                
                setupGlobalKeyboardShortcuts() {
                    // Add global keyboard shortcuts
                    document.addEventListener('keydown', (event) => {
                        // Ctrl+Enter to send transcription to terminal
                        if (event.ctrlKey && event.key === 'Enter' && this.transcription) {
                            event.preventDefault();
                            this.sendToTerminal();
                        }
                        
                        // Ctrl+Shift+C to clear terminal line  
                        if (event.ctrlKey && event.shiftKey && event.key === 'C') {
                            event.preventDefault();
                            this.clearTerminalLine();
                        }
                        
                        // Escape to stop recording if recording
                        if (event.key === 'Escape' && this.isRecording) {
                            event.preventDefault();
                            this.stopRecording();
                        }
                    });
                },

                // Recording Management
                async toggleRecording() {
                    if (this.isRecording) {
                        this.stopRecording();
                    } else {
                        await this.startRecording();
                    }
                },

                async startRecording() {
                    this.updateStatus('🎙️ Requesting microphone access...', 'info');
                    
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ 
                            audio: {
                                // Fixed optimal settings for quality
                                echoCancellation: false, // OFF by default - prevents muffled audio when YouTube/videos play in background
                                noiseSuppression: true,  // Always on for best quality
                                autoGainControl: true,   // Always on for consistent levels
                                // Keep quality settings
                                channelCount: 1, // Mono for voice
                                // Removed sampleRate - let browser choose optimal value
                                latency: 0.01 // Low latency
                            } 
                        });
                        
                        // Check supported MIME types and use the best available with quality settings
                        let mimeType = 'audio/webm;codecs=opus';
                        let recordingOptions = { audioBitsPerSecond: 128000 }; // 128 kbps for good quality
                        
                        if (!MediaRecorder.isTypeSupported(mimeType)) {
                            mimeType = 'audio/webm';
                            if (!MediaRecorder.isTypeSupported(mimeType)) {
                                mimeType = 'audio/mp4';
                                if (!MediaRecorder.isTypeSupported(mimeType)) {
                                    mimeType = ''; // Use default
                                    recordingOptions = {}; // Clear bitrate if unsupported format
                                }
                            }
                        }
                        
                        if (mimeType) {
                            recordingOptions.mimeType = mimeType;
                        }
                        
                        this.mediaRecorder = new MediaRecorder(this.stream, recordingOptions);
                        
                        console.log('MediaRecorder created with options:', {
                            mimeType: this.mediaRecorder.mimeType,
                            audioBitsPerSecond: recordingOptions.audioBitsPerSecond
                        });
                        this.audioChunks = [];
                        
                        this.mediaRecorder.ondataavailable = (event) => {
                            if (event.data.size > 0) {
                                this.audioChunks.push(event.data);
                            }
                        };

                        this.mediaRecorder.onstop = async () => {
                            if (this.audioChunks.length === 0) {
                                this.updateStatus('❌ No audio data recorded', 'error');
                                return;
                            }

                            const audioBlob = new Blob(this.audioChunks, { 
                                type: this.mediaRecorder.mimeType || 'audio/webm' 
                            });
                            
                            console.log('Audio blob created:', {
                                size: audioBlob.size + ' bytes',
                                type: audioBlob.type
                            });
                            
                            await this.processAudio(audioBlob);
                        };

                        this.mediaRecorder.onerror = (event) => {
                            console.error('MediaRecorder error:', event.error);
                            this.updateStatus('❌ Recording failed: ' + event.error, 'error');
                        };

                        this.mediaRecorder.start();
                        this.isRecording = true;
                        this.updateStatus('🎙️ Recording... Click to stop', 'info');
                        
                    } catch (error) {
                        console.error('Microphone access denied:', error);
                        this.updateStatus('❌ Microphone access denied', 'error');
                    }
                },

                stopRecording() {
                    if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
                        this.mediaRecorder.stop();
                        this.isRecording = false;
                        this.isProcessing = true;
                        this.updateStatus('⏳ Converting speech to text...', 'info');
                        
                        // Stop all audio tracks
                        if (this.stream) {
                            this.stream.getTracks().forEach(track => track.stop());
                        }
                    }
                },

                async processAudio(audioBlob) {
                    try {
                        console.log('Processing audio blob:', {
                            size: audioBlob.size + ' bytes', 
                            type: audioBlob.type
                        });
                        
                        if (audioBlob.size < 1000) {
                            this.updateStatus('❌ Recording too short', 'error');
                            return;
                        }

                        // Generate appropriate filename based on MIME type
                        let filename = 'audio.webm';
                        if (audioBlob.type.includes('mp4') || audioBlob.type.includes('m4a')) {
                            filename = 'audio.m4a';
                        } else if (audioBlob.type.includes('wav')) {
                            filename = 'audio.wav';
                        }

                        const formData = new FormData();
                        formData.append('audio', audioBlob, filename);
                        
                        const response = await fetch('/transcribe', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            }
                        });

                        if (!response.ok) {
                            const errorData = await response.json();
                            throw new Error(errorData.error || `API error: ${response.status}`);
                        }

                        const data = await response.json();
                        
                        if (data.transcription && data.transcription.trim().length > 0) {
                            this.transcription = data.transcription;
                            this.updateStatus('✅ Audio transcribed - sending to terminal...', 'success');
                            
                            // Automatically send to terminal after transcription
                            await this.sendToTerminal();
                        } else {
                            this.updateStatus('❌ No speech detected', 'error');
                        }
                        
                    } catch (error) {
                        console.error('Transcription failed:', error);
                        this.updateStatus(`❌ ${error.message || 'Transcription failed'}`, 'error');
                    } finally {
                        this.isProcessing = false;
                    }
                },

                // Terminal Communication
                async sendToTerminal() {
                    if (!this.transcription) return;
                    
                    try {
                        if (window.terminalCommunication) {
                            const success = window.terminalCommunication.sendTextToTerminal(this.transcription);
                            if (success) {
                                this.updateStatus('✅ Command sent to terminal', 'success');
                            } else {
                                this.updateStatus('❌ Failed to send to terminal', 'error');
                            }
                        } else {
                            this.updateStatus('❌ Terminal not ready', 'error');
                        }
                        
                        // Clear transcription after sending
                        setTimeout(() => {
                            this.transcription = '';
                        }, 2000);
                        
                    } catch (error) {
                        console.error('Terminal send error:', error);
                        this.updateStatus('❌ Failed to send to terminal', 'error');
                    }
                },

                clearTerminalLine() {
                    try {
                        console.log('Attempting to clear terminal line...');
                        if (window.terminalCommunication) {
                            // Send Escape twice
                            const success1 = window.terminalCommunication.sendKeyToTerminal('escape');
                            // Longer delay between escapes to allow terminal to process first escape
                            setTimeout(() => {
                                const success2 = window.terminalCommunication.sendKeyToTerminal('escape');
                                console.log('Escape commands sent, success:', success1, success2);
                                if (success1 && success2) {
                                    this.updateStatus('🗑️ Terminal line cleared', 'success');
                                } else {
                                    this.updateStatus('❌ Failed to clear terminal line', 'error');
                                }
                            }, 100); // 100ms delay to give terminal time to process first escape
                        } else {
                            this.updateStatus('❌ Terminal not ready', 'error');
                        }
                    } catch (error) {
                        console.error('Clear terminal line error:', error);
                        this.updateStatus('❌ Failed to clear terminal line', 'error');
                    }
                },

                sendNewLine() {
                    try {
                        console.log('Sending new line (Alt+Enter)...');
                        if (window.terminalCommunication) {
                            const success = window.terminalCommunication.sendKeyToTerminal('alt-enter');
                            console.log('Alt+Enter command sent, success:', success);
                            if (success) {
                                this.updateStatus('↵ New line sent', 'success');
                            } else {
                                this.updateStatus('❌ Failed to send new line', 'error');
                            }
                        } else {
                            this.updateStatus('❌ Terminal not ready', 'error');
                        }
                    } catch (error) {
                        console.error('Send new line error:', error);
                        this.updateStatus('❌ Failed to send new line', 'error');
                    }
                },

                // Status Management
                updateStatus(message, type = 'info') {
                    this.status = message;
                    this.statusType = type;
                    
                    // Auto-clear status after delay
                    setTimeout(() => {
                        if (this.status === message) {
                            this.status = '';
                            this.statusType = '';
                        }
                    }, type === 'error' ? 5000 : 3000);
                },

                // Computed Properties
                get recordingButtonText() {
                    if (this.isProcessing) return '⏳ Processing...';
                    if (this.isRecording) return '⏹️ Stop Recording';
                    return '🎙️ Start Recording';
                },

                get recordingButtonClass() {
                    if (this.isProcessing) {
                        return 'bg-orange-600 cursor-not-allowed focus:ring-orange-300';
                    }
                    if (this.isRecording) {
                        return 'bg-red-600 hover:bg-red-700 animate-pulse focus:ring-red-300';
                    }
                    return 'bg-blue-600 hover:bg-blue-700 focus:ring-blue-300';
                },

                get statusClass() {
                    switch (this.statusType) {
                        case 'success':
                            return 'bg-green-700 text-green-100 border border-green-600';
                        case 'error':
                            return 'bg-red-700 text-red-100 border border-red-600';
                        default:
                            return 'bg-gray-700 text-gray-100 border border-gray-600';
                    }
                },

                get defaultStatus() {
                    return '🎙️ Press "Start Recording" to begin voice input';
                }
            }
        }
    </script>
</body>
</html>