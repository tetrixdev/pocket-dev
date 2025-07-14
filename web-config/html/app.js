// Main application initialization and button control
class VoiceButtonController {
    constructor(voiceHandler) {
        this.voiceHandler = voiceHandler;
        this.button = document.getElementById('voice-btn');
        this.statusDiv = document.getElementById('status');
        
        this.states = {
            idle: { text: '🎤 Start Dictation', class: 'btn-idle' },
            recording: { text: '🔴 Stop Recording', class: 'btn-recording' },
            processing: { text: '⏳ Converting...', class: 'btn-processing' }
        };
        
        this.currentState = 'idle';
        this.setupEventListeners();
    }

    setupEventListeners() {
        // Voice button event listeners
        this.button.addEventListener('click', this.handleButtonClick.bind(this));
        
        // Prevent accidental double-taps on mobile
        this.button.addEventListener('touchstart', (e) => {
            e.preventDefault();
        });

        // Handle touch end to ensure click is registered
        this.button.addEventListener('touchend', (e) => {
            e.preventDefault();
            if (e.target === this.button) {
                this.handleButtonClick();
            }
        });

        // Keyboard shortcuts (for desktop/laptop users)
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + Shift + V for voice recording
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'V') {
                e.preventDefault();
                this.handleButtonClick();
            }
            
            // Escape to stop recording
            if (e.key === 'Escape' && this.currentState === 'recording') {
                e.preventDefault();
                this.handleButtonClick();
            }
        });
    }


    async handleButtonClick() {
        switch(this.currentState) {
            case 'idle':
                await this.startRecording();
                break;
            case 'recording':
                this.stopRecording();
                break;
            case 'processing':
                // Ignore clicks while processing
                break;
        }
    }

    async startRecording() {
        this.showStatus('🎙️ Initializing microphone...');
        
        const initialized = await this.voiceHandler.initializeRecording();
        if (!initialized) {
            this.setState('idle');
            return;
        }

        const started = this.voiceHandler.startRecording();
        if (started) {
            this.setState('recording');
            this.showStatus('🎙️ Listening... Tap to stop');
        } else {
            this.setState('idle');
            this.showStatus('❌ Failed to start recording', 'error');
        }
    }

    stopRecording() {
        const stopped = this.voiceHandler.stopRecording();
        if (stopped) {
            this.setState('processing');
            this.showStatus('⏳ Converting speech to text...');
        } else {
            this.setState('idle');
            this.showStatus('❌ Failed to stop recording', 'error');
        }
    }

    setState(newState) {
        this.currentState = newState;
        const stateConfig = this.states[newState];
        this.button.textContent = stateConfig.text;
        this.button.className = `voice-button ${stateConfig.class}`;
    }

    showStatus(message, type = '') {
        if (this.statusDiv) {
            this.statusDiv.textContent = message;
            this.statusDiv.className = `status-display ${type}`;
        }
        
        // Auto-clear success messages and reset to idle
        if (message.includes('✅')) {
            setTimeout(() => {
                this.statusDiv.textContent = '';
                this.statusDiv.className = 'status-display';
                this.setState('idle');
            }, 2000);
        } else if (type === 'error') {
            // Auto-clear error messages
            setTimeout(() => {
                this.statusDiv.textContent = '';
                this.statusDiv.className = 'status-display';
                if (this.currentState === 'processing') {
                    this.setState('idle');
                }
            }, 3000);
        }
    }
}

// Application initialization
class PocketDevApp {
    constructor() {
        this.init();
    }

