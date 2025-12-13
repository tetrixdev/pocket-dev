# PocketDev TODO

This file tracks improvements and features needed for the PocketDev Claude Code integration.

## 🟣 Tech Stack Migration (High Priority)

**Decision**: Migrate from Vanilla JS to Alpine.js + HTMX  
**See**: `TECH_STACK.md` for full analysis and rationale

### Phase 0: Cleanup (COMPLETED ✅)
- [x] **Delete Livewire implementation**
  - ✅ Removed `www/app/Livewire/ClaudeChat.php`
  - ✅ Removed `www/resources/views/livewire/claude-chat.blade.php`
  - ✅ Removed Livewire routes from `web.php` (kept auth routes)
  - ✅ Converted to proper Laravel Blade view (`chat.blade.php`)

- [x] **Delete test files**
  - ✅ Removed `www/app/Livewire/SimpleTest.php`
  - ✅ Removed `www/resources/views/livewire/simple-test.blade.php`

- [x] **Delete old documentation**
  - ✅ Removed `HANDOVER_DOCUMENTATION.md` (1,360 lines - replaced by CLAUDE.md)
  - ✅ Removed `IMPLEMENTATION_GUIDE.md` (599 lines - obsolete)
  - ✅ Removed `QUICK_START.md` (93 lines - obsolete)
  - ✅ Removed `www/IMPLEMENTATION_COMPLETE.md` (277 lines - obsolete)

- [x] **Additional cleanup completed (2025-10-26)**
  - ✅ Removed `livewire/livewire` package from composer (~20MB saved)
  - ✅ Deleted unused `www/resources/views/components/layouts/app.blade.php` (had Livewire directives)
  - ✅ Deleted Laravel default `welcome.blade.php` (unused)
  - ✅ Deleted broken Safe Mode feature:
    - Removed `SafeModeController.php` (had broken message handling code)
    - Removed `safe-mode.blade.php` view
    - Removed 4 Safe Mode routes from `web.php`
  - **Result**: Codebase is now 100% Livewire-free and cleaner!

### Phase 1: Fix Vanilla JS (COMPLETED ✅)
All critical bugs have been fixed and features implemented:

- ✅ **Fixed disappearing messages** - Proper async/await flow prevents message clearing
- ✅ **Session list in sidebar** - Loads from Claude's .jsonl files, displays with click handlers
- ✅ **Session persistence** - Uses Claude Code's native .jsonl storage (better than localStorage)
- ✅ **Markdown rendering** - marked.js + highlight.js fully configured
- ✅ **Streaming responses** - EventSource implementation working

### Phase 2: Learn Alpine + HTMX (1-2 Days)
- [ ] **Complete Alpine.js tutorial**
  - Work through https://alpinejs.dev/start-here
  - Understand x-data, x-show, x-for, x-model
  - Learn Alpine Store pattern

- [ ] **Complete HTMX tutorial**
  - Read https://htmx.org/docs/
  - Understand hx-get, hx-post, hx-swap
  - Learn SSE extension

- [ ] **Build prototype component**
  - Create simple Alpine + HTMX example
  - Test streaming with SSE
  - Verify understanding

### Phase 3: Incremental Migration (2-3 Weeks)
Migrate one component at a time, keep app working throughout:

- [ ] **Add libraries to chat.html**
  - Add Alpine.js CDN
  - Add HTMX CDN
  - Add HTMX SSE extension
  - Test page still works

- [ ] **Create Alpine Store**
  - Global state: sessionId, sessions[], authenticated
  - Persist to localStorage
  - Test state reactivity

- [ ] **Migrate Session List (easiest first)**
  - Convert to Alpine x-for loop
  - Add reactive click handlers
  - Update on session changes
  - **Test thoroughly before proceeding**

- [ ] **Migrate Authentication Check**
  - Convert to Alpine x-data
  - Reactive redirect logic
  - Update store on auth status

- [ ] **Migrate Message Display**
  - Convert to Alpine template
  - Reactive message list
  - Markdown rendering in template

- [ ] **Migrate Message Input**
  - HTMX form submission
  - Clear input after send
  - Show loading state

- [ ] **Migrate Streaming**
  - HTMX SSE extension
  - Stream to message container
  - Handle connection errors
  - Add "stop generating" button

- [ ] **Remove vanilla JavaScript**
  - Delete old event handlers
  - Remove manual DOM manipulation
  - Clean up global variables
  - **Test entire app**

