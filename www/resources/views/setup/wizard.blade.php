<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Welcome to PocketDev</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-900 text-white min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg" x-data="setupWizard()">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold mb-2">Welcome to PocketDev</h1>
            <p class="text-gray-400">Let's get you set up with an AI provider</p>
        </div>

        @if(session('error'))
            <div class="mb-6 p-4 bg-red-900 border-l-4 border-red-500 text-red-200 rounded">
                {{ session('error') }}
            </div>
        @endif

        @if(session('warning'))
            <div class="mb-6 p-4 bg-yellow-900 border-l-4 border-yellow-500 text-yellow-200 rounded">
                {{ session('warning') }}
            </div>
        @endif

        <form method="POST" action="{{ route('setup.provider.process') }}" class="space-y-6">
            @csrf

            {{-- Provider Selection --}}
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">Choose an AI Provider</h2>
                <p class="text-sm text-gray-400 mb-4">Select at least one provider to get started.</p>

                <div class="space-y-3">
                    {{-- Claude Code --}}
                    <label class="flex items-start gap-3 p-4 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors"
                           :class="{ 'ring-2 ring-blue-500': provider === 'claude_code' }">
                        <input type="radio" name="provider" value="claude_code" x-model="provider" class="mt-1">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">Claude Code (CLI)</span>
                                <span class="text-xs bg-blue-600 px-2 py-0.5 rounded">Recommended</span>
                            </div>
                            <p class="text-sm text-gray-400 mt-1">Uses your Claude Pro/Team subscription. No API key needed.</p>
                            @if($hasClaudeCode)
                                <p class="text-sm text-green-400 mt-1">Already authenticated</p>
                            @endif
                        </div>
                    </label>

                    {{-- Codex CLI --}}
                    <label class="flex items-start gap-3 p-4 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors"
                           :class="{ 'ring-2 ring-blue-500': provider === 'codex' }">
                        <input type="radio" name="provider" value="codex" x-model="provider" class="mt-1">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">Codex (CLI)</span>
                            </div>
                            <p class="text-sm text-gray-400 mt-1">Uses your ChatGPT Plus/Pro subscription. No API key needed.</p>
                            @if($hasCodex)
                                <p class="text-sm text-green-400 mt-1">Already authenticated</p>
                            @endif
                        </div>
                    </label>

                    {{-- Anthropic API --}}
                    <label class="flex items-start gap-3 p-4 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors"
                           :class="{ 'ring-2 ring-blue-500': provider === 'anthropic' }">
                        <input type="radio" name="provider" value="anthropic" x-model="provider" class="mt-1">
                        <div class="flex-1">
                            <span class="font-medium">Anthropic API</span>
                            <p class="text-sm text-gray-400 mt-1">Direct API access. Pay-per-use pricing.</p>
                            @if($hasAnthropicKey)
                                <p class="text-sm text-green-400 mt-1">Already configured</p>
                            @endif
                        </div>
                    </label>

                    {{-- OpenAI API --}}
                    <label class="flex items-start gap-3 p-4 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors"
                           :class="{ 'ring-2 ring-blue-500': provider === 'openai' }">
                        <input type="radio" name="provider" value="openai" x-model="provider" class="mt-1">
                        <div class="flex-1">
                            <span class="font-medium">OpenAI API</span>
                            <p class="text-sm text-gray-400 mt-1">GPT models. Pay-per-use pricing.</p>
                            @if($hasOpenAiKey)
                                <p class="text-sm text-green-400 mt-1">Already configured</p>
                            @endif
                        </div>
                    </label>

                    {{-- OpenAI Compatible (Local LLM) --}}
                    <label class="flex items-start gap-3 p-4 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors"
                           :class="{ 'ring-2 ring-blue-500': provider === 'openai_compatible' }">
                        <input type="radio" name="provider" value="openai_compatible" x-model="provider" class="mt-1">
                        <div class="flex-1">
                            <span class="font-medium">Local LLM (OpenAI Compatible)</span>
                            <p class="text-sm text-gray-400 mt-1">KoboldCpp, Ollama, LM Studio, or any OpenAI-compatible server.</p>
                            @if($hasOpenAiCompatible)
                                <p class="text-sm text-green-400 mt-1">Already configured</p>
                            @endif
                        </div>
                    </label>
                </div>
            </div>

            {{-- Claude Code Setup (conditional) --}}
            @if(!$hasClaudeCode)
            <div x-show="provider === 'claude_code'" x-cloak class="bg-gray-800 rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-3">Claude Code Setup</h3>
                <p class="text-sm text-gray-400 mb-4">
                    Run this command in your terminal to authenticate with your Claude Pro/Team subscription:
                </p>
                @if(config('backup.user_id') !== null)
                    @php($queueContainerName = config('pocketdev.project_name', 'pocket-dev') . '-queue')
                    <div class="relative">
                        <div
                            @click="copyCommand('docker exec -it -u {{ config('backup.user_id') }} {{ $queueContainerName }} claude', 'claude')"
                            class="bg-gray-900 rounded p-3 font-mono text-sm text-green-400 cursor-pointer hover:bg-gray-800 transition-colors mb-3"
                            title="Click to copy"
                        >
                            docker exec -it -u {{ config('backup.user_id') }} {{ $queueContainerName }} claude
                        </div>
                        <div
                            x-show="copiedCommand === 'claude'"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute -top-8 left-1/2 -translate-x-1/2 px-2 py-1 bg-green-600 text-white text-xs rounded shadow-lg"
                        >
                            Copied!
                        </div>
                    </div>
                    <p class="text-sm text-gray-400 mb-2">
                        This opens an interactive login. Complete the OAuth flow in your browser, then return here and click <strong class="text-white">Verify & Continue</strong>.
                    </p>
                @else
                    <div class="bg-red-900/50 border border-red-500/50 rounded p-3 text-red-300 text-sm mb-3">
                        <strong>Configuration required:</strong> Set <code class="bg-red-900 px-1 rounded">PD_USER_ID</code> and <code class="bg-red-900 px-1 rounded">PD_GROUP_ID</code> in your .env file.
                        <span class="text-red-400 text-xs block mt-1">Run <code>id -u</code> and <code>id -g</code> on your host to get the values.</span>
                    </div>
                @endif
                <p class="text-xs text-gray-500">
                    If you prefer to use an API key instead, select "Anthropic API" above.
                </p>
            </div>
            @endif

            {{-- Codex Setup (conditional) --}}
            @if(!$hasCodex)
            <div x-show="provider === 'codex'" x-cloak class="bg-gray-800 rounded-lg p-6" x-data="wizardCodexDeviceAuth()">
                <h3 class="text-lg font-semibold mb-3">Codex Setup</h3>

                <!-- Idle: show start button -->
                <div x-show="state === 'idle'">
                    <p class="text-sm text-gray-400 mb-4">
                        Sign in with your ChatGPT subscription (Plus, Pro, Team, Edu, or Enterprise).
                        No terminal or Docker commands needed.
                    </p>
                    <button
                        type="button"
                        @click="startAuth()"
                        class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm font-semibold transition-colors"
                    >
                        Login with ChatGPT
                    </button>
                    <p class="text-xs text-gray-500 mt-3">
                        If you prefer to use an API key instead, select "OpenAI API" above.
                        You can also skip this and authenticate later via <strong class="text-gray-400">Settings → Codex Auth</strong>.
                    </p>
                </div>

                <!-- Starting: spinner -->
                <div x-show="state === 'starting'" class="text-center py-4">
                    <svg class="animate-spin w-6 h-6 mx-auto text-blue-400 mb-2" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <p class="text-sm text-gray-400">Connecting to OpenAI...</p>
                </div>

                <!-- Ready: show URL and code -->
                <div x-show="state === 'ready'" style="display:none">
                    <p class="text-sm text-gray-400 mb-4">
                        Open the link on your phone or another browser, then enter the code when prompted.
                    </p>
                    <!-- URL -->
                    <div class="mb-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Step 1 — Open this link</p>
                        <div class="flex items-center gap-2 bg-gray-900 rounded p-2 border border-gray-700">
                            <span class="text-blue-400 text-xs font-mono flex-1 truncate" x-text="verificationUrl"></span>
                            <a
                                :href="verificationUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex-shrink-0 px-2 py-1 rounded text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 transition-all"
                                title="Open in new tab"
                            >↗</a>
                            <button
                                type="button"
                                @click="copyUrl()"
                                class="flex-shrink-0 px-2 py-1 rounded text-xs transition-all"
                                :class="urlCopied ? 'bg-green-700 text-green-200' : 'bg-gray-700 hover:bg-gray-600 text-gray-300'"
                                x-text="urlCopied ? '✓' : '📋'"
                            ></button>
                        </div>
                    </div>
                    <!-- Code -->
                    <div class="mb-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Step 2 — Enter this code</p>
                        <div class="flex items-center gap-3">
                            <div class="bg-gray-900 border border-gray-600 rounded px-4 py-2">
                                <span class="text-xl font-mono font-bold tracking-widest" x-text="userCode"></span>
                            </div>
                            <button
                                type="button"
                                @click="copyCode()"
                                class="px-3 py-1.5 rounded text-xs font-medium transition-all"
                                :class="codeCopied ? 'bg-green-700 text-green-200' : 'bg-gray-700 hover:bg-gray-600 text-gray-300'"
                                x-text="codeCopied ? '✓ Copied' : '📋 Copy'"
                            ></button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">
                        Waiting for login… expires in <span class="font-mono" x-text="countdown"></span>
                    </p>
                </div>

                <!-- Authenticated -->
                <div x-show="state === 'authenticated'" style="display:none" class="text-center py-4">
                    <div class="text-3xl mb-2">✅</div>
                    <p class="text-green-400 font-semibold mb-1">Authenticated!</p>
                    <p class="text-sm text-gray-400">Click <strong class="text-white">Verify &amp; Continue</strong> below to proceed.</p>
                </div>

                <!-- Failed / expired -->
                <div x-show="state === 'expired' || state === 'failed'" style="display:none">
                    <div class="bg-red-900/30 border border-red-700 rounded p-3 mb-3">
                        <p class="text-red-400 text-sm font-semibold">
                            <span x-show="state === 'expired'">Code expired</span>
                            <span x-show="state === 'failed'">Failed</span>
                        </p>
                        <p class="text-red-300 text-xs mt-1" x-text="error || 'Please try again.'"></p>
                    </div>
                    <div class="flex gap-2 mb-4">
                        <button
                            type="button"
                            @click="reset(); startAuth()"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm font-medium"
                        >
                            Try again
                        </button>
                        <button
                            type="button"
                            @click="reset()"
                            class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-sm text-gray-300"
                        >
                            Skip — auth later
                        </button>
                    </div>
                    <!-- Org restriction hint -->
                    <p class="text-xs text-yellow-400/80">
                        💡 If your organisation blocks device auth, run <code class="bg-black/20 px-1 rounded">codex login</code> on another machine,
                        then upload <code class="bg-black/20 px-1 rounded">~/.codex/auth.json</code> via
                        <a href="{{ route('codex.auth') }}" target="_blank" class="underline">Settings → Codex Auth</a>.
                    </p>
                </div>
            </div>
            @endif

            {{-- API Key Input (conditional) --}}
            <div x-show="provider === 'anthropic'" x-cloak class="bg-gray-800 rounded-lg p-6">
                <label class="block text-sm font-medium mb-2">Anthropic API Key</label>
                <input
                    type="password"
                    name="anthropic_api_key"
                    placeholder="sk-ant-..."
                    class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                    :required="provider === 'anthropic'"
                >
                <p class="text-xs text-gray-500 mt-2">
                    Get your key from <a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-blue-400 hover:underline">console.anthropic.com</a>
                </p>
            </div>

            <div x-show="provider === 'openai'" x-cloak class="bg-gray-800 rounded-lg p-6">
                <label class="block text-sm font-medium mb-2">OpenAI API Key</label>
                <input
                    type="password"
                    name="openai_api_key"
                    placeholder="sk-..."
                    class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                    :required="provider === 'openai'"
                >
                <p class="text-xs text-gray-500 mt-2">
                    Get your key from <a href="https://platform.openai.com/api-keys" target="_blank" class="text-blue-400 hover:underline">platform.openai.com</a>
                </p>
            </div>

            {{-- OpenAI Compatible Setup --}}
            <div x-show="provider === 'openai_compatible'" x-cloak class="bg-gray-800 rounded-lg p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Server URL <span class="text-red-400">*</span></label>
                    <input
                        type="url"
                        name="openai_compatible_base_url"
                        placeholder="http://localhost:5001"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                        :required="provider === 'openai_compatible'"
                    >
                    <p class="text-xs text-gray-500 mt-2">
                        The base URL of your local LLM server (e.g., http://localhost:5001 for KoboldCpp)
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">API Key <span class="text-gray-500">(optional)</span></label>
                    <input
                        type="password"
                        name="openai_compatible_api_key"
                        placeholder="Leave empty if not required"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                    >
                    <p class="text-xs text-gray-500 mt-2">
                        Only needed if your server requires authentication
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Model Name <span class="text-gray-500">(optional)</span></label>
                    <input
                        type="text"
                        name="openai_compatible_model"
                        placeholder="Leave empty for server default"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                    >
                    <p class="text-xs text-gray-500 mt-2">
                        Most local LLM servers use whatever model is loaded. Only specify if your server supports multiple models.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Context Window <span class="text-gray-500">(optional)</span></label>
                    <input
                        type="number"
                        name="openai_compatible_context_window"
                        placeholder="32768"
                        min="1024"
                        max="2000000"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                    >
                    <p class="text-xs text-gray-500 mt-2">
                        Max tokens the model can process. Default: 32768
                    </p>
                </div>

                <div class="text-xs text-gray-500 pt-2 border-t border-gray-700">
                    <p class="font-medium text-gray-400 mb-1">Supported servers:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li>KoboldCpp - http://localhost:5001</li>
                        <li>Ollama - http://localhost:11434</li>
                        <li>LM Studio - http://localhost:1234</li>
                        <li>LocalAI - http://localhost:8080</li>
                    </ul>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex flex-col gap-3">
                <button
                    type="submit"
                    class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                    :disabled="!provider"
                    :class="{ 'opacity-50 cursor-not-allowed': !provider }"
                    x-text="(provider === 'claude_code' && !{{ $hasClaudeCode ? 'true' : 'false' }}) || (provider === 'codex' && !{{ $hasCodex ? 'true' : 'false' }}) ? 'Verify & Continue' : 'Continue'"
                >
                    Continue
                </button>
            </div>
        </form>

    </div>

    <script>
        function setupWizard() {
            // Pre-select based on what's already configured
            // Priority: Claude Code CLI > Codex CLI > Anthropic API > OpenAI API > Local LLM
            let initialProvider = 'claude_code';
            @if($hasClaudeCode)
                initialProvider = 'claude_code';
            @elseif($hasCodex)
                initialProvider = 'codex';
            @elseif($hasAnthropicKey)
                initialProvider = 'anthropic';
            @elseif($hasOpenAiKey)
                initialProvider = 'openai';
            @elseif($hasOpenAiCompatible)
                initialProvider = 'openai_compatible';
            @endif

            return {
                provider: initialProvider,
                copiedCommand: null,
                copyCommand(text, id) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copiedCommand = id;
                        setTimeout(() => {
                            this.copiedCommand = null;
                        }, 1500);
                    });
                }
            }
        }

        // Inline device auth component reused in the wizard's Codex step
        function wizardCodexDeviceAuth() {
            return {
                state: 'idle',
                verificationUrl: '',
                userCode: '',
                expiresIn: 900,
                urlCopied: false,
                codeCopied: false,
                error: '',
                _pollTimer: null,
                _countdownTimer: null,

                get countdown() {
                    const m = Math.floor(this.expiresIn / 60);
                    const s = this.expiresIn % 60;
                    return `${m}:${String(s).padStart(2, '0')}`;
                },

                async init() {
                    await this.checkStatus();
                },

                async startAuth() {
                    this.state = 'starting';
                    this.error = '';
                    try {
                        const res = await fetch('{{ route('codex.auth.deviceStart') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        });
                        const data = await res.json();
                        if (!data.success) {
                            this.state = 'failed';
                            this.error = data.error || 'Unknown error';
                            return;
                        }
                        this._applyStatus(data);
                        this._startPolling();
                    } catch (e) {
                        this.state = 'failed';
                        this.error = e.message;
                    }
                },

                _startPolling() {
                    if (this._pollTimer) clearInterval(this._pollTimer);
                    this._pollTimer = setInterval(() => this.checkStatus(), 2500);
                },

                async checkStatus() {
                    try {
                        const res = await fetch('{{ route('codex.auth.deviceStatus') }}');
                        const data = await res.json();
                        this._applyStatus(data);
                    } catch (e) {}
                },

                _applyStatus(data) {
                    const status = data.status;
                    if (status === 'ready' && this.state !== 'ready') {
                        this.state = 'ready';
                        this.verificationUrl = data.verification_url || '';
                        this.userCode = data.user_code || '';
                        this.expiresIn = data.expires_in ?? 900;
                        this._startCountdown();
                        if (!this._pollTimer) this._startPolling();
                    } else if (status === 'ready') {
                        if (data.expires_in !== undefined) this.expiresIn = data.expires_in;
                    } else if (status === 'authenticated') {
                        this.state = 'authenticated';
                        this._stopTimers();
                    } else if (status === 'failed') {
                        this.state = 'failed';
                        this.error = data.error || 'Authentication failed';
                        this._stopTimers();
                    } else if (status === 'expired') {
                        this.state = 'expired';
                        this._stopTimers();
                    } else if (status === 'starting' && this.state === 'idle') {
                        this.state = 'starting';
                        this._startPolling();
                    }
                },

                _startCountdown() {
                    if (this._countdownTimer) clearInterval(this._countdownTimer);
                    this._countdownTimer = setInterval(() => {
                        if (this.expiresIn > 0) {
                            this.expiresIn--;
                        } else {
                            this.state = 'expired';
                            this._stopTimers();
                        }
                    }, 1000);
                },

                _stopTimers() {
                    if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
                    if (this._countdownTimer) { clearInterval(this._countdownTimer); this._countdownTimer = null; }
                },

                reset() {
                    this._stopTimers();
                    this.state = 'idle';
                    this.verificationUrl = '';
                    this.userCode = '';
                    this.expiresIn = 900;
                    this.error = '';
                    this.urlCopied = false;
                    this.codeCopied = false;
                },

                async copyUrl() {
                    try {
                        await navigator.clipboard.writeText(this.verificationUrl);
                        this.urlCopied = true;
                        setTimeout(() => { this.urlCopied = false; }, 2000);
                    } catch (e) {}
                },

                async copyCode() {
                    try {
                        await navigator.clipboard.writeText(this.userCode);
                        this.codeCopied = true;
                        setTimeout(() => { this.codeCopied = false; }, 2000);
                    } catch (e) {}
                },
            };
        }
    </script>

    <style>
        [x-cloak] { display: none !important; }
    </style>
</body>
</html>
