# PocketDev Migration Plan: TTYD → PHP Container

**Date:** 2025-10-28
**Goal:** Migrate from TTYD-based development to PHP container as the primary Claude Code environment, eventually removing TTYD entirely.

---

## Executive Summary

### Key Changes
- PHP container becomes primary development environment with full bash/docker/git access
- Users work in `/workspace` (shared volume)
- PocketDev source accessible at `/var/www` (development only)
- Claude credentials remain file-based, git credentials move to encrypted database storage
- Configuration page gets desktop/mobile responsive redesign matching chat interface

### Migration Strategy
- Phased approach with testing after each phase
- Immediate fix for 500 error on config page
- Git credentials via encrypted database storage (recommended)
- Smart default working directory (`/workspace`) with override capability
- UI redesign matching chat.blade.php patterns

---

## Design Decisions

### 1. Git Credentials Storage

**DECISION: Database Storage with Encryption (Option B)**

**Reasoning:**
- ✅ Encrypted at rest via Laravel encryption
- ✅ Manageable through web UI
- ✅ No container restart needed to update
- ✅ Follows established pattern (OpenAI API key)
- ✅ Better for multi-user deployments
- ✅ Can add UI to `/config` page

**Implementation:**
- Store in `app_settings` table: `git_token`, `git_user_name`, `git_user_email`
- Configure git in entrypoint on container start
- Add UI to configuration page

**Migration Path:**
1. Phase 1-3: No git config (work on basics first)
2. Phase 4: Implement database storage + UI
3. Future: Environment variables can be removed entirely

---

### 2. Working Directory Configuration

**DECISION: Smart Default with Override (Option C)**

**Configuration:**
```php
'working_directory' => env('CLAUDE_WORKING_DIR', '/workspace'),
```

**Reasoning:**
- ✅ Sensible default for 95% of use cases (`/workspace`)
- ✅ Override available for PocketDev development (set `CLAUDE_WORKING_DIR=/` in `.env`)
- ✅ No UI complexity needed
- ✅ Well-documented in CLAUDE.md

**Development vs Production:**
- **Development:** Users have access to both `/workspace` AND `/var/www` (PocketDev source)
- **Production:** Only `/workspace` accessible, `/var/www` mount removed

---

### 3. Configuration Page UI Design

**DECISION: Desktop Sidebar + Mobile Full-Page Scroll**

**Pattern:** Match chat.blade.php architecture

**Desktop Layout (≥768px):**
```
┌─────────────────────────────────────────────┐
│ Sidebar (Categories) │ Main Editor Area     │
│  - Files              │  Tabs + Editor       │
│  - System             │  + Preview           │
│  - Git                │  Action Buttons      │
└─────────────────────────────────────────────┘
```

**Mobile Layout (<768px):**
```
┌─────────────┐
│ ☰  Header   │ ← Sticky with hamburger
│ Tabs        │ ← Horizontal scroll
│ Editor      │ ← Full width
│ Preview     │ ← Stacked below
│ Actions     │ ← Fixed bottom
└─────────────┘
```

**Categories:**
- 📄 **Files:** CLAUDE.md, settings.json
- 🔧 **System:** Nginx Proxy Config
- 🔐 **Git:** Configuration form (token, name, email, test button)

---

## Migration Phases

### Phase 1: Infrastructure Setup (PHP Container)

**Objective:** Add workspace mount and set default working directory

**Tasks:**
- [ ] Add `workspace-data:/workspace` volume mount to PHP container in `compose.yml`
- [ ] Update `config/claude.php` to set `working_directory => '/workspace'`
- [ ] Restart containers to apply changes
- [ ] Verify mount with `docker exec pocket-dev-php ls -la /workspace`

**Files Modified:**
- `compose.yml`
- `www/config/claude.php`

**Testing:**
- ✅ PHP container can access `/workspace`
- ✅ Claude sessions start in `/workspace` directory
- ✅ Frontend uses single WORKING_DIRECTORY constant
- ✅ New sessions create with project_path=/workspace

**Status:** ✅ COMPLETED

---

### Phase 1.5: Add Git/GitHub Command Permissions

**Objective:** Enable git and gh commands without requiring manual approval

**Problem:**
Current `settings.json` only allows specific bash commands (ls, mkdir, docker, etc.) but not `git` or `gh`. Users must manually approve every git/gh command, which is disruptive for development workflow.

