# PocketDev Claude Code UI Implementation Plan

**Goal:** Build a comprehensive, user-friendly Claude Code interface with full feature parity to the CLI.

**Total Estimated Time:** 12-15 hours
**Start Date:** 2025-10-20
**Current Step:** Not started

---

## Phase 1: Thinking Mode Enhancement (Quick Wins)
**Estimated Time:** 2-3 hours

### ✅ Step 1.1: Visual Thinking Indicator
**Goal:** Add a visible thinking level indicator next to the input field

**Tasks:**
- [ ] Add thinking badge HTML next to Send button
- [ ] Style 4 thinking states (Off, Think, Think Hard, Ultrathink)
- [ ] Add color coding (Gray, Blue, Purple, Gold)
- [ ] Add icons for each state (🧠, 💭, 🤔, 🌟)
- [ ] Store thinking state in JavaScript variable
- [ ] Make badge clickable to cycle through states

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Reload the page
2. You should see a thinking badge next to the Send button
3. Click the badge to cycle through: Off → Think → Think Hard → Ultrathink → Off
4. Each state should have different color and icon

**Acceptance Criteria:**
- Badge is visible and styled correctly
- Clicking cycles through all 4 states
- Colors match design (gray, blue, purple, gold)

---

### ✅ Step 1.2: Tab Key Toggle
**Goal:** Implement Tab key to toggle thinking mode

**Tasks:**
- [ ] Add keydown event listener on prompt input
- [ ] Detect Tab key press (prevent default)
- [ ] Cycle through thinking states on Tab
- [ ] Update badge visual when Tab is pressed
- [ ] Prevent Tab from moving focus

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Focus on the message input field
2. Press Tab key
3. Thinking badge should cycle through states
4. Press Tab multiple times to verify cycling works
5. Focus should stay in input field (not move to next element)

**Acceptance Criteria:**
- Tab key cycles through thinking states
- Input field retains focus
- Badge updates visually on each Tab press

---

### ✅ Step 1.3: Auto-Prefix Prompts with Thinking Keywords
**Goal:** Automatically prepend thinking keywords to user prompts based on selected level

**Tasks:**
- [ ] Modify `sendMessage()` function
- [ ] Check current thinking state before sending
- [ ] Prepend appropriate keyword:
  - Off: no prefix
  - Think: "think: "
  - Think Hard: "think hard: "
  - Ultrathink: "ultrathink: "
- [ ] Send modified prompt to backend
- [ ] Display original (non-prefixed) prompt in UI

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Set thinking to "Ultrathink"
2. Send message: "What is 2+2?"
3. Check browser console/network tab - actual request should be "ultrathink: What is 2+2?"
4. Response should include thinking block
5. UI should show "What is 2+2?" (without prefix)
6. Try with different thinking levels

**Acceptance Criteria:**
- Prompts are prefixed based on thinking level
- Thinking blocks appear in responses when enabled
- Original prompt displays in UI (no prefix shown)
- Works for all 4 thinking levels

---

## Phase 2: Collapsible Options Panel
**Estimated Time:** 4-5 hours

### ✅ Step 2.1: Options Panel UI Structure
**Goal:** Create collapsible options panel below input field

**Tasks:**
- [ ] Add options panel HTML below input/send area
- [ ] Add collapse/expand toggle button
- [ ] Implement expand/collapse animation (300ms)
- [ ] Save collapsed state to localStorage
- [ ] Style panel with proper spacing and borders
- [ ] Add "Options" header with down/up arrow icon

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Look below the message input - should see "⚙️ Options ▼"
2. Click to expand - panel should slide down smoothly
3. Click again to collapse - panel should slide up
4. Reload page - panel should remember its state (collapsed/expanded)

**Acceptance Criteria:**
- Panel expands and collapses smoothly
- State persists across page reloads
- Clean visual design with proper spacing

---

### ✅ Step 2.2: Model Selection Dropdown
**Goal:** Add model selection to options panel

**Tasks:**
- [ ] Add model dropdown in options panel
- [ ] List available models:
  - Sonnet 4.5 (claude-sonnet-4-5-20250929)
  - Haiku 4.5 (claude-haiku-4-5-20251001)
  - Opus 4 (claude-opus-4-20250514)
