@extends('layouts.config')

@section('title', 'Providers')

@section('content')
<div class="space-y-8">
    {{-- AI Providers Section --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">AI Providers</h2>
        <p class="text-sm text-gray-400 mb-4">Configure at least one AI provider to use PocketDev.</p>

        <div class="space-y-4">
            {{-- Claude Code (CLI) --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-medium text-white">Claude Code (CLI)</h3>
                        <p class="text-sm text-gray-400">Uses your Claude Pro/Team subscription via CLI authentication</p>
                    </div>
                    <div class="flex items-center gap-3">
                        @if($hasClaudeCode)
                            <span class="text-green-400 text-sm">Authenticated</span>
                        @else
                            <span class="text-gray-500 text-sm">Not configured</span>
                        @endif
                        <a href="{{ route('claude.auth') }}" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                            {{ $hasClaudeCode ? 'Manage' : 'Set up' }}
                        </a>
                    </div>
                </div>
            </div>

            {{-- Codex (CLI) --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-medium text-white">Codex (CLI)</h3>
                        <p class="text-sm text-gray-400">Uses your ChatGPT Plus/Pro subscription via CLI authentication</p>
                    </div>
                    <div class="flex items-center gap-3">
                        @if($hasCodex)
                            <span class="text-green-400 text-sm">Authenticated</span>
                        @else
                            <span class="text-gray-500 text-sm">Not configured</span>
                        @endif
                        <a href="{{ route('codex.auth') }}" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                            {{ $hasCodex ? 'Manage' : 'Set up' }}
                        </a>
                    </div>
                </div>
            </div>

            {{-- Anthropic API --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="font-medium text-white">Anthropic API</h3>
                        <p class="text-sm text-gray-400">Direct API access with your own key</p>
                    </div>
                    @if($hasAnthropicKey)
                        <div class="flex items-center gap-3">
                            <span class="text-green-400 text-sm">Configured</span>
                            <form method="POST" action="{{ route('config.credentials.api-keys.delete', 'anthropic') }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-400 hover:text-red-300 text-sm" onclick="return confirm('Delete Anthropic API key?')">
                                    Delete
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
                @unless($hasAnthropicKey)
                <form method="POST" action="{{ route('config.credentials.api-keys') }}">
                    @csrf
                    <div class="flex gap-2">
                        <input
                            type="password"
                            name="anthropic_api_key"
                            placeholder="sk-ant-..."
                            class="flex-1 bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                        >
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                            Save
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        Get your key from <a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-blue-400 hover:underline">console.anthropic.com</a>
                    </p>
                </form>
                @endunless
            </div>

            {{-- OpenAI API --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="font-medium text-white">OpenAI API</h3>
                        <p class="text-sm text-gray-400">For GPT models and voice transcription</p>
                    </div>
                    @if($hasOpenAiKey)
                        <div class="flex items-center gap-3">
                            <span class="text-green-400 text-sm">Configured</span>
                            <form method="POST" action="{{ route('config.credentials.api-keys.delete', 'openai') }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-400 hover:text-red-300 text-sm" onclick="return confirm('Delete OpenAI API key?')">
                                    Delete
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
                @unless($hasOpenAiKey)
                <form method="POST" action="{{ route('config.credentials.api-keys') }}">
                    @csrf
                    <div class="flex gap-2">
                        <input
                            type="password"
                            name="openai_api_key"
                            placeholder="sk-..."
                            class="flex-1 bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                        >
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                            Save
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        Get your key from <a href="https://platform.openai.com/api-keys" target="_blank" class="text-blue-400 hover:underline">platform.openai.com</a>
                    </p>
                </form>
                @endunless
            </div>

            {{-- OpenAI Compatible (Local LLM) --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="font-medium text-white">Local LLM (OpenAI Compatible)</h3>
                        <p class="text-sm text-gray-400">KoboldCpp, Ollama, LM Studio, or any OpenAI-compatible server</p>
                    </div>
                    @if($hasOpenAiCompatible)
                        <div class="flex items-center gap-3">
                            <span class="text-green-400 text-sm">Configured</span>
                            <form method="POST" action="{{ route('config.credentials.api-keys.delete', 'openai_compatible') }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <x-button type="submit" variant="ghost" size="sm" class="!p-0 !bg-transparent text-red-400 hover:text-red-300" onclick="return confirm('Delete Local LLM configuration?')">
                                    Delete
                                </x-button>
                            </form>
                        </div>
                    @endif
                </div>

                @if($hasOpenAiCompatible)
                    <div class="text-sm text-gray-400 mb-3 space-y-1">
                        <p><span class="text-gray-500">URL:</span> {{ $openAiCompatibleBaseUrl }}</p>
                        @if($openAiCompatibleModel)
                            <p><span class="text-gray-500">Model:</span> {{ $openAiCompatibleModel }}</p>
                        @endif
                    </div>
                @endif

                <form method="POST" action="{{ route('config.credentials.api-keys') }}">
                    @csrf
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Server URL</label>
                            <input
                                type="url"
                                name="openai_compatible_base_url"
                                placeholder="http://localhost:5001"
                                value="{{ $openAiCompatibleBaseUrl ?? '' }}"
                                class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                                required
                            >
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">API Key <span class="text-gray-600">(optional)</span></label>
                            <input
                                type="password"
                                name="openai_compatible_api_key"
                                placeholder="Leave empty if not required"
                                class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                            >
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Model Name <span class="text-gray-600">(optional)</span></label>
                            <input
                                type="text"
                                name="openai_compatible_model"
                                placeholder="Leave empty for server default"
                                value="{{ $openAiCompatibleModel ?? '' }}"
                                class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                            >
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Context Window <span class="text-gray-600">(optional)</span></label>
                            <input
                                type="number"
                                name="openai_compatible_context_window"
                                placeholder="32768"
                                value="{{ $openAiCompatibleContextWindow ?? '' }}"
                                min="1024"
                                max="2000000"
                                class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                            >
                            <p class="text-xs text-gray-600 mt-1">Max tokens the model can process. Default: 32768</p>
                        </div>
                        <div class="pt-2">
                            <x-button type="submit" variant="primary">
                                {{ $hasOpenAiCompatible ? 'Update' : 'Save' }}
                            </x-button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-3">
                        Common ports: KoboldCpp (5001), Ollama (11434), LM Studio (1234), LocalAI (8080)
                    </p>
                </form>
            </div>
        </div>
    </section>

    {{-- Git Credentials Section --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">Git Credentials</h2>
        <p class="text-sm text-gray-400 mb-4">Optional. Required for git operations inside PocketDev.</p>

        <div class="bg-gray-800 rounded-lg p-4">
            @if($hasGitCredentials)
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="text-white">{{ $gitCredentials['name'] }}</p>
                        <p class="text-sm text-gray-400">{{ $gitCredentials['email'] }}</p>
                        <p class="text-xs text-gray-500 mt-1">Token: ****{{ substr($gitCredentials['token'] ?? '', -4) }}</p>
                    </div>
                    <form method="POST" action="{{ route('config.credentials.git.delete') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-400 hover:text-red-300 text-sm" onclick="return confirm('Delete Git credentials?')">
                            Delete
                        </button>
                    </form>
                </div>
            @endif

            <form method="POST" action="{{ route('config.credentials.git') }}">
                @csrf
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">GitHub Token</label>
                        <input
                            type="password"
                            name="git_token"
                            placeholder="ghp_..."
                            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                            required
                        >
                        @if($hasGitCredentials)
                        <p class="text-xs text-gray-400 mt-1">Re-enter your token to update credentials</p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Name</label>
                        <input
                            type="text"
                            name="git_user_name"
                            placeholder="Your Name"
                            value="{{ $gitCredentials['name'] ?? '' }}"
                            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                            {{ $hasGitCredentials ? '' : 'required' }}
                        >
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Email</label>
                        <input
                            type="email"
                            name="git_user_email"
                            placeholder="you@example.com"
                            value="{{ $gitCredentials['email'] ?? '' }}"
                            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                            {{ $hasGitCredentials ? '' : 'required' }}
                        >
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                            {{ $hasGitCredentials ? 'Update' : 'Save' }}
                        </button>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-3">
                    Create a token at <a href="https://github.com/settings/tokens" target="_blank" class="text-blue-400 hover:underline">github.com/settings/tokens</a> with repo access.
                </p>
            </form>
        </div>
    </section>
</div>
@endsection