**Tasks:**
- [ ] Add `Bash(git:*)` to allowed permissions in settings.json
- [ ] Add `Bash(gh:*)` to allowed permissions in settings.json
- [ ] Test git commands work without approval
- [ ] Test gh commands work without approval

**Files Modified:**
- `/home/appuser/.claude/settings.json` (in user-data volume)

**Implementation:**

Add to the `allow` array in settings.json:
```json
{
  "permissions": {
    "allow": [
      "Bash(ls:*)",
      "Bash(mkdir:*)",
      "Bash(find:*)",
      "Bash(docker:*)",
      "Bash(grep:*)",
      "Bash(rg:*)",
      "Bash(pandoc:*)",
      "Bash(xlsx2csv:*)",
      "Bash(extract_msg:*)",
      "Bash(git:*)",      // ← ADD THIS
      "Bash(gh:*)",       // ← ADD THIS
      // ... rest of permissions
    ]
  }
}
```

**Testing:**
- [ ] Ask Claude: "List my GitHub repositories with gh repo list"
- [ ] Expected: Command runs without approval prompt
- [ ] Ask Claude: "What is the git status?"
- [ ] Expected: Command runs without approval prompt
- [ ] Ask Claude: "Clone a repository"
- [ ] Expected: git clone works without approval

**Notes:**
- This change only affects the PHP container's Claude instance
- Users can still deny dangerous operations if they appear suspicious
- Git operations are essential for development workflow

**Status:** 🔄 PENDING

---

### Phase 2: Fix Configuration Page (Immediate)

**Objective:** Fix 500 errors on `/config` page

**Tasks:**
- [ ] Update `ConfigController.php` - change paths from `/ttyd-user-home/` to `/home/appuser/`
- [ ] Clean up `entrypoint.sh` - remove dead `/ttyd-user-home` permission block (lines 24-29)
- [ ] Test `/config` page loads without errors
- [ ] Test each config tab loads content
- [ ] Test save/reload functionality

**Files Modified:**
- `www/app/Http/Controllers/ConfigController.php`
- `docker-laravel/local/php/entrypoint.sh`

**Specific Changes:**

**ConfigController.php:**
```php
'claude' => [
    'title' => 'CLAUDE.md',
    'local_path' => '/home/appuser/.claude/CLAUDE.md',  // ← CHANGE
    'syntax' => 'markdown',
    'validate' => false,
    'reload_cmd' => null,
],
'settings' => [
    'title' => 'Claude Settings',
    'local_path' => '/home/appuser/.claude/settings.json',  // ← CHANGE
    'syntax' => 'json',
    'validate' => false,
    'reload_cmd' => null,
],
```

**entrypoint.sh:**
```bash
# DELETE lines 24-29:
if [ -d "/ttyd-user-home" ]; then
    echo "Setting permissions on /ttyd-user-home..."
    chmod 775 /ttyd-user-home 2>/dev/null || true
    find /ttyd-user-home -type f -exec chmod 664 {} \; 2>/dev/null || true
    find /ttyd-user-home -type d -exec chmod 775 {} \; 2>/dev/null || true
fi
```

**Testing:**
- [ ] Visit `/config` - no 500 error
- [ ] CLAUDE.md tab loads content
- [ ] settings.json tab loads content
- [ ] nginx tab loads content
- [ ] Edit CLAUDE.md and save - persists
- [ ] Reload page - changes preserved

---

### Phase 3: Update Claude Configuration Files

**Objective:** Remove TTYD references, update for PHP container context

**Tasks:**
- [ ] Update `/home/appuser/.claude/CLAUDE.md` - change container references and paths
- [ ] Update `/home/appuser/.claude/agents/docker-and-proxy-nginx-agent.md` - update container context
- [ ] Update `/home/appuser/.claude/settings.json` - add `/var/www` permissions for dev mode
- [ ] Test files are correctly updated in both containers (shared volume)

**Files Modified:**
- `/home/appuser/.claude/CLAUDE.md` (in user-data volume)
- `/home/appuser/.claude/agents/docker-and-proxy-nginx-agent.md` (in user-data volume)
- `/home/appuser/.claude/settings.json` (in user-data volume)

**CLAUDE.md Changes:**