### Phase 4: Polish & Optimize (1 Week)
- [ ] **Add build step (optional)**
  - Set up Vite for production
  - Bundle Alpine + HTMX
  - Minify JavaScript
  - Optimize assets

- [ ] **Update documentation**
  - Update CLAUDE.md with Alpine patterns
  - Add Alpine examples to docs
  - Update README if needed

- [ ] **Performance testing**
  - Test with long sessions
  - Test rapid message sending
  - Test multiple tabs
  - Optimize if needed

**Estimated Total Time**: 3-4 weeks of incremental work

**Benefits**:
- ✅ Cleaner, more maintainable code
- ✅ Reactive UI updates automatically
- ✅ Better developer experience
- ✅ Easier to add features
- ✅ Still lightweight (only 29kb)

**See TECH_STACK.md for**:
- Full Alpine + HTMX code examples
- Why we chose this over Livewire/React/Vue
- Detailed migration steps
- Architecture patterns


## 🟡 High Priority (Next Sprint)

### Session Management
- [ ] **Load session list on page load**
  - Fetch from `/api/claude/sessions`
  - Display in sidebar with titles and timestamps
  - Show active session indicator
  - Auto-generate smart titles from first message (instead of "New Session")

- [ ] **Session switching**
  - Click session in sidebar to load it
  - Load session messages from `/api/claude/sessions/{id}`
  - Preserve current session before switching
  - Highlight active session

- [ ] **Session actions**
  - Rename session (inline editing)
  - Delete session (with confirmation)
  - Archive/unarchive sessions
  - Pin important sessions to top

- [ ] **Session search/filter**
  - Search by content or title
  - Filter by date range
  - Filter by project path
  - Sort by: newest, oldest, most active

### UI/UX Improvements
- [ ] **Markdown rendering**
  - Add markdown parser (marked.js or similar)
  - Render Claude's markdown responses properly
  - Support for headers, lists, links, etc.

- [ ] **Code syntax highlighting**
  - Add Prism.js or highlight.js
  - Auto-detect language in code blocks
  - Support for common languages (PHP, JavaScript, Python, SQL, etc.)

- [ ] **Copy code button**
  - Add copy button to code blocks
  - Show "Copied!" feedback
  - Copy to clipboard API

- [x] **Message timestamps** ✅ (Implemented 2025-10-26)
  - Show time for each message
  - Format: "Just now", "5 min ago", or full timestamp
  - Displays below each message

- [ ] **Better loading states**
  - Animated thinking indicator
  - Show "Claude is typing..." with animation
  - Progress indicator for long responses

- [ ] **Error handling**
  - Friendly error messages (not just raw errors)
  - Retry button for failed messages
  - Network status indicator
  - Handle token expiry gracefully

### Streaming Responses
- [x] **Implement streaming in UI**
  - Use `/api/claude/sessions/{session}/stream` endpoint
  - Use Server-Sent Events (EventSource)
  - Show response as it's being generated
  - Much better UX than waiting for full response

- [x] **Streaming UI updates**
  - Stream text character-by-character or chunk-by-chunk
  - Show "stop generating" button (future enhancement)
  - Handle stream errors
  - Reconnect on connection loss (future enhancement)

## 🟢 Medium Priority (Future Sprints)

### Authentication Improvements
- [ ] **Token expiry warnings**
  - Show warning when token expires in < 7 days
  - Show countdown in auth page
  - Auto-redirect to auth when token expires

- [ ] **Token auto-refresh**
  - Implement refresh token logic
  - Auto-refresh before expiry
  - Handle refresh failures

- [ ] **Multiple auth methods**
  - Support API key authentication (for pay-per-use)
  - Support both OAuth and API key simultaneously
  - Let user choose preferred method

### Chat Features
- [ ] **Export conversation**
  - Export as Markdown
  - Export as JSON
  - Export as PDF
  - Include metadata (timestamps, model, cost)

- [ ] **Edit messages**
  - Edit sent messages
  - Re-run from edited message
  - Branch conversations

### Cost Tracking & Analytics
- [x] **Cost display** ✅ (Implemented 2025-10-26)
  - Show cost per message (calculated from usage data)
  - Show session total cost (displayed in sidebar)
  - Token count tracking
  - Cost breakdown: $3/M input tokens, $15/M output tokens (Claude 4.5 Sonnet)
  - Note: Only tracks costs for current session, not historical messages

