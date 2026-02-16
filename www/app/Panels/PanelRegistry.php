<?php

namespace App\Panels;

use App\Models\PocketTool;

class PanelRegistry
{
    protected array $systemPanels = [];
    private ?array $cachedAllPanels = null;

    public function __construct()
    {
        $this->registerSystemPanels();
    }

    /**
     * Register all system panels.
     * Add new panels here.
     */
    protected function registerSystemPanels(): void
    {
        // Add system panels here:
        $this->register(new FileExplorerPanel());
        $this->register(new GitStatusPanel());
        $this->register(new DockerContainersPanel());
    }

    public function register(Panel $panel): void
    {
        $this->systemPanels[$panel->slug] = $panel;
    }

    public function get(string $slug): ?Panel
    {
        return $this->systemPanels[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->systemPanels[$slug]);
    }

    /**
     * Get all system panels.
     */
    public function all(): array
    {
        return $this->systemPanels;
    }

    /**
     * Get all available panels (system + database).
     * System panels take precedence over database panels with the same slug.
     * Panels are sorted alphabetically by category, then by name within category.
     * The 'other' category is always sorted last as a catch-all.
     */
    public function allAvailable(): array
    {
        if ($this->cachedAllPanels !== null) {
            return $this->cachedAllPanels;
        }

        $panels = [];

        // Add system panels
        foreach ($this->systemPanels as $panel) {
            $panels[] = $panel->toArray();
        }

        // Get system panel slugs for deduplication
        $systemSlugs = array_keys($this->systemPanels);

        // Add database panels (user-created), skipping duplicates
        $dbPanels = PocketTool::where('type', PocketTool::TYPE_PANEL)
            ->where('enabled', true)
            ->get();

        foreach ($dbPanels as $dbPanel) {
            // Skip database panels that have a matching system panel slug
            if (in_array($dbPanel->slug, $systemSlugs, true)) {
                continue;
            }

            $panels[] = [
                'slug' => $dbPanel->slug,
                'name' => $dbPanel->name,
                'description' => $dbPanel->description,
                'icon' => 'fa-solid fa-table-columns',
                'parameters' => $dbPanel->input_schema['properties'] ?? [],
                'category' => $dbPanel->category ?? 'other',
                'is_system' => false,
            ];
        }

        // Sort alphabetically by category, then by name within category.
        // 'other' category always sorts last as the catch-all.
        usort($panels, function ($a, $b) {
            $catA = $a['category'] ?? 'other';
            $catB = $b['category'] ?? 'other';

            // 'other' always goes last
            if ($catA === 'other' && $catB !== 'other') return 1;
            if ($catB === 'other' && $catA !== 'other') return -1;

            // Otherwise sort categories alphabetically
            $catCmp = strcmp($catA, $catB);
            if ($catCmp !== 0) {
                return $catCmp;
            }

            // Within same category, sort by name
            return strcmp($a['name'], $b['name']);
        });

        $this->cachedAllPanels = $panels;

        return $panels;
    }
}