Replace references:
- `pocket-dev-ttyd` → `pocket-dev-php`
- `/home/devuser` → `/home/appuser`

Add section:
```markdown
## Working Directory

Claude Code starts in `/workspace` by default. This is where all user development work happens.

**Available directories:**
- `/workspace` - User projects (git clones, code, etc.)
- `/var/www` - PocketDev source code (Laravel app)
  - **Development only**: This directory is available for working on PocketDev itself
  - **Production**: This directory will not be accessible in deployed images

When a user clones a repository with `gh repo clone`, it will create `/workspace/repo-name/`.
You can then work within that directory for all git operations, file edits, etc.

**To work on PocketDev itself:** Set `CLAUDE_WORKING_DIR=/` in `.env` to access both `/workspace` and `/var/www`.
```

**docker-and-proxy-nginx-agent.md Changes:**

Replace all references:
- `pocket-dev-ttyd` → `pocket-dev-php`
- `/home/devuser` → `/home/appuser`

Update context section:
```markdown
## General Pocket-Dev Environment Overview

You are running inside the **pocket-dev-php** container, which is part of the pocket-dev development environment.

**Current context:**
- **Container:** pocket-dev-php
- **User workspace:** `/workspace` (where user projects should be created)
- **Home directory:** `/home/appuser` (persistent across restarts)
```

**settings.json Changes:**

Add `/var/www` permissions (development mode):
```json
{
  "permissions": {
    "allow": [
      "Bash(ls:*)",
      "Bash(mkdir:*)",
      "Bash(find:*)",
      "Bash(docker:*)",
      "Bash(grep:*)",
      "Bash(rg:*)",
      "Bash(pandoc:*)",
      "Bash(xlsx2csv:*)",
      "Bash(extract_msg:*)",

      "mcp__browsermcp__browser_navigate",
      "mcp__browsermcp__browser_snapshot",
      "mcp__browsermcp__browser_click",
      "mcp__browsermcp__browser_hover",
      "mcp__browsermcp__browser_type",
      "mcp__browsermcp__browser_select_option",
      "mcp__browsermcp__browser_press_key",
      "mcp__browsermcp__browser_wait",
      "mcp__browsermcp__browser_get_console_logs",
      "mcp__browsermcp__browser_screenshot",

      "Read(//etc/nginx-proxy-config/**)",
      "Edit(//etc/nginx-proxy-config/**)",
      "Read(/workspace/**)",
      "Edit(/workspace/**)",
      "Write(/workspace/**)",
      "Read(/var/www/**)",
      "Edit(/var/www/**)",
      "Write(/var/www/**)",
      "Read(//tmp/**)",
      "Edit(//tmp/**)",
      "Write(//tmp/**)"
    ],
    "deny": [
      "Read(**/.env)",
      "Edit(**/.env)",
      "Write(**/.env)"
    ]
  },
  "model": "sonnet"
}
```

**Testing:**
- [ ] Start new Claude session
- [ ] Verify working directory is `/workspace`
- [ ] Create test file in `/workspace/test.txt`
- [ ] Read file from `/var/www/composer.json`
- [ ] Verify CLAUDE.md shows correct container info

---

### Phase 4: Git Credentials Implementation

**Objective:** Add encrypted database storage for git credentials with UI management

**Tasks:**
- [ ] Add methods to `AppSettingsService.php`
- [ ] Create controller methods in `ConfigController.php`
- [ ] Add API routes for git config
- [ ] Update entrypoint to configure git from database
- [ ] Create UI for git config management
- [ ] Test end-to-end workflow

**Files Created/Modified:**
- `www/app/Services/AppSettingsService.php` (add methods)
- `www/app/Http/Controllers/ConfigController.php` (add methods)
- `www/routes/web.php` or `api.php` (add routes)
- `docker-laravel/local/php/entrypoint.sh` (add git configuration)
- `www/resources/views/config/index.blade.php` (add git tab UI)

**4.1 AppSettingsService.php Additions:**

