<?php

namespace App\Http\View\Composers;

use Illuminate\View\View;

class ConfigSidebarComposer
{
    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        // Only load sidebar data for config pages
        $view->with([
            'agents' => $this->getAgents(),
            'commands' => $this->getCommands(),
            'skills' => $this->getSkills(),
        ]);
    }

    /**
     * Get list of agents for sidebar
     */
    protected function getAgents(): array
    {
        $path = '/home/appuser/.claude/agents';

        if (!is_dir($path)) {
            return [];
        }

        $agents = [];
        $files = scandir($path);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                $agents[] = [
                    'filename' => $file,
                    'name' => $this->extractNameFromFile($path . '/' . $file),
                ];
            }
        }

        return $agents;
    }

    /**
     * Get list of commands for sidebar
     */
    protected function getCommands(): array
    {
        $path = '/home/appuser/.claude/commands';

        if (!is_dir($path)) {
            return [];
        }

        $commands = [];
        $files = scandir($path);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                $commands[] = [
                    'filename' => $file,
                    'name' => pathinfo($file, PATHINFO_FILENAME),
                ];
            }
        }

        return $commands;
    }

    /**
     * Get list of skills for sidebar
     */
    protected function getSkills(): array
    {
        $path = '/home/appuser/.claude/skills';

        if (!is_dir($path)) {
            return [];
        }

        $skills = [];
        $files = scandir($path);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($path . '/' . $file)) {
                $skills[] = [
                    'name' => $file,
                ];
            }
        }

        return $skills;
    }

    /**
     * Extract name from agent file (from frontmatter or filename)
     */
    protected function extractNameFromFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return pathinfo($filePath, PATHINFO_FILENAME);
        }

        $content = file_get_contents($filePath);

        // Try to extract name from frontmatter
        if (preg_match('/^---\s*\n(.*?)\n---/s', $content, $matches)) {
            if (preg_match('/name:\s*(.+)$/m', $matches[1], $nameMatch)) {
                return trim($nameMatch[1]);
            }
        }

        // Fall back to filename
        return pathinfo($filePath, PATHINFO_FILENAME);
    }
}
