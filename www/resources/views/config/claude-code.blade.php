@extends('layouts.config')

@section('title', 'Claude Code')

@section('content')
{{-- Global Settings Section --}}
<form method="POST" action="{{ route('config.claude-code.save') }}">
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
            rows="20"
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

{{-- Base Prompt Section (CLAUDE.md equivalent) --}}
@if($workspaces->count() > 0)
<div
    x-data="basePromptEditor()"
    x-init="loadBasePrompt()"
    class="mt-8 pt-8 border-t border-gray-700"
>
    <h3 class="text-lg font-semibold mb-2">CLAUDE.md</h3>
    <p class="text-sm text-gray-400 mb-4">
        Workspace-specific base prompt. This content is included in the system prompt for all conversations in the selected workspace.
    </p>

    {{-- Workspace Selector --}}
    <div class="mb-4">
        <label for="base_prompt_workspace" class="block text-sm font-medium text-gray-300 mb-1">Workspace</label>
        <select
            id="base_prompt_workspace"
            x-model="selectedWorkspaceId"
            @change="loadBasePrompt()"
            class="w-full max-w-xs bg-gray-900 border border-gray-700 rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
        >
            @foreach($workspaces as $workspace)
            <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- Base Prompt Textarea --}}
    <div class="mb-4">
        <textarea
            x-model="basePrompt"
            :disabled="loading"
            placeholder="Enter CLAUDE.md content for this workspace..."
            class="config-editor w-full"
            rows="15"
        ></textarea>
    </div>

    {{-- Save Button and Status --}}
    <div class="flex items-center gap-4">
        <button
            type="button"
            @click="saveBasePrompt()"
            :disabled="loading || saving"
            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        >
            <span x-show="!saving">Save Base Prompt</span>
            <span x-show="saving" class="flex items-center gap-2">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Saving...
            </span>
        </button>
        <span x-show="success" x-cloak class="text-green-400 text-sm" x-text="success"></span>
        <span x-show="error" x-cloak class="text-red-400 text-sm" x-text="error"></span>
    </div>
</div>