```php
/**
 * Get Git token
 */
public function getGitToken(): ?string
{
    return $this->get('git_token');
}

/**
 * Set Git token
 */
public function setGitToken(string $token): AppSetting
{
    Log::info('Git token updated');
    return $this->set('git_token', $token);
}

/**
 * Get Git user name
 */
public function getGitUserName(): ?string
{
    return $this->get('git_user_name');
}

/**
 * Set Git user name
 */
public function setGitUserName(string $name): AppSetting
{
    return $this->set('git_user_name', $name);
}

/**
 * Get Git user email
 */
public function getGitUserEmail(): ?string
{
    return $this->get('git_user_email');
}

/**
 * Set Git user email
 */
public function setGitUserEmail(string $email): AppSetting
{
    return $this->set('git_user_email', $email);
}

/**
 * Check if Git is configured
 */
public function hasGitConfig(): bool
{
    return $this->has('git_token')
        && $this->has('git_user_name')
        && $this->has('git_user_email');
}

/**
 * Delete all Git configuration
 */
public function deleteGitConfig(): bool
{
    Log::info('Git configuration deleted');
    $this->delete('git_token');
    $this->delete('git_user_name');
    $this->delete('git_user_email');
    return true;
}
```

**4.2 ConfigController.php Additions:**

```php
/**
 * Get Git configuration status
 */
public function getGitConfig(): JsonResponse
{
    $settings = app(AppSettingsService::class);

    return response()->json([
        'configured' => $settings->hasGitConfig(),
        'user_name' => $settings->getGitUserName(),
        'user_email' => $settings->getGitUserEmail(),
        'has_token' => $settings->has('git_token'),
    ]);
}

/**
 * Save Git configuration
 */
public function saveGitConfig(Request $request): JsonResponse
{
    $validated = $request->validate([
        'token' => 'required|string|min:20',
        'user_name' => 'required|string|max:255',
        'user_email' => 'required|email|max:255',
    ]);

    try {
        $settings = app(AppSettingsService::class);
        $settings->setGitToken($validated['token']);
        $settings->setGitUserName($validated['user_name']);
        $settings->setGitUserEmail($validated['user_email']);

        return response()->json([
            'success' => true,
            'message' => 'Git configuration saved successfully. Restart PHP container to apply changes.'
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to save git config', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'error' => 'Failed to save configuration: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Delete Git configuration
 */
public function deleteGitConfig(): JsonResponse
{
    try {
        $settings = app(AppSettingsService::class);
        $settings->deleteGitConfig();

        return response()->json([
            'success' => true,
            'message' => 'Git configuration deleted. Restart PHP container to apply changes.'
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to delete git config', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'error' => 'Failed to delete configuration: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Test Git connection
 */
public function testGitConnection(): JsonResponse
{
    $settings = app(AppSettingsService::class);

    if (!$settings->hasGitConfig()) {
        return response()->json([
            'success' => false,
            'message' => 'Git configuration not found. Please configure Git first.'
        ], 400);
    }

    try {
        $token = $settings->getGitToken();

        // Test gh auth status
        $command = sprintf('GH_TOKEN=%s gh auth status 2>&1', escapeshellarg($token));
        exec($command, $output, $returnCode);

        return response()->json([
            'success' => $returnCode === 0,
            'message' => $returnCode === 0
                ? 'GitHub connection successful!'
                : 'GitHub connection failed. Please check your token.',
            'output' => implode("\n", $output),
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to test git connection', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'message' => 'Connection test failed: ' . $e->getMessage()
        ], 500);
    }
}
```

**4.3 Routes Addition (web.php or api.php):**

```php
// Git configuration routes
Route::get('/config/git', [ConfigController::class, 'getGitConfig'])->name('config.git.get');
Route::post('/config/git', [ConfigController::class, 'saveGitConfig'])->name('config.git.save');
Route::delete('/config/git', [ConfigController::class, 'deleteGitConfig'])->name('config.git.delete');
Route::post('/config/git/test', [ConfigController::class, 'testGitConnection'])->name('config.git.test');
```

**4.4 Entrypoint Git Configuration:**

Add to `docker-laravel/local/php/entrypoint.sh` after composer install:

