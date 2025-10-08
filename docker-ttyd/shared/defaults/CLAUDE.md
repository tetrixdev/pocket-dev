# Where You Are

## General Pocket-Dev Environment Overview

You are running inside the **pocket-dev-ttyd** container, which is part of the pocket-dev development environment.

**Current context:**
- **Container:** pocket-dev-ttyd
- **User workspace:** `/workspace` (where user projects should be created)
- **Home directory:** `/home/devuser` (persistent across restarts)

**Important:** Always create user projects in **subdirectories** of `/workspace`, never in `/workspace` itself or in the pocket-dev root directory. For example: `/workspace/my-project/`.

## Critical Architectural Rule

⚠️ **NOTHING IS WEB-ACCESSIBLE WITHOUT GOING THROUGH pocket-dev-proxy**

**This means:**
- ❌ Files in `/workspace` are **NOT** automatically accessible in browser
- ❌ Creating HTML files does **NOT** make them web-accessible
- ❌ Running a container does **NOT** make it web-accessible
- ✅ **ONLY** projects configured through the proxy are accessible

## docker-and-proxy-nginx-agent
**You MUST hand over ALL Docker and web accessibility tasks requested by the user, or inferred from his request, to this agent.**

Examples of tasks to hand over include:
- Setting up new projects with Docker Compose
- Troubleshooting browser accessibility issues (404/502/500 errors)
- Modifying nginx proxy routes
- Debugging container networking
- Fixing docker compose configurations
- etc... pretty much any task involving containers, nginx, or making projects accessible via browser.

**Do NOT attempt any of the above tasks yourself - ALWAYS delegate to the agent!**

# git and gh instructions

You can run both `git` and `gh` commands directly from within this container.
- `git` is pre-configured with your name and email.
- `gh` is pre-authenticated with your GitHub account.

## Finding and Cloning Repositories

When the user wants to work on a specific GitHub repository, you MUST use `gh` commands to find and clone it.

If the user is refering to a specific repository, you MUST first check if it is already cloned in `/workspace`.
You should list all repos of the user with `gh repo list USERNAME --limit 1000` and see if you find the likely repo name in the list.
If not check the linked organizations with `gh org list --limit 1000` and then list the repos of the organization with `gh repo list ORGNAME --limit 1000`.
If there's multiple organizations, you MUST ask the user which one to use to not waste time.

## Committing and Pushing Changes

When the user wants to commit changes, you MUST use `git` commands to stage, commit, and push the changes.
Always first check which branch the user is on with `git branch` and if there are any uncommitted changes with `git status`.
Do NOT commit or push any changes without explicit user instruction. You can ask the user if you think it makes sense, you don't need permission for each separate command, but you MUST for a combined action like committing and pushing.
When committing, you MUST use meaningful commit messages that accurately describe the changes made.
If the branch seems to not be a feature branch, you MUST ask the user if they want to create a new branch for the changes.

## Merging and Creating Pull Requests

When the user wants to create a pull request, you MUST use `gh` commands to create it.
When merging pull requests, you MUST NEVER squash commits, unless explicitly instructed by the user.
