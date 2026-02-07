<?php

namespace App\Panels;

use Illuminate\Support\Facades\Process;

class GitStatusPanel extends Panel
{
    public string $slug = 'git-status';
    public string $name = 'Git Status';
    public string $description = 'View git repository status with file tree and diff viewer';
    public string $icon = 'fa-brands fa-git-alt';

    public array $parameters = [
        'path' => [
            'type' => 'string',
            'description' => 'Repository path',
            'default' => '/workspace/default',
        ],
        'compare_branch' => [
            'type' => 'string',
            'description' => 'Branch to compare against (optional)',
            'default' => null,
        ],
    ];

    public function render(array $params, array $state, ?string $panelStateId = null): string
    {
        $path = $params['path'] ?? '/workspace/default';
        $compareBranch = $params['compare_branch'] ?? null;

        return view('panels.git-status', [
            'path' => $path,
            'compareBranch' => $compareBranch,
            'state' => $state,
            'panelStateId' => $panelStateId,
        ])->render();
    }

    /**
     * Handle panel actions for diff viewing.
     */
    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        if ($action === 'getDiff') {
            // Pass panel params to getDiff for path validation
            return $this->getDiff($params, $panelParams);
        }

        return parent::handleAction($action, $params, $state, $panelParams);
    }

    /**
     * Get diff for a specific file.
     */
    protected function getDiff(array $params, array $panelParams = []): array
    {
        $file = $params['file'] ?? '';
        $isStaged = $params['isStaged'] ?? false;
        // Use panel params for repoPath to prevent path traversal
        // Fall back to request params for backward compatibility, but validate
        $repoPath = $panelParams['path'] ?? $params['repoPath'] ?? '/workspace/default';
        $compareBranch = $panelParams['compare_branch'] ?? $params['compareBranch'] ?? null;

        if (empty($file)) {
            return ['error' => 'No file specified'];
        }

        // Validate repoPath is within allowed directories
        $realRepoPath = realpath($repoPath);
        if ($realRepoPath === false ||
            (!str_starts_with($realRepoPath, '/workspace/') && !str_starts_with($realRepoPath, '/pocketdev-source'))) {
            return ['error' => 'Access denied: invalid repository path'];
        }

        if (!is_dir($repoPath . '/.git')) {
            return ['error' => 'Not a git repository: ' . $repoPath];
        }

        // Build git diff command
        $escapedFile = escapeshellarg($file);
        $escapedPath = escapeshellarg($repoPath);

        if (!empty($compareBranch)) {
            // Branch comparison mode
            $escapedBranch = escapeshellarg($compareBranch);
            $cmd = "cd {$escapedPath} && git diff {$escapedBranch}...HEAD -- {$escapedFile} 2>/dev/null";
        } elseif ($isStaged) {
            // Staged changes
            $cmd = "cd {$escapedPath} && git diff --cached -- {$escapedFile} 2>/dev/null";
        } else {
            // Unstaged changes - check if untracked first
            $statusCmd = "cd {$escapedPath} && git status --porcelain {$escapedFile} 2>/dev/null";
            $statusResult = Process::run($statusCmd);
            $status = trim($statusResult->output());

            if (str_starts_with($status, '??')) {
                // Untracked file - show full content as additions
                $cmd = "cd {$escapedPath} && git diff --no-index /dev/null {$escapedFile} 2>/dev/null || true";
            } else {
                $cmd = "cd {$escapedPath} && git diff -- {$escapedFile} 2>/dev/null";
            }
        }

        $result = Process::run($cmd);
        $diffOutput = $result->output();

        // Parse and format the diff
        $html = $this->formatDiffHtml($diffOutput);
        $stats = $this->countDiffStats($diffOutput);

        return [
            'html' => $html,
            'state' => null,
            'data' => [
                'stats' => $stats,
                'debug' => "repoPath={$repoPath}, file={$file}, isStaged=" . ($isStaged ? 'true' : 'false'),
            ],
            'error' => null,
        ];
    }

    /**
     * Format diff output as HTML.
     */
    protected function formatDiffHtml(string $diffOutput): string
    {
        if (empty(trim($diffOutput))) {
            return '<div class="p-4 text-center text-gray-500">No changes to display</div>';
        }

        $lines = explode("\n", $diffOutput);
        $html = '';
        $oldLine = 0;
        $newLine = 0;
        $hunkNum = 0;
        $inHunk = false;

        foreach ($lines as $line) {
            // Skip header lines
            if (preg_match('/^diff --git/', $line) ||
                preg_match('/^index /', $line) ||
                preg_match('/^--- /', $line) ||
                preg_match('/^\+\+\+ /', $line)) {
                continue;
            }

            // Hunk header
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $matches)) {
                // Close previous hunk's scrollable container
                if ($inHunk) {
                    $html .= '</div></div>'; // Close min-w-max and overflow-x-auto
                }

                $hunkNum++;
                $oldLine = (int) $matches[1];
                $newLine = (int) $matches[2];
                if ($oldLine === 0) $oldLine = 1;
                if ($newLine === 0) $newLine = 1;

                if ($hunkNum > 1) {
                    $html .= '<div class="h-2 bg-gray-800/50"></div>';
                }
                $escaped = e($line);
                $html .= '<div class="flex bg-blue-900/30 text-blue-300 border-y border-gray-700/50">';
                $html .= '<span class="w-20 md:w-24 flex-none px-2 py-1 text-right text-gray-500 select-none">...</span>';
                $html .= '<span class="flex-1 px-2 py-1">' . $escaped . '</span>';
                $html .= '</div>';

                // Open scrollable container for hunk content
                // grid makes children stretch to full width of container
                $html .= '<div class="overflow-x-auto"><div class="grid min-w-max">';
                $inHunk = true;
                continue;
            }

            // Addition - strip the leading +
            if (str_starts_with($line, '+')) {
                $content = substr($line, 1); // Remove the + prefix
                $escaped = e($content);
                $html .= '<div class="flex bg-green-900/20 hover:bg-green-900/30">';
                $html .= '<span class="w-10 md:w-12 flex-none px-1 md:px-2 py-0.5 text-right text-gray-600 select-none border-r border-gray-700/50"></span>';
                $html .= '<span class="w-10 md:w-12 flex-none px-1 md:px-2 py-0.5 text-right text-green-600 select-none border-r border-gray-700/50">' . $newLine . '</span>';
                $html .= '<span class="flex-1 px-2 py-0.5 text-green-300 whitespace-pre">' . $escaped . '</span>';
                $html .= '</div>';
                $newLine++;
                continue;
            }

            // Deletion - strip the leading -
            if (str_starts_with($line, '-')) {
                $content = substr($line, 1); // Remove the - prefix
                $escaped = e($content);
                $html .= '<div class="flex bg-red-900/20 hover:bg-red-900/30">';
                $html .= '<span class="w-10 md:w-12 flex-none px-1 md:px-2 py-0.5 text-right text-red-600 select-none border-r border-gray-700/50">' . $oldLine . '</span>';
                $html .= '<span class="w-10 md:w-12 flex-none px-1 md:px-2 py-0.5 text-right text-gray-600 select-none border-r border-gray-700/50"></span>';
                $html .= '<span class="flex-1 px-2 py-0.5 text-red-300 whitespace-pre">' . $escaped . '</span>';
                $html .= '</div>';
                $oldLine++;
                continue;
            }

            // No newline at end of file
            if (str_starts_with($line, '\\')) {
                $escaped = e($line);
                $html .= '<div class="flex text-gray-500 italic">';
                $html .= '<span class="w-20 md:w-24 flex-none px-2 py-0.5 text-right select-none border-r border-gray-700/50"></span>';
                $html .= '<span class="flex-1 px-2 py-0.5">' . $escaped . '</span>';
                $html .= '</div>';
                continue;
            }

            // Context line - strip the leading space
            if (str_starts_with($line, ' ')) {
                $content = $line !== '' ? substr($line, 1) : ''; // Remove leading space
                $escaped = e($content);
                $html .= '<div class="flex hover:bg-gray-800/50">';
                $html .= '<span class="w-10 md:w-12 flex-none px-1 md:px-2 py-0.5 text-right text-gray-600 select-none border-r border-gray-700/50">' . $oldLine . '</span>';
                $html .= '<span class="w-10 md:w-12 flex-none px-1 md:px-2 py-0.5 text-right text-gray-600 select-none border-r border-gray-700/50">' . $newLine . '</span>';
                $html .= '<span class="flex-1 px-2 py-0.5 text-gray-400 whitespace-pre">' . $escaped . '</span>';
                $html .= '</div>';
                $oldLine++;
                $newLine++;
            }
        }

        // Close final hunk's scrollable container
        if ($inHunk) {
            $html .= '</div></div>';
        }

        return $html ?: '<div class="p-4 text-center text-gray-500">No changes to display</div>';
    }

    /**
     * Count additions and deletions in diff.
     */
    protected function countDiffStats(string $diffOutput): array
    {
        $lines = explode("\n", $diffOutput);
        $additions = 0;
        $deletions = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\+(?!\+)/', $line)) {
                $additions++;
            } elseif (preg_match('/^-(?!-)/', $line)) {
                $deletions++;
            }
        }

        return [
            'additions' => $additions,
            'deletions' => $deletions,
        ];
    }

    public function peek(array $params, array $state): string
    {
        $path = $params['path'] ?? '/workspace/default';
        $compareBranch = $params['compare_branch'] ?? null;

        if (!is_dir($path . '/.git')) {
            return "## Error: Not a git repository\n\nPath: {$path}";
        }

        $escapedPath = escapeshellarg($path);

        // Get current branch
        $branchResult = Process::run("cd {$escapedPath} && git branch --show-current 2>/dev/null");
        $branch = trim($branchResult->output()) ?: 'detached';

        $output = "## Git Status: {$path}\n\n";
        $output .= "**Branch:** {$branch}";

        if ($compareBranch) {
            $output .= " vs {$compareBranch}\n\n";

            // Commits ahead
            $escapedBranch = escapeshellarg($compareBranch);
            $commitsResult = Process::run("cd {$escapedPath} && git log {$escapedBranch}..HEAD --oneline 2>/dev/null");
            $commits = trim($commitsResult->output());

            if (!empty($commits)) {
                $commitLines = explode("\n", $commits);
                $count = count($commitLines);
                $output .= "### Commits Ahead ({$count})\n";
                foreach ($commitLines as $line) {
                    $hash = substr($line, 0, 7);
                    $msg = substr($line, 8);
                    $output .= "- `{$hash}` {$msg}\n";
                }
                $output .= "\n";
            }

            // Changed files
            $changesResult = Process::run("cd {$escapedPath} && git diff {$escapedBranch}...HEAD --name-status 2>/dev/null");
            $changes = trim($changesResult->output());

            if (!empty($changes)) {
                $changeLines = explode("\n", $changes);
                $count = count($changeLines);
                $output .= "### Changed Files ({$count})\n";
                foreach ($changeLines as $line) {
                    $parts = preg_split('/\s+/', $line, 2);
                    $status = $parts[0] ?? '';
                    $file = $parts[1] ?? '';
                    $output .= "- `{$status}` {$file}\n";
                }
            } else {
                $output .= "### Changed Files (0)\nNo changes from {$compareBranch}\n";
            }
        } else {
            $output .= "\n\n";

            // Get status
            // Use rtrim() not trim() - trim() would remove leading space from first line's status code!
            $statusResult = Process::run("cd {$escapedPath} && git status --porcelain 2>/dev/null");
            $status = rtrim($statusResult->output());

            if (empty($status)) {
                $output .= "*Working tree clean*\n";
                return $output;
            }

            $statusLines = explode("\n", $status);
            $staged = [];
            $unstaged = [];

            foreach ($statusLines as $line) {
                $code = substr($line, 0, 2);
                $file = trim(substr($line, 2));

                // Staged: first char is not space or ?
                if (!in_array($code[0], [' ', '?'])) {
                    $staged[] = ['code' => $code, 'file' => $file];
                }
                // Unstaged: second char indicates working tree change, or untracked
                // M=modified, T=type changed, A=added, D=deleted, R=renamed, C=copied, U=unmerged
                if (in_array($code[1], ['M', 'T', 'A', 'D', 'R', 'C', 'U']) || $code === '??') {
                    $unstaged[] = ['code' => $code, 'file' => $file];
                }
            }

            if (!empty($staged)) {
                $count = count($staged);
                $output .= "### Staged ({$count} files)\n";
                foreach ($staged as $item) {
                    $output .= "- `{$item['code']}` {$item['file']}\n";
                }
                $output .= "\n";
            }

            if (!empty($unstaged)) {
                $count = count($unstaged);
                $output .= "### Unstaged ({$count} files)\n";
                foreach ($unstaged as $item) {
                    $output .= "- `{$item['code']}` {$item['file']}\n";
                }
            }
        }

        // Check if viewing a file diff
        $viewingFile = $state['viewingFile'] ?? null;
        if ($viewingFile) {
            $output .= "\n---\n";
            $output .= "**Currently viewing diff:** {$viewingFile['file']}\n";
        }

        return $output;
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Opens a Git Status panel showing repository status as a collapsible file tree.

## CLI Example
```bash
# Working directory mode (staged/unstaged)
php artisan tool:run git-status -- --path=/workspace/default

# Branch comparison mode (diff vs another branch)
php artisan tool:run git-status -- --path=/workspace/default --compare_branch=main
```

## Parameters
- path: Repository path (default: /workspace/default)
- compare_branch: Optional branch to compare against

## Working Directory Mode (default)
Shows:
- Current branch with ahead/behind indicators
- Staged changes (ready to commit) as file tree
- Unstaged changes (modified but not staged) as file tree
- Status codes: M=modified, A=added, D=deleted, ?=untracked

## Branch Comparison Mode
When compare_branch is set, shows:
- Files changed between current branch and compare branch
- Commits ahead of compare branch
- Useful for reviewing what will be in a PR

## File Tree View
- Directories are collapsible
- Only directories with changes are shown
- Files show git status code with color coding

Use `php artisan panel:peek git-status` to see current state.
PROMPT;
    }
}
