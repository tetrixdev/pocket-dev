{{-- Git Status Panel - with file tree view, branch comparison, and diff viewer --}}
@php
    $expanded = $state['expanded'] ?? [];
    $expandedDirs = $state['expandedDirs'] ?? [];
    $viewingFile = $state['viewingFile'] ?? null;

    // Get git status
    $status = [];
    $branch = 'unknown';
    $ahead = 0;
    $behind = 0;
    $commitsAhead = [];

    if (is_dir($path . '/.git')) {
        // Get current branch
        $branch = trim(shell_exec("cd " . escapeshellarg($path) . " && git branch --show-current 2>/dev/null") ?: 'detached');

        // Get ahead/behind from upstream
        $tracking = shell_exec("cd " . escapeshellarg($path) . " && git rev-list --left-right --count HEAD...@{upstream} 2>/dev/null");
        if ($tracking) {
            list($ahead, $behind) = array_map('intval', preg_split('/\s+/', trim($tracking)));
        }

        if ($compareBranch) {
            // Branch comparison mode - show diff against specified branch
            $diffOutput = shell_exec("cd " . escapeshellarg($path) . " && git diff " . escapeshellarg($compareBranch) . "...HEAD --name-status 2>/dev/null") ?: '';
            foreach (explode("\n", trim($diffOutput)) as $line) {
                if (empty($line)) continue;
                $parts = preg_split('/\t+/', $line, 2);
                if (count($parts) < 2) continue;
                $code = $parts[0];
                $file = $parts[1];
                $status[] = [
                    'code' => $code,
                    'file' => $file,
                    'staged' => true,
                    'unstaged' => false,  // Branch diffs have no unstaged concept
                    'type' => match(substr($code, 0, 1)) {
                        'M' => 'modified',
                        'A' => 'added',
                        'D' => 'deleted',
                        'R' => 'renamed',
                        default => 'other',
                    },
                ];
            }

            // Get commits ahead
            $logOutput = shell_exec("cd " . escapeshellarg($path) . " && git log " . escapeshellarg($compareBranch) . "..HEAD --oneline 2>/dev/null") ?: '';
            foreach (explode("\n", trim($logOutput)) as $line) {
                if (empty($line)) continue;
                $commitsAhead[] = $line;
            }
        } else {
            // Working directory mode - show staged/unstaged
            $statusOutput = shell_exec("cd " . escapeshellarg($path) . " && git status --porcelain 2>/dev/null") ?: '';
            // Use rtrim() not trim() - trim() removes leading space from first line's status code!
            foreach (explode("\n", rtrim($statusOutput)) as $line) {
                if (empty($line)) continue;
                $code = substr($line, 0, 2);
                $file = trim(substr($line, 2));
                $firstChar = $code[0] ?? ' ';
                $secondChar = $code[1] ?? ' ';

                // Staged: first char indicates index change (not space, not ?)
                $isStaged = !in_array($firstChar, [' ', '?']);
                // Unstaged: second char indicates working tree change, or untracked
                // M=modified, T=type changed, A=added, D=deleted, R=renamed, C=copied, U=unmerged
                $isUnstaged = in_array($secondChar, ['M', 'T', 'A', 'D', 'R', 'C', 'U']) || $code === '??';

                $status[] = [
                    'code' => $code,
                    'file' => $file,
                    'staged' => $isStaged,
                    'unstaged' => $isUnstaged,
                    'type' => match(true) {
                        str_contains($code, 'M') => 'modified',
                        str_contains($code, 'A') => 'added',
                        str_contains($code, 'D') => 'deleted',
                        str_contains($code, 'R') => 'renamed',
                        str_contains($code, '?') => 'untracked',
                        default => 'other',
                    },
                ];
            }
        }
    }

    // Files can appear in both staged and unstaged if they have changes in both
    $staged = array_filter($status, fn($s) => $s['staged']);
    $unstaged = array_filter($status, fn($s) => $s['unstaged']);

    // Build flat file list for navigation
    $allFiles = [];
    foreach ($staged as $item) {
        $allFiles[] = ['file' => $item['file'], 'staged' => true, 'type' => $item['type'], 'code' => $item['code']];
    }
    foreach ($unstaged as $item) {
        $allFiles[] = ['file' => $item['file'], 'staged' => false, 'type' => $item['type'], 'code' => $item['code']];
    }

    // Build file tree from flat list
    $buildFileTree = function($files) {
        $tree = [];
        foreach ($files as $item) {
            $parts = explode('/', $item['file']);
            $current = &$tree;
            $pathSoFar = '';

            foreach ($parts as $i => $part) {
                $pathSoFar .= ($pathSoFar ? '/' : '') . $part;
                $isFile = ($i === count($parts) - 1);

                if ($isFile) {
                    $current[] = [
                        'type' => 'file',
                        'name' => $part,
                        'path' => $pathSoFar,
                        'item' => $item,
                    ];
                } else {
                    $found = false;
                    foreach ($current as $k => &$node) {
                        if (($node['type'] ?? '') === 'dir' && $node['name'] === $part) {
                            $current = &$current[$k]['children'];
                            $found = true;
                            break;
                        }
                    }
                    unset($node);

                    if (!$found) {
                        $current[] = [
                            'type' => 'dir',
                            'name' => $part,
                            'path' => $pathSoFar,
                            'children' => [],
                        ];
                        $current = &$current[count($current) - 1]['children'];
                    }
                }
            }
            unset($current);
        }

        // Sort: directories first, then alphabetically
        $sortTree = function(&$tree) use (&$sortTree) {
            usort($tree, function($a, $b) {
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'dir' ? -1 : 1;
                }
                return strcasecmp($a['name'], $b['name']);
            });
            foreach ($tree as &$node) {
                if ($node['type'] === 'dir' && !empty($node['children'])) {
                    $sortTree($node['children']);
                }
            }
        };
        $sortTree($tree);

        return $tree;
    };

    // Count files in tree
    $countFiles = function($tree) use (&$countFiles) {
        $count = 0;
        foreach ($tree as $node) {
            if ($node['type'] === 'dir') {
                $count += $countFiles($node['children']);
            } else {
                $count++;
            }
        }
        return $count;
    };

    // Render tree to HTML - now with clickable files
    $renderTree = function($tree, $depth, $isStaged) use (&$renderTree, &$countFiles) {
        $html = '';
        $indent = $depth * 12;

        foreach ($tree as $node) {
            if ($node['type'] === 'dir') {
                $nodePath = $node['path'];
                // Prefix with section to make keys unique between staged/unstaged
                $sectionPrefix = $isStaged ? 'staged:' : 'unstaged:';
                $expandKey = $sectionPrefix . $nodePath;
                // json_encode for JS escaping, then htmlspecialchars for HTML attribute context
                $escapedExpandKey = htmlspecialchars(json_encode($expandKey), ENT_QUOTES);
                $fileCount = $countFiles($node['children']);
                $html .= '<div>';
                $html .= '<button @click="toggleDir(' . $escapedExpandKey . ')" ';
                $html .= 'class="flex items-center gap-1.5 w-full text-left py-0.5 px-1 md:px-2 hover:bg-gray-800 rounded text-sm" ';
                $html .= 'style="padding-left: ' . ($indent + 4) . 'px">';
                $html .= '<i class="fa-solid text-xs transition-transform text-gray-500" ';
                $html .= ':class="isDirExpanded(' . $escapedExpandKey . ') ? \'fa-chevron-down\' : \'fa-chevron-right\'"></i>';
                $html .= '<i class="fa-solid fa-folder text-yellow-600 text-xs"></i>';
                $html .= '<span class="text-gray-300">' . htmlspecialchars($node['name']) . '</span>';
                $html .= '<span class="text-xs text-gray-600 ml-1">(' . $fileCount . ')</span>';
                $html .= '</button>';
                $html .= '<div x-show="isDirExpanded(' . $escapedExpandKey . ')" x-collapse>';
                $html .= $renderTree($node['children'], $depth + 1, $isStaged);
                $html .= '</div>';
                $html .= '</div>';
            } else {
                $item = $node['item'];
                $typeClass = match($item['type']) {
                    'added' => 'text-green-400',
                    'modified' => 'text-yellow-400',
                    'deleted' => 'text-red-400',
                    'untracked' => 'text-gray-400',
                    default => 'text-gray-400',
                };
                // json_encode for JS escaping, then htmlspecialchars for HTML attribute context
                $escapedFile = htmlspecialchars(json_encode($item['file']), ENT_QUOTES);
                $stagedJs = $isStaged ? 'true' : 'false';
                $html .= '<button @click="viewFileDiff(' . $escapedFile . ', ' . $stagedJs . ')" ';
                $html .= 'class="flex items-center gap-1.5 py-0.5 px-1 md:px-2 text-sm hover:bg-gray-800 rounded w-full text-left cursor-pointer group" ';
                $html .= 'style="padding-left: ' . ($indent + 4) . 'px">';
                $html .= '<span class="w-5 text-center font-mono text-xs shrink-0 ' . $typeClass . '">';
                $html .= htmlspecialchars(trim($item['code']));
                $html .= '</span>';
                $html .= '<span class="truncate text-gray-200 group-hover:text-white">' . htmlspecialchars($node['name']) . '</span>';
                $html .= '<i class="fa-solid fa-chevron-right text-gray-600 text-xs ml-auto opacity-0 group-hover:opacity-100 transition-opacity"></i>';
                $html .= '</button>';
            }
        }

        return $html;
    };

    $stagedTree = $buildFileTree(array_values($staged));
    $unstagedTree = $buildFileTree(array_values($unstaged));
    $stagedTreeHtml = $renderTree($stagedTree, 0, true);
    $unstagedTreeHtml = $renderTree($unstagedTree, 0, false);
