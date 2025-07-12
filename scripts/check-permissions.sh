#!/bin/bash

echo "🔍 Checking GitHub token permissions..."

# Check if GITHUB_TOKEN is set
if [ -z "$GITHUB_TOKEN" ]; then
    echo "❌ GITHUB_TOKEN environment variable not set"
    echo "Please set your GitHub token: -e GITHUB_TOKEN=your_token"
    exit 1
fi

# Authenticate with GitHub CLI (non-interactive)
echo "$GITHUB_TOKEN" | gh auth login --with-token --hostname github.com >/dev/null 2>&1

# Check if authentication worked
if ! gh auth status &>/dev/null; then
    echo "❌ GitHub authentication failed"
    echo "Please check your GITHUB_TOKEN is valid"
    exit 1
fi

echo "✅ GitHub authentication successful"

# Check token scopes
echo "🔍 Checking token permissions..."

# Get current user to test repo scope
if gh api user &>/dev/null; then
    echo "✅ Basic user access works"
else
    echo "❌ Cannot access user information"
    exit 1
fi

# Test repo scope by trying to list repos
if gh repo list --limit 1 &>/dev/null; then
    echo "✅ Repository access works"
else
    echo "⚠️  Repository access limited - you may need 'repo' scope"
fi

# Test workflow scope (this is harder to test directly)
echo "ℹ️  Make sure your token has these scopes:"
echo "   - repo (Full repository access)"
echo "   - workflow (Update GitHub Action workflows)"
echo "   - user:email (Access user email addresses)"

echo ""
echo "🎉 GitHub setup complete! You're ready to develop."
echo "Current user: $(gh api user --jq .login)"
echo "Workspace: /workspace"