# Configuration UI Conventions

## TLDR

⚠️ TLDR insufficient - read full document when modifying config forms.

**Key rule**: All config forms (agents, commands, skills) must follow identical patterns. When modifying one, check the others for consistency.

---

## Unified Form Layout

All create/edit forms for Claude Code entities (agents, commands, skills) follow a consistent 2-column layout:

```
┌─────────────────────┬──────────────────────────────────┐
│  Left Column (1/3)  │  Right Column (2/3)              │
│  ┌───────────────┐  │  ┌────────────────────────────┐  │
│  │ Metadata Card │  │  │ Content Editor Card        │  │
│  │               │  │  │                            │  │
│  │ - Name        │  │  │ Large textarea for:        │  │
│  │ - Description │  │  │ - System Prompt (agents)   │  │
│  │ - Options     │  │  │ - Prompt (commands)        │  │
│  │               │  │  │ - SKILL.md (skills)        │  │
│  │ ─────────────│  │  │                            │  │
│  │ [Save]        │  │  │                            │  │
│  │ [Cancel]      │  │  │                            │  │
│  │ [Delete]      │  │  │                            │  │
│  └───────────────┘  │  └────────────────────────────┘  │
└─────────────────────┴──────────────────────────────────┘
```

### Mobile Behavior

On mobile (`< lg` breakpoint), columns stack vertically:
- Metadata card appears first (with save/cancel/delete)
- Content editor appears below

This means on mobile, users see the metadata and action buttons first before scrolling to the content editor.

---

## Card Styling

All forms wrap their content in cards:

```blade
<div class="bg-gray-800 p-4 rounded border border-gray-700">
    <h2 class="text-xl font-semibold mb-4">Card Title</h2>
    <!-- content -->
</div>
```

---

## Form Field Conventions

### Required Field Indicators

Use bright red asterisks for required fields:

```blade
<label class="block text-sm font-medium mb-2">
    Field Name <span class="text-red-500 font-bold">*</span>
</label>
```

### Input Styling

Standard input:
```blade
<input class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded">
```

Disabled input (for edit mode):
```blade
<input class="w-full px-3 py-2 bg-gray-600 text-gray-300 border border-gray-600 rounded cursor-not-allowed" disabled>
```

### Help Text

All fields should have explanatory help text below:

```blade
<p class="text-xs text-gray-500 mt-1">
    Explanation of what this field does and how it's used.
</p>
```

### Name Field Pattern

The name field follows special rules:
- **Create mode**: Editable input with pattern validation
- **Edit mode**: Disabled input (names become filenames/directories, can't be changed)
- **Same help text** in both modes, with "(Cannot be changed)" appended in edit mode

```blade
<input
    type="text"
    name="name"
    value="{{ old('name', $entity['name'] ?? '') }}"
    class="w-full px-3 py-2 {{ isset($entity) ? 'bg-gray-600 text-gray-300 cursor-not-allowed' : 'bg-gray-700 text-white' }} border border-gray-600 rounded"
    pattern="[a-z0-9-]+"
    placeholder="my-entity-name"
    {{ isset($entity) ? 'disabled' : 'required' }}
>
<p class="text-xs text-gray-500 mt-1">
    Lowercase letters, numbers, and hyphens only. This becomes the filename.
    @if(isset($entity))
        <span class="text-gray-400">(Cannot be changed)</span>
    @endif
</p>
```

---

## Action Buttons

Buttons appear at the bottom of the metadata card, separated by a border:

```blade
<div class="space-y-2 pt-2 border-t border-gray-700">
    <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium">
        {{ isset($entity) ? 'Save Entity' : 'Create Entity' }}
    </button>

    <a href="{{ route('config.entities') }}" class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium text-center">
        Cancel
    </a>

    @if(isset($entity))
        <button type="button" onclick="..." class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium">
            Delete Entity
        </button>
    @endif
</div>
```

Button order:
1. **Save/Create** (blue) - Primary action
2. **Cancel** (gray) - Return to list
3. **Delete** (red) - Only shown in edit mode

---

## Content Editor

The right column contains a large content editor with:
- Title with required indicator (if applicable)
- Explanatory paragraph
- Large textarea with `config-editor` class

```blade
<div class="bg-gray-800 p-4 rounded border border-gray-700">
    <h2 class="text-xl font-semibold mb-2">
        Content Title <span class="text-red-500 font-bold">*</span>
    </h2>
    <p class="text-sm text-gray-400 mb-4">
        Explanation of what this content is for.
    </p>

    <textarea
        name="content"
        class="config-editor w-full"
        placeholder="Example content..."
        required
    >{{ old('content', $entity['content'] ?? '') }}</textarea>
</div>
```

---

## Checklist for New Forms

When creating or modifying a config form, verify:

- [ ] Uses 2-column grid layout (`grid-cols-1 lg:grid-cols-3`)
- [ ] Metadata in left column (1/3 width)
- [ ] Content editor in right column (2/3 width)
- [ ] Both columns use card styling (bg-gray-800, border, rounded)
- [ ] Required fields have red asterisk (`text-red-500 font-bold`)
- [ ] All fields have help text below (`text-xs text-gray-500`)
- [ ] Name field is disabled in edit mode with "(Cannot be changed)"
- [ ] Action buttons in correct order with border separator
- [ ] Delete button only shown in edit mode
- [ ] Forms use `isset($entity)` to handle create vs edit
- [ ] Single form file handles both create and edit
- [ ] Content editor has helpful placeholder text

---

## Files Using These Conventions

| Form | Location |
|------|----------|
| Agents | `resources/views/config/agents/form.blade.php` |
| Commands | `resources/views/config/commands/form.blade.php` |
| Skills | `resources/views/config/skills/form.blade.php` |

**When modifying one, always check the others for consistency.**