- [ ] Show pricing info for each model
- [ ] Store selected model in localStorage
- [ ] Pass model to backend in query options
- [ ] Update backend to accept dynamic model

**Files to Modify:**
- `resources/views/chat.blade.php`
- `app/Http/Controllers/Api/ClaudeController.php`
- `app/Services/ClaudeCodeService.php`

**Testing Instructions:**
1. Expand options panel
2. See model dropdown with 3 options
3. Select "Haiku 4.5"
4. Send a message
5. Check response metadata - should show haiku model was used
6. Reload page - Haiku should still be selected

**Acceptance Criteria:**
- Dropdown shows all 3 models with pricing
- Selected model is used for queries
- Model preference persists across reloads
- Backend correctly applies --model flag

---

### ✅ Step 2.3: Permission Mode Selector
**Goal:** Add permission mode dropdown to control Claude's permission behavior

**Tasks:**
- [ ] Add permission mode dropdown in options panel
- [ ] List 4 modes:
  - Default (ask for each action)
  - Accept Edits (auto-accept file changes)
  - Bypass All (⚠️ skip all permissions)
  - Plan Mode (show plan before executing)
- [ ] Color-code modes (green, yellow, red)
- [ ] Store selected mode in localStorage
- [ ] Pass permission mode to backend
- [ ] Update backend to apply --permission-mode flag

**Files to Modify:**
- `resources/views/chat.blade.php`
- `app/Http/Controllers/Api/ClaudeController.php`
- `app/Services/ClaudeCodeService.php`

**Testing Instructions:**
1. Expand options panel
2. See permission mode dropdown
3. Try each mode and verify color coding:
   - Default: Green
   - Accept Edits: Yellow
   - Bypass All: Red with warning icon
   - Plan Mode: Blue
4. Select "Bypass All"
5. Send a message that requires file operations
6. Verify Claude doesn't ask for permission

**Acceptance Criteria:**
- All 4 modes are available
- Color coding is correct
- Selected mode persists
- Backend applies correct permission flag

---

### ✅ Step 2.4: Thinking Level Selector in Panel
**Goal:** Add thinking level selector to options panel (in addition to badge)

**Tasks:**
- [ ] Add thinking level radio buttons/dropdown in options panel
- [ ] Sync with badge state (bidirectional)
- [ ] Show keyboard shortcut hint (Tab)
- [ ] Style to match other controls

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Expand options panel
2. See thinking level selector
3. Change from panel - badge should update
4. Change from badge - panel should update
5. Press Tab - both should update
6. Verify all states stay in sync

**Acceptance Criteria:**
- Panel selector and badge are synchronized
- Changing one updates the other
- Tab key still works
- All UI elements show correct state

---

### ✅ Step 2.5: Tools Configuration Button & Modal
**Goal:** Add button to configure which tools Claude can use

**Tasks:**
- [ ] Add "Configure Tools..." button in options panel
- [ ] Create modal overlay for tool configuration
- [ ] List all available tools with checkboxes:
  - Read, Write, Edit, Bash, Grep, Glob, Task, WebSearch, WebFetch
- [ ] Group tools by category (File Ops, Search, Execution, Network)
- [ ] Add "Dangerously Skip Permissions" checkbox with warning
- [ ] Add preset buttons (Safe Mode, Standard, Full Access)
- [ ] Store tool settings in localStorage
- [ ] Pass allowed/disallowed tools to backend

**Files to Modify:**
- `resources/views/chat.blade.php`
- `app/Http/Controllers/Api/ClaudeController.php`
- `app/Services/ClaudeCodeService.php`

**Testing Instructions:**
1. Click "Configure Tools..." button
2. Modal should appear with tool list
3. Uncheck "Bash" and "WebSearch"
4. Click Apply
5. Send a message asking Claude to run a bash command
6. Verify Claude says it cannot use Bash
7. Try preset buttons - verify tool selection changes
8. Check "Dangerously Skip Permissions" - verify warning is shown

**Acceptance Criteria:**
- Modal opens and closes correctly
- Tool selections persist
- Backend receives and applies tool restrictions
- Presets work correctly
- Warning shown for dangerous options

---

