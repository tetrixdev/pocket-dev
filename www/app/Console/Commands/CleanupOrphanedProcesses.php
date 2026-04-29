<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Kill long-running orphaned processes inside the queue container.
 *
 * "Orphaned" means the process was re-parented to PID 1 (supervisord) after its
 * original parent exited — the most common cause is an AI session that ran a dev
 * server or similar background process with `&`, which then lived on after the
 * conversation ended.
 *
 * Safety constraints (ALL must be true before a process is killed):
 *  1. Its parent PID is 1 (definitely re-parented/orphaned).
 *  2. Its command matches a known-transient pattern (node, chrome, …).
 *  3. It has been running for at least $minAgeSeconds (default: 30 min).
 *     This avoids killing freshly-started legitimate processes.
 *
 * Patterns deliberately excluded:
 *  - supervisord itself
 *  - php artisan queue:work / schedule:work  (managed by supervisord)
 *  - Any process whose cmdline contains "artisan" (queue infrastructure)
 */
class CleanupOrphanedProcesses extends Command
{
    protected $signature = 'processes:cleanup-orphaned
                            {--min-age=1800 : Minimum process age in seconds before it is eligible for cleanup (default: 30 min)}
                            {--dry-run      : List orphaned processes without killing them}';

    protected $description = 'Kill long-running orphaned Node/Chrome processes left behind by ended AI sessions';

    /**
     * Command patterns that are eligible for cleanup when orphaned.
     * These are processes legitimately started by the AI during a session,
     * but that should not survive after the session ends.
     *
     * Each entry is a substring matched against the full cmdline string.
     */
    private array $eligiblePatterns = [
        'node ',          // Node.js app servers started by the AI (e.g., node server.js &)
        '/node/',         // Node binaries by absolute path
        'npm run ',       // npm scripts run in background
        'npm start',
        'npx ',           // npx-launched tools (but NOT npm exec @playwright — see exclusions)
        'chrome',         // Leftover Chromium processes from Playwright panel tool
        'chromium',
        'Xvfb',           // Headless display server (Playwright dependency)
    ];

    /**
     * Substrings that disqualify a process from cleanup, even if it matches
     * an eligible pattern above.  These are infrastructure processes that must
     * keep running.
     */
    private array $exclusionPatterns = [
        'artisan',              // All artisan workers / scheduler
        'queue:work',           // queue workers
        'schedule:work',        // Laravel scheduler
        'schedule:run',
        '@playwright/mcp',      // Active Playwright MCP server (Claude panel tool — in-use)
        'pocket-dev',           // Any pocket-dev infrastructure process
        'supervisord',
    ];

    public function handle(): int
    {
        $minAge   = (int) $this->option('min-age');
        $dryRun   = (bool) $this->option('dry-run');
        $now      = time();
        $killed   = 0;
        $skipped  = 0;

        if ($dryRun) {
            $this->info('[DRY RUN] No processes will be killed.');
        }

        // Iterate all /proc/<pid> entries
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) as $procDir) {
            $pid = (int) basename($procDir);
            if ($pid <= 1) {
                continue;
            }

            // Read status file to get PPID
            $statusFile = "{$procDir}/status";
            if (! is_readable($statusFile)) {
                continue;
            }
            $status = @file_get_contents($statusFile);
            if ($status === false) {
                continue;
            }

            // Only target processes whose parent is PID 1 (orphaned/re-parented)
            if (! preg_match('/^PPid:\s+(\d+)/m', $status, $m) || (int) $m[1] !== 1) {
                continue;
            }

            // Read the full command line
            $cmdlineFile = "{$procDir}/cmdline";
            if (! is_readable($cmdlineFile)) {
                continue;
            }
            $cmdline = @file_get_contents($cmdlineFile);
            if ($cmdline === false || $cmdline === '') {
                continue;
            }
            // cmdline uses NUL separators — convert to readable string
            $cmdline = str_replace("\0", ' ', $cmdline);
            $cmdline = trim($cmdline);

            // Skip if the command doesn't match any eligible pattern
            $eligible = false;
            foreach ($this->eligiblePatterns as $pattern) {
                if (stripos($cmdline, $pattern) !== false) {
                    $eligible = true;
                    break;
                }
            }
            if (! $eligible) {
                continue;
            }

            // Skip if an exclusion pattern matches
            foreach ($this->exclusionPatterns as $excl) {
                if (stripos($cmdline, $excl) !== false) {
                    $skipped++;
                    continue 2;
                }
            }

            // Check process age via /proc/<pid>/stat field 22 (starttime in clock ticks)
            $statFile = "{$procDir}/stat";
            if (! is_readable($statFile)) {
                continue;
            }
            $stat = @file_get_contents($statFile);
            if ($stat === false) {
                continue;
            }

            // Field 22 (0-indexed: 21) in /proc/pid/stat is starttime in clock ticks since boot
            $fields = explode(' ', $stat);
            if (count($fields) < 22) {
                continue;
            }
            $startTicks = (int) $fields[21];
            $clockTick  = 100; // sysconf(_SC_CLK_TCK) — nearly always 100 on Linux

            // /proc/uptime gives seconds since boot
            $uptime = (float) explode(' ', @file_get_contents('/proc/uptime') ?: '0')[0];
            $processAgeSeconds = $uptime - ($startTicks / $clockTick);

            if ($processAgeSeconds < $minAge) {
                // Too young — may be a legitimately started process
                continue;
            }

            $ageMin = round($processAgeSeconds / 60, 1);
            $preview = substr($cmdline, 0, 120);

            if ($dryRun) {
                $this->line("  [DRY RUN] Would kill PID {$pid} (age {$ageMin}m): {$preview}");
                continue;
            }

            // Send SIGTERM first, then SIGKILL after a brief grace period
            $terminated = @posix_kill($pid, SIGTERM);
            if ($terminated) {
                usleep(200_000); // 200 ms grace
                // Check if still alive, then SIGKILL
                if (is_readable("/proc/{$pid}")) {
                    @posix_kill($pid, SIGKILL);
                }
                $killed++;
                $this->line("  Killed PID {$pid} (age {$ageMin}m): {$preview}");
                Log::info('CleanupOrphanedProcesses: killed orphaned process', [
                    'pid'     => $pid,
                    'age_min' => $ageMin,
                    'cmdline' => $preview,
                ]);
            }
        }

        $summary = $dryRun
            ? "Dry run complete — {$killed} would be killed, {$skipped} excluded."
            : "Cleanup complete — killed: {$killed}, excluded by safety rules: {$skipped}.";

        $this->info($summary);
        Log::info('CleanupOrphanedProcesses: ' . $summary);

        return Command::SUCCESS;
    }
}