```bash
# Configure git from database if available
echo "Configuring git from database..."
if [ -f "/var/www/artisan" ]; then
    # Wait for database to be ready
    until php /var/www/artisan migrate:status >/dev/null 2>&1; do
        echo "Waiting for database..."
        sleep 1
    done

    # Get git config from database using tinker
    GIT_NAME=$(php /var/www/artisan tinker --execute="echo app(\App\Services\AppSettingsService::class)->getGitUserName() ?? '';" 2>/dev/null | tail -1)
    GIT_EMAIL=$(php /var/www/artisan tinker --execute="echo app(\App\Services\AppSettingsService::class)->getGitUserEmail() ?? '';" 2>/dev/null | tail -1)
    GIT_TOKEN=$(php /var/www/artisan tinker --execute="echo app(\App\Services\AppSettingsService::class)->getGitToken() ?? '';" 2>/dev/null | tail -1)

    # Configure git if values exist
    if [ -n "$GIT_NAME" ] && [ "$GIT_NAME" != "null" ] && [ "$GIT_NAME" != "" ]; then
        git config --global user.name "$GIT_NAME"
        echo "✓ Git user.name configured from database: $GIT_NAME"
    fi

    if [ -n "$GIT_EMAIL" ] && [ "$GIT_EMAIL" != "null" ] && [ "$GIT_EMAIL" != "" ]; then
        git config --global user.email "$GIT_EMAIL"
        echo "✓ Git user.email configured from database: $GIT_EMAIL"
    fi

    if [ -n "$GIT_TOKEN" ] && [ "$GIT_TOKEN" != "null" ] && [ "$GIT_TOKEN" != "" ]; then
        mkdir -p /home/appuser/.config/gh
        cat > /home/appuser/.config/gh/hosts.yml <<EOF
github.com:
    oauth_token: $GIT_TOKEN
    user: $GIT_NAME
    git_protocol: https
EOF
        chmod 600 /home/appuser/.config/gh/hosts.yml
        echo "✓ GitHub CLI configured from database"
    fi
fi
```

**4.5 UI Implementation (Partial - will complete in Phase 5):**

Add git config tab to config page (detailed UI in Phase 5).

**Testing:**
- [ ] Access `/config` and navigate to Git tab
- [ ] Save git credentials (token, name, email)
- [ ] Verify credentials are encrypted in database
- [ ] Test connection button works
- [ ] Restart PHP container
- [ ] Verify `git config --list` shows name/email
- [ ] Verify `gh auth status` shows authenticated
- [ ] Clone a test repo without prompting for credentials

---

### Phase 5: Configuration Page UI Revamp

**Objective:** Redesign config page to match chat.blade.php with desktop/mobile responsive layout

**Tasks:**
- [ ] Restructure config/index.blade.php with desktop sidebar + mobile drawer
- [ ] Add category-based navigation (Files, System, Git)
- [ ] Implement git config form with validation
- [ ] Match color scheme and styles from chat.blade.php
- [ ] Add keyboard shortcuts (Ctrl+S for save)
- [ ] Test responsive behavior on desktop and mobile
- [ ] Add status indicators and notifications

**Files Modified:**
- `www/resources/views/config/index.blade.php` (major refactor)

**Key Design Elements:**

**Desktop Layout:**
- Left sidebar with categories (like chat sidebar)
- Main area with tabs + dual-pane editor/preview
- Fixed header with title
- Action buttons at bottom of main area

**Mobile Layout:**
- Hamburger menu for categories (drawer)
- Horizontal scrolling tabs
- Stacked editor/preview (not side-by-side)
- Fixed bottom action bar with safe-area padding

**Git Config Tab:**
- Form fields instead of text editor
- Password field for token (with show/hide toggle)
- Test connection button with loading state
- Status indicator (configured/not configured)
- Clear/delete button

**Color Scheme (from chat.blade.php):**
- Background: `bg-gray-900`
- Cards: `bg-gray-800`
- Borders: `border-gray-700`
- Primary action: `bg-blue-600 hover:bg-blue-700`
- Success: `bg-green-600`
- Text: `text-gray-100`, `text-gray-400` (secondary)

**Alpine.js State Structure:**

```javascript
function configEditor(configs) {
    return {
        // Existing state
        configs: configs,
        activeTab: 'claude',
        activeCategory: 'files',
        contents: {},
        previews: {},
        loading: {},
        saving: {},
        notification: null,

        // New state for mobile
        showMobileDrawer: false,

        // Git config state
        gitConfig: {
            token: '',
            userName: '',
            userEmail: '',
            showToken: false,
            configured: false,
            testing: false,
        },

        // Methods...
        init() { /* ... */ },
        switchTab(id) { /* ... */ },
        switchCategory(category) { /* ... */ },
        loadConfig(id) { /* ... */ },
        saveConfig(id) { /* ... */ },

        // Git methods
        async loadGitConfig() { /* ... */ },
        async saveGitConfig() { /* ... */ },
        async testGitConnection() { /* ... */ },
        async deleteGitConfig() { /* ... */ },
    };
}
```

