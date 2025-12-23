{{-- Agent Selector Modal --}}
<x-modal show="showAgentSelector" title="Select Agent" max-width="lg">
    <div class="space-y-4">
        {{-- Loading State --}}
        <template x-if="agents.length === 0">
            <div class="text-center py-8">
                <p class="text-gray-400 mb-4">No agents available.</p>
                <a href="{{ route('config.agents') }}" class="text-blue-400 hover:underline text-sm">
                    Configure agents in settings
                </a>
            </div>
        </template>

        {{-- Agent List (grouped by provider) --}}
        <template x-if="agents.length > 0">
            <div>
                {{-- Filter info when in conversation --}}
                <template x-if="currentConversationUuid">
                    <div class="text-xs text-gray-400 mb-3 pb-2 border-b border-gray-700">
                        <span x-text="'Showing agents for ' + getProviderDisplayName(conversationProvider)"></span>
                        <span class="text-gray-500">(mid-conversation switch)</span>
                    </div>
                </template>

                <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
                    <template x-for="providerKey in availableProviderKeys" :key="providerKey">
                        <div>
                            {{-- Provider Header --}}
                            <h4 class="text-sm font-semibold text-gray-300 mb-2 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full"
                                      :class="{
                                          'bg-orange-500': providerKey === 'anthropic',
                                          'bg-green-500': providerKey === 'openai',
                                          'bg-purple-500': providerKey === 'claude_code',
                                          'bg-blue-500': providerKey === 'codex',
                                          'bg-teal-500': providerKey === 'openai_compatible',
                                          'bg-gray-500': !['anthropic', 'openai', 'claude_code', 'codex', 'openai_compatible'].includes(providerKey)
                                      }"></span>
                                <span x-text="getProviderDisplayName(providerKey)"></span>
                            </h4>

                            {{-- Agents for this provider --}}
                            <div class="space-y-2">
                                <template x-for="agent in agentsForProvider(providerKey)" :key="agent.id">
                                    <button
                                        @click="selectAgent(agent)"
                                        class="w-full text-left p-3 rounded-lg border transition-all"
                                        :class="currentAgentId === agent.id
                                            ? 'bg-blue-600/20 border-blue-500 text-white'
                                            : 'bg-gray-800 border-gray-700 hover:border-gray-600 text-gray-200'"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="font-medium" x-text="agent.name"></span>
                                                    <span x-show="agent.is_default" class="px-1.5 py-0.5 text-xs bg-blue-600 text-white rounded">Default</span>
                                                </div>
                                                <div class="text-xs text-gray-400 mt-1">
                                                    <span class="font-mono bg-gray-700/50 px-1 rounded" x-text="agent.model"></span>
                                                    <template x-if="agent.allowed_tools === null">
                                                        <span class="ml-2 text-gray-500">All tools</span>
                                                    </template>
                                                    <template x-if="agent.allowed_tools !== null">
                                                        <span class="ml-2 text-gray-500" x-text="agent.allowed_tools.length + ' tools'"></span>
                                                    </template>
                                                </div>
                                                <p x-show="agent.description" class="text-xs text-gray-400 mt-1 line-clamp-2" x-text="agent.description"></p>
                                            </div>
                                            <template x-if="currentAgentId === agent.id">
                                                <svg class="w-5 h-5 text-blue-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                </svg>
                                            </template>
                                        </div>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        {{-- Footer --}}
        <div class="flex justify-between items-center pt-3 border-t border-gray-700">
            <a href="{{ route('config.agents') }}" class="text-xs text-gray-400 hover:text-gray-300">
                Manage Agents
            </a>
            <x-button variant="secondary" @click="showAgentSelector = false">
                Close
            </x-button>
        </div>
    </div>
</x-modal>
