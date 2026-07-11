# PocketDev Frontend Tech Stack Decision

**Date**: October 2025  
**Decision**: Alpine.js + HTMX for chat interface  
**Status**: Approved - Migration planned

---

## Executive Summary

After evaluating multiple frontend approaches for the Claude Code chat interface, we've decided on **Alpine.js + HTMX** for the long-term architecture. This document explains why.

## Requirements

Our chat interface needs:
1. ✅ **Real-time streaming** - Server-Sent Events (SSE) for Claude responses
2. ✅ **Reactive UI** - Auto-update when state changes
3. ✅ **Lightweight** - Fast loading, minimal overhead
4. ✅ **Simple** - Easy to maintain and debug
5. ✅ **Session management** - Handle multiple sessions, switching, persistence
6. ✅ **Markdown rendering** - Display Claude's formatted responses
7. ✅ **Code highlighting** - Syntax highlighting for code blocks

---

## Options Evaluated

### ❌ Livewire - Rejected

**What it is**: Laravel's full-stack framework for building reactive interfaces

**Why we tried it**:
- Native Laravel integration
- Familiar to Laravel developers
- Handles state automatically

**Why we rejected it**:
- ❌ **Wrong tool for streaming** - Uses AJAX, not SSE
- ❌ **Performance issues** - Every interaction = server round-trip
- ❌ **Complexity for chat** - Designed for forms/CRUD, not real-time chat
- ❌ **Already hit issues** - Alpine.js conflicts, form submission problems
- ❌ **Latency** - Not instant like chat needs to be

**Verdict**: ❌ Delete Livewire implementation

**When to use Livewire**: Admin panels, forms, CRUD operations - NOT real-time chat

---

### ✅ Vanilla JavaScript - Current Implementation

**What it is**: Pure JavaScript with no framework

**Status**: Currently working in `chat.html`

**Pros**:
- ✅ Works right now
- ✅ Full control, no magic
- ✅ EventSource (SSE) is native - perfect for streaming
- ✅ Easy to debug
- ✅ No build step
- ✅ Fast - zero framework overhead

**Cons**:
- ❌ Manual DOM manipulation gets messy
- ❌ No reactivity - must manually update UI
- ❌ State management is manual
- ❌ Code organization degrades as features grow
- ❌ Lots of boilerplate for common patterns

**Streaming implementation**:
```javascript
// Simple and works great
const stream = new EventSource(`/api/claude/sessions/${sessionId}/stream`);
stream.onmessage = (event) => {
    const chunk = JSON.parse(event.data);
    appendToMessage(chunk.text);
};
```

**Verdict**: ✅ Good for now, but will get messy as we add features

**Migration path**: Keep for short-term, replace with Alpine + HTMX when adding more features

---

### 🏆 Alpine.js + HTMX - **CHOSEN SOLUTION**

**What it is**:
- **Alpine.js** (~15kb): Lightweight reactive framework - "Vue lite"
- **HTMX** (~14kb): Server communication library with SSE support

**Total size**: ~29kb (vs React 130kb, Vue 90kb)

**Why this is perfect for us**:

#### 1. Lightweight & Fast
- Only 29kb combined
- No build step required (can add later if needed)
- CDN-ready for development
- Minimal overhead

#### 2. Built for Laravel
- Alpine.js is built by Caleb Porzio (Livewire creator)
- HTMX philosophy matches Laravel's simplicity
- Used extensively in Laravel community
- Works great with Blade templates

#### 3. Perfect for Streaming
HTMX has **native SSE support** via extension:

```html
<div hx-ext="sse" sse-connect="/api/claude/sessions/12/stream">
    <div sse-swap="message" hx-swap="beforeend">
        <!-- Messages stream here automatically -->
    </div>
</div>
```

Alpine.js handles reactive state:

```html
<div x-data="{ messages: [], sessionId: null }">
    <div x-show="messages.length > 0">
        <template x-for="msg in messages">
            <div x-text="msg.content"></div>
        </template>
    </div>
</div>
```