**New Categories Structure:**

```javascript
const categories = {
    files: {
        title: '📄 Files',
        items: ['claude', 'settings']
    },
    system: {
        title: '🔧 System',
        items: ['nginx']
    },
    git: {
        title: '🔐 Git',
        items: ['git-config']
    }
};
```

**Testing:**
- [ ] Desktop: Sidebar navigation works
- [ ] Desktop: Tabs within categories work
- [ ] Desktop: Editor/preview dual-pane works
- [ ] Desktop: Save/reload buttons work
- [ ] Mobile: Drawer opens/closes
- [ ] Mobile: Categories accessible via drawer
- [ ] Mobile: Editor stacks above preview
- [ ] Mobile: Fixed bottom bar works
- [ ] Mobile: Safe area insets applied
- [ ] Git tab: Form validation works
- [ ] Git tab: Save/test/delete buttons work
- [ ] Keyboard: Ctrl+S saves current config
- [ ] Notifications appear and auto-hide

---

### Phase 6: Chat Interface Updates

**Objective:** Update chat interface to reflect PHP container context

**Tasks:**
- [ ] Update sidebar info to show `pocket-dev-php` container
- [ ] Update working directory display
- [ ] Update accessible directories info
- [ ] Verify config page link works
- [ ] Update any TTYD references in comments

**Files Modified:**
- `www/resources/views/chat.blade.php`

**Changes:**

Desktop sidebar (around line 92-95):
```html
<div class="p-4 border-t border-gray-700 text-xs text-gray-400">
    <!-- ... existing session cost ... -->
    <div>Container: pocket-dev-php</div>
    <div>Working Dir: /workspace</div>
    <div class="text-gray-500">Access: /workspace, /var/www</div>
    <a href="/config" class="text-blue-400 hover:text-blue-300">⚙️ Configuration</a>
    <button @click="showShortcutsModal = true" class="text-blue-400 hover:text-blue-300 ml-2">Shortcuts</button>
</div>
```

Mobile drawer (similar location):
```html
<div class="p-4 border-t border-gray-700 text-xs text-gray-400">
    <div class="mb-2">Cost: <span id="session-cost-mobile" class="text-green-400 font-mono">$0.00</span></div>
    <div class="mb-2"><span id="total-tokens-mobile">0 tokens</span></div>
    <div class="mb-2">Container: pocket-dev-php</div>
    <button @click="showShortcutsModal = true; showMobileDrawer = false" class="text-blue-400 hover:text-blue-300">
        View Shortcuts
    </button>
</div>
```

**Testing:**
- [ ] Desktop sidebar shows correct container info
- [ ] Mobile drawer shows correct container info
- [ ] Config link navigates to `/config`
- [ ] No visual regressions

---

### Phase 7: Production Image Preparation

**Objective:** Prepare production compose.yml and configs to restrict access to /var/www

**Tasks:**
- [ ] Update `deploy/compose.yml` to remove `/var/www` source mount
- [ ] Create production-specific `settings.json` without `/var/www` permissions
- [ ] Document production vs development differences
- [ ] Test production image build

**Files Modified:**
- `deploy/compose.yml`
- Documentation files

**deploy/compose.yml Changes:**

Remove PocketDev source mount:
```yaml
# pocket-dev-php service
volumes:
  - workspace-data:/workspace
  # DO NOT include: - .:/pocketdev-source
```

**Production Settings Strategy:**

Option A: Build different settings.json into production image
Option B: Use environment variable to control permissions
Option C: Document that users should manually edit settings.json for production

**Recommendation:** Option A - build production-safe settings.json into image

**Testing:**
- [ ] Build production image
- [ ] Verify `/var/www` not accessible from Claude
- [ ] Verify `/workspace` still accessible
- [ ] Verify git still works
- [ ] Verify config page works

---

### Phase 8: Testing & Validation

**Objective:** Comprehensive end-to-end testing of all migration components

