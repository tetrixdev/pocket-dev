@php
    $credentials = \App\Models\Credential::getForWorkspace($workspace->id);
    $globalCredentials = $credentials->whereNull('workspace_id');
    $workspaceCredentials = $credentials->whereNotNull('workspace_id');

    // Get selected packages, or all packages if none selected
    $selectedPackageNames = $workspace->selected_packages;
    $allPackages = \App\Models\SystemPackage::orderBy('name')->get();

    if (empty($selectedPackageNames)) {
        // No selection means all packages are available
        $packages = $allPackages;
        $showingAllPackages = true;
    } else {
        $packages = $allPackages->whereIn('name', $selectedPackageNames);
        $showingAllPackages = false;
    }
@endphp

<div class="space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Credentials Section --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">Credentials</h4>
                <a href="{{ route('config.environment') }}" class="text-xs text-blue-400 hover:text-blue-300">
                    Manage
                </a>
            </div>

            @if($credentials->isEmpty())
                <p class="text-sm text-gray-500">No credentials configured.</p>
            @else
                <div class="space-y-3">
                    {{-- Global Credentials --}}
                    @if($globalCredentials->isNotEmpty())
                        <div>
                            <p class="text-xs text-gray-600 mb-1.5">Global</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($globalCredentials as $credential)
                                    <div class="inline-flex items-center gap-1.5 bg-gray-700 rounded px-2 py-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500" title="Configured"></span>
                                        <code class="text-xs text-green-400">{{ $credential->env_var }}</code>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Workspace-specific Credentials --}}
                    @if($workspaceCredentials->isNotEmpty())
                        <div>
                            <p class="text-xs text-gray-600 mb-1.5">This Workspace</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($workspaceCredentials as $credential)
                                    <div class="inline-flex items-center gap-1.5 bg-gray-700 rounded px-2 py-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500" title="Configured"></span>
                                        <code class="text-xs text-green-400">{{ $credential->env_var }}</code>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Packages Section --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Packages
                    @if($showingAllPackages)
                        <span class="text-gray-600 font-normal">(all)</span>
                    @endif
                </h4>
                <a href="{{ route('config.workspaces.edit', $workspace) }}#packages" class="text-xs text-blue-400 hover:text-blue-300">
                    Configure
                </a>
            </div>

            @if($packages->isEmpty())
                <p class="text-sm text-gray-500">No packages configured.</p>
            @else
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

                            <code class="text-xs @if($package->status === 'installed') text-green-400 @elseif($package->status === 'failed') text-red-400 @else text-yellow-400 @endif">{{ $package->name }}</code>

                            {{-- Tooltip for failed packages --}}
                            @if($package->status === 'failed' && $package->status_message)
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-gray-900 border border-red-700 rounded text-xs text-red-300 whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 pointer-events-none max-w-xs">
                                    {{ Str::limit($package->status_message, 60) }}
                                    <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900"></div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Legend --}}
    <div class="flex flex-wrap gap-4 text-xs text-gray-500 pt-2 border-t border-gray-700">
        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Configured / Installed</span>
        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-yellow-500"></span> Pending</span>
        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Failed</span>
    </div>
</div>
