@extends('layouts.config')

@section('title', 'Claude Code')

@section('content')
{{-- Import Configuration Section --}}
<div
    x-data="configImport()"
    class="mb-8"
>
    <h3 class="text-lg font-semibold mb-2">Import Configuration</h3>
    <p class="text-sm text-gray-400 mb-4">
        Import settings from a Claude Code export archive. This can update settings.json, CLAUDE.md (to workspace), MCP servers, and skills (to memory).
    </p>

    {{-- Upload Section --}}
    <div class="flex gap-3 items-start">
        <label class="flex-1">
            <div
                class="flex items-center gap-2 px-4 py-3 bg-gray-700 border border-gray-600 rounded cursor-pointer hover:bg-gray-600 transition-colors"
                :class="{ 'border-blue-500': fileName }"
            >
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                <span x-text="fileName || 'Choose export archive (.tar.gz)...'" class="text-sm truncate" :class="fileName ? 'text-white' : 'text-gray-400'"></span>
            </div>
            <input
                type="file"
                accept=".tar.gz,.gz"
                class="hidden"
                x-ref="fileInput"
                @change="handleFileSelect($event)"
            >
        </label>
        <button
            type="button"
            class="px-4 py-3 bg-blue-600 hover:bg-blue-500 text-white text-sm rounded transition-colors"
            :disabled="!fileName || uploading"
            :class="{ 'opacity-50 cursor-not-allowed': !fileName || uploading }"
            @click="uploadAndPreview()"
        >
            <span x-show="!uploading">Preview Import</span>
            <span x-show="uploading" class="flex items-center gap-2">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Analyzing...
            </span>
        </button>
    </div>

    {{-- Error Message --}}
    <div x-show="error" x-cloak class="mt-3 p-3 bg-red-900/50 border border-red-500/50 rounded text-red-300 text-sm">
        <span x-text="error"></span>
    </div>

    {{-- Success Message --}}
    <div x-show="success" x-cloak class="mt-3 p-3 bg-green-900/50 border border-green-500/50 rounded text-green-300 text-sm">
        <span x-text="success"></span>
    </div>

    {{-- How to Export --}}
    <details class="mt-4 text-sm">
        <summary class="text-gray-500 cursor-pointer hover:text-gray-400">How to export your Claude Code config</summary>
        <div class="mt-2 p-3 bg-gray-900 rounded text-gray-400">
            <p class="mb-2">Run this on the machine where Claude Code is installed:</p>
            <code class="block text-green-400 text-xs break-all">curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/scripts/export-claude-config.sh | bash</code>
            <p class="mt-2 text-xs">This creates <code>~/claude-config-export-*.tar.gz</code> which you can upload here.</p>
        </div>
    </details>

    {{-- Preview Modal --}}
    <div
        x-show="showPreview"
        x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        @keydown.escape.window="showPreview = false"
    >
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
            {{-- Backdrop --}}
            <div class="fixed inset-0 bg-black/70 transition-opacity" @click="showPreview = false"></div>

            {{-- Modal Content --}}
            <div class="relative bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-2xl sm:w-full border border-gray-700">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-1">Import Preview</h3>
                    <p class="text-sm text-gray-400 mb-4" x-show="preview?.manifest">
                        Exported <span x-text="preview?.manifest?.exportDate"></span> from <span x-text="preview?.manifest?.hostname"></span>
                    </p>

                    {{-- Sections --}}
                    <div class="space-y-4 max-h-96 overflow-y-auto">

                        {{-- settings.json --}}
                        <div x-show="preview?.sections?.settings" class="bg-gray-900 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium">settings.json</span>
                                    <span class="ml-2 text-xs text-gray-500">
                                        (<span x-text="(preview?.sections?.settings?.content || '').split('\\n').length"></span> lines,
                                        <span x-text="Math.round((preview?.sections?.settings?.content?.length || 0) / 1024 * 10) / 10"></span> KB)
                                    </span>
                                </div>
                                <select x-model="options.settings" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="overwrite">Import (overwrite existing)</option>
                                    <option value="skip">Skip</option>
                                </select>
                            </div>
                            <div class="text-xs text-gray-500 flex gap-3">
                                <button type="button" @click="showSettingsPreview = 'new'" class="text-blue-400 hover:text-blue-300 hover:underline">View new</button>
                                <button x-show="preview?.sections?.settings?.current_exists" type="button" @click="showSettingsPreview = 'current'" class="text-blue-400 hover:text-blue-300 hover:underline">View current</button>
                            </div>
                        </div>

                        {{-- CLAUDE.md / Workspace Prompt --}}
                        <div x-show="preview?.sections?.claude_md" class="bg-gray-900 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium">Workspace Prompt</span>
                                    <span class="ml-2 text-xs text-gray-500">
                                        (<span x-text="preview?.sections?.claude_md?.lines"></span> lines,
                                        <span x-text="Math.round((preview?.sections?.claude_md?.size || 0) / 1024)"></span> KB)
                                    </span>
                                </div>
                                <select x-model="options.claude_md" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="overwrite">Import (overwrite existing)</option>
                                    <option value="append">Import (append to existing)</option>
                                    <option value="skip">Skip</option>
                                </select>
                            </div>
                            <div class="text-xs text-gray-500 flex gap-3 mb-2">
                                <span>From CLAUDE.md</span>
                                <span class="text-gray-600">|</span>
                                <button type="button" @click="showClaudeMdPreview = 'new'" class="text-blue-400 hover:text-blue-300 hover:underline">View new</button>
                                <button x-show="options.claude_md_workspace && getWorkspacePrompt(options.claude_md_workspace)" type="button" @click="showClaudeMdPreview = 'current'" class="text-blue-400 hover:text-blue-300 hover:underline">View current</button>
                            </div>
                            {{-- Workspace selector for CLAUDE.md --}}
                            <div x-show="options.claude_md !== 'skip'">
                                <label class="text-xs text-gray-400 block mb-1">Import to workspace:</label>
                                <select x-model="options.claude_md_workspace" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1 w-full">
                                    <option value="">Select a workspace...</option>
                                    @foreach($workspaces as $workspace)
                                    <option value="{{ $workspace->id }}" data-prompt="{{ $workspace->claude_base_prompt ?? '' }}">{{ $workspace->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- MCP Servers --}}
                        <div x-show="preview?.sections?.mcp_servers" class="bg-gray-900 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium">MCP Servers</span>
                                    <span class="ml-2 text-xs text-gray-500" x-text="'(' + (preview?.sections?.mcp_servers?.servers?.length || 0) + ' servers)'"></span>
                                </div>
                                <select x-model="options.mcp_servers" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="merge_skip">Import (skip existing)</option>
                                    <option value="merge_overwrite">Import (overwrite existing)</option>
                                    <option value="skip">Skip all</option>
                                </select>
                            </div>
                            <ul class="text-xs space-y-1 mt-2">
                                <template x-for="server in preview?.sections?.mcp_servers?.servers || []" :key="server">
                                    <li class="flex items-center gap-2">
                                        <template x-if="options.mcp_servers === 'skip'">
                                            <span class="text-gray-500 font-medium">Skip</span>
                                        </template>
                                        <template x-if="options.mcp_servers !== 'skip' && preview?.sections?.mcp_servers?.new_servers?.includes(server)">
                                            <span class="text-green-500 font-medium">Import (new)</span>
                                        </template>
                                        <template x-if="options.mcp_servers === 'merge_skip' && preview?.sections?.mcp_servers?.conflict_servers?.includes(server)">
                                            <span class="text-yellow-500 font-medium">Skip (existing)</span>
                                        </template>
                                        <template x-if="options.mcp_servers === 'merge_overwrite' && preview?.sections?.mcp_servers?.conflict_servers?.includes(server)">
                                            <span class="text-orange-500 font-medium">Import (overwrite)</span>
                                        </template>
                                        <span class="text-gray-300" x-text="server"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        {{-- Skills to Memory --}}
                        <div x-show="preview?.sections?.parsed_skills" class="bg-gray-900 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium">Skills to Memory</span>
                                    <span class="ml-2 text-xs text-gray-500" x-text="'(' + (preview?.sections?.parsed_skills?.count || 0) + ' skills found)'"></span>
                                </div>
                                <select x-model="options.skills_to_memory" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="merge_skip">Import (skip existing)</option>
                                    <option value="merge_overwrite">Import (overwrite existing)</option>
                                    <option value="skip">Skip all</option>
                                </select>
                            </div>
                            <div x-show="options.skills_to_memory !== 'skip'" class="mt-2">
                                <label class="text-xs text-gray-400 block mb-1">Target Schema:</label>
                                <select x-model="options.skills_schema" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1 w-full">
                                    <option value="">Select a schema...</option>
                                    <template x-for="schema in availableSchemas" :key="schema.schema_name">
                                        <option :value="schema.schema_name" x-text="schema.name + ' (' + schema.schema_name + ')'"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="text-xs mt-2">
                                <p class="text-gray-500 mb-1">Skills parsed from commands/ and skills/ directories:</p>
                                <ul class="space-y-1">
                                    <template x-for="skill in preview?.sections?.parsed_skills?.skills?.slice(0, 10)" :key="skill.name">
                                        <li class="flex items-center gap-2">
                                            <template x-if="options.skills_to_memory === 'skip'">
                                                <span class="text-gray-500 font-medium">Skip</span>
                                            </template>
                                            <template x-if="options.skills_to_memory !== 'skip' && !isExistingSkill(skill.name)">
                                                <span class="text-green-500 font-medium">Import (new)</span>
                                            </template>
                                            <template x-if="options.skills_to_memory === 'merge_skip' && isExistingSkill(skill.name)">
                                                <span class="text-yellow-500 font-medium">Skip (existing)</span>
                                            </template>
                                            <template x-if="options.skills_to_memory === 'merge_overwrite' && isExistingSkill(skill.name)">
                                                <span class="text-orange-500 font-medium">Import (overwrite)</span>
                                            </template>
                                            <span class="text-gray-300" x-text="skill.name"></span>
                                            <span class="text-gray-600" x-text="'(' + skill.source + ')'"></span>
                                        </li>
                                    </template>
                                    <li x-show="(preview?.sections?.parsed_skills?.count || 0) > 10" class="text-gray-500">
                                        ... and <span x-text="(preview?.sections?.parsed_skills?.count || 0) - 10"></span> more
                                    </li>
                                </ul>
                            </div>
                        </div>

                    </div>

                    {{-- Actions --}}
                    <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-700">
                        <button
                            type="button"
                            class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white text-sm rounded transition-colors"
                            @click="showPreview = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-sm rounded transition-colors"
                            :disabled="applying || (options.claude_md !== 'skip' && !options.claude_md_workspace)"
                            :class="{ 'opacity-50 cursor-not-allowed': applying || (options.claude_md !== 'skip' && !options.claude_md_workspace) }"
                            @click="applyImport()"
                        >
                            <span x-show="!applying">Apply Import</span>
                            <span x-show="applying" class="flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Importing...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CLAUDE.md / Workspace Prompt Preview Modal --}}
    <div
        x-show="showClaudeMdPreview"
        x-cloak
        class="fixed inset-0 z-[60] overflow-y-auto"
        @keydown.escape.stop="showClaudeMdPreview = null"
    >
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
            {{-- Backdrop --}}
            <div class="fixed inset-0 bg-black/80 transition-opacity" @click="showClaudeMdPreview = null"></div>

            {{-- Modal Content --}}
            <div class="relative bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-4xl sm:w-full border border-gray-700 z-[61]">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold" x-text="showClaudeMdPreview === 'new' ? 'New Workspace Prompt (from CLAUDE.md)' : 'Current Workspace Prompt'"></h3>
                        <button
                            type="button"
                            @click="showClaudeMdPreview = null"
                            class="text-gray-400 hover:text-white"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto bg-gray-900 rounded p-4 prose prose-invert prose-sm max-w-none" x-html="renderMarkdown(showClaudeMdPreview === 'new' ? (preview?.sections?.claude_md?.content || '') : getWorkspacePrompt(options.claude_md_workspace))"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- settings.json Preview Modal --}}
    <div
        x-show="showSettingsPreview"
        x-cloak
        class="fixed inset-0 z-[60] overflow-y-auto"
        @keydown.escape.stop="showSettingsPreview = null"
    >
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
            {{-- Backdrop --}}
            <div class="fixed inset-0 bg-black/80 transition-opacity" @click="showSettingsPreview = null"></div>

            {{-- Modal Content --}}
            <div class="relative bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-4xl sm:w-full border border-gray-700 z-[61]">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold" x-text="showSettingsPreview === 'new' ? 'New settings.json' : 'Current settings.json'"></h3>
                        <button
                            type="button"
                            @click="showSettingsPreview = null"
                            class="text-gray-400 hover:text-white"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto bg-gray-900 rounded p-4">
                        <pre class="text-sm text-gray-300 whitespace-pre-wrap font-mono" x-text="formatJson(showSettingsPreview === 'new' ? preview?.sections?.settings?.content : preview?.sections?.settings?.current_content)"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function configImport() {
    return {
        fileName: '',
        uploading: false,
        applying: false,
        error: '',
        success: '',
        showPreview: false,
        showClaudeMdPreview: null, // null, 'new', or 'current'
        showSettingsPreview: null, // null, 'new', or 'current'
        preview: null,
        sessionKey: null,
        availableSchemas: [],
        workspacePrompts: {
            @foreach($workspaces as $workspace)
            '{{ $workspace->id }}': @json($workspace->claude_base_prompt ?? ''),
            @endforeach
        },
        options: {
            settings: 'overwrite',
            claude_md: 'overwrite',
            claude_md_workspace: '{{ $workspaces->first()?->id ?? '' }}',
            mcp_servers: 'merge_skip',
            skills_to_memory: 'merge_skip',
            skills_schema: '',
        },

        // Get workspace prompt by ID
        getWorkspacePrompt(workspaceId) {
            return this.workspacePrompts[workspaceId] || '';
        },

        // Check if skill already exists in selected schema
        isExistingSkill(skillName) {
            if (!this.options.skills_schema || !this.preview?.sections?.parsed_skills?.existing_by_schema) {
                return false;
            }
            const existingSkills = this.preview.sections.parsed_skills.existing_by_schema[this.options.skills_schema] || [];
            return existingSkills.includes(skillName);
        },

        // Format JSON for display
        formatJson(jsonStr) {
            if (!jsonStr) return '';
            try {
                return JSON.stringify(JSON.parse(jsonStr), null, 2);
            } catch {
                return jsonStr;
            }
        },

        // Simple markdown renderer for preview
        renderMarkdown(text) {
            if (!text) return '';
            // Escape HTML first
            let html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            // Headers
            html = html.replace(/^### (.+)$/gm, '<h3 class="text-base font-semibold mt-4 mb-2">$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2 class="text-lg font-semibold mt-4 mb-2">$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1 class="text-xl font-bold mt-4 mb-2">$1</h1>');
            // Code blocks
            html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre class="bg-gray-800 p-3 rounded my-2 overflow-x-auto"><code>$2</code></pre>');
            // Inline code
            html = html.replace(/`([^`]+)`/g, '<code class="bg-gray-800 px-1 rounded">$1</code>');
            // Bold
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            // Italic
            html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
            // Lists
            html = html.replace(/^- (.+)$/gm, '<li class="ml-4">$1</li>');
            html = html.replace(/^(\d+)\. (.+)$/gm, '<li class="ml-4">$2</li>');
            // Paragraphs (double newlines)
            html = html.replace(/\n\n/g, '</p><p class="my-2">');
            // Single newlines in remaining text
            html = html.replace(/\n/g, '<br>');
            return '<p class="my-2">' + html + '</p>';
        },

        async init() {
            // Fetch available memory schemas
            try {
                const response = await fetch('{{ route("config.import.schemas") }}');
                if (response.ok) {
                    const data = await response.json();
                    this.availableSchemas = data.schemas || [];
                    // Set default schema if available
                    if (this.availableSchemas.length > 0) {
                        const defaultSchema = this.availableSchemas.find(s => s.schema_name === 'default');
                        this.options.skills_schema = defaultSchema?.schema_name || this.availableSchemas[0].schema_name;
                    }
                }
            } catch (err) {
                console.error('Failed to fetch memory schemas:', err);
            }
        },

        handleFileSelect(event) {
            const file = event.target.files[0];
            this.fileName = file?.name || '';
            this.error = '';
            this.success = '';
        },

        async uploadAndPreview() {
            if (!this.$refs.fileInput?.files[0]) return;

            this.uploading = true;
            this.error = '';
            this.success = '';

            const formData = new FormData();
            formData.append('config_archive', this.$refs.fileInput.files[0]);
            formData.append('_token', '{{ csrf_token() }}');

            try {
                const response = await fetch('{{ route("config.import.preview") }}', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    const errText = await response.text();
                    throw new Error(`Server error ${response.status}: ${errText}`);
                }

                const data = await response.json();

                if (data.success) {
                    this.preview = data.preview;
                    this.sessionKey = data.session_key;
                    this.showPreview = true;
                } else {
                    this.error = data.error || 'Failed to analyze archive';
                }
            } catch (err) {
                this.error = 'Upload failed: ' + err.message;
            } finally {
                this.uploading = false;
            }
        },

        async applyImport() {
            this.applying = true;

            try {
                const response = await fetch('{{ route("config.import.apply") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        session_key: this.sessionKey,
                        options: this.options
                    })
                });

                if (!response.ok) {
                    const errText = await response.text();
                    throw new Error(`Server error ${response.status}: ${errText}`);
                }

                const data = await response.json();

                if (data.success) {
                    this.showPreview = false;
                    this.fileName = '';
                    this.$refs.fileInput.value = '';

                    let message = '';
                    if (data.imported.length) {
                        message += 'Imported: ' + data.imported.join(', ');
                    }
                    if (data.skipped.length) {
                        message += (message ? '. ' : '') + 'Skipped: ' + data.skipped.join(', ');
                    }
                    this.success = message || 'Import complete';

                    // Reload the page to show updated settings.json
                    if (data.imported.includes('settings.json')) {
                        setTimeout(() => window.location.reload(), 1500);
                    }
                } else {
                    this.error = data.error || 'Import failed';
                    this.showPreview = false;
                }
            } catch (err) {
                this.error = 'Import failed: ' + err.message;
                this.showPreview = false;
            } finally {
                this.applying = false;
            }
        }
    };
}
</script>

{{-- Global Settings Section --}}
<form method="POST" action="{{ route('config.claude-code.save') }}" class="mt-8 pt-8 border-t border-gray-700">
    @csrf
    <div class="mb-4">
        <label for="content" class="block text-sm font-medium mb-2">~/.claude/settings.json</label>
        <p class="text-sm text-zinc-400 mb-3">
            Claude Code settings file. See <a href="https://docs.anthropic.com/en/docs/claude-code/settings" target="_blank" class="text-blue-400 hover:underline">docs.anthropic.com/en/docs/claude-code/settings</a> for available options.
        </p>
        <textarea
            id="content"
            name="content"
            class="config-editor w-full"
            style="min-height: 180px; height: auto;"
            required
        >{{ $content }}</textarea>
    </div>
    <button
        type="submit"
        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
    >
        Save
    </button>
</form>

{{-- MCP Servers Section --}}
<form method="POST" action="{{ route('config.claude-code.mcp.save') }}" class="mt-8 pt-8 border-t border-gray-700">
    @csrf
    <div class="mb-4">
        <label for="mcp_content" class="block text-sm font-medium mb-2">MCP Servers</label>
        <p class="text-sm text-zinc-400 mb-3">
            MCP (Model Context Protocol) servers configuration. Stored in <code class="text-gray-300">~/.claude.json</code> under <code class="text-gray-300">mcpServers</code>.
        </p>
        <textarea
            id="mcp_content"
            name="mcp_content"
            class="config-editor w-full"
            style="min-height: 180px; height: auto;"
            required
        >{{ $mcpContent }}</textarea>
    </div>
    <button
        type="submit"
        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
    >
        Save
    </button>
</form>

@endsection
