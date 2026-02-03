<?php

namespace App\Panels;

use App\Models\PocketTool;

class PanelRegistry
{
    protected array $systemPanels = [];

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
        // $this->register(new GitStatusPanel());
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
     */
    public function allAvailable(): array
    {
        $panels = [];

        // Add system panels
        foreach ($this->systemPanels as $panel) {
            $panels[] = $panel->toArray();
        }

        // Add database panels (user-created)
        $dbPanels = PocketTool::where('type', PocketTool::TYPE_PANEL)
            ->where('enabled', true)
            ->get();

        foreach ($dbPanels as $dbPanel) {
            $panels[] = [
                'slug' => $dbPanel->slug,
                'name' => $dbPanel->name,
                'description' => $dbPanel->description,
                'icon' => 'fa-solid fa-table-columns',
                'parameters' => $dbPanel->input_schema['properties'] ?? [],
                'is_system' => false,
            ];
        }

        return $panels;
    }
}