## Phase 3: Advanced Features
**Estimated Time:** 3-4 hours

### ✅ Step 3.1: Advanced Settings Section
**Goal:** Add advanced settings for max turns, timeout, system prompts

**Tasks:**
- [ ] Add collapsible "Advanced ▼" section in options panel
- [ ] Add max turns slider/input (1-100, default 50)
- [ ] Add timeout input (60-600s, default 300s)
- [ ] Add "Custom System Prompt" textarea
- [ ] Add "Append System Prompt" textarea
- [ ] Store all settings in localStorage
- [ ] Pass to backend via options

**Files to Modify:**
- `resources/views/chat.blade.php`
- `app/Http/Controllers/Api/ClaudeController.php`
- `app/Services/ClaudeCodeService.php`

**Testing Instructions:**
1. Expand "Advanced" section in options
2. Set max turns to 10
3. Send a complex message that would take >10 turns
4. Verify Claude stops at 10 turns
5. Add custom system prompt: "You are a pirate"
6. Send a message - verify Claude responds in pirate speak
7. Test timeout with long-running command

**Acceptance Criteria:**
- All advanced settings are functional
- Settings persist across reloads
- Backend applies all flags correctly
- System prompts affect Claude's behavior

---

### ✅ Step 3.2: Session Information Display
**Goal:** Show session stats (cost, tokens, model) in status area

**Tasks:**
- [ ] Update status bar to show:
  - Message count
  - Total cost (estimated)
  - Token usage
  - Current model
- [ ] Extract info from Claude API responses
- [ ] Accumulate across session
- [ ] Format currency and numbers nicely
- [ ] Update in real-time as messages are sent

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Start new session
2. Send a message
3. Check status bar - should show:
   - "1 message"
   - "$0.XX cost"
   - "XXX tokens"
   - "Sonnet 4.5"
4. Send another message
5. Verify counts increment
6. Try different models - verify name updates

**Acceptance Criteria:**
- All stats display correctly
- Updates in real-time
- Formatting is clean and readable
- Cost calculation is accurate

---

### ✅ Step 3.3: Keyboard Shortcuts
**Goal:** Implement additional keyboard shortcuts

**Tasks:**
- [ ] Add keyboard shortcut listeners:
  - Ctrl/Cmd+K: New session
  - Ctrl/Cmd+L: Clear conversation
  - Esc: Stop Claude (interrupt)
  - Ctrl/Cmd+Enter: Send message
  - ?: Show shortcuts help overlay
  - Alt+1-4: Set thinking levels
  - Alt+H/S/O: Switch models
- [ ] Create shortcuts help modal
- [ ] Show hints in UI (tooltips)

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Press Ctrl+K - should start new session
2. Press Ctrl+L - should clear messages
3. Press ? - should show shortcuts help
4. Press Alt+4 - should set Ultrathink
5. Press Alt+H - should switch to Haiku
6. Press Ctrl+Enter - should send message
7. Verify all shortcuts work on both Mac (Cmd) and PC (Ctrl)

**Acceptance Criteria:**
- All shortcuts work as expected
- Help modal is clear and complete
- Cross-platform compatibility (Mac/PC)
- No conflicts with browser shortcuts

---

## Phase 4: Mobile & Polish
**Estimated Time:** 3-4 hours

### ✅ Step 4.1: Mobile-Responsive Design
**Goal:** Make interface fully responsive for mobile devices

**Tasks:**
- [ ] Add responsive breakpoints:
  - Mobile: < 768px
  - Tablet: 768px - 1024px
  - Desktop: > 1024px
- [ ] Optimize for mobile:
  - Single column layout
  - Bottom sheet for options
  - Larger touch targets (48x48px min)
  - Hamburger menu for sessions
- [ ] Test on various screen sizes
- [ ] Optimize thinking badge for mobile

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Open in Chrome DevTools responsive mode
2. Test at 375px width (iPhone)
3. Verify options panel becomes bottom sheet
4. Tap thinking badge - should work with finger
5. Test at 768px (iPad)
6. Test at 1920px (desktop)
7. Verify all layouts work smoothly

**Acceptance Criteria:**
- Fully functional on all screen sizes
- Touch targets are large enough
- No horizontal scrolling
- Smooth transitions between breakpoints

