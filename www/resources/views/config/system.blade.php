@extends('layouts.config')

@section('title', 'System')

@section('content')
<div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-6">System</h1>

    {{-- Version Info Section --}}
    <div class="bg-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4">Version</h2>

        @if($version['mode'] === 'local')
            {{-- Local Development Mode --}}
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-gray-400">Mode:</span>
                    <span class="bg-blue-600/20 text-blue-400 px-2 py-0.5 rounded text-sm">Local Development</span>
                </div>

                @if($version['branch'])
                <div class="flex items-center gap-2">
                    <span class="text-gray-400">Branch:</span>
                    <code class="bg-gray-900 px-2 py-1 rounded">{{ $version['branch'] }}</code>
                    @if($version['has_changes'] ?? false)
                        <span class="text-yellow-400 text-sm">(uncommitted changes)</span>
                    @endif
                </div>
                @endif

                @if($version['commit'])
                <div class="flex items-center gap-2">
                    <span class="text-gray-400">Commit:</span>
                    <code class="bg-gray-900 px-2 py-1 rounded">{{ $version['commit'] }}</code>
                </div>
                @endif

                @if(isset($version['error']))
                <div class="text-red-400 text-sm">{{ $version['error'] }}</div>
                @endif
            </div>

            {{-- Update Check for Local --}}
            @if($updateInfo)
                @if($updateInfo['is_feature_branch'] ?? false)
                    <div class="mt-4 p-3 bg-yellow-900/30 border border-yellow-600/30 rounded">
                        <p class="text-yellow-400 text-sm">
                            On feature branch <code class="bg-gray-900 px-1 rounded">{{ $version['branch'] }}</code>.
                            Switch to main to check for updates.
                        </p>
                    </div>
                @elseif(($updateInfo['commits_behind'] ?? 0) > 0)
                    <div class="mt-4 p-3 bg-blue-900/30 border border-blue-600/30 rounded">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-blue-400 font-medium">
                                    {{ $updateInfo['commits_behind'] }} commit{{ $updateInfo['commits_behind'] > 1 ? 's' : '' }} behind main
                                </p>
                                @if($updateInfo['can_auto_update'] ?? false)
                                    <p class="text-gray-400 text-sm mt-1">Ready to update</p>
                                @else
                                    <p class="text-yellow-400 text-sm mt-1">Uncommitted changes - commit or stash first</p>
                                @endif
                            </div>
                            @if($updateInfo['can_auto_update'] ?? false)
                                <form method="POST" action="{{ route('config.system.pull-main') }}" class="inline">
                                    @csrf
                                    <x-button type="submit" variant="primary" size="sm">
                                        Pull from Main
                                    </x-button>
                                </form>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="mt-4 p-3 bg-green-900/30 border border-green-600/30 rounded">
                        <p class="text-green-400 text-sm">Up to date with main</p>
                    </div>
                @endif
            @endif

        @else
            {{-- Production Mode --}}
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-gray-400">Mode:</span>
                    <span class="bg-green-600/20 text-green-400 px-2 py-0.5 rounded text-sm">Production</span>
                </div>

                @if($version['tag'])
                <div class="flex items-center gap-2">
                    <span class="text-gray-400">Version:</span>
                    <code class="bg-gray-900 px-2 py-1 rounded">{{ $version['tag'] }}</code>
                    @if($version['prerelease'] ?? false)
                        <span class="bg-yellow-600/20 text-yellow-400 px-2 py-0.5 rounded text-xs">Pre-release</span>
                    @endif
                </div>
                @endif

                @if($version['build_date'])
                <div class="flex items-center gap-2">
                    <span class="text-gray-400">Built:</span>
                    <span>{{ \Carbon\Carbon::parse($version['build_date'])->format('M j, Y H:i') }} UTC</span>
                </div>
                @endif

                @if(isset($version['error']))
                <div class="text-yellow-400 text-sm">{{ $version['error'] }}</div>
                @endif
            </div>

            {{-- Update Check for Production --}}
            @if($updateInfo && ($updateInfo['update_available'] ?? false))
                <div class="mt-4 p-3 bg-blue-900/30 border border-blue-600/30 rounded">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-blue-400 font-medium">
                                Update available: {{ $updateInfo['latest']['tag'] ?? 'new version' }}
                            </p>
                            @if($updateInfo['latest']['published_at'] ?? null)
                                <p class="text-gray-400 text-sm mt-1">
                                    Released {{ \Carbon\Carbon::parse($updateInfo['latest']['published_at'])->diffForHumans() }}
                                </p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('config.system.apply-update') }}" id="apply-update-form">
                            @csrf
                            <x-button type="submit" variant="primary" size="sm">
                                Update Now
                            </x-button>
                        </form>
                    </div>
                    @if($updateInfo['latest']['html_url'] ?? null)
                        <a href="{{ $updateInfo['latest']['html_url'] }}" target="_blank" class="text-blue-400 hover:text-blue-300 text-sm mt-2 inline-block">
                            View release notes &rarr;
                        </a>
                    @endif
                </div>
            @elseif($updateInfo && !($updateInfo['error'] ?? null))
                <div class="mt-4 p-3 bg-green-900/30 border border-green-600/30 rounded">
                    <p class="text-green-400 text-sm">You're on the latest version</p>
                </div>
            @endif
        @endif

        {{-- Branch Switcher (local dev only) --}}
        @if($version['mode'] === 'local' && count($branches) > 0)
        <div class="mt-4 pt-4 border-t border-gray-700">
            <h3 class="text-sm font-medium text-gray-300 mb-2">Switch Branch</h3>
            <form method="POST" action="{{ route('config.system.switch-branch') }}" class="flex gap-2">
                @csrf
                <select name="branch"
                        class="flex-1 bg-gray-900 border border-gray-600 rounded px-3 py-1.5 text-sm text-gray-200 focus:outline-none focus:border-blue-500">
                    @foreach($branches as $b)
                        <option value="{{ $b }}" {{ $b === ($version['branch'] ?? '') ? 'selected' : '' }}>
                            {{ $b }}{{ $b === ($version['branch'] ?? '') ? ' (current)' : '' }}
                        </option>
                    @endforeach
                </select>
                <x-button type="submit" variant="ghost" size="sm"
                          onclick="return confirm('Switch branch? Any uncommitted changes will be stashed.')">
                    Switch
                </x-button>
            </form>
        </div>
        @endif

        {{-- Check for Updates Button --}}
        <div class="mt-4 pt-4 border-t border-gray-700">
            <form method="POST" action="{{ route('config.system.check-update') }}">
                @csrf
                <x-button type="submit" variant="ghost" size="sm">
                    Check for Updates
                </x-button>
            </form>
        </div>
    </div>

    {{-- Active Conversations Warning --}}
    @if($processingCount > 0)
    <div class="bg-yellow-900/50 border border-yellow-600 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-2 text-yellow-400">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span class="font-semibold">{{ $processingCount }} conversation{{ $processingCount > 1 ? 's' : '' }} currently processing</span>
        </div>
        <p class="text-yellow-300/80 text-sm mt-2">
            Restarting containers will interrupt these conversations and may cause them to fail.
        </p>
    </div>
    @endif

    {{-- Restart Containers --}}
    <div class="bg-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold mb-2">Restart Containers</h2>
        <p class="text-gray-400 text-sm mb-4">
            Runs <code class="bg-gray-900 px-2 py-1 rounded">docker compose up -d --force-recreate</code>.
            Use this after changing frontend assets (JS, CSS, Alpine.js) or to install pending system packages.
        </p>

        <form method="POST" action="{{ route('config.system.restart') }}" id="restart-form">
            @csrf
            <x-button type="submit" variant="secondary">
                Restart Containers
            </x-button>
        </form>
    </div>

    {{-- Rebuild Containers (Local Only) --}}
    @if(app()->environment('local'))
    <div class="bg-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold mb-2">Rebuild Containers</h2>
        <p class="text-gray-400 text-sm mb-4">
            Runs <code class="bg-gray-900 px-2 py-1 rounded">docker compose down && docker compose build --no-cache && docker compose up -d --force-recreate</code>.
            Use this when Dockerfiles or entrypoint scripts have changed. Takes longer but rebuilds images from scratch without using cache.
            <span class="text-yellow-400">Data is preserved.</span>
        </p>

        <form method="POST" action="{{ route('config.system.rebuild') }}" id="rebuild-form">
            @csrf
            <x-button type="submit" variant="danger">
                Rebuild Containers
            </x-button>
        </form>
    </div>
    @endif

    <div class="text-gray-500 text-sm">
        @if(app()->environment('local'))
            <p>Running in <code class="bg-gray-900 px-2 py-1 rounded">local</code> mode</p>
        @else
            <p>Running in <code class="bg-gray-900 px-2 py-1 rounded">production</code> mode</p>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script>
