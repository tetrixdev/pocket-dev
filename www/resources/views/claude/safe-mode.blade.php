<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude Code - Safe Mode</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-yellow-400 mb-2">⚠️ Claude Code - Safe Mode</h1>
            <p class="text-gray-400">Emergency fallback interface (No Livewire, pure HTML)</p>
            <a href="/" class="text-blue-400 hover:underline text-sm">← Back to main interface</a>
        </div>

        <div class="bg-yellow-900 border border-yellow-700 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-yellow-300 mb-2">🛡️ When to use Safe Mode:</h3>
            <ul class="text-sm text-yellow-100 space-y-1">
                <li>• Livewire chat interface is broken</li>
                <li>• Frontend JavaScript errors prevent normal usage</li>
                <li>• Emergency access to Claude needed during development</li>
            </ul>
        </div>

        <div class="bg-gray-800 rounded-lg p-4 mb-6">
            <h3 class="font-semibold mb-2">Session Info</h3>
            <p class="text-sm text-gray-400">Session ID: <span class="text-blue-400 font-mono">{{ $sessionId ?? 'Not set' }}</span></p>
            @if(isset($session))
                <p class="text-sm text-gray-400">Title: {{ $session->title }}</p>
                <p class="text-sm text-gray-400">Messages: {{ $session->turn_count }}</p>
            @endif
        </div>

        @if(isset($messages) && count($messages) > 0)
            <div class="bg-gray-800 rounded-lg p-4 mb-6">
                <h3 class="font-semibold mb-4">Message History</h3>
                <div class="space-y-4 max-h-96 overflow-y-auto">
                    @foreach($messages as $message)
                        <div class="fade-in">
                            @if($message['role'] === 'user')
                                <div class="bg-blue-900 rounded-lg p-3">
                                    <div class="text-xs text-blue-300 mb-1">You:</div>
                                    <div class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</div>
                                </div>
                            @else
                                <div class="bg-gray-700 rounded-lg p-3">
                                    <div class="text-xs text-green-300 mb-1">Claude:</div>
                                    <div class="text-sm whitespace-pre-wrap">
                                        @if(is_array($message['content']) && isset($message['content']['result']))
                                            {{ $message['content']['result'] }}
                                        @elseif(is_string($message['content']))
                                            {{ $message['content'] }}
                                        @else
                                            <pre class="text-xs bg-gray-900 p-2 rounded overflow-x-auto">{{ json_encode($message['content'], JSON_PRETTY_PRINT) }}</pre>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if(isset($response))
            <div class="bg-green-900 border border-green-700 rounded-lg p-4 mb-6 fade-in">
                <h3 class="font-semibold text-green-300 mb-2">✅ Response:</h3>
                <div class="text-sm whitespace-pre-wrap bg-gray-900 p-3 rounded">{{ $response }}</div>
            </div>
        @endif

        @if(isset($error))
            <div class="bg-red-900 border border-red-700 rounded-lg p-4 mb-6 fade-in">
                <h3 class="font-semibold text-red-300 mb-2">❌ Error:</h3>
                <div class="text-sm">{{ $error }}</div>
            </div>
        @endif

        <div class="bg-gray-800 rounded-lg p-6">
            <h3 class="font-semibold mb-4">Send Message to Claude</h3>

            <form method="POST" action="{{ route('claude.safe-mode.query') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium mb-2">Session ID (optional - leave empty for auto-create):</label>
                    <input
                        type="number"
                        name="session_id"
                        value="{{ $sessionId ?? '' }}"
                        class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded focus:outline-none focus:border-blue-500 font-mono text-sm"
                        placeholder="Leave empty to create new session"
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Your Message:</label>
                    <textarea
                        name="prompt"
                        rows="6"
                        required
                        class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded focus:outline-none focus:border-blue-500"
                        placeholder="Ask Claude anything..."
                    ></textarea>
                </div>

                <button
                    type="submit"
                    class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded font-semibold transition"
                >
                    Send Message
                </button>
            </form>
        </div>

        <div class="mt-6 bg-gray-800 rounded-lg p-4">
            <h3 class="font-semibold mb-3 text-sm">Quick Actions:</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                <a href="{{ route('claude.safe-mode.new-session') }}" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-center transition">
                    🆕 New Session
                </a>
                <a href="{{ route('claude.safe-mode.list-sessions') }}" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-center transition">
                    📋 List Sessions
                </a>
                <a href="/claude/auth/status" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-center transition" target="_blank">
                    🔑 Check Auth
                </a>
                <a href="/api/claude/status" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-center transition" target="_blank">
                    ❤️ API Health
                </a>
            </div>
        </div>

        <div class="mt-8 text-center text-xs text-gray-500">
            <p>Safe Mode uses direct API calls - no Livewire, no Alpine, no JavaScript frameworks</p>
            <p class="mt-1">This interface cannot be broken by frontend changes ✅</p>
        </div>
    </div>
</body>
</html>