    async init() {
        console.log('Initializing PocketDev Mobile Terminal...');
        
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setup());
        } else {
            this.setup();
        }
    }

    setup() {
        try {
            // Initialize core components
            this.setupGlobalComponents();
            this.setupVoiceInterface();
            this.setupTerminalInterface();
            this.setupUsageTracking();
            this.setupErrorHandling();
            
            console.log('✅ PocketDev Mobile Terminal initialized successfully');
            this.showInitializationStatus();
            
        } catch (error) {
            console.error('Failed to initialize PocketDev:', error);
            this.showError('Failed to initialize application');
        }
    }

    setupGlobalComponents() {
        // Make components globally available
        window.terminalCommunication = new TerminalCommunication();
        window.usageTracker = new UsageTracker();
        window.errorHandler = new ErrorHandler();
    }

    setupVoiceInterface() {
        const voiceHandler = new VoiceHandler();
        const buttonController = new VoiceButtonController(voiceHandler);
        
        // Make voice handler globally available for debugging
        window.voiceHandler = voiceHandler;
        window.buttonController = buttonController;
        
        // Override the voice handler's status updates to use button controller
        const originalUpdateStatus = voiceHandler.updateStatus.bind(voiceHandler);
        voiceHandler.updateStatus = (message, type) => {
            buttonController.showStatus(message, type);
        };
    }

    setupTerminalInterface() {
        // Additional terminal setup if needed
        const terminalFrame = document.getElementById('terminal-frame');
        if (terminalFrame) {
            // Handle iframe load errors
            terminalFrame.addEventListener('error', () => {
                this.showError('Failed to load terminal interface');
            });
        }
    }

    setupUsageTracking() {
        if (window.usageTracker) {
            window.usageTracker.updateUsageDisplay();
        }
    }

    setupErrorHandling() {
        // Additional error handling setup
        if ('serviceWorker' in navigator) {
            // Optional: Register service worker for offline functionality
            this.registerServiceWorker();
        }
    }

    async registerServiceWorker() {
        try {
            // This would be implemented if we want offline functionality
            console.log('Service worker not implemented (optional feature)');
        } catch (error) {
            console.log('Service worker registration failed (optional feature)');
        }
    }

    showInitializationStatus() {
        const statusDiv = document.getElementById('status');
        if (statusDiv) {
            statusDiv.textContent = '✅ Ready for voice input';
            statusDiv.className = 'status-display success';
            
            setTimeout(() => {
                statusDiv.textContent = '';
                statusDiv.className = 'status-display';
            }, 3000);
        }
    }

    showError(message) {
        const statusDiv = document.getElementById('status');
        if (statusDiv) {
            statusDiv.textContent = `❌ ${message}`;
            statusDiv.className = 'status-display error';
        }
    }
}

// Utility functions
function checkBrowserCompatibility() {
    const features = {
        mediaRecorder: 'MediaRecorder' in window,
        getUserMedia: 'mediaDevices' in navigator && 'getUserMedia' in navigator.mediaDevices,
        fetch: 'fetch' in window,
        postMessage: 'postMessage' in window
    };
    
    const unsupported = Object.entries(features)
        .filter(([, supported]) => !supported)
        .map(([feature]) => feature);
    
    if (unsupported.length > 0) {
        console.warn('Unsupported browser features:', unsupported);
        const statusDiv = document.getElementById('status');
        if (statusDiv) {
            statusDiv.textContent = `⚠️ Browser compatibility issues: ${unsupported.join(', ')}`;
            statusDiv.className = 'status-display error';
        }
        return false;
    }
    
    return true;
}

function detectMobile() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

// Debug helpers (remove in production)
window.debug = {
    testVoice: () => {
        if (window.voiceHandler && window.terminalCommunication) {
            const testText = "echo 'Hello from voice input!'";
            window.terminalCommunication.sendTextToTerminal(testText);
            console.log('Test text sent to terminal:', testText);
        }
    },
    
    clearUsage: () => {
        if (window.usageTracker) {
            window.usageTracker.resetSession();
            localStorage.removeItem('voice_usage');
            console.log('Usage tracking cleared');
        }
    },
    
    testAPI: async () => {
        const key = localStorage.getItem('openai_api_key');
        if (!key) {
            console.log('No API key found');
            return;
        }
        
        try {
            const response = await fetch('https://api.openai.com/v1/models', {
                headers: { 'Authorization': `Bearer ${key}` }
            });
            console.log('API test result:', response.ok ? 'Success' : 'Failed');
        } catch (error) {
            console.log('API test error:', error.message);
        }
    }
};

// Initialize application
document.addEventListener('DOMContentLoaded', () => {
    if (checkBrowserCompatibility()) {
        new PocketDevApp();
    }
});