<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Settings') - PocketDev</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Hide elements until Alpine.js initializes */
        [x-cloak] { display: none !important; }

        /* Category button styles */
        .category-button {
            padding: 12px 16px;
            text-align: left;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            cursor: pointer;
        }

        .category-button:hover {
            background: #374151;
        }

        .category-button.active {
            background: #1f2937;
            border-left-color: #3b82f6;
        }

        /* File list styles */
        .file-item {
            padding: 10px 16px;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .file-item:hover {
            background: #374151;
        }

        .file-item.active {
            background: #1f2937;
            border-left-color: #10b981;
        }

        /* Editor styles */
        .config-editor {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            border: 1px solid #374151;
            border-radius: 4px;
            padding: 16px;
            font-size: 14px;
            line-height: 1.5;
            resize: vertical;
            min-height: 400px;
        }

        .config-editor:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        /* Notification styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }

        .notification.success {
            background: #065f46;
            border-left: 4px solid #10b981;
        }

        .notification.error {
            background: #7f1d1d;
            border-left: 4px solid #ef4444;
        }

        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Auto-hide notification after 3s */
        .notification {
            animation: slideIn 0.3s ease-out, fadeOut 0.5s ease-in 2.5s forwards;
        }

        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(400px); }
        }

        /* Hide desktop layout on mobile */
        @media (max-width: 767px) {
            .desktop-layout { display: none !important; }
        }

        /* Hide mobile layout on desktop */
        @media (min-width: 768px) {
            .mobile-layout { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-900 text-white" x-data="{ showMobileDrawer: false }">
    <!-- Desktop Layout -->
    @php
        // Get last conversation for the active workspace
        $activeWorkspaceId = session('active_workspace_id');
        $lastConversationUuid = $activeWorkspaceId
            ? session("last_conversation_{$activeWorkspaceId}")
            : session('last_conversation_default');
        $backToChatUrl = $lastConversationUuid ? '/chat/' . $lastConversationUuid : '/';
    @endphp
    <div class="desktop-layout flex" style="height: 100dvh;">

        <!-- Sidebar (Categories) -->
        <div class="w-64 bg-gray-800 border-r border-gray-700 flex flex-col">
            <!-- Sidebar Header -->
            <div class="p-4 border-b border-gray-700">
                <h2 class="text-lg font-semibold">Menu</h2>
            </div>
            {{-- TODO: Extract navigation to <x-config.navigation> component to reduce duplication with mobile drawer --}}
            <!-- Sidebar Navigation -->
            <div class="flex-1 overflow-y-auto">

                <!-- System Prompt (Primary Setting) -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.system-prompt') }}"
                       class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.system-prompt') ? 'active' : '' }}">
                        🧠 System Prompt
                    </a>
                </div>

                <!-- Workspaces Category -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.workspaces') }}"
                       class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.workspaces') ? 'active' : '' }}">
                        📁 Workspaces
                    </a>
                </div>

                <!-- Agents Category -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.agents') }}"
                       class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.agents') ? 'active' : '' }}">
                        🤖 Agents
                    </a>
                    <div class="bg-gray-900">
                        <a href="{{ route('config.agents.create') }}"
                           class="file-item w-full text-sm text-blue-400 hover:text-blue-300 block">
                            + New Agent
                        </a>
                        @if(isset($agents))
                            @foreach($agents as $agent)
                                <a href="{{ route('config.agents.edit', $agent['filename']) }}"
                                   class="file-item w-full text-sm block {{ isset($activeAgent) && $activeAgent == $agent['filename'] ? 'active' : '' }}">
                                    {{ $agent['name'] }}
                                </a>
                            @endforeach
                        @endif
                    </div>
                </div>

                <!-- Commands Category -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.commands') }}"
                       class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.commands') ? 'active' : '' }}">
                        ⚡ Commands
                    </a>
                    <div class="bg-gray-900">
                        <a href="{{ route('config.commands.create') }}"
                           class="file-item w-full text-sm text-blue-400 hover:text-blue-300 block">
                            + New Command
                        </a>
                        @if(isset($commands))
                            @foreach($commands as $command)
                                <a href="{{ route('config.commands.edit', $command['filename']) }}"
                                   class="file-item w-full text-sm block {{ isset($activeCommand) && $activeCommand == $command['filename'] ? 'active' : '' }}">
                                    {{ $command['name'] }}
                                </a>
                            @endforeach
                        @endif
                    </div>
                </div>

                <!-- Hooks Category -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.hooks') }}"
                       class="category-button w-full block {{ Route::currentRouteName() == 'config.hooks' ? 'active' : '' }}">
                        🪝 Hooks
                    </a>
                </div>

                <!-- Tools Category -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.tools') }}"
                       class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.tools') ? 'active' : '' }}">
                        🛠️ Tools
                    </a>
                </div>

                <!-- Memory -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.memory') }}"
                       class="category-button w-full block {{ Route::currentRouteName() == 'config.memory' ? 'active' : '' }}">
                        🧠 Memory
                    </a>
                </div>

                <!-- Providers (AI API Keys) -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.credentials') }}"
                       class="category-button w-full block {{ Route::currentRouteName() == 'config.credentials' ? 'active' : '' }}">
                        🔑 Providers
                    </a>
                </div>

                <!-- Environment -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.environment') }}"
                       class="category-button w-full block {{ Route::currentRouteName() == 'config.environment' ? 'active' : '' }}">
                        🌐 Environment
                    </a>
                </div>

                <!-- Backup & Restore -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.backup') }}"
                       class="category-button w-full block {{ Route::currentRouteName() == 'config.backup' ? 'active' : '' }}">
                        💾 Backup
                    </a>
                </div>

                @if(app()->environment('local'))
                <!-- Developer Tools (Local Only) -->
                <div class="border-b border-gray-700">
                    <a href="{{ route('config.developer') }}"
                       class="category-button w-full block {{ Route::currentRouteName() == 'config.developer' ? 'active' : '' }}">
                        🔧 Developer
                    </a>
                </div>
                @endif

            </div>
        </div>

        <!-- Content Area -->
        <div class="flex-1 flex flex-col">

            <!-- Desktop Header -->
            <div class="bg-gray-800 border-b border-gray-700 p-2 flex items-center justify-between">
                <div class="flex items-center gap-3 pl-2">
                    <h2 class="text-base font-semibold">Settings</h2>
                </div>
                <a href="{{ $backToChatUrl }}" class="text-gray-300 hover:text-white p-2" title="Back to Chat"
                   onclick="localStorage.setItem('pocketdev_returning_from_settings', 'true')">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-6">

            <!-- Notifications -->
            @if(session('success'))
                <div class="notification success">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="notification error">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="notification error">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Page Content -->
            @yield('content')
            </div>
        </div>
    </div>

    <!-- Mobile Layout -->
    <div class="mobile-layout min-h-screen flex flex-col">
        <!-- Mobile Header (Sticky) -->
        <div class="sticky top-0 z-10 bg-gray-800 border-b border-gray-700 p-2 flex items-center justify-between">
            <button @click="showMobileDrawer = true" class="text-gray-300 hover:text-white p-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <h2 class="text-lg font-semibold">Settings</h2>
            <a href="{{ $backToChatUrl }}" class="text-gray-300 hover:text-white p-2"
               onclick="localStorage.setItem('pocketdev_returning_from_settings', 'true')">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
        </div>

        <!-- Mobile Content Area -->
        <div class="flex-1 overflow-y-auto p-4 md:p-6">
            <!-- Notifications -->
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-900 border-l-4 border-green-500 text-green-200 rounded">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4 p-4 bg-red-900 border-l-4 border-red-500 text-red-200 rounded">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 p-4 bg-red-900 border-l-4 border-red-500 text-red-200 rounded">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Page Content -->
            @yield('content')
        </div>

        <!-- Mobile Drawer Overlay -->
        <div x-show="showMobileDrawer"
             @click="showMobileDrawer = false"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black bg-opacity-50 z-40"
             style="display: none;">
        </div>

        <!-- Mobile Drawer -->
        <div x-show="showMobileDrawer"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-300 transform"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 w-5/6 max-w-sm bg-gray-800 z-50 flex flex-col overflow-y-auto"
             style="display: none;">

            <div class="p-4 border-b border-gray-700 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Menu</h2>
                <button @click="showMobileDrawer = false" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- System Prompt (Primary Setting) -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.system-prompt') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.system-prompt') ? 'active' : '' }}">
                    🧠 System Prompt
                </a>
            </div>

            <!-- Workspaces Category -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.workspaces') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.workspaces') ? 'active' : '' }}">
                    📁 Workspaces
                </a>
            </div>

            <!-- Agents Category -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.agents') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.agents') ? 'active' : '' }}">
                    🤖 Agents
                </a>
                <div class="bg-gray-900">
                    <a href="{{ route('config.agents.create') }}"
                       @click="showMobileDrawer = false"
                       class="file-item w-full text-sm text-blue-400 hover:text-blue-300 block">
                        + New Agent
                    </a>
                    @if(isset($agents))
                        @foreach($agents as $agent)
                            <a href="{{ route('config.agents.edit', $agent['filename']) }}"
                               @click="showMobileDrawer = false"
                               class="file-item w-full text-sm block {{ isset($activeAgent) && $activeAgent == $agent['filename'] ? 'active' : '' }}">
                                {{ $agent['name'] }}
                            </a>
                        @endforeach
                    @endif
                </div>
            </div>

            <!-- Commands Category -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.commands') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.commands') ? 'active' : '' }}">
                    ⚡ Commands
                </a>
                <div class="bg-gray-900">
                    <a href="{{ route('config.commands.create') }}"
                       @click="showMobileDrawer = false"
                       class="file-item w-full text-sm text-blue-400 hover:text-blue-300 block">
                        + New Command
                    </a>
                    @if(isset($commands))
                        @foreach($commands as $command)
                            <a href="{{ route('config.commands.edit', $command['filename']) }}"
                               @click="showMobileDrawer = false"
                               class="file-item w-full text-sm block {{ isset($activeCommand) && $activeCommand == $command['filename'] ? 'active' : '' }}">
                                {{ $command['name'] }}
                            </a>
                        @endforeach
                    @endif
                </div>
            </div>

            <!-- Hooks Category -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.hooks') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Route::currentRouteName() == 'config.hooks' ? 'active' : '' }}">
                    🪝 Hooks
                </a>
            </div>

            <!-- Tools Category -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.tools') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Str::startsWith(Route::currentRouteName(), 'config.tools') ? 'active' : '' }}">
                    🛠️ Tools
                </a>
            </div>

            <!-- Memory -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.memory') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Route::currentRouteName() == 'config.memory' ? 'active' : '' }}">
                    🧠 Memory
                </a>
            </div>

            <!-- Providers (AI API Keys) -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.credentials') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Route::currentRouteName() == 'config.credentials' ? 'active' : '' }}">
                    🔑 Providers
                </a>
            </div>

            <!-- Environment -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.environment') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Route::currentRouteName() == 'config.environment' ? 'active' : '' }}">
                    🌐 Environment
                </a>
            </div>

            <!-- Backup & Restore -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.backup') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Route::currentRouteName() == 'config.backup' ? 'active' : '' }}">
                    💾 Backup
                </a>
            </div>

            @if(app()->environment('local'))
            <!-- Developer Tools (Local Only) -->
            <div class="border-b border-gray-700">
                <a href="{{ route('config.developer') }}"
                   @click="showMobileDrawer = false"
                   class="category-button w-full block {{ Route::currentRouteName() == 'config.developer' ? 'active' : '' }}">
                    🔧 Developer
                </a>
            </div>
            @endif

            <!-- Footer with Back to Chat -->
            <div class="p-4 border-t border-gray-700 mt-auto">
                <a href="{{ $backToChatUrl }}" class="text-blue-400 hover:text-blue-300 text-sm"
                   onclick="localStorage.setItem('pocketdev_returning_from_settings', 'true')">
                    ← Back to Chat
                </a>
            </div>
        </div>
    </div>

@stack('scripts')
</body>
</html>
