<?php

/**
 * Panel Configuration
 *
 * Defines dependencies and styling available to all panels (system and user-created).
 * This is the single source of truth - used by PanelController for iframe wrapping
 * and by SystemPromptBuilder for generating the Panel Dependencies section.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | CDN Dependencies
    |--------------------------------------------------------------------------
    |
    | External libraries loaded in all panels. Order matters for scripts -
    | plugins must load before their parent library.
    |
    | VERSION UPDATE PROCESS:
    | 1. Check for updates at jsdelivr.com or cdnjs.com
    | 2. Test locally by opening panels (file-explorer, git-status, etc.)
    | 3. Update version numbers below
    | 4. Update integrity hash for Font Awesome if version changes
    |
    | Last reviewed: Feb 2026
    |
    */
    'dependencies' => [
        'tailwind' => [
            'type' => 'script',
            'url' => 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18',
            'description' => 'Tailwind CSS v4 - All utility classes available including arbitrary values like w-[137px]',
        ],
        'alpine-collapse' => [
            'type' => 'script',
            'url' => 'https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.15.8/dist/cdn.min.js',
            'defer' => true,
            'description' => 'Alpine.js Collapse plugin - Use x-collapse for smooth expand/collapse animations',
        ],
        'alpine' => [
            'type' => 'script',
            'url' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.15.8/dist/cdn.min.js',
            'defer' => true,
            'description' => 'Alpine.js - Reactive UI with x-data, x-show, x-on, x-effect, x-for, x-text, etc.',
        ],
        'font-awesome' => [
            'type' => 'stylesheet',
            'url' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css',
            'crossorigin' => 'anonymous',
            'description' => 'Font Awesome 6 - Icons via fa-* classes (e.g., fa-solid fa-folder, fa-brands fa-github)',
        ],
        'highlight-css' => [
            'type' => 'stylesheet',
            'url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css',
            'crossorigin' => 'anonymous',
            'description' => 'Highlight.js GitHub Dark theme for syntax highlighting in code viewers',
        ],
        'highlight-js' => [
            'type' => 'script',
            'url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js',
            'crossorigin' => 'anonymous',
            'description' => 'Highlight.js - Syntax highlighting for code blocks. Use hljs.highlightElement(el) or hljs.highlight(code, {language})',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tailwind Theme Customization
    |--------------------------------------------------------------------------
    |
    | Custom Tailwind theme applied to all panels via <style type="text/tailwindcss">.
    |
    */
    'tailwind_theme' => <<<'CSS'
@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
}
CSS,

    /*
    |--------------------------------------------------------------------------
    | Base CSS
    |--------------------------------------------------------------------------
    |
    | CSS always included in panels. Includes essential rules like x-cloak.
    |
    */
    'base_css' => <<<'CSS'
[x-cloak] { display: none !important; }
html, body {
    height: 100%;
    margin: 0;
    background: transparent;
    color: #e5e7eb;
    font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
}
/* Scrollbar styling (matches main app) */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: #6b7280; }
* { scrollbar-width: thin; scrollbar-color: #4b5563 transparent; }
CSS,

    /*
    |--------------------------------------------------------------------------
    | Documentation Examples
    |--------------------------------------------------------------------------
    |
    | Example code snippets included in the tool:create system prompt.
    |
    */
    'examples' => [
        'icons' => <<<'EXAMPLE'
<!-- Solid icons -->
<i class="fa-solid fa-folder text-yellow-500"></i>
<i class="fa-solid fa-file text-gray-400"></i>
<i class="fa-solid fa-chevron-right"></i>

<!-- Spinner (use SVG for smooth animation without wobble) -->
<svg class="animate-spin" style="width: 1em; height: 1em;" viewBox="0 0 24 24" fill="none">
    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
</svg>

<!-- Brand icons -->
<i class="fa-brands fa-github"></i>
<i class="fa-brands fa-php text-purple-400"></i>
<i class="fa-brands fa-js text-yellow-400"></i>
EXAMPLE,
        'collapse' => <<<'EXAMPLE'
<div x-data="{ open: false }">
    <button @click="open = !open" class="flex items-center gap-2">
        <i class="fa-solid fa-chevron-right transition-transform" :class="open && 'rotate-90'"></i>
        Toggle Section
    </button>
    <div x-show="open" x-collapse class="mt-2 ml-4">
        This content smoothly expands and collapses.
    </div>
</div>
EXAMPLE,
        'card' => <<<'EXAMPLE'
<div class="bg-white/5 border border-white/10 rounded-lg p-3">
    <div class="flex items-center gap-2">
        <i class="fa-solid fa-server text-blue-400"></i>
        <span class="font-medium">Server Name</span>
        <span class="w-2 h-2 rounded-full bg-green-500"></span>
    </div>
</div>
EXAMPLE,
    ],

    /*
    |--------------------------------------------------------------------------
    | Script Environment Documentation
    |--------------------------------------------------------------------------
    |
    | Documentation about the script execution environment, included in the
    | Panel Dependencies section of the system prompt.
    |
    */
    'script_environment' => <<<'DOC'
## Script Environment

Scripts run with POSIX shell (`sh`), not bash. Avoid bash-specific syntax:

| Avoid | Use Instead |
|-------|-------------|
| `[[ ]]` | `[ ]` |
| `${var,,}` | `echo "$var" \| tr '[:upper:]' '[:lower:]'` |
| `<<<` | `echo ... \| cmd` |
| `source file` | `. file` |

**Available commands:** curl, wget, git, jq, php, node, npm, python3

**Error handling pattern:**

```sh
#!/bin/sh
set -e  # Exit on first error
[ -z "$TOOL_INPUT" ] && { echo '{"error":"input required"}'; exit 0; }
result=$(command) || { echo '{"error":"command failed"}'; exit 0; }
echo "{\"data\":$result}"
```
DOC,
];
