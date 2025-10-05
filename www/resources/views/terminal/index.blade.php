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
            display: flex;
            align-items: center;
            justify-content: center;
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
    <div class="mobile-container h-[100dvh] flex flex-col pb-[144px]"
         x-data="terminalApp('{{ $wsUrl }}', {{ $hasOpenAI ? 'true' : 'false' }})">

        <!-- Terminal Section (full height minus bottom nav) -->
        <div class="terminal-section flex-1 p-4">
            <div class="terminal-container h-full relative">
                <iframe
                    id="terminal-iframe"
                    class="terminal-iframe"
                    :src="terminalUrl"
                    @load="onTerminalLoad()"
                    @touchstart="handleMobileTouch($event)">
                </iframe>

                <!-- Loading/Retry Spinner Overlay -->
                <div x-show="isRetrying"
                     class="absolute inset-0 bg-gray-900 bg-opacity-80 flex items-center justify-center z-10"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-300"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto mb-4"></div>
                        <p class="text-white text-sm" x-text="status"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fixed Bottom Navigation -->
        <div class="fixed bottom-0 left-0 right-0 bg-gray-800 border-t border-gray-700 safe-area-bottom z-50">
            <div class="grid grid-cols-2 gap-2 p-2">
                <!-- Voice Recording or Status -->
                <template x-if="hasOpenAI">
                    <button @click="toggleRecording()"
                            class="control-btn px-3 py-2 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-2 text-sm"
                            :class="recordingButtonClass"
                            :disabled="isProcessing || !terminalLoaded"
                            x-text="recordingButtonText">
                        🎙️ Record
                    </button>
                </template>
                <template x-if="!hasOpenAI">
                    <div class="flex items-center gap-2 px-3 py-2">
                        <div class="w-2 h-2 rounded-full"
                             :class="terminalLoaded ? 'bg-green-500' : 'bg-yellow-500'">
                        </div>
                        <span class="text-xs" x-text="terminalLoaded ? 'Ready' : 'Loading...'"></span>
                    </div>
                </template>

                <button @click="sendNewLine()"
                        class="control-btn bg-purple-600 hover:bg-purple-700 focus:ring-purple-300 px-3 py-2 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-2 text-sm"
                        :disabled="!terminalLoaded">
                    ↵ Enter
                </button>
            </div>

            <!-- Additional quick actions row -->
            <div class="grid grid-cols-2 gap-2 p-2 pt-0">
                <button @click="clearLine()"
                        class="control-btn bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-300 px-3 py-2 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-2 text-sm"
                        :disabled="!terminalLoaded">
                    🗑️ Clear
                </button>

                <!-- Config Navigation -->
                <a href="/config"
                   class="control-btn bg-gray-700 hover:bg-gray-600 focus:ring-gray-300 px-3 py-2 rounded-lg font-semibold transition-all duration-200 focus:outline-none focus:ring-2 text-center text-sm">
                    ⚙️ Configuration
                </a>
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
                isRetrying: true, // Start with spinner visible

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
                    // Use nginx proxy URL for same-origin access
                    this.terminalUrl = '/terminal-ws/';
                    console.log('🚀 Terminal URL (via nginx proxy):', this.terminalUrl);

                    // Setup postMessage listener for iframe communication
                    this.setupMessageListener();

                    // Setup iframe retry mechanism
                    this.setupIframeRetry();

                    // Start loading the terminal
                    this.loadTerminalWithRetry();
                },

                setupIframeRetry() {
                    this.maxRetries = 3;
                    this.retryCount = 0;
                    this.retryDelay = 2000; // 2 seconds
                },

                async loadTerminalWithRetry() {
                    const iframe = document.getElementById('terminal-iframe');
                    if (!iframe) return;

                    this.retryCount++;
                    this.isRetrying = true;
                    this.status = `Loading terminal (attempt ${this.retryCount}/${this.maxRetries})...`;

                    try {
                        // Test the URL first with fetch
                        const response = await fetch(this.terminalUrl, {
                            method: 'HEAD',
                            cache: 'no-cache'
                        });

                        if (response.ok) {
                            // If URL is accessible, load the iframe
                            iframe.src = this.terminalUrl;
                            console.log(`✅ Terminal URL accessible, loading iframe (attempt ${this.retryCount})`);
                        } else {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }
                    } catch (error) {
                        console.warn(`⚠️ Terminal load attempt ${this.retryCount} failed:`, error.message);

                        if (this.retryCount < this.maxRetries) {
                            this.status = `Connection failed, retrying in ${this.retryDelay/1000}s...`;
                            setTimeout(() => {
                                this.loadTerminalWithRetry();
                            }, this.retryDelay);
                        } else {
                            this.status = 'Terminal connection failed after multiple attempts';
                            this.isRetrying = false;
                            console.error('🚨 Terminal failed to load after all retry attempts');
                        }
                    }
                },

                setupMessageListener() {
                    window.addEventListener('message', (event) => {
                        // Only accept messages from same origin (since we're using nginx proxy)
                        if (event.origin === window.location.origin) {
                            console.log('📥 Received message from terminal:', event.data);
                        }
                    });
                },

                onTerminalLoad() {
                    console.log('📄 Iframe loaded, checking if it\'s the actual terminal...');
                    this.terminalIframe = document.getElementById('terminal-iframe');

                    // Don't hide spinner yet - need to verify it's actually the terminal
                    // Test same-origin access to confirm it's working
                    setTimeout(() => {
                        this.verifyTerminalWorking();
                    }, 500); // Small delay to let iframe fully initialize
                },

                onTerminalError() {
                    console.warn('⚠️ Terminal iframe error detected');
                    if (this.retryCount < this.maxRetries) {
                        this.isRetrying = true;
                        this.status = 'Terminal error, retrying...';
                        setTimeout(() => {
                            this.loadTerminalWithRetry();
                        }, this.retryDelay);
                    } else {
                        this.isRetrying = false;
                        this.status = 'Terminal failed to load';
                    }
                },

                verifyTerminalWorking() {
                    try {
                        // Check if iframe loaded an error page by looking at the title or content
                        const iframeDoc = this.terminalIframe.contentDocument;

                        if (!iframeDoc) {
                            console.warn('⚠️ Cannot access iframe content - still loading or error');
                            this.retryIfNeeded();
                            return;
                        }

                        // Check if it's a 502 error page
                        const title = iframeDoc.title;
                        const bodyText = iframeDoc.body?.textContent || '';

                        if (title.includes('502') || bodyText.includes('502 Bad Gateway') || bodyText.includes('Bad Gateway')) {
                            console.warn('⚠️ Iframe loaded 502 error page, retrying...');
                            this.retryIfNeeded();
                            return;
                        }

                        // Check if it's the actual terminal (ttyd page should have specific content)
                        if (title.includes('ttyd') || bodyText.includes('terminal') || iframeDoc.querySelector('#terminal')) {
                            console.log('✅ Terminal successfully loaded and verified!');
                            this.terminalLoaded = true;
                            this.isRetrying = false; // Hide spinner - terminal is working!
                            this.status = 'Terminal ready - connection successful';
                            this.retryCount = 0; // Reset retry count

                            // Test same-origin access for additional features
                            this.testSameOriginAccess();
                        } else {
                            console.warn('⚠️ Iframe loaded unknown content, retrying...');
                            this.retryIfNeeded();
                        }

                    } catch (error) {
                        console.warn('⚠️ Error verifying terminal:', error.message);
                        this.retryIfNeeded();
                    }
                },

                retryIfNeeded() {
                    if (this.retryCount < this.maxRetries) {
                        this.status = `Connection failed, retrying in ${this.retryDelay/1000}s...`;
                        setTimeout(() => {
                            this.loadTerminalWithRetry();
                        }, this.retryDelay);
                    } else {
                        this.status = 'Terminal connection failed after multiple attempts';
                        this.isRetrying = false; // Hide spinner after max retries
                        console.error('🚨 Terminal failed to load after all retry attempts');
                    }
                },

                testSameOriginAccess() {
                    try {
                        const iframeDoc = this.terminalIframe.contentDocument;
                        if (iframeDoc) {
                            console.log('🎉 Same-origin access confirmed - keyboard injection enabled');
                            this.status = 'Terminal ready - keyboard injection enabled';
                        } else {
                            this.status = 'Terminal ready - limited access';
                        }
                    } catch (error) {
                        console.warn('⚠️ Same-origin test failed:', error.message);
                        this.status = 'Terminal ready - cross-origin restrictions apply';
                    }
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

                        const response = await fetch('/transcribe', {
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

                // Helper method for terminal access
                getTerminalInstance() {
                    if (!this.terminalIframe || !this.terminalLoaded) {
                        this.status = 'Terminal not ready';
                        return null;
                    }

                    try {
                        const iframeWin = this.terminalIframe.contentWindow;
                        if (iframeWin.term) {
                            return iframeWin.term;
                        } else {
                            console.warn('⚠️ No terminal object found');
                            this.status = 'No terminal object available';
                            return null;
                        }
                    } catch (error) {
                        console.warn('⚠️ Terminal access failed:', error.message);
                        this.status = 'Terminal access failed';
                        return null;
                    }
                },

                // Terminal control methods using direct terminal access
                sendCommand(command) {
                    console.log('📤 Sending command via direct terminal access:', command);
                    const term = this.getTerminalInstance();
                    if (term) {
                        term.input(command + ' ');
                        console.log('✅ Command sent via term.input (with space)');
                        this.status = `Sent: ${command}`;
                    }
                },

                async clearLine() {
                    const term = this.getTerminalInstance();
                    if (term) {
                        // Send escape twice with 100ms delay between them
                        term.input('\x1b');
                        await new Promise(resolve => setTimeout(resolve, 100));
                        term.input('\x1b');
                        console.log('✅ Clear line sent via term.input (escape twice with delay)');
                        this.status = 'Line cleared';
                    }
                },

                sendNewLine() {
                    const term = this.getTerminalInstance();
                    if (term) {
                        term.input('\r');
                        console.log('✅ Enter key sent via term.input');
                        this.status = 'Enter key sent';
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