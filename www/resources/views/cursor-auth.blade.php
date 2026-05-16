<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cursor Agent Authentication</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.8/dist/cdn.min.js"></script>
</head>
<body class="antialiased bg-gray-900 text-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-3xl w-full space-y-6">
            <!-- Header -->
            <div class="text-center">
                <h1 class="text-3xl font-bold mb-2">Cursor Agent Authentication</h1>
                <p class="text-gray-400">Manage your Cursor Pro/Business subscription</p>
                <span class="inline-block mt-2 px-2 py-0.5 bg-yellow-700/40 text-yellow-300 text-xs rounded-full">Experimental</span>
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
                                    <p><strong>Method:</strong> Cursor Subscription</p>
                                    @if(!empty($status["email"]))
                                        <p><strong>Account:</strong> {{ $status["email"] }}</p>
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
                    <button onclick="switchTab('browser')" id="tab-browser" class="tab-btn px-4 py-2 -mb-px border-b-2 border-blue-500 text-blue-400">
                        Via PocketDev UI
                    </button>
                    <button onclick="switchTab('laptop')" id="tab-laptop" class="tab-btn px-4 py-2 -mb-px border-b-2 border-transparent text-gray-400 hover:text-gray-300">
                        Via laptop / SSH
                    </button>
                    <button onclick="switchTab('apikey')" id="tab-apikey" class="tab-btn px-4 py-2 -mb-px border-b-2 border-transparent text-gray-400 hover:text-gray-300">
                        API Key
                    </button>
                </div>

                <!-- Browser Auth Tab (Subscription) -->
                <div id="content-browser" class="tab-content" x-data="cursorBrowserAuth()">

                    <!-- Idle state: show start button -->
                    <div x-show="state === 'idle'">
                        <p class="text-gray-300 mb-2">
                            Sign in with your Cursor Pro or Business subscription.
                        </p>
                        <p class="text-sm text-gray-400 mb-6">
                            Opens a Cursor login page. No terminal needed.
                        </p>
                        <button
                            @click="startAuth()"
                            class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-colors"
                        >
                            Login with Cursor
                        </button>
                    </div>

                    <!-- Starting state: spinner -->
                    <div x-show="state === 'starting'" class="text-center py-8">
                        <svg class="animate-spin w-8 h-8 mx-auto text-blue-400 mb-3" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        <p class="text-gray-400">Connecting to Cursor...</p>
                    </div>

                    <!-- Ready state: show auth URL -->
                    <div x-show="state === 'ready'" style="display:none">
                        <p class="text-gray-300 mb-6 text-sm">
                            Open the link below to sign in with your Cursor account.
                        </p>

                        <!-- Auth URL -->
                        <div class="mb-6">
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Open this link to authenticate</p>
                            <div class="flex items-center gap-3 bg-gray-900 rounded-lg p-3 border border-gray-700">
                                <span class="text-blue-400 text-sm font-mono flex-1 truncate" x-text="verificationUrl"></span>
                                <a
                                    :href="verificationUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex-shrink-0 px-3 py-1.5 rounded text-sm bg-gray-700 hover:bg-gray-600 text-gray-300 transition-all"
                                    title="Open in new tab"
                                >Open</a>
                                <button
                                    @click="copyUrl()"
                                    class="flex-shrink-0 px-3 py-1.5 rounded text-sm transition-all"
                                    :class="urlCopied ? 'bg-green-700 text-green-200' : 'bg-gray-700 hover:bg-gray-600 text-gray-300'"
                                >
                                    <span x-show="!urlCopied">Copy</span>
                                    <span x-show="urlCopied">Copied!</span>
                                </button>
                            </div>
                        </div>

                        <!-- Waiting indicator + countdown -->
                        <div class="flex items-center gap-3 text-sm text-gray-400">
                            <svg class="animate-spin w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            <span>Waiting for authentication...</span>
                            <span class="text-gray-500">(expires in <span class="font-mono" x-text="countdown"></span>)</span>
                        </div>
                    </div>

                    <!-- Authenticated! -->
                    <div x-show="state === 'authenticated'" style="display:none" class="text-center py-8">
                        <div class="text-5xl mb-4">&#x2705;</div>
                        <p class="text-green-400 font-semibold text-lg mb-2">Successfully authenticated!</p>
                        <p class="text-gray-400 text-sm mb-6">Your Cursor subscription is now connected to PocketDev.</p>
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
                                <span x-show="state === 'expired'">Session expired</span>
                                <span x-show="state === 'failed'">Authentication failed</span>
                            </p>
                            <p class="text-red-300 text-sm" x-text="error || (state === 'expired' ? 'The session expired before authentication completed.' : 'Please try again.')"></p>
                        </div>
                        <button
                            @click="reset(); startAuth()"
                            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-colors text-sm"
                        >
                            Try again
                        </button>
                    </div>
                </div>

                <!-- Via laptop / SSH tab -->
                @php
                    $pdUrl = url('/');
                    $apiUploadUrl = "{$pdUrl}/api/cursor/auth/upload";
                    $linux1 = 'curl -fsSL https://cursor.com/install | bash && agent login';
                    $linux2 = "python3 -c \"import json,os; print(json.dumps({'json': open(os.path.expanduser('~/.config/cursor/auth.json')).read()}))\" | curl -s -X POST '{$apiUploadUrl}' -H 'Content-Type: application/json' -d @- && echo 'Done!'";
                    $linuxAll = "curl -fsSL https://cursor.com/install | bash && agent login && python3 -c \"import json,os; print(json.dumps({'json': open(os.path.expanduser('~/.config/cursor/auth.json')).read()}))\" | curl -s -X POST '{$apiUploadUrl}' -H 'Content-Type: application/json' -d @- && echo 'Done!'";
                    $win1 = 'irm https://cursor.com/install | iex; agent login';
                    $win2 = "\$auth = (Get-Content \"\$env:APPDATA\\Cursor\\auth.json\" -Raw -Encoding UTF8).Trim(); \$body = [System.Text.Encoding]::UTF8.GetBytes('{\"json\":' + (\$auth | ConvertTo-Json -Compress) + '}'); Invoke-RestMethod -Uri '{$apiUploadUrl}' -Method Post -ContentType 'application/json' -Body \$body; Write-Host 'Done!'";
                    $winAll = "irm https://cursor.com/install | iex; agent login; \$auth = (Get-Content \"\$env:APPDATA\\Cursor\\auth.json\" -Raw -Encoding UTF8).Trim(); \$body = [System.Text.Encoding]::UTF8.GetBytes('{\"json\":' + (\$auth | ConvertTo-Json -Compress) + '}'); Invoke-RestMethod -Uri '{$apiUploadUrl}' -Method Post -ContentType 'application/json' -Body \$body; Write-Host 'Done!'";
                @endphp
                <div id="content-laptop" class="tab-content hidden" x-data="{ os: 'linux' }">
                    <p class="text-gray-300 mb-1">Run these commands on your <strong class="text-white">laptop, desktop, or via SSH</strong>.</p>
                    <p class="text-sm text-gray-400 mb-4">Install the Cursor Agent CLI, log in, then upload credentials to PocketDev.</p>

                    <!-- OS toggle -->
                    <div class="flex gap-2 mb-6">
                        <button
                            @click="os = 'linux'"
                            :class="os === 'linux' ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-400 hover:bg-gray-600'"
                            class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors"
                        >Linux / macOS</button>
                        <button
                            @click="os = 'windows'"
                            :class="os === 'windows' ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-400 hover:bg-gray-600'"
                            class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors"
                        >Windows (PowerShell)</button>
                    </div>

                    <!-- Step 1 -->
                    <div class="mb-5">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Step 1 - Install Cursor Agent and log in</p>
                        <div class="flex items-center gap-2 bg-gray-900 rounded-lg border border-gray-700 p-3">
                            <code class="text-green-400 text-sm flex-1 select-all" x-text="os === 'linux' ? {{ json_encode($linux1) }} : {{ json_encode($win1) }}"></code>
                            <button
                                @click="copyCmd($el, os === 'linux' ? {{ json_encode($linux1) }} : {{ json_encode($win1) }})"
                                class="flex-shrink-0 px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded text-sm transition-all"
                            >Copy</button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1.5">This opens a browser to sign in with your Cursor account.</p>
                    </div>

                    <!-- Step 2 -->
                    <div class="mb-5">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Step 2 - Upload auth.json to PocketDev</p>
                        <div class="flex items-start gap-2 bg-gray-900 rounded-lg border border-gray-700 p-3">
                            <code class="text-green-400 text-sm flex-1 break-all select-all" x-text="os === 'linux' ? {{ json_encode($linux2) }} : {{ json_encode($win2) }}"></code>
                            <button
                                @click="copyCmd($el, os === 'linux' ? {{ json_encode($linux2) }} : {{ json_encode($win2) }})"
                                class="flex-shrink-0 px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded text-sm transition-all mt-0.5"
                            >Copy</button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1.5">Run step 2 only after you complete sign-in in step 1.</p>
                    </div>

                    <!-- All in one -->
                    <div class="border-t border-gray-700 pt-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Or run everything in one command</p>
                        <div class="flex items-start gap-2 bg-gray-900 rounded-lg border border-gray-700 p-3">
                            <code class="text-green-400 text-sm flex-1 break-all select-all" x-text="os === 'linux' ? {{ json_encode($linuxAll) }} : {{ json_encode($winAll) }}"></code>
                            <button
                                @click="copyCmd($el, os === 'linux' ? {{ json_encode($linuxAll) }} : {{ json_encode($winAll) }})"
                                class="flex-shrink-0 px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded text-sm transition-all mt-0.5"
                            >Copy</button>
                        </div>
                    </div>
                </div>

                <!-- API Key tab -->
                <div id="content-apikey" class="tab-content hidden">
                    <div class="mb-4">
                        <p class="text-gray-300 font-medium mb-1">Cursor API Key</p>
                        <p class="text-sm text-gray-400 mb-3">
                            Use a Cursor API key for pay-per-use access. This uses API billing, not your subscription credits.
                        </p>
                    </div>

                    <!-- File upload / paste -->
                    <div class="mb-6">
                        <p class="text-gray-300 font-medium mb-1">Upload auth.json</p>
                        <p class="text-sm text-gray-400 mb-3">
                            Or paste the contents of your <code class="text-green-400">auth.json</code> file from your local machine.
                        </p>
                        <form id="json-form" class="space-y-3">
                            <!-- File picker -->
                            <div class="flex items-center gap-3">
                                <label class="flex items-center gap-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded cursor-pointer text-sm text-gray-200 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                    Select auth.json
                                    <input type="file" id="json-file" accept=".json,application/json" class="hidden">
                                </label>
                                <span id="file-name" class="text-xs text-gray-500">No file selected</span>
                            </div>
                            <!-- Path hints -->
                            <div class="bg-gray-900/60 rounded-lg border border-gray-700/50 p-3 space-y-2">
                                <p class="text-xs text-gray-500 font-medium uppercase tracking-wide mb-1">File location</p>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-500 w-24 shrink-0">Windows</span>
                                    <code class="text-xs text-gray-300 flex-1">%APPDATA%\Cursor\auth.json</code>
                                    <button type="button" onclick="copyCmd(this, '%APPDATA%\\Cursor\\auth.json')" class="text-xs px-2 py-0.5 bg-gray-700 hover:bg-gray-600 text-gray-400 rounded transition-all">Copy</button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-500 w-24 shrink-0">macOS</span>
                                    <code class="text-xs text-gray-300 flex-1">~/.cursor/auth.json</code>
                                    <button type="button" onclick="copyCmd(this, '~/.cursor/auth.json')" class="text-xs px-2 py-0.5 bg-gray-700 hover:bg-gray-600 text-gray-400 rounded transition-all">Copy</button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-500 w-24 shrink-0">Linux</span>
                                    <code class="text-xs text-gray-300 flex-1">~/.config/cursor/auth.json</code>
                                    <button type="button" onclick="copyCmd(this, '~/.config/cursor/auth.json')" class="text-xs px-2 py-0.5 bg-gray-700 hover:bg-gray-600 text-gray-400 rounded transition-all">Copy</button>
                                </div>
                            </div>
                            <!-- Textarea -->
                            <textarea id="json-input" rows="4" placeholder='{"accessToken": "...", "refreshToken": "..."}'
                                class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-sm font-mono focus:outline-none focus:border-blue-500"></textarea>
                            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded">
                                Save
                            </button>
                        </form>
                        <div id="json-result" class="mt-4"></div>
                    </div>
                </div>
            </div>

            <!-- Info block -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 text-sm">
                <h3 class="font-semibold mb-2">About Cursor Agent Authentication:</h3>
                <ul class="list-disc list-inside space-y-1 text-gray-300">
                    <li><strong>Subscription:</strong> Uses your Cursor Pro/Business subscription (recommended)</li>
                    <li><strong>API Key:</strong> Pay-per-use via Cursor API</li>
                    <li>Access 100+ models including Claude Opus 4.7, GPT-5.5, Grok 4.3, and Gemini 3.1 Pro</li>
                    <li>Credentials are stored at: <code class="text-blue-400">~/.config/cursor/auth.json</code> (Linux)</li>
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
        // ── Copy helper ───────────────────────────────────────────────────────────
        function copyCmd(btn, text) {
            navigator.clipboard.writeText(text).then(() => {
                const orig = btn.textContent;
                btn.textContent = 'Copied!';
                btn.classList.add('bg-green-700', 'text-green-200');
                btn.classList.remove('bg-gray-700', 'text-gray-300');
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.classList.remove('bg-green-700', 'text-green-200');
                    btn.classList.add('bg-gray-700', 'text-gray-300');
                }, 2000);
            });
        }

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

        // ── File picker -> textarea ────────────────────────────────────────────────
        document.getElementById('json-file')?.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;
            document.getElementById('file-name').textContent = file.name;
            const reader = new FileReader();
            reader.onload = (ev) => {
                document.getElementById('json-input').value = ev.target.result;
            };
            reader.readAsText(file, 'UTF-8');
        });

        // ── Browser auth Alpine component ──────────────────────────────────────────
        function cursorBrowserAuth() {
            return {
                state: 'idle',   // idle | starting | ready | authenticated | expired | failed
                verificationUrl: '',
                expiresIn: 900,
                urlCopied: false,
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
                        const res = await fetch('{{ route("cursor.auth.browserStart") }}', {
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
                        const res = await fetch('{{ route("cursor.auth.browserStatus") }}');
                        const data = await res.json();
                        this._applyStatus(data);
                    } catch (e) {
                        // Network error - keep polling silently
                    }
                },

                _applyStatus(data) {
                    const status = data.status;

                    if (status === 'ready' && this.state !== 'ready') {
                        this.state = 'ready';
                        this.verificationUrl = data.verification_url || '';
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
                    this.expiresIn = 900;
                    this.error = '';
                    this.urlCopied = false;
                },

                async copyUrl() {
                    try {
                        await navigator.clipboard.writeText(this.verificationUrl);
                        this.urlCopied = true;
                        setTimeout(() => { this.urlCopied = false; }, 2000);
                    } catch (e) {}
                },
            };
        }

        // ── JSON (upload/paste) form ──────────────────────────────────────────────
        document.getElementById('json-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const jsonInput = document.getElementById('json-input').value;

            if (!jsonInput.trim()) {
                showResult('json-result', 'error', 'Please paste JSON content');
                return;
            }

            try {
                const response = await fetch('{{ url("/cursor/auth/upload-json") }}', {
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
                const response = await fetch('{{ url("/cursor/auth/logout") }}', {
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
