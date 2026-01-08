@extends('layouts.config')

@section('title', 'Environment')

@section('content')
<div x-data="{
    showAddCredentialModal: false,
    showEditCredentialModal: false,
    editingCredential: null,

    openEditModal(credential) {
        this.editingCredential = credential;
        this.showEditCredentialModal = true;
    }
}" class="space-y-8">

    {{-- Custom Credentials Section --}}
    <section>
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-200">Custom Credentials</h2>
                <p class="text-sm text-gray-400 mt-1">Environment variables for CLI tools and external services.</p>
            </div>
            <x-button variant="primary" size="sm" @click="showAddCredentialModal = true">
                + Add Credential
            </x-button>
        </div>

        @if($credentials->isEmpty())
            <div class="bg-gray-800 rounded-lg p-6 text-center">
                <p class="text-gray-400">No custom credentials configured.</p>
                <p class="text-sm text-gray-500 mt-2">Add credentials to make them available as environment variables for tools and CLI commands.</p>
            </div>
        @else
            {{-- Mobile: Card layout --}}
            <div class="sm:hidden space-y-3">
                @foreach($credentials as $credential)
                    <div class="bg-gray-800 rounded-lg p-4">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-green-500 shrink-0" title="Configured"></span>
                                <code class="text-sm text-green-400 bg-gray-900 px-2 py-1 rounded break-all">{{ $credential->env_var }}</code>
                            </div>
                            @if($credential->workspace)
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-900/50 text-purple-300 shrink-0">
                                    {{ $credential->workspace->name }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-700 text-gray-300 shrink-0">
                                    Global
                                </span>
                            @endif
                        </div>
                        @if($credential->description)
                            <p class="text-sm text-gray-400 mb-3">{{ Str::limit($credential->description, 80) }}</p>
                        @endif
                        <div class="flex items-center gap-3 pt-2 border-t border-gray-700">
                            <button
                                @click="openEditModal({
                                    id: '{{ $credential->id }}',
                                    env_var: '{{ $credential->env_var }}',
                                    description: @js($credential->description ?? ''),
                                    workspace_id: '{{ $credential->workspace_id ?? '' }}'
                                })"
                                class="text-blue-400 hover:text-blue-300 text-sm"
                            >
                                Edit
                            </button>
                            <form
                                method="POST"
                                action="{{ route('config.environment.credentials.destroy', $credential) }}"
                                class="inline"
                                onsubmit="return confirm('Delete credential {{ $credential->env_var }}?')"
                            >
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-400 hover:text-red-300 text-sm">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: Table layout --}}
            <div class="hidden sm:block bg-gray-800 rounded-lg overflow-hidden">
                <table class="w-full">
                    <thead class="bg-gray-750">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-400">Env Variable</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-400">Scope</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-400 hidden lg:table-cell">Description</th>
                            <th class="px-4 py-3 text-right text-sm font-medium text-gray-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        @foreach($credentials as $credential)
                            <tr class="hover:bg-gray-750">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-green-500 shrink-0" title="Configured"></span>
                                        <code class="text-sm text-green-400 bg-gray-900 px-2 py-1 rounded">{{ $credential->env_var }}</code>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($credential->workspace)
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-900/50 text-purple-300">
                                            {{ $credential->workspace->name }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-700 text-gray-300">
                                            Global
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-400 hidden lg:table-cell">
                                    {{ Str::limit($credential->description, 40) ?: '-' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            @click="openEditModal({
                                                id: '{{ $credential->id }}',
                                                env_var: '{{ $credential->env_var }}',
                                                description: @js($credential->description ?? ''),
                                                workspace_id: '{{ $credential->workspace_id ?? '' }}'
                                            })"
                                            class="text-blue-400 hover:text-blue-300 text-sm"
                                        >
                                            Edit
                                        </button>
                                        <form
                                            method="POST"
                                            action="{{ route('config.environment.credentials.destroy', $credential) }}"
                                            class="inline"
                                            onsubmit="return confirm('Delete credential {{ $credential->env_var }}?')"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-400 hover:text-red-300 text-sm">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- System Packages Section --}}
    <section>
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-200">System Packages</h2>
                <p class="text-sm text-gray-400 mt-1">CLI tools and libraries installed in the container. Packages are managed by AI via commands; you can remove them here.</p>
            </div>
        </div>

        <div class="bg-gray-800 rounded-lg p-4">
            {{-- Package List --}}
            @if($packages->isEmpty())
                <div class="text-center py-4">
                    <p class="text-gray-400 text-sm">No system packages configured.</p>
                    <p class="text-gray-500 text-xs mt-1">Ask the AI to install packages using <code class="bg-gray-700 px-1 rounded">system:package add</code>.</p>
                </div>
            @else
                <div>
                    <h3 class="text-sm font-medium text-gray-400 mb-3">Installed Packages ({{ $packages->count() }})</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach($packages as $package)
                            <div class="inline-flex items-center gap-1.5 bg-gray-700 rounded px-2 py-1 group relative">
                                {{-- Status indicator dot --}}
                                @if($package->status === 'installed')
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500" title="Installed"></span>
                                @elseif($package->status === 'failed')
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-500" title="Installation failed"></span>
                                @else
                                    <span class="w-1.5 h-1.5 rounded-full bg-yellow-500" title="Pending installation"></span>
                                @endif

                                {{-- Package name with status-based color --}}
                                <code class="text-xs @if($package->status === 'installed') text-green-400 @elseif($package->status === 'failed') text-red-400 @else text-yellow-400 @endif">{{ $package->name }}</code>

                                {{-- Tooltip for failed packages --}}
                                @if($package->status === 'failed' && $package->status_message)
                                    <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-gray-900 border border-red-700 rounded text-xs text-red-300 whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 pointer-events-none max-w-xs">
                                        {{ Str::limit($package->status_message, 60) }}
                                        <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900"></div>
                                    </div>
                                @endif

                                {{-- Installed timestamp tooltip --}}
                                @if($package->status === 'installed' && $package->installed_at)
                                    <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-gray-900 border border-gray-600 rounded text-xs text-gray-300 whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 pointer-events-none">
                                        Installed {{ $package->installed_at->diffForHumans() }}
                                        <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900"></div>
                                    </div>
                                @endif

                                <form
                                    method="POST"
                                    action="{{ route('config.environment.packages.destroy', $package->id) }}"
                                    class="inline"
                                    onsubmit="return confirm('Remove {{ $package->name }} from the packages list?')"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-gray-400 hover:text-red-400 ml-0.5" title="Remove from list">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>

                    {{-- Legend --}}
                    <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-500">
                        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Installed</span>
                        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-yellow-500"></span> Pending</span>
                        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Failed</span>
                    </div>

                    <p class="text-xs text-gray-500 mt-3">
                        Removing a package from this list will not uninstall it from the current container, but it won't be installed in new containers.
                    </p>
                </div>
            @endif
        </div>
    </section>

    {{-- Add Credential Modal --}}
    <x-modal show="showAddCredentialModal" title="Add Credential" max-width="lg">
        <form method="POST" action="{{ route('config.environment.credentials.store') }}">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Environment Variable Name</label>
                    <input
                        type="text"
                        name="env_var"
                        placeholder="e.g., GITHUB_TOKEN"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none font-mono"
                        required
                        pattern="[A-Z][A-Z0-9_]*"
                    >
                    <p class="text-xs text-gray-500 mt-1">Must start with an uppercase letter, then uppercase letters, numbers, or underscores (e.g., GITHUB_TOKEN, AWS_ACCESS_KEY_ID).</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Value</label>
                    <input
                        type="password"
                        name="value"
                        placeholder="Enter secret value"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none font-mono"
                        required
                    >
                    <p class="text-xs text-gray-500 mt-1">The secret value. This will be encrypted at rest.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Scope</label>
                    <select
                        name="workspace_id"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none"
                    >
                        <option value="">Global (available to all workspaces)</option>
                        @foreach($workspaces as $workspace)
                            <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Global credentials are available everywhere. Workspace credentials override global ones with the same env var.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Description <span class="text-gray-500">(optional)</span></label>
                    <input
                        type="text"
                        name="description"
                        placeholder="e.g., Personal access token for GitHub CLI"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <x-button type="button" variant="secondary" @click="showAddCredentialModal = false">
                    Cancel
                </x-button>
                <x-button type="submit" variant="primary">
                    Create Credential
                </x-button>
            </div>
        </form>
    </x-modal>

    {{-- Edit Credential Modal --}}
    <x-modal show="showEditCredentialModal" title="Edit Credential" max-width="lg">
        <form method="POST" x-bind:action="'/config/environment/credentials/' + (editingCredential?.id || '')">
            @csrf
            @method('PUT')
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Environment Variable Name</label>
                    <input
                        type="text"
                        name="env_var"
                        x-model="editingCredential?.env_var"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none font-mono"
                        required
                        pattern="[A-Z][A-Z0-9_]*"
                    >
                    <p class="text-xs text-gray-500 mt-1">Must start with an uppercase letter, then uppercase letters, numbers, or underscores (e.g., GITHUB_TOKEN, AWS_ACCESS_KEY_ID).</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Value</label>
                    <input
                        type="password"
                        name="value"
                        placeholder="Enter new value to change (leave empty to keep current)"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none font-mono"
                    >
                    <p class="text-xs text-gray-500 mt-1">Leave empty to keep the current value.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Scope</label>
                    <select
                        name="workspace_id"
                        x-model="editingCredential?.workspace_id"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none"
                    >
                        <option value="">Global (available to all workspaces)</option>
                        @foreach($workspaces as $workspace)
                            <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Global credentials are available everywhere. Workspace credentials override global ones.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Description <span class="text-gray-500">(optional)</span></label>
                    <input
                        type="text"
                        name="description"
                        x-model="editingCredential?.description"
                        placeholder="e.g., Personal access token for GitHub CLI"
                        class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <x-button type="button" variant="secondary" @click="showEditCredentialModal = false; editingCredential = null;">
                    Cancel
                </x-button>
                <x-button type="submit" variant="primary">
                    Update Credential
                </x-button>
            </div>
        </form>
    </x-modal>
</div>
@endsection
