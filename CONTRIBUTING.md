# Contributing to PocketDev

This guide is for developers who want to contribute to PocketDev itself.

## Development Setup

```bash
# Clone the repository
git clone https://github.com/tetrixdev/pocket-dev.git
cd pocket-dev

# Run setup (auto-detects and sets PD_USER_ID, PD_GROUP_ID, PD_DOCKER_GID)
./setup.sh

# Start development environment
docker compose up -d
```

The root `compose.yml` is configured for development with:
- Source code mounted from `./www`
- Vite dev server on port 5173 (hot reload)
- Docker socket access for dogfooding

## Directory Structure

```
pocket-dev/
├── www/                    # Laravel application
├── docker-laravel/         # PHP container configs
│   ├── local/             # Development Dockerfiles
│   ├── production/        # Production Dockerfiles
│   └── shared/            # Shared configs
├── docker-postgres/        # PostgreSQL container config
├── deploy/                 # Production deployment package
│   ├── compose.yml        # Production compose file
│   ├── setup.sh           # User setup script
│   └── .env.example       # Production env template
├── compose.yml             # Development compose file
├── setup.sh                # Developer setup script
└── .env.example            # Development env template
```

## Development Workflow

### Making Changes

1. Create a feature branch
2. Make your changes in `www/`
3. Test locally
4. Submit a pull request

### Rebuilding Containers

After changing Dockerfiles or entrypoints:

```bash
# Quick rebuild
docker compose up -d --build

# Clean rebuild (no cache)
docker compose build --no-cache && docker compose up -d

# Full rebuild (clears volumes - WARNING: loses data)
docker compose down -v && docker compose build --no-cache && docker compose up -d
```

### Viewing Logs

```bash
# All logs
docker compose logs -f

# Specific service
docker compose logs -f pocket-dev-queue
docker compose logs -f pocket-dev-php
```

## Dogfooding (Self-Development)

PocketDev can develop itself! The queue container has:

- `/pocketdev-source` - Full project source (read/write)
- Docker socket access - Can restart containers
- Git/GitHub CLI configured

### Self-Restart Pattern

When making changes that require container restart:

```bash
# From inside PocketDev (safe self-restart)
docker run --rm -d \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$HOST_PROJECT_PATH:$HOST_PROJECT_PATH" \
    -w "$HOST_PROJECT_PATH" \
    docker:27-cli \
    docker compose restart pocket-dev-queue
```

## Code Style