**Tasks:**
- [ ] Test config page (all tabs, save/reload)
- [ ] Test git functionality (save, test, use in Claude)
- [ ] Test Claude workspace operations
- [ ] Test agent behavior
- [ ] Test responsive design
- [ ] Verify no regressions

**8.1 Config Page Testing:**
- [ ] Visit `/config` - loads without errors
- [ ] Desktop: Sidebar navigation works
- [ ] Desktop: All tabs load content
- [ ] Desktop: Edit and save CLAUDE.md
- [ ] Desktop: Edit and save settings.json
- [ ] Desktop: Edit and save nginx config
- [ ] Desktop: Validate nginx config works
- [ ] Desktop: Reload button works
- [ ] Mobile: Drawer opens/closes
- [ ] Mobile: Categories accessible
- [ ] Mobile: Tabs scroll horizontally
- [ ] Mobile: Editor/preview stack correctly
- [ ] Mobile: Fixed bottom bar visible
- [ ] Git tab: Save credentials
- [ ] Git tab: Test connection succeeds
- [ ] Git tab: Delete credentials works
- [ ] Keyboard: Ctrl+S saves current tab
- [ ] Notifications appear and auto-dismiss

**8.2 Git Functionality Testing:**
- [ ] Save git credentials via UI
- [ ] Test connection button shows success
- [ ] Restart PHP container
- [ ] Verify `docker exec pocket-dev-php git config --list` shows name/email
- [ ] Verify `docker exec pocket-dev-php gh auth status` shows authenticated
- [ ] Start new Claude session
- [ ] Ask Claude to clone a repo
- [ ] Verify no credential prompts
- [ ] Verify repo cloned to `/workspace/repo-name/`
- [ ] Ask Claude to make a commit
- [ ] Ask Claude to push (if you want to test)

**8.3 Claude Workspace Testing:**
- [ ] New session starts in `/workspace`
- [ ] Can create files in `/workspace/test/`
- [ ] Can read files from `/workspace`
- [ ] Can execute bash commands
- [ ] Can run docker commands
- [ ] Can access `/var/www` for PocketDev edits (dev mode)
- [ ] Cannot access outside allowed directories
- [ ] File permissions work correctly (www-data user)

**8.4 Agent Testing:**
- [ ] docker-and-proxy-nginx-agent shows correct container context
- [ ] Agent can set up new projects in `/workspace`
- [ ] Nginx proxy routes work for new projects
- [ ] Docker compose operations work from agent

**8.5 Responsive Design Testing:**
- [ ] Test on desktop browser (≥768px width)
- [ ] Test on tablet (768px threshold)
- [ ] Test on mobile (320px - 767px)
- [ ] Test on iPhone (safe area insets)
- [ ] Test landscape orientation
- [ ] No horizontal scroll issues
- [ ] Touch targets appropriately sized

**8.6 Regression Testing:**
- [ ] Chat interface still works
- [ ] Session management works
- [ ] Voice recording works
- [ ] Cost tracking works
- [ ] Streaming responses work
- [ ] Authentication flow works

---

### Phase 9: TTYD Removal (Final Phase)

**Objective:** Remove TTYD container and cleanup references

**Tasks:**
- [ ] Comment out or remove `pocket-dev-ttyd` service from `compose.yml`
- [ ] Update nginx config to remove `/terminal-ws/` route
- [ ] Remove TTYD-related volumes (if not shared)
- [ ] Update navigation to remove terminal links
- [ ] Clean up docker-ttyd directory (optional - can keep for reference)
- [ ] Update README and documentation
- [ ] Update CLAUDE.md in project root

**Files Modified:**
- `compose.yml`
- `docker-proxy/shared/nginx.conf.template`
- `README.md`
- `CLAUDE.md` (project root)
- Various navigation links

**compose.yml Changes:**

```yaml
# Comment out or remove entirely:
# pocket-dev-ttyd:
#   build:
#     context: .
#     dockerfile: docker-ttyd/shared/Dockerfile
#   ...
```

**nginx.conf.template Changes:**

Remove TTYD upstream and route:
```nginx
# Remove this upstream block:
# upstream ttyd {
#     server pocket-dev-ttyd:7681;
# }

# Remove this location block:
# location /terminal-ws/ {
#     proxy_pass http://ttyd/;
#     ...
# }
```

