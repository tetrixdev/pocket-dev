<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Codex Authentication</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.8/dist/cdn.min.js"></script>
</head>
<body class="antialiased bg-gray-900 text-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-3xl w-full space-y-6">
            <!-- Header -->
            <div class="text-center">
                <h1 class="text-3xl font-bold mb-2">OpenAI Codex Authentication</h1>
                <p class="text-gray-400">Manage your ChatGPT subscription or API key</p>
            </div>

            <!-- Status Card -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">Authentication Status</h2>
                <div id="status-display">
                    @if($status["authenticated"])
                        <div class="bg-green-900/30 border border-green-700 rounded p-4 mb-4">
                            <div class="flex items-center mb-2">
                                <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-green-400 font-semibold">Authenticated</span>
                            </div>
                            <div class="text-sm space-y-1">
                                @if($status["auth_type"] === "api_key")
                                    <p><strong>Method:</strong> API Key</p>
                                    <p><strong>Key:</strong> {{ $status["key_preview"] }}</p>
                                @elseif($status["auth_type"] === "subscription")
                                    <p><strong>Method:</strong> ChatGPT Subscription</p>
                                    @if($status["expires_at"])
                                        <p><strong>Expires:</strong> {{ $status["expires_at"] }} ({{ $status["days_until_expiry"] }} days)</p>
                                    @endif
                                @endif
                            </div>
                        </div>
                        <button onclick="logout()" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded text-sm">
                            Logout (Clear Credentials)
                        </button>
                    @else
                        <div class="bg-yellow-900/30 border border-yellow-700 rounded p-4">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-yellow-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-yellow-400 font-semibold">Not Authenticated</span>
                            </div>
                            <p class="text-sm mt-2 text-gray-300">{{ $status["message"] }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Authentication Methods -->
            @if(!$status["authenticated"])
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">Authentication Methods</h2>

                <!-- Tabs -->
                <div class="flex space-x-2 mb-6 border-b border-gray-700">
                    <button onclick="switchTab('device')" id="tab-device" class="tab-btn px-4 py-2 -mb-px border-b-2 border-blue-500 text-blue-400">
                        Subscription (Recommended)
                    </button>
                    <button onclick="switchTab('json')" id="tab-json" class="tab-btn px-4 py-2 -mb-px border-b-2 border-transparent text-gray-400 hover:text-gray-300">
                        API Key
                    </button>
                </div>

                <!-- Inline Device Auth Tab (Subscription) -->
                <div id="content-device" class="tab-content" x-data="codexDeviceAuth()">

                    <!-- Idle state: show start button -->
                    <div x-show="state === 'idle'">
                        <p class="text-gray-300 mb-2">
                            Sign in with your ChatGPT subscription (Plus, Pro, Team, Edu, or Enterprise).
                        </p>
                        <p class="text-sm text-gray-400 mb-6">
                            Works on any device — no terminal, no Docker commands needed.
                        </p>
                        <button
                            @click="startAuth()"
                            class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-colors"
                        >
                            Login met ChatGPT
                        </button>
                    </div>

                    <!-- Starting state: spinner -->
                    <div x-show="state === 'starting'" class="text-center py-8">
                        <svg class="animate-spin w-8 h-8 mx-auto text-blue-400 mb-3" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        <p class="text-gray-400">Connecting to OpenAI...</p>
                    </div>

                    <!-- Ready state: show URL and code -->
                    <div x-show="state === 'ready'" style="display:none">
                        <p class="text-gray-300 mb-6 text-sm">
                            Open the link below on your phone or another browser window, then enter the code when prompted.
                        </p>

                        <!-- Step 1: URL -->
                        <div class="mb-6">
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Step 1 — Open this link</p>
                            <div class="flex items-center gap-3 bg-gray-900 rounded-lg p-3 border border-gray-700">
                                <span class="text-blue-400 text-sm font-mono flex-1 truncate" x-text="verificationUrl"></span>
                                <a
                                    :href="verificationUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex-shrink-0 px-3 py-1.5 rounded text-sm bg-gray-700 hover:bg-gray-600 text-gray-300 transition-all"
                                    title="Open in new tab"
                                >↗ Open</a>
                                <button
                                    @click="copyUrl()"
                                    class="flex-shrink-0 px-3 py-1.5 rounded text-sm transition-all"
                                    :class="urlCopied ? 'bg-green-700 text-green-200' : 'bg-gray-700 hover:bg-gray-600 text-gray-300'"
                                >
                                    <span x-show="!urlCopied">📋 Copy</span>
                                    <span x-show="urlCopied">✓ Copied!</span>
                                </button>
                            </div>
                        </div>

                        <!-- Step 2: Code -->
                        <div class="mb-6">
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Step 2 — Enter this code</p>
                            <div class="flex items-center gap-4">
                                <div class="bg-gray-900 border border-gray-600 rounded-lg px-6 py-4">
                                    <span class="text-3xl font-mono font-bold tracking-widest text-white" x-text="userCode"></span>
                                </div>
                                <button
                                    @click="copyCode()"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all"
                                    :class="codeCopied ? 'bg-green-700 text-green-200' : 'bg-gray-700 hover:bg-gray-600 text-gray-300'"
                                >
                                    <span x-show="!codeCopied">📋 Copy code</span>
                                    <span x-show="codeCopied">✓ Copied!</span>
                                </button>
                            </div>
                        </div>

                        <!-- Waiting indicator + countdown -->
                        <div class="flex items-center gap-3 text-sm text-gray-400">
                            <svg class="animate-spin w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            <span>Waiting for authentication…</span>
                            <span class="text-gray-500">(expires in <span class="font-mono" x-text="countdown"></span>)</span>
                        </div>
                    </div>

                    <!-- Authenticated! -->
                    <div x-show="state === 'authenticated'" style="display:none" class="text-center py-8">
                        <div class="text-5xl mb-4">✅</div>
                        <p class="text-green-400 font-semibold text-lg mb-2">Successfully authenticated!</p>
                        <p class="text-gray-400 text-sm mb-6">Your ChatGPT subscription is now connected to PocketDev.</p>
                        <button
                            onclick="window.location.reload()"
                            class="px-6 py-3 bg-green-600 hover:bg-green-700 rounded-lg font-semibold transition-colors"
                        >
                            Reload Page
                        </button>
                    </div>

                    <!-- Expired or failed -->
                    <div x-show="state === 'expired' || state === 'failed'" style="display:none">
                        <div class="bg-red-900/30 border border-red-700 rounded-lg p-4 mb-4">
                            <p class="text-red-400 font-semibold mb-1">
                                <span x-show="state === 'expired'">Code expired</span>
                                <span x-show="state === 'failed'">Authentication failed</span>
                            </p>
                            <p class="text-red-300 text-sm" x-text="error || (state === 'expired' ? 'The code expired before authentication completed.' : 'Please try again.')"></p>
                        </div>
                        <div class="flex gap-3 flex-wrap mb-6">
                            <button
                                @click="reset(); startAuth()"
                                class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-colors text-sm"
                            >
                                Try again
                            </button>
                        </div>
                        <!-- Org restriction help -->
                        <div class="bg-yellow-900/20 border border-yellow-700/50 rounded-lg p-4 text-sm">
                            <p class="text-yellow-400 font-semibold mb-1">💡 Is your organisation blocking device auth?</p>
                            <p class="text-yellow-200/70 mb-2">
                                If you see <em>"Contact your workspace administrator to enable device code authentication"</em> on the OpenAI page,
                                your organisation has disabled this flow. Use the <strong class="text-white">API Key tab</strong> instead:
                            </p>
                            <ol class="list-decimal list-inside text-yellow-200/70 space-y-1 mb-3">
                                <li>On a machine with a browser, run: <code class="bg-black/30 px-1.5 py-0.5 rounded text-xs">codex login</code></li>
                                <li>Copy the resulting <code class="bg-black/30 px-1.5 py-0.5 rounded text-xs">~/.codex/auth.json</code> file contents</li>
                                <li>Paste it in the <strong class="text-white">API Key tab</strong> below</li>
                            </ol>
                            <button onclick="switchTab('json')" class="px-4 py-1.5 bg-yellow-700/60 hover:bg-yellow-700 rounded text-xs font-medium text-yellow-100 transition-colors">
                                Switch to API Key tab →
                            </button>
                        </div>
                    </div>
                </div>

                <!-- API Key / Manual Upload Tab -->
                <div id="content-json" class="tab-content hidden">
                    <p class="text-gray-300 mb-1">Paste your <code class="text-blue-400 text-sm">~/.codex/auth.json</code> file contents below.</p>
                    <p class="text-sm text-gray-500 mb-4">
                        Works for both API keys (<code>{"OPENAI_API_KEY": "sk-..."}</code>) and
                        subscription tokens obtained via <code>codex login</code> on another machine.
                    </p>
                    <p class="text-gray-400 mb-2"><strong>Paste auth.json content below</strong></p>
                    <form id="json-form" class="space-y-4">
                        <div>
                            <textarea id="json-input" rows="4" placeholder='{"OPENAI_API_KEY": "sk-proj-..."}'
                                class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-sm font-mono focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded">
                            Save Credentials
                        </button>
                    </form>
                    <div id="json-result" class="mt-4"></div>
                </div>
            </div>

            <!-- Info block -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 text-sm">
                <h3 class="font-semibold mb-2">About Codex Authentication:</h3>
                <ul class="list-disc list-inside space-y-1 text-gray-300">
                    <li><strong>Subscription:</strong> Uses your ChatGPT Plus/Pro/Team subscription (recommended)</li>
                    <li><strong>API Key:</strong> Pay-per-use via OpenAI API</li>
                    <li>Credentials are stored at: <code class="text-blue-400">~/.codex/auth.json</code></li>
                    <li>Both methods work with Codex CLI features</li>
                </ul>
            </div>
            @endif

            <!-- Navigation -->
            <div class="text-center space-x-4">
                <a href="{{ route('config.credentials') }}" class="inline-block px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg">
                    Back to Settings
                </a>
                @if($status["authenticated"])
                <a href="{{ url('/') }}" class="inline-block px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg">
                    Go to Chat
                </a>
                @endif
            </div>
        </div>
    </div>

    <script>
        // ── Tab switching ──────────────────────────────────────────────────────────
        function switchTab(tab) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('border-blue-500', 'text-blue-400');
                el.classList.add('border-transparent', 'text-gray-400');
            });
            document.getElementById('content-' + tab).classList.remove('hidden');
            const activeTab = document.getElementById('tab-' + tab);
            activeTab.classList.remove('border-transparent', 'text-gray-400');
            activeTab.classList.add('border-blue-500', 'text-blue-400');
        }

        // ── Device auth Alpine component ───────────────────────────────────────────
        function codexDeviceAuth() {
            return {
                state: 'idle',   // idle | starting | ready | authenticated | expired | failed
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
                    // Resume any session already in progress (e.g. after page reload)
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
                    } catch (e) {
                        // Network error — keep polling silently
                    }
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
                        // Already showing — sync expiry
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
                    // 'none': nothing to do (still idle)
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

        // ── JSON (API Key) form ────────────────────────────────────────────────────
        document.getElementById('json-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const jsonInput = document.getElementById('json-input').value;

            if (!jsonInput.trim()) {
                showResult('json-result', 'error', 'Please paste JSON content');
                return;
            }

            try {
                const response = await fetch('{{ url('/codex/auth/upload-json') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ json: jsonInput })
                });

                const data = await response.json();

                if (data.success) {
                    showResult('json-result', 'success', data.message);
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showResult('json-result', 'error', data.message);
                }
            } catch (err) {
                showResult('json-result', 'error', 'Save failed: ' + err.message);
            }
        });

        // ── Logout ─────────────────────────────────────────────────────────────────
        async function logout() {
            if (!confirm('Are you sure you want to clear your credentials?')) {
                return;
            }

            try {
                const response = await fetch('{{ url('/codex/auth/logout') }}', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Logout failed: ' + data.message);
                }
            } catch (err) {
                alert('Logout failed: ' + err.message);
            }
        }

        // ── Helper ─────────────────────────────────────────────────────────────────
        function showResult(elementId, type, message) {
            const element = document.getElementById(elementId);
            const bgColor = type === 'success'
                ? 'bg-green-900/30 border-green-700 text-green-400'
                : 'bg-red-900/30 border-red-700 text-red-400';
            const div = document.createElement('div');
            div.className = `border rounded p-3 ${bgColor}`;
            div.textContent = message;
            element.replaceChildren(div);
        }
    </script>
</body>
</html>
