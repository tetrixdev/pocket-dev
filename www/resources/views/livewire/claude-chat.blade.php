<div class="flex h-screen bg-gray-900 text-gray-100">
    {{-- Sidebar --}}
    <div class="w-64 bg-gray-800 border-r border-gray-700 flex flex-col">
        <div class="p-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold">Claude Code</h2>
            <button 
                wire:click="clearSession"
                type="button"
                class="mt-2 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm"
            >
                New Session
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-2">
            <h3 class="text-xs font-semibold text-gray-400 px-2 mb-2">Recent Sessions</h3>
            @foreach($sessions as $session)
                <button
                    type="button"
                    wire:click="$set('sessionId', {{ $session->id }}); loadSession()"
                    class="w-full text-left px-3 py-2 rounded hover:bg-gray-700 mb-1 {{ $sessionId == $session->id ? 'bg-gray-700' : '' }}"
                >
                    <div class="text-sm truncate">{{ $session->title }}</div>
                    <div class="text-xs text-gray-400">{{ $session->turn_count }} messages</div>
                </button>
            @endforeach
        </div>

        <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
            <div>Project: {{ $projectPath }}</div>
        </div>
    </div>

    {{-- Main Chat Area --}}
    <div class="flex-1 flex flex-col">
        {{-- Messages --}}
        <div 
            class="flex-1 overflow-y-auto p-4 space-y-4" 
            id="messages-container"
        >
            @if(empty($messages))
                <div class="text-center text-gray-400 mt-20">
                    <h3 class="text-xl mb-2">Welcome to Claude Code</h3>
                    <p>Start a conversation to begin AI-powered development</p>
                </div>
            @endif

            @foreach($messages as $message)
                <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-3xl">
                        {{-- Message Header --}}
                        <div class="flex items-center gap-2 mb-1">
                            @if($message['role'] === 'user')
                                <span class="text-xs text-gray-400">You</span>
                            @elseif($message['role'] === 'assistant')
                                <span class="text-xs text-blue-400">Claude</span>
                            @else
                                <span class="text-xs text-red-400">Error</span>
                            @endif
                            <span class="text-xs text-gray-500">
                                {{ \Carbon\Carbon::parse($message['timestamp'])->diffForHumans() }}
                            </span>
                        </div>

                        {{-- Message Content --}}
                        <div class="px-4 py-3 rounded-lg {{ 
                            $message['role'] === 'user' ? 'bg-blue-600' : 
                            ($message['role'] === 'error' ? 'bg-red-900' : 'bg-gray-800') 
                        }}">
                            <div class="text-sm whitespace-pre-wrap">{{ is_array($message['content']) ? json_encode($message['content'], JSON_PRETTY_PRINT) : $message['content'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach

            @if($isProcessing)
                <div class="flex justify-start">
                    <div class="max-w-3xl">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-xs text-blue-400">Claude</span>
                            <span class="text-xs text-gray-500">Thinking...</span>
                        </div>
                        <div class="px-4 py-3 rounded-lg bg-gray-800">
                            <div class="flex space-x-2">
                                <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                                <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                                <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Input Area --}}
        <div class="border-t border-gray-700 p-4">
            <form wire:submit="sendMessage" class="flex gap-2">
                <input
                    type="text"
                    wire:model.live="prompt"
                    placeholder="Ask Claude to help with your code..."
                    class="flex-1 px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white"
                    @disabled($isProcessing)
                >
                <button
                    type="submit"
                    class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
                    @disabled($isProcessing)
                >
                    Send
                </button>
            </form>
            <div class="mt-2 text-xs text-gray-400">
                Claude Code can read, write, edit files and run commands in {{ $projectPath }}
            </div>
        </div>
    </div>
</div>
