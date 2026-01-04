@extends('layouts.config')

@section('title', 'Backup & Restore')

@section('content')
<div class="max-w-3xl" x-data="backupManager()">
    <h1 class="text-2xl font-bold mb-6">Backup & Restore</h1>

    <!-- Create Backup Section -->
    <div class="bg-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold mb-2">Create Backup</h2>
        <p class="text-gray-400 text-sm mb-4">
            Creates a complete backup including the database (via pg_dump) and all Docker volumes
            (workspace, user data, storage, Redis, proxy config). The <code class="bg-gray-900 px-2 py-1 rounded">.env</code> file is excluded for security.
        </p>

        @if($processingCount > 0)
        <div class="bg-yellow-900/50 border border-yellow-600 rounded-lg p-4 mb-4">
            <div class="flex items-center gap-2 text-yellow-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span class="font-semibold">{{ $processingCount }} conversation{{ $processingCount > 1 ? 's' : '' }} currently processing</span>
            </div>
            <p class="text-yellow-300/80 text-sm mt-2">
                Consider waiting for active conversations to complete for a consistent backup.
            </p>
        </div>
        @endif

        <form method="POST" action="{{ route('config.backup.create') }}" id="create-backup-form">
            @csrf
            <x-button type="submit" variant="primary">
                Create New Backup
            </x-button>
        </form>
    </div>

    <!-- Existing Backups Section -->
    <div class="bg-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4">Available Backups</h2>

        @if(empty($backups))
        <p class="text-gray-500 text-sm">No backups available. Create one using the button above.</p>
        @else
        <div class="space-y-3">
            @foreach($backups as $backup)
            <div class="flex items-center justify-between bg-gray-900 rounded-lg p-4">
                <div>
                    <div class="font-mono text-sm">{{ $backup['filename'] }}</div>
                    <div class="text-gray-500 text-xs mt-1">
                        {{ $backup['created_at'] }} &bull; {{ $backup['size'] }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('config.backup.download', $backup['filename']) }}"
                       class="text-blue-400 hover:text-blue-300 text-sm px-3 py-1 border border-blue-400/50 rounded hover:border-blue-300 download-link">
                        Download
                    </a>
                    <form method="POST" action="{{ route('config.backup.delete', $backup['filename']) }}"
                          onsubmit="return confirm('Delete this backup?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-400 hover:text-red-300 text-sm px-3 py-1 border border-red-400/50 rounded hover:border-red-300">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    <!-- Restore Section -->
    <div class="bg-gray-800 rounded-lg p-6">
        <h2 class="text-lg font-semibold mb-2">Restore from Backup</h2>
        <p class="text-gray-400 text-sm mb-4">
            Upload a backup file to restore the database and all volumes.
            <strong class="text-yellow-400">This will overwrite all current data.</strong>
        </p>

        <!-- Safety Warning -->
        @if(!$hasDownloadedBackup)
        <div class="bg-red-900/50 border border-red-600 rounded-lg p-4 mb-4">
            <div class="flex items-center gap-2 text-red-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span class="font-semibold">Restore Disabled</span>
            </div>
            <p class="text-red-300/80 text-sm mt-2">
                For safety, you must <strong>download a backup</strong> before restoring. This ensures you have a safety copy of your current data. Restore is enabled for 5 minutes after downloading.
            </p>
        </div>
        @else
        <div class="bg-green-900/50 border border-green-600 rounded-lg p-4 mb-4">
            <div class="flex items-center gap-2 text-green-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span class="font-semibold">Restore Enabled (5 min window)</span>
            </div>
            <p class="text-green-300/80 text-sm mt-2">
                You've downloaded a backup. You can restore within the next few minutes.
            </p>
        </div>
        @endif

        <form method="POST" action="{{ route('config.backup.restore') }}" enctype="multipart/form-data" id="restore-form">
            @csrf

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300 mb-2">
                    Backup File (.tar.gz)
                </label>
                <input type="file"
                       name="backup_file"
                       accept=".tar.gz,.gz"
                       class="block w-full text-sm text-gray-400
                              file:mr-4 file:py-2 file:px-4
                              file:rounded file:border-0
                              file:text-sm file:font-semibold
                              file:bg-gray-700 file:text-gray-200
                              hover:file:bg-gray-600
                              cursor-pointer"
                       @if(!$hasDownloadedBackup) disabled @endif
                       required>
            </div>

            <x-button type="submit"
                      variant="danger"
                      :disabled="!$hasDownloadedBackup">
                Restore Backup
            </x-button>
        </form>
    </div>

    <!-- Info Section -->
    <div class="text-gray-500 text-sm mt-6">
        <h3 class="font-semibold text-gray-400 mb-2">What's included in backups:</h3>
        <ul class="list-disc list-inside space-y-1">
            <li>PostgreSQL database (all conversations, settings, memory tables)</li>
            <li>Redis data (queue state, caches)</li>
            <li>Workspace files (projects, code)</li>
            <li>User data (Claude config, SSH keys)</li>
            <li>Proxy configuration</li>
            <li>PocketDev storage files</li>
        </ul>
        <div class="mt-3 p-3 bg-yellow-900/30 border border-yellow-700/50 rounded">
            <p class="text-yellow-500">
                <strong>Not included:</strong> .env file (contains sensitive credentials)
            </p>
            <p class="text-yellow-600/80 mt-1">
                If restoring a backup from another instance, the <code class="bg-gray-800 px-1 rounded">APP_KEY</code> must match or API credentials will need to be re-entered.
            </p>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function backupManager() {
    return {
        init() {
            // Handle download links - trigger download and reload page to update UI
            document.querySelectorAll('.download-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const url = this.href;

                    // Create hidden iframe to trigger download
                    const iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    iframe.src = url;
                    document.body.appendChild(iframe);

                    // Reload page after short delay to show updated restore state
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                });
            });

            // Confirmation for restore
            document.getElementById('restore-form')?.addEventListener('submit', function(e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to restore from this backup? This will OVERWRITE all current data including the database and all volumes.')) {
                    return false;
                }

                if (!confirm('This action cannot be undone. Have you verified you have a working backup of your current data?')) {
                    return false;
                }

                HTMLFormElement.prototype.submit.call(this);
            });
        }
    };
}
</script>
@endpush
