class VoiceHandler {
    constructor() {
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.isRecording = false;
        this.openaiApiKey = null;
        this.stream = null;
        this.recordingStartTime = null;
        this.apiKeyLoaded = false;
    }

    async getApiKey() {
        // Get API key from server environment
        console.log('[DEBUG] Getting API key from server...');
        try {
            const response = await fetch('/api/config.php');
            if (response.ok) {
                const config = await response.json();
                console.log('[DEBUG] API key received:', config.openaiApiKey ? 'YES (' + config.openaiApiKey.length + ' chars)' : 'NO');
                return config.openaiApiKey;
            }
        } catch (error) {
            console.error('Failed to fetch API configuration:', error);
        }
        return null;
    }

    async initializeRecording() {
        // Load API key if not already loaded
        if (!this.apiKeyLoaded) {
            this.openaiApiKey = await this.getApiKey();
            this.apiKeyLoaded = true;
        }

        if (!this.openaiApiKey) {
            console.log('[DEBUG] No API key available, showing error');
            this.showError('OpenAI API key not configured. Please set OPENAI_API_KEY environment variable.');
            return false;
        }

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ 
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    sampleRate: 16000 // Optimal for Whisper
                } 
            });
            
            // Check supported MIME types and use the best available
            let mimeType = 'audio/webm;codecs=opus';
            if (!MediaRecorder.isTypeSupported(mimeType)) {
                mimeType = 'audio/webm';
                if (!MediaRecorder.isTypeSupported(mimeType)) {
                    mimeType = 'audio/mp4';
                    if (!MediaRecorder.isTypeSupported(mimeType)) {
                        mimeType = ''; // Use default
                    }
                }
            }

            this.mediaRecorder = new MediaRecorder(this.stream, {
                mimeType: mimeType || undefined
            });
            
            this.setupRecorderEvents();
            return true;
        } catch (error) {
            console.error('Microphone access denied:', error);
            if (error.name === 'NotAllowedError') {
                this.showError('Microphone access denied. Please allow microphone access and try again.');
            } else if (error.name === 'NotFoundError') {
                this.showError('No microphone found. Please connect a microphone and try again.');
            } else {
                this.showError('Failed to access microphone: ' + error.message);
            }
            return false;
        }
    }

    setupRecorderEvents() {
        this.mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                this.audioChunks.push(event.data);
            }
        };

        this.mediaRecorder.onstop = async () => {
            if (this.audioChunks.length === 0) {
                this.showError('No audio data recorded');
                return;
            }

            const audioBlob = new Blob(this.audioChunks, { 
                type: this.mediaRecorder.mimeType || 'audio/webm' 
            });
            this.audioChunks = [];
            
            // Calculate recording duration for usage tracking
            const recordingDuration = this.recordingStartTime ? 
                (Date.now() - this.recordingStartTime) / 1000 : 0;
            
            await this.transcribeAudio(audioBlob, recordingDuration);
        };

        this.mediaRecorder.onerror = (event) => {
            console.error('Recording error:', event.error);
            this.showError('Recording failed: ' + event.error);
        };
    }

    startRecording() {
        if (!this.mediaRecorder || this.mediaRecorder.state !== 'inactive') {
            return false;
        }

        try {
            this.recordingStartTime = Date.now();
            this.mediaRecorder.start();
            this.isRecording = true;
            return true;
        } catch (error) {
            console.error('Failed to start recording:', error);
            this.showError('Failed to start recording');
            return false;
        }
    }

    stopRecording() {
        if (!this.mediaRecorder || this.mediaRecorder.state !== 'recording') {
            return false;
        }

        try {
            this.mediaRecorder.stop();
            this.isRecording = false;
            
            // Stop all audio tracks to release microphone
            if (this.stream) {
                this.stream.getTracks().forEach(track => track.stop());
            }
            
            return true;
        } catch (error) {
            console.error('Failed to stop recording:', error);
            this.showError('Failed to stop recording');
            return false;
        }
    }

    async transcribeAudio(audioBlob, duration = 0) {
        this.updateStatus('⏳ Converting speech to text...');
        console.log('[DEBUG] Starting transcription, audio blob size:', audioBlob.size);
        
        try {
            // Check minimum audio size
            if (audioBlob.size < 1000) { // Less than 1KB is probably too short
                this.showError('Recording too short, please try again');
                return;
            }

            const formData = new FormData();
            
            // Convert to appropriate format if needed
            const fileName = this.getAudioFileName(audioBlob.type);
            formData.append('file', audioBlob, fileName);
            formData.append('model', 'gpt-4o-transcribe');
            formData.append('response_format', 'json');
            formData.append('language', 'en'); // Can be made configurable
            
            console.log('[DEBUG] Sending request to OpenAI API...');
            const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.openaiApiKey}`
                },
                body: formData
            });

            if (!response.ok) {
                console.log('[DEBUG] API response failed:', response.status, response.statusText);
                const errorData = await response.json().catch(() => ({}));
                const errorMessage = errorData.error?.message || `API error: ${response.status}`;
                throw new Error(errorMessage);
            }

            const result = await response.json();
            console.log('[DEBUG] Transcription result received:', result);
            
            const transcription = result.text;
            
            if (!transcription || transcription.trim().length === 0) {
                this.showError('No speech detected, please try again');
                return;
            }

            this.displayTranscript(transcription);
            this.sendToTerminal(transcription);
            this.updateStatus('✅ Text sent to terminal', 'success');
            
            // Track usage
            if (window.usageTracker && duration > 0) {
                window.usageTracker.trackUsage(duration);
            }
            
        } catch (error) {
            console.error('[DEBUG] Transcription error details:', error);
            console.error('[DEBUG] Full error object:', JSON.stringify(error, Object.getOwnPropertyNames(error)));
            console.error('Transcription failed:', error);
            
            if (error.message.includes('API key')) {
                this.showError('Invalid API key. Please check your OpenAI API key.');
                localStorage.removeItem('openai_api_key');
            } else if (error.message.includes('quota')) {
                this.showError('API quota exceeded. Please check your OpenAI account.');
            } else if (error.message.includes('network') || error.name === 'TypeError') {
                this.showError('Network error. Please check your connection and try again.');
            } else {
                this.showError('Failed to convert speech to text: ' + error.message);
            }
        }
    }

    getAudioFileName(mimeType) {
        if (mimeType.includes('webm')) {
            return 'audio.webm';
        } else if (mimeType.includes('mp4')) {
            return 'audio.m4a';
        } else if (mimeType.includes('wav')) {
            return 'audio.wav';
        } else {
            return 'audio.webm'; // Default
        }
    }

    displayTranscript(text) {
        const transcriptDiv = document.getElementById('transcript');
        if (transcriptDiv) {
            transcriptDiv.textContent = text;
            transcriptDiv.scrollTop = transcriptDiv.scrollHeight;
        }
    }

    sendToTerminal(text) {
        if (window.terminalCommunication) {
            window.terminalCommunication.sendTextToTerminal(text);
        } else {
            // Fallback: try direct iframe communication
            const terminalFrame = document.getElementById('terminal-frame');
            if (terminalFrame && terminalFrame.contentWindow) {
                terminalFrame.contentWindow.postMessage({
                    type: 'keyboard-input',
                    text: text,
                    pressEnter: false
                }, '*');
            }
        }
    }

    updateStatus(message, type = '') {
        const statusDiv = document.getElementById('status');
        if (statusDiv) {
            statusDiv.textContent = message;
            statusDiv.className = `status-display ${type}`;
        }
    }

    showError(message) {
        this.updateStatus(`❌ ${message}`, 'error');
        
        // Auto-clear error after 5 seconds
        setTimeout(() => {
            this.updateStatus('');
        }, 5000);
    }

    cleanup() {
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }
        if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
            this.mediaRecorder.stop();
        }
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.isRecording = false;
    }
}