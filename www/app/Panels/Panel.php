<?php

namespace App\Panels;

use App\Support\PathValidator;

abstract class Panel
{
    // Panel metadata (override in subclasses)
    public string $slug;
    public string $name;
    public string $description = '';
    public string $icon = 'fa-solid fa-table-columns';  // FontAwesome icon

    // Parameter schema for validation/documentation
    public array $parameters = [];

    /**
     * Render the panel HTML.
     *
     * @param array $params  Parameters passed when opening panel
     * @param array $state   Current interaction state (expanded folders, etc.)
     * @param string|null $panelStateId  The panel state ID for action calls
     * @return string        HTML content
     */
    abstract public function render(array $params, array $state, ?string $panelStateId = null): string;

    /**
     * Generate a text peek for AI consumption.
     * Returns markdown describing what's currently visible.
     *
     * @param array $params  Parameters passed when opening panel
     * @param array $state   Current interaction state
     * @return string        Markdown text for AI
     */
    abstract public function peek(array $params, array $state): string;

    /**
     * Handle a panel action (for interactive panels).
     *
     * Actions enable lazy-loading and server-side logic for panel interactions.
     * Override this in panel subclasses to handle specific actions.
     *
     * @param string $action The action name (e.g., 'loadChildren', 'updateStat')
     * @param array $params Action parameters from the client
     * @param array $state Current panel state
     * @param array $panelParams Panel parameters (configured when panel was opened)
     * @return array Response with optional keys: html, state, data, error
     */
    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        return [
            'html' => null,
            'state' => null,
            'data' => null,
            'error' => "Action '{$action}' is not supported by this panel",
        ];
    }

    /**
     * Get the system prompt documentation for this panel.
     * Injected into AI context when panel tools are available.
     */
    public function getSystemPrompt(): string
    {
        return "Opens {$this->name} panel. {$this->description}";
    }

    /**
     * Validate that a path is within allowed directories.
     *
     * @param string $path The path to validate
     * @return string|null The resolved real path if valid, null otherwise
     */
    protected function validatePath(string $path): ?string
    {
        return PathValidator::validate($path);
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'parameters' => $this->parameters,
            'is_system' => true,
        ];
    }
}
