<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ $csrfToken }}">
    <title>PocketDev Terminal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Terminal specific styles */
        .terminal-container {
            background: #1a1a1a;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
        }

        .terminal-iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: #1a1a1a;
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
            .terminal-section {
                flex: 0.75 !important;
            }
            .control-section {
                flex: 0.25 !important;
                max-width: 300px;
            }
        }

        /* Mobile keyboard focus fixes */
        @media (max-width: 768px) {
            .terminal-iframe:focus {
                outline: none;
            }
        }
    </style>
</head>
<body class="bg-gray-900 text-white overflow-hidden">
    <div class="mobile-container h-screen flex flex-col"
         x-data="terminalApp('{{ $wsUrl }}', {{ $hasOpenAI ? 'true' : 'false' }})">

        <!-- Terminal Section (70% height) -->
        <div class="terminal-section flex-[0.7] p-4">
            <div class="terminal-container h-full">
                <iframe
                    id="terminal-iframe"
                    class="terminal-iframe"
                    :src="terminalUrl"
                    @load="onTerminalLoad()"
                    @touchstart="handleMobileTouch($event)">
                </iframe>
            </div>
        </div>

        <!-- Control Section (30% height) -->
        <div class="control-section flex-[0.3] bg-gray-800 p-4 flex flex-col gap-3">

            <!-- Connection Status -->
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 rounded-full"
                     :class="terminalLoaded ? 'bg-green-500' : 'bg-yellow-500'">
                </div>
                <span class="text-sm" x-text="terminalLoaded ? 'Terminal Ready' : 'Loading Terminal...'"></span>
            </div>

            <!-- Status Display -->
            <div class="status-display">
                <div class="text-sm p-2 rounded mb-2 transition-all duration-300 bg-gray-700 text-gray-100 border border-gray-600"
                     :class="statusClass"
                     x-text="status">
                    Ready for voice input
                </div>
            </div>

            <!-- Control Buttons -->
            <div class="flex flex-col gap-3">

                <!-- Voice Recording Button (Full Width) -->
                <template x-if="hasOpenAI">
                    <button @click="toggleRecording()"
                            class="control-btn px-4 py-3 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-4 w-full"
                            :class="recordingButtonClass"
                            :disabled="isProcessing || !terminalLoaded"
                            x-text="recordingButtonText">
                        🎙️ Start Recording
                    </button>
                </template>

                <!-- Quick Action Buttons (Half Width Grid) -->
                <div class="grid grid-cols-2 gap-3">
                    <!-- Clear Line Button -->
                    <button @click="clearLine()"
                            class="control-btn bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-300 px-4 py-3 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-4"
                            :disabled="!terminalLoaded">
                        🗑️ Clear Line
                    </button>

                    <!-- New Line Button -->
                    <button @click="sendNewLine()"
                            class="control-btn bg-purple-600 hover:bg-purple-700 focus:ring-purple-300 px-4 py-3 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-4"
                            :disabled="!terminalLoaded">
                        ↵ Enter
                    </button>
                </div>

                <!-- Quick Commands (if needed) -->
                <template x-if="showQuickCommands">
                    <div class="grid grid-cols-2 gap-2">
                        <button @click="sendCommand('ls -la')"
                                class="text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded"
                                :disabled="!terminalLoaded">
                            ls -la
                        </button>
                        <button @click="sendCommand('git status')"
                                class="text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded"
                                :disabled="!terminalLoaded">
                            git status
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        function terminalApp(wsUrl, hasOpenAI) {
            return {
                // Terminal connection via iframe
                terminalUrl: null,
                terminalIframe: null,
                terminalLoaded: false,

                // Voice recording
                hasOpenAI: hasOpenAI,
                isRecording: false,
                isProcessing: false,
                mediaRecorder: null,
                audioChunks: [],
                status: 'Ready for voice input',

                // UI state
                showQuickCommands: false,

                init() {
                    // Convert WebSocket URL to HTTP URL for iframe
                    this.terminalUrl = wsUrl.replace('ws://', 'http://').replace('wss://', 'https://').split('/?')[0];
                    console.log('🚀 Terminal URL:', this.terminalUrl);

                    // Setup postMessage listener for iframe communication
                    this.setupMessageListener();
                },

                setupMessageListener() {
                    window.addEventListener('message', (event) => {
                        // Only accept messages from the terminal iframe
                        if (event.origin === new URL(this.terminalUrl).origin) {
                            console.log('📥 Received message from terminal:', event.data);
                        }
                    });
                },

                onTerminalLoad() {
                    console.log('✅ Terminal iframe loaded');
                    this.terminalIframe = document.getElementById('terminal-iframe');
                    this.terminalLoaded = true;
                    this.status = 'Terminal ready';
                },

                handleMobileTouch(event) {
                    // Mobile keyboard focus fix
                    event.preventDefault();
                    if (this.terminalIframe) {
                        this.terminalIframe.focus();
                        console.log('📱 Mobile touch - focused terminal iframe');
                    }
                },

                // Voice recording methods
                async toggleRecording() {
                    if (this.isRecording) {
                        this.stopRecording();
                    } else {
                        await this.startRecording();
                    }
                },

                async startRecording() {
                    try {
                        this.status = 'Requesting microphone access...';

                        const stream = await navigator.mediaDevices.getUserMedia({
                            audio: {
                                sampleRate: 16000,
                                channelCount: 1,
                                echoCancellation: true,
                                noiseSuppression: true
                            }
                        });

                        this.audioChunks = [];
                        this.mediaRecorder = new MediaRecorder(stream, {
                            mimeType: 'audio/webm;codecs=opus'
                        });

                        this.mediaRecorder.ondataavailable = (event) => {
                            if (event.data.size > 0) {
                                this.audioChunks.push(event.data);
                            }
                        };

                        this.mediaRecorder.onstop = () => {
                            this.processRecording();
                            stream.getTracks().forEach(track => track.stop());
                        };

                        this.mediaRecorder.start();
                        this.isRecording = true;
                        this.status = '🎙️ Recording... Click to stop';

                    } catch (error) {
                        console.error('Failed to start recording:', error);
                        this.status = 'Microphone access denied';
                    }
                },

                stopRecording() {
                    if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
                        this.mediaRecorder.stop();
                        this.isRecording = false;
                        this.isProcessing = true;
                        this.status = 'Processing audio...';
                    }
                },

                async processRecording() {
                    try {
                        const audioBlob = new Blob(this.audioChunks, { type: 'audio/webm' });
                        const formData = new FormData();
                        formData.append('audio', audioBlob, 'recording.webm');

                        const response = await fetch('/terminal/transcribe', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            }
                        });

                        const result = await response.json();

                        if (result.success && result.transcription) {
                            this.sendCommand(result.transcription);
                            this.status = `Sent: ${result.transcription}`;
                        } else {
                            this.status = result.error || 'Transcription failed';
                        }

                    } catch (error) {
                        console.error('Failed to process recording:', error);
                        this.status = 'Failed to process audio';
                    } finally {
                        this.isProcessing = false;
                    }
                },

                // Terminal control methods using clipboard/postMessage
                sendCommand(command) {
                    if (this.terminalIframe && this.terminalLoaded) {
                        console.log('📤 Sending command to terminal:', command);

                        // Focus the iframe first
                        this.terminalIframe.focus();

                        // Try to send command via postMessage
                        try {
                            this.terminalIframe.contentWindow.postMessage({
                                type: 'command',
                                data: command + '\r'
                            }, this.terminalUrl);
                        } catch (error) {
                            console.warn('⚠️ PostMessage failed, falling back to clipboard method');
                            // Fallback: Use clipboard to send command
                            this.sendViaClipboard(command);
                        }
                    }
                },

                async sendViaClipboard(command) {
                    try {
                        await navigator.clipboard.writeText(command);
                        this.terminalIframe.focus();
                        console.log('📋 Command copied to clipboard, focus on terminal to paste');
                        this.status = `Copied to clipboard: ${command}`;
                    } catch (error) {
                        console.error('Failed to copy to clipboard:', error);
                        this.status = 'Failed to send command';
                    }
                },

                clearLine() {
                    if (this.terminalIframe && this.terminalLoaded) {
                        // Send Ctrl+U (clear line) character
                        this.terminalIframe.focus();
                        try {
                            this.terminalIframe.contentWindow.postMessage({
                                type: 'key',
                                data: '\x15'  // Ctrl+U
                            }, this.terminalUrl);
                        } catch (error) {
                            console.warn('⚠️ Clear line via postMessage failed');
                        }
                    }
                },

                sendNewLine() {
                    if (this.terminalIframe && this.terminalLoaded) {
                        this.terminalIframe.focus();
                        try {
                            this.terminalIframe.contentWindow.postMessage({
                                type: 'key',
                                data: '\r'  // Enter key
                            }, this.terminalUrl);
                        } catch (error) {
                            console.warn('⚠️ Send newline via postMessage failed');
                        }
                    }
                },

                // Computed properties
                get recordingButtonText() {
                    if (this.isProcessing) return '⏳ Processing...';
                    if (this.isRecording) return '⏹️ Stop Recording';
                    return '🎙️ Start Recording';
                },

                get recordingButtonClass() {
                    if (this.isProcessing) return 'bg-gray-600 cursor-not-allowed';
                    if (this.isRecording) return 'bg-red-600 hover:bg-red-700 focus:ring-red-300';
                    return 'bg-blue-600 hover:bg-blue-700 focus:ring-blue-300';
                },

                get statusClass() {
                    if (this.status.includes('error') || this.status.includes('failed')) {
                        return 'border-red-500 bg-red-900';
                    }
                    if (this.isRecording) {
                        return 'border-red-500 bg-red-900';
                    }
                    if (this.isProcessing) {
                        return 'border-yellow-500 bg-yellow-900';
                    }
                    return 'border-gray-600 bg-gray-700';
                }
            };
        }
    </script>
</body>
</html>