**Navigation Updates:**

Any "Terminal" links should be removed or updated to "Chat"

**Documentation Updates:**

- Update README to remove TTYD references
- Update architecture diagrams
- Update setup instructions
- Explain migration in changelog

**Testing:**
- [ ] Compose up succeeds without TTYD
- [ ] All services start correctly
- [ ] No broken links in UI
- [ ] Nginx config valid without TTYD routes
- [ ] Documentation accurate

---

## Remaining Questions

These questions should be answered before or during specific phases:

### Question 1: Git Config in Entrypoint vs Runtime

The entrypoint configures git once at container start. If user updates credentials via UI while container is running, changes require restart.

**Options:**
- **A)** Accept this limitation (document: "Changes require container restart")
- **B)** Also configure git before each Claude session starts (in ClaudeCodeService)
- **C)** Add a "Restart PHP Container" button to config page

**Decision needed:** Before Phase 4

---

### Question 2: Production Default Files

For production images, how should default CLAUDE.md, settings.json, and agents be distributed?

**Options:**
- **A)** Add defaults copying to PHP entrypoint (like TTYD does)
- **B)** Bake defaults into PHP container image
- **C)** Provide init command users run once (`php artisan claude:init`)

**Decision needed:** Before Phase 7

---

### Question 3: Config Page Link Text

Currently config page has "🖥️ Terminal" link back to chat.

**Options:**
- **A)** "💬 Chat" (it's the chat interface)
- **B)** "🏠 Home" (generic)
- **C)** "← Back" (simple)
- **D)** Something else?

**Decision needed:** Before Phase 5

---

### Question 4: Mobile Config Page Tab Priority

Which config should be shown first on mobile?

**Options:**
- **A)** CLAUDE.md (most commonly edited)
- **B)** Git Config (one-time setup)
- **C)** Settings.json (advanced)

**Decision needed:** Before Phase 5

---

### Question 5: Nginx Config Validation

Should nginx config validation be enhanced?

**Options:**
- **A)** Keep basic validation (current)
- **B)** Add full validation (write temp file, run `nginx -t` in proxy container)
- **C)** Remove validation entirely

**Decision needed:** Before Phase 5

---

## Success Criteria

### Phase Completion Criteria

Each phase is considered complete when:
1. All tasks in phase checklist are completed
2. All tests pass
3. No regressions introduced
4. Changes committed to git (if appropriate)

### Overall Migration Success

Migration is successful when:
1. PHP container is primary development environment
2. Users can work in `/workspace` with git/docker/bash
3. Configuration page works on desktop and mobile
4. Git credentials managed via encrypted database
5. Claude Code operations work identically to TTYD setup
6. TTYD container can be safely removed
7. Documentation is updated and accurate

---

## Rollback Plan

If issues arise during migration:

### Phase 1-3 Rollback:
- Revert `compose.yml` changes
- Revert `config/claude.php` changes
- Restart containers

### Phase 4-5 Rollback:
- Drop git config columns from database
- Revert controller/service changes
- Revert UI changes
- Remove git config from entrypoint

### Phase 6-9 Rollback:
- Restore TTYD service in compose.yml
- Restore nginx routes
- Revert documentation

---

## Notes

- Always test changes in development before applying to production
- Keep TTYD container running until Phase 9 (safety net)
- Take database backups before Phase 4
- Document any deviations from plan
- Update this plan if requirements change

---

## Timeline Estimate

- **Phase 1:** 15 minutes (infrastructure changes + restart)
- **Phase 2:** 30 minutes (fix config page)
- **Phase 3:** 1 hour (update all config files)
- **Phase 4:** 2-3 hours (git credentials implementation)
- **Phase 5:** 3-4 hours (UI revamp)
- **Phase 6:** 30 minutes (chat updates)
- **Phase 7:** 1 hour (production prep)
- **Phase 8:** 2-3 hours (comprehensive testing)
- **Phase 9:** 1 hour (TTYD removal)

**Total:** ~12-16 hours of work

---

## Contact & Support

If issues arise during migration:
- Check Laravel logs: `docker compose logs -f pocket-dev-php`
- Check nginx logs: `docker compose logs -f pocket-dev-proxy`
- Review this document for troubleshooting
- Check git commit history for recent changes
