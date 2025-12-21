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

        <form method="POST" action="{{ route('setup.process') }}" class="space-y-6">
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
                <div class="relative">
                    <div
                        @click="copyCommand('docker exec -it pocket-dev-queue claude', 'claude')"
                        class="bg-gray-900 rounded p-3 font-mono text-sm text-green-400 cursor-pointer hover:bg-gray-800 transition-colors mb-3"
                        title="Click to copy"
                    >
                        docker exec -it pocket-dev-queue claude
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
                <p class="text-xs text-gray-500">
                    If you prefer to use an API key instead, select "Anthropic API" above.
                </p>
            </div>
            @endif

            {{-- Codex Setup (conditional) --}}
            @if(!$hasCodex)
            <div x-show="provider === 'codex'" x-cloak class="bg-gray-800 rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-3">Codex Setup</h3>
                <p class="text-sm text-gray-400 mb-4">
                    Run this command on your <strong class="text-white">host machine</strong> (not in Docker) to authenticate:
                </p>
                <div class="relative">
                    <div
                        @click="copyCommand('sudo npm install -g @openai/codex && codex login && docker cp ~/.codex/auth.json pocket-dev-queue:/home/appuser/.codex/auth.json && docker exec pocket-dev-queue chmod 600 /home/appuser/.codex/auth.json', 'codex')"
                        class="bg-gray-900 rounded p-3 font-mono text-xs text-green-400 cursor-pointer hover:bg-gray-800 transition-colors mb-3 overflow-x-auto"
                        title="Click to copy"
                    >
                        sudo npm install -g @openai/codex && codex login && docker cp ~/.codex/auth.json pocket-dev-queue:/home/appuser/.codex/auth.json && docker exec pocket-dev-queue chmod 600 /home/appuser/.codex/auth.json
                    </div>
                    <div
                        x-show="copiedCommand === 'codex'"
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
                    This installs Codex locally, opens a browser login, then copies credentials to the container. After completion, click <strong class="text-white">Verify & Continue</strong>.
                </p>
                <p class="text-xs text-gray-500">
                    If you prefer to use an API key instead, select "OpenAI API" above.
                </p>
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

            {{-- Git Credentials (optional) --}}
            <div class="bg-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold">Git Credentials</h2>
                    <span class="text-sm text-gray-500">Optional</span>
                </div>
                <p class="text-sm text-gray-400 mb-4">Required for git operations. You can configure this later.</p>

                <div x-show="showGitCredentials" class="space-y-3">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">GitHub Token</label>
                        <input
                            type="password"
                            name="git_token"
                            placeholder="ghp_..."
                            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Name</label>
                        <input
                            type="text"
                            name="git_user_name"
                            placeholder="Your Name"
                            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Email</label>
                        <input
                            type="email"
                            name="git_user_email"
                            placeholder="you@example.com"
                            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                </div>

                <button
                    type="button"
                    @click="showGitCredentials = !showGitCredentials"
                    class="text-blue-400 hover:text-blue-300 text-sm mt-2"
                    x-text="showGitCredentials ? 'Hide Git credentials' : 'Add Git credentials'"
                ></button>
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
                showGitCredentials: false,
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
    </script>

    <style>
        [x-cloak] { display: none !important; }
    </style>
</body>
</html>