- [ ] **Extended cost tracking**
  - Show daily/weekly/monthly costs
  - Cost breakdown by model
  - Persist costs to database

- [ ] **Usage analytics**
  - Token usage charts
  - Messages per day/week/month
  - Most active sessions
  - Model usage breakdown

- [ ] **Cost limits**
  - Set daily/weekly/monthly cost limits
  - Warning when approaching limit
  - Disable when limit exceeded
  - Admin override

### Project Management
- [ ] **Multiple project support**
  - Select project path when creating session
  - Quick-switch between projects
  - Project-specific sessions
  - Recent projects list

- [ ] **Project settings**
  - Default working directory per project
  - Allowed tools per project
  - Model preference per project
  - Permission mode per project

### Voice Input (Already Configured)
- [ ] **Test voice transcription**
  - Verify OpenAI Whisper integration works
  - Add microphone button to chat input
  - Show recording indicator
  - Handle permissions

- [ ] **Voice settings**
  - Choose transcription provider (OpenAI vs browser)
  - Language selection
  - Auto-send after transcription option

## 🔵 Low Priority (Nice to Have)

### Advanced Features
- [ ] **Multi-turn conversations**
  - Show conversation as tree/branches
  - Allow exploring different paths
  - Visual representation of conversation flow

- [ ] **Collaborative sessions**
  - Share session with other users
  - Real-time collaboration
  - Comments/annotations

- [ ] **Templates/Prompts**
  - Save common prompts
  - Prompt library
  - Share prompts between users
  - Variables in prompts

- [ ] **Keyboard shortcuts**
  - Cmd/Ctrl + Enter to send
  - Cmd/Ctrl + K for new session
  - Cmd/Ctrl + / for search
  - Arrow keys for navigation

- [ ] **Dark/Light mode toggle**
  - Currently only dark mode
  - Add light mode option
  - System preference detection
  - Persist preference

### Performance
- [ ] **Lazy loading sessions**
  - Don't load all sessions at once
  - Pagination or infinite scroll
  - Load messages on-demand

- [ ] **Database indexing**
  - Index `claude_sessions.last_activity_at`
  - Index `claude_sessions.status`
  - Index for full-text search on messages

- [ ] **Caching**
  - Cache session list (Redis)
  - Cache auth status
  - Invalidate on updates

- [ ] **Asset optimization**
  - Minify JavaScript
  - Use local copies of Tailwind
  - Image optimization
  - Lazy load libraries

### Mobile Responsiveness
- [ ] **Mobile layout**
  - Collapsible sidebar
  - Touch-friendly buttons
  - Mobile-optimized input
  - Better keyboard handling

- [ ] **PWA support**
  - Add manifest.json
  - Service worker for offline
  - Install prompt
  - Push notifications

## 🔧 Technical Debt & Refactoring

### Code Quality
- [ ] **Replace vanilla JS with proper framework**
  - **Option 1**: Fix Livewire implementation (already partially there)
  - **Option 2**: Use Vue.js or React
  - **Option 3**: Alpine.js (lightweight)
  - **Decision needed**: Discuss architecture choice

- [ ] **Separate concerns in chat.html**
  - Move JavaScript to separate file
  - Use modules for organization
  - Add build step (Vite)

- [ ] **Better state management**
  - Currently everything is global variables
  - Use proper state management pattern
  - Handle state persistence

- [ ] **Error handling patterns**
  - Consistent error handling across app
  - Error boundary pattern
  - User-friendly error messages
  - Error logging/reporting

### Testing
- [ ] **Unit tests for ClaudeCodeService**
  - Test query() method
  - Test streamQuery() method
  - Mock proc_open calls
  - Test error scenarios

- [ ] **Unit tests for Controllers**
  - Test ClaudeController endpoints
  - Test ClaudeAuthController
  - Mock ClaudeCodeService

- [ ] **Integration tests**
  - Test full auth flow
  - Test session creation → query → response
  - Test streaming
  - Test error paths

- [ ] **E2E tests**
  - Use Laravel Dusk or Playwright
  - Test complete user workflows
  - Test authentication
  - Test chat interactions

- [ ] **Test coverage**
  - Set up code coverage reporting
  - Aim for >80% coverage on core services
  - CI/CD integration

### API Improvements
- [ ] **API rate limiting**
  - Prevent abuse
  - Per-user limits
  - Graceful degradation

