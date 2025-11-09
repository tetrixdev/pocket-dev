<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Agent - Config</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white min-h-screen p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">Create New Agent</h1>

        <nav class="mb-6 p-4 bg-gray-800 rounded">
            <a href="{{ route('config.claude') }}" class="text-blue-400 hover:text-blue-300">CLAUDE.md</a> |
            <a href="{{ route('config.settings') }}" class="text-blue-400 hover:text-blue-300">Settings</a> |
            <a href="{{ route('config.nginx') }}" class="text-blue-400 hover:text-blue-300">Nginx</a> |
            <a href="{{ route('config.agents') }}" class="text-blue-400 hover:text-blue-300">Agents</a> |
            <a href="{{ route('config.commands') }}" class="text-blue-400 hover:text-blue-300">Commands</a> |
            <a href="{{ route('config.hooks') }}" class="text-blue-400 hover:text-blue-300">Hooks</a> |
            <a href="{{ route('config.skills') }}" class="text-blue-400 hover:text-blue-300">Skills</a>
        </nav>

        @if($errors->any())
            <div class="mb-4 p-4 bg-red-600 text-white rounded">
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('config.agents.store') }}">
            @csrf

            <div class="mb-4">
                <label for="name" class="block text-sm font-medium mb-2">Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="{{ old('name') }}"
                    class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
                    required
                >
            </div>

            <div class="mb-4">
                <label for="description" class="block text-sm font-medium mb-2">Description</label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
                >{{ old('description') }}</textarea>
            </div>

            <div class="mb-4">
                <label for="tools" class="block text-sm font-medium mb-2">Tools (comma-separated)</label>
                <input
                    type="text"
                    id="tools"
                    name="tools"
                    value="{{ old('tools') }}"
                    class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
                    placeholder="bash, read, write"
                >
            </div>

            <div class="mb-6">
                <label for="model" class="block text-sm font-medium mb-2">Model</label>
                <select
                    id="model"
                    name="model"
                    class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
                >
                    <option value="claude-sonnet-4-5-20250929" {{ old('model') == 'claude-sonnet-4-5-20250929' ? 'selected' : '' }}>Claude Sonnet 4.5</option>
                    <option value="claude-opus-4-20250514" {{ old('model') == 'claude-opus-4-20250514' ? 'selected' : '' }}>Claude Opus 4</option>
                    <option value="claude-3-5-sonnet-20241022" {{ old('model') == 'claude-3-5-sonnet-20241022' ? 'selected' : '' }}>Claude 3.5 Sonnet</option>
                </select>
            </div>

            <div class="flex gap-3">
                <button
                    type="submit"
                    class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded font-medium"
                >
                    Create Agent
                </button>
                <a
                    href="{{ route('config.agents') }}"
                    class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium inline-block"
                >
                    Cancel
                </a>
            </div>
        </form>
    </div>
</body>
</html>