- PHP: Follow PSR-12 (but don't run automated formatters)
- Use public properties over getter/setter methods
- Use Blade components (`<x-*>`) for reusable UI
- Keep it simple - avoid over-engineering

## Pull Request Guidelines

1. Keep PRs focused on a single feature/fix
2. Test your changes locally
3. Update documentation if needed
4. Use conventional commit messages

## GitHub Project Workflow

We track all work in the [PocketDev GitHub Project](https://github.com/orgs/tetrixdev/projects/2). Every issue moves through these statuses:

### Status Definitions

| Status | Description | Assignee |
|--------|-------------|----------|
| **Todo** | Rough idea or bug report. Not fully fleshed out yet - just capturing the concept. | Unassigned (anyone can pick it up to flesh out) |
| **Ready for Plan Review** | Fully detailed with: (1) high-level description of what we're achieving, (2) what problem it solves, (3) plan of attack for implementation. | Assign to the **other team member** for review |
| **Ready for Development** | Plan has been reviewed and approved. Ready to be picked up. | Unassigned (anyone can pick it up) |
| **In Progress** | Actively being worked on. **Move here immediately when you start** to prevent duplicate work. | The person working on it |
| **Done** | Merged AND released. Only mark done after the release is published. | N/A |

### Workflow Rules

1. **Creating new items**: Start in "Todo" with a rough description. Don't overthink it - just capture the idea.

2. **Fleshing out an item**: When you fully detail an item (description + plan), move it to "Ready for Plan Review" and assign the other team member.

3. **Reviewing a plan**: Read the description and plan. If it makes sense, move to "Ready for Development" and remove the assignee. If changes needed, comment and leave in review status.

4. **Starting work**: Before coding, move the item to "In Progress" and assign yourself. This is critical - it prevents two people working on the same thing.

5. **Completing work**: Only move to "Done" after:
   - PR is merged
   - Release is created and published

### Issue Types

Every issue must be labeled as exactly ONE of:
- **`bug`** - Something is broken or not working as expected
- **`enhancement`** - New feature or improvement to existing functionality

An issue cannot have both labels, and cannot have neither. The label determines which format to use below.

---

## Feature Request Format (`enhancement` label)

Use this format for new features, improvements, or enhancements. When moving to "Ready for Plan Review", the issue should contain all sections below.

```markdown
## User Story

**As a** [type of user],
**I want** [goal/desire],
**So that** [benefit/value].

Example: "As a mobile user, I want to install PocketDev as a PWA, so that I can access it quickly from my home screen without opening a browser."

## Problem Statement

[Describe the current limitation, pain point, or gap. Why does this matter? What's the impact on users? Be specific about the user experience problem, not just the technical gap.]

## Success Criteria

- [ ] [Measurable outcome 1]
- [ ] [Measurable outcome 2]
- [ ] [Measurable outcome 3]

## Options Considered

### Option A: [Name]
**Description:** [Brief explanation]
**Pros:** [Benefits]
**Cons:** [Drawbacks]

### Option B: [Name]
**Description:** [Brief explanation]
**Pros:** [Benefits]
**Cons:** [Drawbacks]

### Option C: [Name] (if applicable)
**Description:** [Brief explanation]
**Pros:** [Benefits]
**Cons:** [Drawbacks]

## Chosen Approach

**Selected:** Option [X] — [Name]

**Rationale:** [Why this option was chosen over the others. What trade-offs were accepted?]

## Technical Plan

### Architecture Overview
[High-level description of how this will be implemented. Describe the components, data flow, and how they interact. Keep it architectural — focus on the "what" and "why", not line-by-line details.]

### Implementation Steps
1. [Step 1 — what will be done and why]
2. [Step 2 — what will be done and why]
3. [Step 3 — what will be done and why]
...

### Files Involved
- `path/to/file1.php` — [what changes]
- `path/to/file2.blade.php` — [what changes]
- `path/to/file3.js` — [what changes]

### Database Changes
[New tables, columns, or migrations needed. "None" if not applicable.]

### API Changes
[New endpoints, changed signatures, or breaking changes. "None" if not applicable.]

## Effort Estimate

**Size:** [Small (< 1 day) / Medium (1-3 days) / Large (3+ days)]

**Complexity:** [Low / Medium / High]

**Risk Areas:** [What could go wrong? What needs extra attention?]

## Open Questions

- [Any unresolved questions that need input before or during development]
```

---

## Bug Report Format (`bug` label)

Use this format for bugs, defects, or unexpected behavior. When moving to "Ready for Plan Review", the issue should contain all sections below.

```markdown
## Bug Summary

[One sentence describing what's broken and the impact]

## Current Behavior

[What happens now? Be specific. Include error messages, screenshots, or logs if available.]

## Expected Behavior

[What should happen instead?]

## Steps to Reproduce

1. [Step 1]
2. [Step 2]
3. [Step 3]
4. [Observe: what goes wrong]

**Environment:**
- Browser/Device: [e.g., Chrome 120, iPhone 15]
- PocketDev version: [e.g., v0.50.1]
- Relevant settings: [if applicable]

## Impact Assessment

**Severity:** [Critical / High / Medium / Low]
- **Critical:** System unusable, data loss, security vulnerability
- **High:** Major feature broken, significant UX degradation
- **Medium:** Feature partially broken, workaround exists
- **Low:** Minor inconvenience, cosmetic issue

**Affected Users:** [All users / Specific subset / Edge case]

## Root Cause Analysis

[What's causing this bug? Include your investigation findings. Reference specific code if known.]

**Hypothesis:** [Your best understanding of why this happens]

**Evidence:** [Logs, code references, or observations that support the hypothesis]

## Options Considered

### Option A: [Name]
**Description:** [Brief explanation]
**Pros:** [Benefits]
**Cons:** [Drawbacks]

### Option B: [Name]
**Description:** [Brief explanation]
**Pros:** [Benefits]
**Cons:** [Drawbacks]

## Chosen Fix

**Selected:** Option [X] — [Name]

**Rationale:** [Why this approach? What trade-offs?]

## Technical Plan

### Fix Overview
[High-level description of how the bug will be fixed. Focus on the architectural approach.]

### Implementation Steps
1. [Step 1 — what will be changed and why]
2. [Step 2 — what will be changed and why]
3. [Step 3 — what will be changed and why]

### Files Involved
- `path/to/file1.php` — [what changes]
- `path/to/file2.blade.php` — [what changes]

### Testing Plan
- [ ] [How to verify the fix works]
- [ ] [Edge cases to test]
- [ ] [Regression tests needed]

## Effort Estimate

**Size:** [Small (< 1 day) / Medium (1-3 days) / Large (3+ days)]

**Risk Areas:** [What could break? What needs careful testing?]

## Open Questions

- [Any unresolved questions]

---

## Original Description

[If this issue was migrated from a rough description, preserve the original text here for context.]
```

---

## Troubleshooting

**Container won't start?**
```bash
docker compose logs -f
```

**Database issues?**
```bash
docker compose exec pocket-dev-php php artisan migrate:fresh
```

**Need a clean restart?**
```bash
docker compose down -v && docker compose up -d
```

**Permission issues?**
Check that `PD_USER_ID` and `PD_GROUP_ID` in `.env` match your host user (`id -u` and `id -g`).
