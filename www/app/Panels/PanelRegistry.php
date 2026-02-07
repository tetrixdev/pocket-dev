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
            if (in_array($dbPanel->slug, $systemSlugs)) {
                continue;
            }

            $panels[] = [
                'slug' => $dbPanel->slug,
                'name' => $dbPanel->name,
                'description' => $dbPanel->description,
                'icon' => 'fa-solid fa-table-columns',
                'parameters' => $dbPanel->input_schema['properties'] ?? [],
                'is_system' => false,
            ];
        }

        $this->cachedAllPanels = $panels;

        return $panels;
    }
}