document.getElementById('restart-form').addEventListener('submit', function(e) {
    e.preventDefault();

    if (!confirm('Restart all containers? This will briefly interrupt the application.')) {
        return false;
    }

    @if($processingCount > 0)
    if (!confirm('WARNING: {{ $processingCount }} conversation{{ $processingCount > 1 ? "s are" : " is" }} currently processing. Restarting will interrupt {{ $processingCount > 1 ? "them" : "it" }}. Continue anyway?')) {
        return false;
    }
    @endif

    HTMLFormElement.prototype.submit.call(this);
});

@if(app()->environment('local'))
document.getElementById('rebuild-form').addEventListener('submit', function(e) {
    e.preventDefault();

    if (!confirm('Rebuild all containers? This will take longer and rebuild Docker images from scratch.')) {
        return false;
    }

    @if($processingCount > 0)
    if (!confirm('WARNING: {{ $processingCount }} conversation{{ $processingCount > 1 ? "s are" : " is" }} currently processing. Rebuilding will interrupt {{ $processingCount > 1 ? "them" : "it" }}. Continue anyway?')) {
        return false;
    }
    @endif

    HTMLFormElement.prototype.submit.call(this);
});
@endif

const applyUpdateForm = document.getElementById('apply-update-form');
if (applyUpdateForm) {
    applyUpdateForm.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!confirm('Update PocketDev? This will pull the latest images and restart all containers.')) {
            return false;
        }

        @if($processingCount > 0)
        if (!confirm('WARNING: {{ $processingCount }} conversation{{ $processingCount > 1 ? "s are" : " is" }} currently processing. Updating will interrupt {{ $processingCount > 1 ? "them" : "it" }}. Continue anyway?')) {
            return false;
        }
        @endif

        HTMLFormElement.prototype.submit.call(this);
    });
}
</script>
@endpush
