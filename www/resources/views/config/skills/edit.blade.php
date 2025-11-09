<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Skill - Config</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white min-h-screen p-6">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">Edit Skill: {{ $skill['name'] }}</h1>

        <nav class="mb-6 p-4 bg-gray-800 rounded">
            <a href="{{ route('config.claude') }}" class="text-blue-400 hover:text-blue-300">CLAUDE.md</a> |
            <a href="{{ route('config.settings') }}" class="text-blue-400 hover:text-blue-300">Settings</a> |
            <a href="{{ route('config.nginx') }}" class="text-blue-400 hover:text-blue-300">Nginx</a> |
            <a href="{{ route('config.agents') }}" class="text-blue-400 hover:text-blue-300">Agents</a> |
            <a href="{{ route('config.commands') }}" class="text-blue-400 hover:text-blue-300">Commands</a> |
            <a href="{{ route('config.hooks') }}" class="text-blue-400 hover:text-blue-300">Hooks</a> |
            <a href="{{ route('config.skills') }}" class="text-blue-400 hover:text-blue-300">Skills</a>
        </nav>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-600 text-white rounded">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-4 p-4 bg-red-600 text-white rounded">
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left sidebar: Skill metadata -->
            <div class="lg:col-span-1">
                <div class="bg-gray-800 p-4 rounded border border-gray-700 mb-4">
                    <h2 class="text-xl font-semibold mb-4">Skill Metadata</h2>
                    <form method="POST" action="{{ route('config.skills.update', $skill['filename']) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-4">
                            <label for="name" class="block text-sm font-medium mb-2">Name</label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="{{ old('name', $skill['name']) }}"
                                class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                                required
                            >
                        </div>

                        <div class="mb-4">
                            <label for="description" class="block text-sm font-medium mb-2">Description</label>
                            <textarea
                                id="description"
                                name="description"
                                rows="3"
                                class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                            >{{ old('description', $skill['description'] ?? '') }}</textarea>
                        </div>

                        <div class="mb-4">
                            <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools</label>
                            <input
                                type="text"
                                id="allowedTools"
                                name="allowedTools"
                                value="{{ old('allowedTools', $skill['allowedTools'] ?? '') }}"
                                class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                                placeholder="bash, read, write"
                            >
                        </div>

                        <button
                            type="submit"
                            class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium mb-2"
                        >
                            Update Metadata
                        </button>
                    </form>

                    <a
                        href="{{ route('config.skills') }}"
                        class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium text-center mb-2"
                    >
                        Back to Skills
                    </a>

                    <form method="POST" action="{{ route('config.skills.delete', $skill['filename']) }}" class="inline w-full">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium" onclick="return confirm('Are you sure you want to delete this skill?')">
                            Delete Skill
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right side: SKILL.md editor -->
            <div class="lg:col-span-2">
                <div class="bg-gray-800 p-4 rounded border border-gray-700">
                    <h2 class="text-xl font-semibold mb-4">SKILL.md Content</h2>
                    <form method="POST" action="{{ route('config.skills.update-file', $skill['filename']) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-4">
                            <textarea
                                id="content"
                                name="content"
                                rows="30"
                                class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded font-mono text-sm"
                            >{{ old('content', $skill['content'] ?? '') }}</textarea>
                        </div>

                        <button
                            type="submit"
                            class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded font-medium"
                        >
                            Save SKILL.md
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