---

### ✅ Step 4.2: UI Polish & Animations
**Goal:** Add smooth animations and visual polish

**Tasks:**
- [ ] Add transitions:
  - Panel expand/collapse: 300ms ease
  - Thinking badge toggle: 200ms
  - Modal fade in/out: 250ms
- [ ] Add hover states on all interactive elements
- [ ] Add focus indicators for keyboard navigation
- [ ] Add loading spinner during API calls
- [ ] Add success/error toast notifications
- [ ] Polish spacing and alignment

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Interact with all controls
2. Verify smooth animations on:
   - Panel open/close
   - Modal open/close
   - Thinking toggle
   - Dropdown menus
3. Tab through interface - verify focus indicators
4. Send message - verify loading state
5. Check hover states on all buttons

**Acceptance Criteria:**
- All animations are smooth (60fps)
- Hover states are clear
- Focus indicators are visible
- Loading states prevent double-submission

---

### ✅ Step 4.3: Accessibility & Final Polish
**Goal:** Ensure WCAG 2.1 AA compliance and final touches

**Tasks:**
- [ ] Add ARIA labels to all interactive elements
- [ ] Ensure keyboard navigation works throughout
- [ ] Test with screen reader (NVDA/VoiceOver)
- [ ] Verify color contrast ratios
- [ ] Add skip-to-content link
- [ ] Add focus trapping in modals
- [ ] Test with keyboard only (no mouse)
- [ ] Add tooltips with helpful hints
- [ ] Final visual review and polish

**Files to Modify:**
- `resources/views/chat.blade.php`

**Testing Instructions:**
1. Use keyboard only - navigate entire interface
2. Use screen reader - verify all labels are read
3. Check color contrast with browser tools
4. Tab through form - verify logical order
5. Open modal - verify focus is trapped
6. Press Esc in modal - verify it closes
7. Verify all tooltips appear on hover/focus

**Acceptance Criteria:**
- Fully keyboard navigable
- Screen reader compatible
- WCAG 2.1 AA compliant
- All interactive elements have helpful tooltips

---

## Testing Checklist (After All Phases)

### Functional Testing
- [ ] Thinking mode works (all 4 levels)
- [ ] Tab key toggles thinking
- [ ] Model selection works
- [ ] Permission modes work
- [ ] Tool configuration works
- [ ] Advanced settings work
- [ ] All keyboard shortcuts work
- [ ] Session persistence works
- [ ] Mobile layout works
- [ ] Animations are smooth

### Cross-Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile Safari (iOS)
- [ ] Mobile Chrome (Android)

### Performance Testing
- [ ] Initial load < 2s
- [ ] Animations at 60fps
- [ ] No memory leaks
- [ ] LocalStorage usage < 5MB
- [ ] API calls are efficient

### Accessibility Testing
- [ ] Keyboard navigation
- [ ] Screen reader (NVDA/JAWS/VoiceOver)
- [ ] Color contrast
- [ ] Focus indicators
- [ ] ARIA labels

---

## Known Issues & Future Enhancements

### Known Issues
- None yet (will update as discovered)

### Future Enhancements
- [ ] Configuration profiles (save/load presets)
- [ ] Voice input integration
- [ ] File attachments via drag & drop
- [ ] Export conversation to markdown
- [ ] Dark/light theme toggle
- [ ] Multi-language support
- [ ] Conversation search
- [ ] Message editing/regeneration
- [ ] Code block copy buttons
- [ ] Syntax highlighting themes

---

## Success Metrics

**User Experience:**
- Users can access all CLI features from UI
- Settings are discoverable and intuitive
- Mobile experience is smooth
- Power users can use keyboard efficiently

**Technical:**
- Zero console errors
- Smooth 60fps animations
- Accessible to all users
- Works on all modern browsers

**Business:**
- Reduced support requests about "how to enable thinking"
- Increased feature adoption
- Positive user feedback

---

## Notes

- Keep commits small and focused (one step per commit)
- Test each step thoroughly before moving to next
- Update this document with any issues found
- Mark steps as complete with ✅ when done
- Add screenshots to documentation

**Last Updated:** 2025-10-20
**Status:** Ready to begin implementation