#### 4. Progressive Enhancement
- Can add to existing vanilla JS incrementally
- No need to rewrite everything at once
- Mix and match with vanilla as needed

#### 5. Developer Experience
- Minimal learning curve (2-3 hours to learn both)
- HTML-first approach (like Blade)
- Great documentation
- Active community

#### 6. Modern & Popular
- Used by Tailwind team
- Growing Laravel ecosystem adoption
- Active development
- Long-term support

**Cons** (minor):
- Two libraries to learn (but both are tiny)
- HTMX SSE requires extension (~2kb more)
- Not as powerful as React/Vue (but we don't need that)

---

### ❌ React/Vue - Overkill

**Why we're NOT using React/Vue**:
- ❌ Too heavy (130kb+ for React, 90kb+ for Vue)
- ❌ Requires build step (Vite/webpack)
- ❌ More complexity than needed
- ❌ Overkill for this project
- ❌ Longer learning curve

**When to use React/Vue**: Large SPAs, complex state management, mobile apps

**Our use case**: Simple chat interface - doesn't need this much power

---

## Implementation Strategy

### Phase 1: Keep Vanilla (Current)
**Timeline**: This week

**Tasks**:
1. ✅ Delete Livewire files
2. ✅ Fix critical bugs in vanilla JS
3. ✅ Add session list
4. ✅ Add localStorage persistence
5. ✅ Implement streaming with native EventSource
6. ✅ Add markdown rendering (marked.js)

**Goal**: Get vanilla working perfectly before migrating

---

### Phase 2: Migrate to Alpine + HTMX
**Timeline**: Next sprint

**Approach**: Incremental migration, not big rewrite

**Step 1: Add Libraries**
```html
<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<!-- HTMX -->
<script src="https://unpkg.com/htmx.org@1.9.10"></script>
<script src="https://unpkg.com/htmx.org/dist/ext/sse.js"></script>
```

**Step 2: Migrate Session List First** (easiest)
```html
<!-- Before (Vanilla) -->
<div id="sessions"></div>
<script>
  async function loadSessions() {
    const res = await fetch('/api/claude/sessions');
    const data = await res.json();
    document.getElementById('sessions').innerHTML = data.map(s => 
      `<div onclick="loadSession(${s.id})">${s.title}</div>`
    ).join('');
  }
</script>

<!-- After (Alpine) -->
<div x-data="{ sessions: [] }" x-init="fetch('/api/claude/sessions').then(r => r.json()).then(d => sessions = d)">
  <template x-for="session in sessions">
    <div @click="loadSession(session.id)" x-text="session.title"></div>
  </template>
</div>
```

**Step 3: Migrate Message Input**
```html
<!-- Before (Vanilla) -->
<form onsubmit="sendMessage(event)">
  <input id="prompt" type="text">
</form>

<!-- After (Alpine + HTMX) -->
<div x-data="{ prompt: '' }">
  <form hx-post="/api/claude/sessions/12/query" 
        hx-vals='js:{prompt: $el.querySelector("input").value}'
        hx-target="#messages" hx-swap="beforeend">
    <input x-model="prompt" type="text">
  </form>
</div>
```

**Step 4: Migrate Streaming**
```html
<!-- After (HTMX SSE) -->
<div hx-ext="sse" sse-connect="/api/claude/sessions/12/stream">
  <div id="messages" sse-swap="message" hx-swap="beforeend"></div>
</div>
```

**Step 5: Add State Management**
```html
<!-- Alpine store for global state -->
<script>
  document.addEventListener('alpine:init', () => {
    Alpine.store('chat', {
      currentSessionId: localStorage.getItem('sessionId'),
      sessions: [],
      authenticated: false,
      
      setSession(id) {
        this.currentSessionId = id;
        localStorage.setItem('sessionId', id);
      }
    });
  });
</script>

<!-- Use in components -->
<div x-data x-show="$store.chat.authenticated">
  <!-- Only show when authenticated -->
</div>
```

---

## Architecture Patterns

### Component Structure
```
chat.html (or chat.blade.php)
├── Alpine Store (global state)
├── Auth Check Component
├── Sidebar Component
│   ├── Session List (Alpine x-for)
│   └── New Session Button
├── Chat Area Component  
│   ├── Messages Container (HTMX SSE)
│   └── Message Input (HTMX POST)
└── Settings Component
```

### State Management
- **Alpine Store**: Global state (sessionId, auth status)
- **localStorage**: Persistence (session, preferences)
- **x-data**: Component-local state (form values, UI flags)

### Server Communication
- **HTMX**: All API calls
- **SSE Extension**: Streaming responses
- **Alpine**: Handle response data

---

## Code Examples

### Full Chat Interface Example

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Claude Code Chat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <script src="https://unpkg.com/htmx.org/dist/ext/sse.js"></script>
</head>
<body class="bg-gray-900 text-gray-100">
    
    <!-- Alpine Global Store -->
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('chat', {
                sessionId: localStorage.getItem('sessionId'),
                sessions: [],
                
                async loadSessions() {
                    const res = await fetch('/api/claude/sessions');
                    this.sessions = await res.json();
                },
                
                setSession(id) {
                    this.sessionId = id;
                    localStorage.setItem('sessionId', id);
                }
            });
        });
    </script>

    <div class="flex h-screen" x-data x-init="$store.chat.loadSessions()">
        
        <!-- Sidebar -->
        <div class="w-64 bg-gray-800 border-r border-gray-700">
            <div class="p-4">
                <h2 class="text-lg font-semibold">Sessions</h2>
                <button @click="$store.chat.setSession(null)" 
                        class="mt-2 w-full px-4 py-2 bg-blue-600 rounded">
                    New Session
                </button>
            </div>
            
            <!-- Session List -->
            <div class="overflow-y-auto">
                <template x-for="session in $store.chat.sessions" :key="session.id">
                    <div @click="$store.chat.setSession(session.id)"
                         :class="$store.chat.sessionId === session.id ? 'bg-blue-600' : 'hover:bg-gray-700'"
                         class="p-3 cursor-pointer">
                        <div x-text="session.title" class="font-medium"></div>
                        <div x-text="session.last_activity_at" class="text-xs text-gray-400"></div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Chat Area -->
        <div class="flex-1 flex flex-col">
            
            <!-- Messages -->
            <div id="messages" class="flex-1 overflow-y-auto p-4"
                 hx-ext="sse" 
                 :sse-connect="`/api/claude/sessions/${$store.chat.sessionId}/stream`">
                
                <!-- Existing messages loaded via Alpine -->
                <template x-for="msg in messages">
                    <div :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
                         class="flex mb-4">
                        <div class="max-w-3xl px-4 py-3 rounded-lg"
                             :class="msg.role === 'user' ? 'bg-blue-600' : 'bg-gray-800'"
                             x-html="marked.parse(msg.content)">
                        </div>
                    </div>
                </template>
                
                <!-- Stream new messages here -->
                <div sse-swap="message" hx-swap="beforeend"></div>
            </div>

            <!-- Input -->
            <div class="border-t border-gray-700 p-4">
                <form hx-post="js:`/api/claude/sessions/${Alpine.store('chat').sessionId}/query`"
                      hx-target="#messages" 
                      hx-swap="beforeend"
                      @htmx:after-request="$el.reset()">
                    <div class="flex gap-2">
                        <input name="prompt" 
                               type="text" 
                               placeholder="Ask Claude..."
                               class="flex-1 px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg">
                        <button type="submit" 
                                class="px-6 py-3 bg-blue-600 rounded-lg">
                            Send
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
```

---

## Benefits Summary

### Why Alpine.js + HTMX Wins

| Feature | Vanilla JS | Livewire | Alpine + HTMX | React/Vue |
|---------|-----------|----------|---------------|-----------|
| **Size** | 0kb | ~50kb | 29kb | 90-130kb |
| **Streaming** | ⭐⭐⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ |
| **Reactivity** | ❌ | ✅ | ✅ | ✅ |
| **Learning Curve** | Low | Medium | Low | High |
| **Build Step** | No | No | No | Yes |
| **Laravel Integration** | N/A | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐ |
| **Real-time UX** | Manual | Slow | Excellent | Excellent |
| **Code Maintainability** | Poor | Good | Excellent | Excellent |
| **Community** | - | Laravel | Growing | Huge |

**Winner**: 🏆 **Alpine.js + HTMX** - Best balance of simplicity, performance, and features

---

## Resources

### Learning Resources
- **Alpine.js**: https://alpinejs.dev/start-here
  - 15-minute tutorial: https://alpinejs.dev/essentials/installation
  - Examples: https://alpinejs.dev/examples

- **HTMX**: https://htmx.org/docs/
  - SSE Extension: https://htmx.org/extensions/server-sent-events/
  - Laravel Integration: https://htmx.org/examples/

### Community
- Alpine.js Discord: https://alpinejs.dev/community
- HTMX Discord: https://htmx.org/discord
- Laravel + Alpine examples: https://github.com/alpinejs/alpine

### Similar Projects
- Laravel Breeze (uses Alpine)
- Filament (uses Alpine + Livewire)
- Many Laravel SaaS templates use Alpine + HTMX

---

## Decision Factors

### Why NOT Other Options

**Livewire**:
- ❌ Not designed for real-time streaming
- ❌ Every interaction = server round-trip
- ❌ We already tried and hit issues

**Vanilla JS**:
- ❌ Gets messy quickly
- ❌ Manual reactivity is error-prone
- ❌ Hard to maintain as features grow

**React/Vue**:
- ❌ Overkill for our needs
- ❌ Requires build process
- ❌ Steeper learning curve
- ❌ Heavier bundle size

**Alpine + HTMX**:
- ✅ Perfect balance
- ✅ Designed for Laravel
- ✅ Great for streaming
- ✅ Lightweight and simple
- ✅ Growing ecosystem

---

## Migration Timeline

### Week 1: Cleanup (Current)
- [x] Delete Livewire files
- [ ] Fix vanilla JS critical bugs
- [ ] Add markdown rendering
- [ ] Implement streaming with EventSource

### Week 2-3: Learn & Plan
- [ ] Complete Alpine.js tutorial (2 hours)
- [ ] Complete HTMX tutorial (2 hours)
- [ ] Build small prototype component
- [ ] Plan migration strategy

### Week 4-6: Incremental Migration
- [ ] Add Alpine + HTMX libraries
- [ ] Migrate session list component
- [ ] Migrate message input
- [ ] Migrate streaming
- [ ] Add Alpine store for state
- [ ] Test thoroughly

### Week 7: Polish & Optimize
- [ ] Remove remaining vanilla code
- [ ] Add build step (Vite) for production
- [ ] Optimize bundle size
- [ ] Update documentation

---

## Conclusion

**Alpine.js + HTMX** is the right choice for PocketDev because:

1. ✅ **Lightweight** - Only 29kb, fast loading
2. ✅ **Perfect for streaming** - Native SSE support
3. ✅ **Laravel-friendly** - Built by Laravel community
4. ✅ **Simple** - Easy to learn and maintain
5. ✅ **Scalable** - Grows with our needs
6. ✅ **Modern** - Active development, good ecosystem

We'll migrate incrementally from vanilla JS, keeping the app working at all times.

**Next steps**: Complete vanilla fixes this week, then start Alpine migration next sprint.

---

**Last Updated**: October 2025  
**Status**: Approved  
**Owner**: PocketDev Team
