{{-- Claude Code Authentication Modal --}}
<x-modal show="showClaudeCodeAuthModal" title="Claude Code Setup" max-width="lg">
    <div class="space-y-5">
        {{-- Option 1: Subscription (OAuth) --}}
        <div class="bg-purple-900/30 border border-purple-500/30 rounded-lg p-4">
            <div class="flex items-center gap-2 mb-2">
                <span class="bg-purple-500 text-white text-xs font-bold px-2 py-0.5 rounded">RECOMMENDED</span>
                <h4 class="text-white font-medium">Claude Pro/Max Subscription</h4>
            </div>
            <p class="text-gray-300 text-sm mb-3">
                Use your monthly subscription. Run this command in your terminal:
            </p>
            <div class="bg-gray-900 rounded p-3 font-mono text-sm text-green-400 select-all">
                docker exec -it -u www-data pocket-dev-queue claude
            </div>
            <p class="text-gray-500 text-xs mt-2">
                This opens an interactive login. Complete the OAuth flow in your browser, then Claude Code will be ready to use.
            </p>
        </div>

        {{-- Divider --}}
        <div class="flex items-center gap-3">
            <div class="flex-1 border-t border-gray-700"></div>
            <span class="text-gray-500 text-sm">OR</span>
            <div class="flex-1 border-t border-gray-700"></div>
        </div>

        {{-- Option 2: API Key --}}
        <div class="bg-gray-800/50 border border-gray-700 rounded-lg p-4">
            <h4 class="text-white font-medium mb-2">Anthropic API Key (Pay-per-use)</h4>
            <p class="text-gray-400 text-sm mb-3">
                Use API credits instead of subscription. Billed per token used.
            </p>

            <x-text-input
                type="password"
                x-model="anthropicKeyInput"
                placeholder="sk-ant-api03-..."
                label="API Key"
                class="mb-2"
            />

            <p class="text-gray-500 text-xs mb-3">
                Get your key at
                <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer" class="text-blue-400 hover:underline">console.anthropic.com</a>
            </p>

            <x-button variant="primary" full-width @click="saveAnthropicKey()">
                Save API Key
            </x-button>
        </div>

        {{-- Close button --}}
        <div class="pt-2">
            <x-button variant="secondary" full-width @click="showClaudeCodeAuthModal = false">
                Cancel
            </x-button>
        </div>
    </div>
</x-modal>