- [ ] **API versioning**
  - Version API endpoints (/api/v1/claude/...)
  - Allow breaking changes
  - Deprecation strategy

- [ ] **API documentation**
  - OpenAPI/Swagger spec
  - Auto-generated docs
  - Example requests/responses
  - Authentication docs

- [ ] **Webhook support**
  - Webhook for session events
  - Webhook for cost alerts
  - Webhook configuration UI

### Security
- [ ] **Tool path traversal protection**
  - `ExecutionContext::isPathAllowed()` is defined but never called
  - All file tools (ReadTool, WriteTool, EditTool, GlobTool, GrepTool) should validate paths
  - Users could potentially access files outside working directory
  - Implement mandatory path validation before file operations

- [ ] **CSRF protection audit**
  - Verify all POST endpoints have CSRF
  - Test CSRF token validation
  - Document CSRF requirements

- [ ] **Input validation**
  - Validate all inputs server-side
  - Sanitize user content
  - Prevent injection attacks

- [ ] **XSS prevention**
  - Audit HTML escaping in chat
  - Test with malicious inputs
  - CSP headers

- [ ] **Rate limiting**
  - Per-user rate limits
  - Per-IP rate limits
  - Configurable limits

- [ ] **Audit logging**
  - Log authentication events
  - Log API usage
  - Log errors and failures
  - Retention policy

## ✅ TTYD Removal (Completed)

The TTYD web terminal container has been removed:
- [x] Removed `docker-ttyd/` directory
- [x] Removed from docker-compose.yml (local and production)
- [x] Removed from nginx proxy configuration
- [x] Removed TerminalController and routes
- [x] Removed terminal views
- [x] Updated GitHub Actions (stopped building TTYD image)
- [x] Updated all documentation
- [x] Created default configs in `docker-laravel/shared/defaults/`

## 📚 Documentation

### User Documentation
- [ ] **Getting Started Guide**
  - Installation steps
  - First-time setup
  - Authentication guide
  - Basic usage tutorial

- [ ] **Feature Documentation**
  - Session management
  - Authentication methods
  - Voice input usage
  - Export/import

- [ ] **Troubleshooting Guide**
  - Common errors and fixes
  - Authentication issues
  - Performance issues
  - Network problems

- [ ] **FAQ**
  - Pricing/cost questions
  - Feature requests
  - Limitations
  - Roadmap

### Developer Documentation
- [ ] **Architecture documentation**
  - System design
  - Data flow diagrams
  - Container architecture
  - API architecture

- [ ] **API documentation**
  - Endpoint reference
  - Authentication
  - Request/response examples
  - Error codes

- [ ] **Contributing guide**
  - Development setup
  - Code style
  - Testing requirements
  - PR process

- [ ] **Deployment guide**
  - Production setup
  - Environment variables
  - Scaling considerations
  - Backup strategies

## 🎨 Design System

- [ ] **Consistent color palette**
  - Define primary/secondary colors
  - Error/warning/success states
  - Dark mode colors
  - Document in style guide

- [ ] **Typography system**
  - Font sizes
  - Line heights
  - Font weights
  - Headings hierarchy

- [ ] **Spacing system**
  - Consistent padding/margins
  - Grid system
  - Component spacing

- [ ] **Component library**
  - Buttons
  - Forms
  - Cards
  - Modals
  - Toast notifications

## 🚀 Future Features (Dream Big)

- [ ] **AI-powered features**
  - Auto-suggest prompts
  - Smart session titles from conversation content
  - Conversation summarization
  - Related sessions suggestions

- [ ] **Integration with other tools**
  - GitHub integration (create issues, PRs)
  - Jira integration
  - Slack notifications
  - Email notifications

- [ ] **Multi-model support**
  - Switch between Claude models mid-conversation
  - Compare responses from different models
  - Model recommendations based on task

- [ ] **Custom tools/plugins**
  - Allow users to define custom tools
  - Plugin marketplace
  - Community-contributed tools

---

## Notes

**Priority Legend:**
- 🔴 Critical - Fix ASAP, blocks users
- 🟡 High - Important for good UX
- 🟢 Medium - Nice to have, plan for future
- 🔵 Low - Long-term improvements

**Update this file as you work:**
- Check off items as completed: `- [x]`
- Add new items as they come up
- Re-prioritize as needed
- Add notes/blockers inline
