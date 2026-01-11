@extends('layouts.config')

@section('title', 'Developer Tools')

@section('content')
<div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-6">Developer Tools</h1>

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

    <div class="bg-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold mb-2">Restart Containers</h2>
        <p class="text-gray-400 text-sm mb-4">
            Runs <code class="bg-gray-900 px-2 py-1 rounded">docker compose up -d --force-recreate</code>.
            Use this after changing frontend assets (JS, CSS, Alpine.js) or to install pending system packages.
        </p>

        <form method="POST" action="{{ route('config.developer.force-recreate') }}" id="force-recreate-form">
            @csrf
            <x-button type="submit" variant="secondary">
                Restart Containers
            </x-button>
        </form>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold mb-2">Rebuild Containers</h2>
        <p class="text-gray-400 text-sm mb-4">
            Runs <code class="bg-gray-900 px-2 py-1 rounded">docker compose down && docker compose build --no-cache && docker compose up -d --force-recreate</code>.
            Use this when Dockerfiles or entrypoint scripts have changed. Takes longer but rebuilds images from scratch without using cache.
            <span class="text-yellow-400">Data is preserved.</span>
        </p>

        <form method="POST" action="{{ route('config.developer.rebuild') }}" id="rebuild-form">
            @csrf
            <x-button type="submit" variant="danger">
                Rebuild Containers
            </x-button>
        </form>
    </div>

    <div class="text-gray-500 text-sm">
        <p>This page is only available when <code class="bg-gray-900 px-2 py-1 rounded">APP_ENV=local</code></p>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.getElementById('force-recreate-form').addEventListener('submit', function(e) {
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
</script>
@endpush
