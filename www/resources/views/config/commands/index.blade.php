<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commands - Config</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white min-h-screen p-6">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">Commands</h1>

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

        <div class="mb-6">
            <a href="{{ route('config.commands.create') }}" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded font-medium inline-block">
                Create New Command
            </a>
        </div>

        <div class="space-y-4">
            @forelse($commands as $command)
                <div class="p-4 bg-gray-800 rounded border border-gray-700">
                    <h3 class="text-xl font-semibold mb-2">{{ $command['name'] }}</h3>
                    @if(!empty($command['description']))
                        <p class="text-gray-400 mb-3">{{ $command['description'] }}</p>
                    @endif
                    <div class="flex gap-3">
                        <a href="{{ route('config.commands.edit', $command['filename']) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm">
                            Edit
                        </a>
                        <form method="POST" action="{{ route('config.commands.delete', $command['filename']) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm" onclick="return confirm('Are you sure you want to delete this command?')">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-gray-400">No commands found.</p>
            @endforelse
        </div>
    </div>
</body>
</html>