@endphp

<div class="h-full bg-gray-900 text-gray-200 overflow-hidden flex flex-col"
     x-data="{
         expanded: @js($expanded),
         expandedDirs: @js($expandedDirs),
         panelStateId: @js($panelStateId),
         syncTimeout: null,

         // Diff view state
         viewingFile: @js($viewingFile),
         diffHtml: '',
         diffStats: { additions: 0, deletions: 0 },
         diffLoading: false,
         diffError: null,
         diffDebug: null,
         diffAbortController: null,

         // File navigation
         allFiles: @js($allFiles),
         currentFileIndex: 0,

         // Path and branch info for diff commands
         repoPath: @js($path),
         compareBranch: @js($compareBranch),

         // Restore diff view if viewingFile was persisted
         init() {
             // Use a global tracker keyed by panelStateId to detect re-inits
             window._panelInitTracker = window._panelInitTracker || {};
             const tracker = window._panelInitTracker[this.panelStateId] || { count: 0, lastInit: 0 };
             const now = Date.now();
             const timeSinceLastInit = now - tracker.lastInit;
             tracker.count++;
             tracker.lastInit = now;
             window._panelInitTracker[this.panelStateId] = tracker;

             // If this is a rapid re-init (within 1 second), skip the viewFileDiff
             // This happens when Alpine re-parses the x-html content
             if (tracker.count > 1 && timeSinceLastInit < 1000) {
                 return;
             }

             if (this.viewingFile) {
                 // Restore diff view - skipSync=true to avoid triggering re-renders
                 this.viewFileDiff(this.viewingFile.file, this.viewingFile.isStaged, true);
             }
         },

         toggleDir(path) {
             const idx = this.expandedDirs.indexOf(path);
             if (idx === -1) {
                 this.expandedDirs.push(path);
             } else {
                 this.expandedDirs.splice(idx, 1);
             }
             this.syncState();
         },

         isDirExpanded(path) {
             return this.expandedDirs.includes(path);
         },

         async viewFileDiff(file, isStaged, skipSync = false) {
             // Cancel any previous in-flight fetch
             if (this.diffAbortController) {
                 this.diffAbortController.abort();
             }
             this.diffAbortController = new AbortController();

             this.diffLoading = true;
             this.diffError = null;
             this.viewingFile = { file, isStaged };
             this._skipSync = skipSync;

             // Find current index
             this.currentFileIndex = this.allFiles.findIndex(f => f.file === file);

             try {
                 const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'Accept': 'application/json',
                     },
                     signal: this.diffAbortController.signal,
                     body: JSON.stringify({
                         action: 'getDiff',
                         params: {
                             file,
                             isStaged,
                             repoPath: this.repoPath,
                             compareBranch: this.compareBranch
                         }
                     })
                 });
                 if (!response.ok) {
                     throw new Error(`Server error: ${response.status}`);
                 }
                 const result = await response.json();
                 if (result.ok) {
                     this.diffHtml = result.html || '';
                     this.diffStats = result.data?.stats || { additions: 0, deletions: 0 };
                     this.diffDebug = result.data?.debug || null;
                 } else {
                     this.diffError = result.error || 'Failed to load diff';
                 }
             } catch (e) {
                 if (e.name === 'AbortError') {
                     return;
                 }
                 this.diffError = 'Network error: ' + e.message;
             }
             this.diffLoading = false;
             if (!this._skipSync) {
                 this.syncState(true);
             }
             this._skipSync = false;
         },

         goBack() {
             // Cancel any in-flight fetch
             if (this.diffAbortController) {
                 this.diffAbortController.abort();
                 this.diffAbortController = null;
             }
             this.viewingFile = null;
             this.diffHtml = '';
             this.diffStats = { additions: 0, deletions: 0 };
             this.diffError = null;
             this.diffDebug = null;
             this.diffLoading = false;
             this.syncState(true);
         },

         prevFile() {
             if (this.currentFileIndex > 0) {
                 const prev = this.allFiles[this.currentFileIndex - 1];
                 this.viewFileDiff(prev.file, prev.staged);
             }
         },

         nextFile() {
             if (this.currentFileIndex < this.allFiles.length - 1) {
                 const next = this.allFiles[this.currentFileIndex + 1];
                 this.viewFileDiff(next.file, next.staged);
             }
         },

         copyPath() {
             if (this.viewingFile) {
                 navigator.clipboard.writeText(this.viewingFile.file);
             }
         },

         syncState(immediate = false) {
             if (this.syncTimeout) clearTimeout(this.syncTimeout);

             const doSync = () => {
                 if (!this.panelStateId) return;
                 fetch(`/api/panel/${this.panelStateId}/state`, {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/json' },
                     body: JSON.stringify({
                         state: {
                             expanded: this.expanded,
                             expandedDirs: this.expandedDirs,
                             viewingFile: this.viewingFile
                         },
                         merge: true
                     })
                 }).catch(console.error);
             };

             if (immediate) {
                 doSync();
             } else {
                 this.syncTimeout = setTimeout(doSync, 300);
             }
         }
     }">

    {{-- File Tree View --}}
    <div x-show="!viewingFile" class="h-full flex flex-col">
        {{-- Sticky Header --}}
        <div class="flex-none flex items-center gap-2 p-2 md:p-4 pb-2 md:pb-2 border-b border-gray-700 bg-gray-900">
            <i class="fa-brands fa-git-alt text-orange-500"></i>
            <span class="font-medium text-sm truncate">{{ $branch }}</span>
            @if($compareBranch)
                <span class="text-xs text-gray-400">vs {{ $compareBranch }}</span>
            @endif
            @if($ahead > 0)
                <span class="text-xs bg-green-600 px-1.5 py-0.5 rounded">↑{{ $ahead }}</span>
            @endif
            @if($behind > 0)
                <span class="text-xs bg-yellow-600 px-1.5 py-0.5 rounded">↓{{ $behind }}</span>
            @endif
            <span class="ml-auto text-xs text-gray-500 truncate max-w-[80px] md:max-w-[100px]">{{ basename($path) }}</span>
        </div>

        {{-- Scrollable Content --}}
        <div class="flex-1 overflow-auto p-2 md:p-4 pt-2 md:pt-3">
        @if(empty($status))
            <div class="text-center text-gray-500 py-8">
                <i class="fa-solid fa-check-circle text-2xl text-green-500 mb-2"></i>
                <p>{{ $compareBranch ? 'No changes from ' . $compareBranch : 'Working tree clean' }}</p>
            </div>
        @else
            {{-- Commits Ahead (branch comparison mode) --}}
            @if($compareBranch && count($commitsAhead) > 0)
                <div class="mb-3 md:mb-4">
                    <button @click="expanded.commits = !expanded.commits"
                            class="flex items-center gap-2 w-full text-left py-1 hover:bg-gray-800 rounded px-1 md:px-2">
                        <i class="fa-solid text-xs transition-transform"
                           :class="expanded.commits ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        <span class="text-blue-400 font-medium text-sm">Commits Ahead</span>
                        <span class="text-xs text-gray-500">({{ count($commitsAhead) }})</span>
                    </button>
                    <div x-show="expanded.commits !== false" x-collapse class="ml-2 md:ml-4 mt-1 space-y-0.5">
                        @foreach($commitsAhead as $commit)
                            <div class="flex items-center gap-2 py-1 px-1 md:px-2 text-sm hover:bg-gray-800 rounded">
                                <i class="fa-solid fa-code-commit text-blue-400 text-xs"></i>
                                <span class="truncate text-gray-300">{{ $commit }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Staged Changes / Changed Files --}}
            @if(count($staged) > 0)
                <div class="mb-3 md:mb-4">
                    <button @click="expanded.staged = !expanded.staged"
                            class="flex items-center gap-2 w-full text-left py-1 hover:bg-gray-800 rounded px-1 md:px-2">
                        <i class="fa-solid text-xs transition-transform"
                           :class="expanded.staged ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        <span class="text-green-400 font-medium text-sm">{{ $compareBranch ? 'Changed Files' : 'Staged Changes' }}</span>
                        <span class="text-xs text-gray-500">({{ count($staged) }})</span>
                    </button>
                    <div x-show="expanded.staged !== false" x-collapse class="ml-1 md:ml-2 mt-1">
                        {!! $stagedTreeHtml !!}
                    </div>
                </div>
            @endif

            {{-- Unstaged Changes --}}
            @if(count($unstaged) > 0 && !$compareBranch)
                <div>
                    <button @click="expanded.unstaged = !expanded.unstaged"
                            class="flex items-center gap-2 w-full text-left py-1 hover:bg-gray-800 rounded px-1 md:px-2">
                        <i class="fa-solid text-xs transition-transform"
                           :class="expanded.unstaged ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        <span class="text-yellow-400 font-medium text-sm">Unstaged Changes</span>
                        <span class="text-xs text-gray-500">({{ count($unstaged) }})</span>
                    </button>
                    <div x-show="expanded.unstaged !== false" x-collapse class="ml-1 md:ml-2 mt-1">
                        {!! $unstagedTreeHtml !!}
                    </div>
                </div>
            @endif
        @endif
        </div>
    </div>

    {{-- Diff View --}}
    <div x-show="viewingFile" x-cloak class="h-full flex flex-col">
        {{-- Diff Header --}}
        <div class="flex-none p-2 md:p-3 border-b border-gray-700 bg-gray-800/50">
            <div class="flex items-center gap-2">
                {{-- Back button --}}
                <button @click="goBack()"
                        class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors"
                        title="Back to file list">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </button>

                {{-- File path --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-200 truncate" x-text="viewingFile?.file"></span>
                        <button @click="copyPath()"
                                class="p-1 hover:bg-gray-700 rounded text-gray-500 hover:text-white transition-colors"
                                title="Copy path">
                            <i class="fa-solid fa-copy text-xs"></i>
                        </button>
                    </div>
                    {{-- Stats --}}
                    <div class="flex items-center gap-3 mt-0.5">
                        <span class="text-xs text-green-400">
                            <i class="fa-solid fa-plus text-[10px]"></i>
                            <span x-text="diffStats.additions"></span>
                        </span>
                        <span class="text-xs text-red-400">
                            <i class="fa-solid fa-minus text-[10px]"></i>
                            <span x-text="diffStats.deletions"></span>
                        </span>
                        <span class="text-xs text-gray-500" x-show="allFiles.length > 1">
                            <span x-text="currentFileIndex + 1"></span>/<span x-text="allFiles.length"></span>
                        </span>
                    </div>
                </div>

                {{-- Prev/Next navigation --}}
                <div class="flex items-center gap-1" x-show="allFiles.length > 1">
                    <button @click="prevFile()"
                            :disabled="currentFileIndex === 0"
                            class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                            title="Previous file">
                        <i class="fa-solid fa-chevron-up text-sm"></i>
                    </button>
                    <button @click="nextFile()"
                            :disabled="currentFileIndex >= allFiles.length - 1"
                            class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                            title="Next file">
                        <i class="fa-solid fa-chevron-down text-sm"></i>
                    </button>
                </div>
            </div>
        </div>

        {{-- Diff Content --}}
        <div class="flex-1 overflow-auto">
            {{-- Loading --}}
            <div x-show="diffLoading" class="flex items-center justify-center h-full">
                <x-spinner class="text-gray-500 text-2xl" />
            </div>

            {{-- Error --}}
            <div x-show="diffError && !diffLoading" class="p-4 text-center">
                <i class="fa-solid fa-exclamation-triangle text-yellow-500 text-2xl mb-2"></i>
                <p class="text-gray-400 text-sm" x-text="diffError"></p>
            </div>

            {{-- Diff HTML --}}
            <div x-show="!diffLoading && !diffError"
                 x-html="diffHtml"
                 class="font-mono text-xs leading-5">
            </div>
        </div>
    </div>
</div>