<script>
function basePromptEditor() {
    return {
        selectedWorkspaceId: '{{ $workspaces->first()?->id ?? '' }}',
        basePrompt: '',
        loading: false,
        saving: false,
        success: '',
        error: '',

        async loadBasePrompt() {
            if (!this.selectedWorkspaceId) return;

            this.loading = true;
            this.success = '';
            this.error = '';

            try {
                const response = await fetch('{{ route("config.claude-code.base-prompt.get") }}?workspace_id=' + this.selectedWorkspaceId);
                if (!response.ok) throw new Error('Failed to load');
                const data = await response.json();
                this.basePrompt = data.base_prompt || '';
            } catch (err) {
                this.error = 'Failed to load base prompt: ' + err.message;
            } finally {
                this.loading = false;
            }
        },

        async saveBasePrompt() {
            if (!this.selectedWorkspaceId) return;

            this.saving = true;
            this.success = '';
            this.error = '';

            try {
                const response = await fetch('{{ route("config.claude-code.base-prompt.save") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        workspace_id: this.selectedWorkspaceId,
                        base_prompt: this.basePrompt,
                    }),
                });

                if (!response.ok) {
                    const data = await response.json();
                    throw new Error(data.error || 'Failed to save');
                }

                this.success = 'Saved successfully!';
                setTimeout(() => this.success = '', 3000);
            } catch (err) {
                this.error = 'Failed to save: ' + err.message;
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>
@endif

{{-- Skills Section --}}
@if(!empty($memorySkills))
<div class="mt-8 pt-8 border-t border-gray-700">
    <h3 class="text-lg font-semibold mb-2">Skills</h3>
    <p class="text-sm text-gray-400 mb-4">
        Skills stored in memory schemas. These are available as slash commands in conversations.
        Use <code class="text-gray-300">/skill-name</code> to invoke a skill.
    </p>

    @foreach($memorySkills as $schemaGroup)
    <div class="mb-4">
        <h4 class="text-sm font-medium text-gray-300 mb-2">
            {{ $schemaGroup['schema']->name }}
            <span class="text-gray-500">({{ $schemaGroup['schema']->schema_name }})</span>
        </h4>
        <div class="bg-gray-900 rounded overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-800">
                    <tr>
                        <th class="px-4 py-2 text-left text-gray-400 font-medium">Name</th>
                        <th class="px-4 py-2 text-left text-gray-400 font-medium">Description</th>
                        <th class="px-4 py-2 text-right text-gray-400 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($schemaGroup['skills'] as $skill)
                    <tr class="border-t border-gray-800 hover:bg-gray-800/50">
                        <td class="px-4 py-2">
                            <code class="text-green-400">/{{ $skill->name }}</code>
                        </td>
                        <td class="px-4 py-2 text-gray-400 truncate max-w-md">
                            {{ \Illuminate\Support\Str::limit($skill->description, 80) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button
                                type="button"
                                class="text-red-400 hover:text-red-300 text-xs"
                                onclick="if(confirm('Delete skill /{{ $skill->name }}?')) { deleteSkill('{{ $schemaGroup['schema']->schema_name }}', '{{ $skill->id }}'); }"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    <p class="text-xs text-gray-500 mt-2">
        To add or edit skills, use the memory tools:
        <code class="text-gray-400">php artisan memory:insert --schema=&lt;name&gt; --table=skills --data='{...}'</code>
    </p>
</div>

<script>
async function deleteSkill(schemaName, skillId) {
    try {
        const response = await fetch('{{ route("config.claude-code") }}/skill/' + schemaName + '/' + skillId, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            }
        });

        if (response.ok) {
            window.location.reload();
        } else {
            const data = await response.json();
            alert('Failed to delete skill: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Failed to delete skill: ' + err.message);
    }
}
</script>
@else
<div class="mt-8 pt-8 border-t border-gray-700">
    <h3 class="text-lg font-semibold mb-2">Skills</h3>
    <p class="text-sm text-gray-400">
        No skills found in memory schemas. Import skills from a Claude Code export or add them using memory tools.
    </p>
</div>
@endif

{{-- Import Configuration Section --}}
<div
    x-data="configImport()"
    class="mt-8 pt-8 border-t border-gray-700"
>
    <h3 class="text-lg font-semibold mb-2">Import Configuration</h3>
    <p class="text-sm text-gray-400 mb-4">
        Import settings from a Claude Code export archive. This can update settings.json, CLAUDE.md, agents, commands, rules, and MCP servers.
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
                                    <span x-show="preview?.sections?.settings?.current_exists" class="ml-2 text-xs text-yellow-500">(will overwrite existing)</span>
                                    <span x-show="!preview?.sections?.settings?.current_exists" class="ml-2 text-xs text-green-500">(new file)</span>
                                </div>
                                <select x-model="options.settings" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="overwrite">Overwrite</option>
                                    <option value="skip">Skip</option>
                                </select>
                            </div>
                            <p class="text-xs text-gray-500">Keys: <span x-text="preview?.sections?.settings?.keys?.join(', ') || 'none'"></span></p>
                        </div>

                        {{-- CLAUDE.md --}}
                        <div x-show="preview?.sections?.claude_md" class="bg-gray-900 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium">CLAUDE.md</span>
                                    <span x-show="preview?.sections?.claude_md?.current_exists" class="ml-2 text-xs text-yellow-500">(will overwrite existing)</span>
                                    <span x-show="!preview?.sections?.claude_md?.current_exists" class="ml-2 text-xs text-green-500">(new file)</span>
                                </div>
                                <select x-model="options.claude_md" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="overwrite">Overwrite</option>
                                    <option value="skip">Skip</option>
                                </select>
                            </div>
                            <p class="text-xs text-gray-500">
                                <span x-text="preview?.sections?.claude_md?.lines"></span> lines,
                                <span x-text="Math.round((preview?.sections?.claude_md?.size || 0) / 1024)"></span> KB
                            </p>
                        </div>

                        {{-- Agents --}}
                        <template x-for="dir in ['agents', 'commands', 'rules']" :key="dir">
                            <div x-show="preview?.sections?.[dir]" class="bg-gray-900 rounded p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <div>
                                        <span class="font-medium" x-text="dir + '/'"></span>
                                        <span class="ml-2 text-xs text-gray-500" x-text="'(' + (preview?.sections?.[dir]?.files?.length || 0) + ' files)'"></span>
                                    </div>
                                    <select x-model="options[dir]" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                        <option value="merge_skip">Merge (skip existing)</option>
                                        <option value="merge_overwrite">Merge (overwrite existing)</option>
                                        <option value="skip">Skip all</option>
                                    </select>
                                </div>
                                <div class="text-xs text-gray-500 space-y-1">
                                    <p x-show="preview?.sections?.[dir]?.new_files?.length">
                                        <span class="text-green-500">New:</span>
                                        <span x-text="preview?.sections?.[dir]?.new_files?.join(', ')"></span>
                                    </p>
                                    <p x-show="preview?.sections?.[dir]?.conflict_files?.length">
                                        <span class="text-yellow-500">Conflicts:</span>
                                        <span x-text="preview?.sections?.[dir]?.conflict_files?.join(', ')"></span>
                                    </p>
                                </div>
                            </div>
                        </template>

                        {{-- MCP Servers --}}
                        <div x-show="preview?.sections?.mcp_servers" class="bg-gray-900 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium">MCP Servers</span>
                                    <span class="ml-2 text-xs text-gray-500" x-text="'(' + (preview?.sections?.mcp_servers?.servers?.length || 0) + ' servers)'"></span>
                                </div>
                                <select x-model="options.mcp_servers" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="merge_skip">Merge (skip existing)</option>
                                    <option value="merge_overwrite">Merge (overwrite existing)</option>
                                    <option value="skip">Skip all</option>
                                </select>
                            </div>
                            <div class="text-xs text-gray-500 space-y-1">
                                <p x-show="preview?.sections?.mcp_servers?.new_servers?.length">
                                    <span class="text-green-500">New:</span>
                                    <span x-text="preview?.sections?.mcp_servers?.new_servers?.join(', ')"></span>
                                </p>
                                <p x-show="preview?.sections?.mcp_servers?.conflict_servers?.length">
                                    <span class="text-yellow-500">Conflicts:</span>
                                    <span x-text="preview?.sections?.mcp_servers?.conflict_servers?.join(', ')"></span>
                                </p>
                            </div>
                        </div>

                        {{-- Skills to Memory --}}
                        <div x-show="preview?.sections?.parsed_skills" class="bg-gray-900 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium">Skills to Memory</span>
                                    <span class="ml-2 text-xs text-gray-500" x-text="'(' + (preview?.sections?.parsed_skills?.count || 0) + ' skills found)'"></span>
                                </div>
                                <select x-model="options.skills_to_memory" class="text-sm bg-gray-700 border border-gray-600 rounded px-2 py-1">
                                    <option value="skip">Skip</option>
                                    <option value="merge_skip">Import (skip existing)</option>
                                    <option value="merge_overwrite">Import (overwrite existing)</option>
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
                            <div class="text-xs text-gray-500 mt-2">
                                <p>Skills parsed from commands/ and skills/ directories:</p>
                                <ul class="list-disc list-inside mt-1">
                                    <template x-for="skill in preview?.sections?.parsed_skills?.skills?.slice(0, 10)" :key="skill.name">
                                        <li>
                                            <span class="text-gray-300" x-text="skill.name"></span>
                                            <span class="text-gray-500" x-text="' (' + skill.source + ')'"></span>
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
                            :disabled="applying"
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
        preview: null,
        sessionKey: null,
        availableSchemas: [],
        options: {
            settings: 'overwrite',
            claude_md: 'overwrite',
            agents: 'merge_skip',
            commands: 'merge_skip',
            rules: 'merge_skip',
            mcp_servers: 'merge_skip',
            skills_to_memory: 'skip',
            skills_schema: '',
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
@endsection
