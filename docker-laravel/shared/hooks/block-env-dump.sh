#!/bin/bash
# Block commands that expose environment variable values.
# AI may check if a variable is set ([ -n "$VAR" ]) but may not read its value.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""')

BLOCKED=0

# Strategy: extract only the first "statement" from the command (up to the first
# newline, pipe, semicolon, &&, ||) to check if it's a bare env-dump command.
# This avoids false positives from multiline content (e.g. heredoc bodies) that
# contain words like "env" in prose.
#
# For chained commands (cmd1; cmd2), we check each statement individually.

# Split on statement separators and check each segment
# We use a Python one-liner for reliable multi-statement splitting
STATEMENTS=$(echo "$COMMAND" | python3 -c "
import sys, re
text = sys.stdin.read()
# Split on ; && || newline (not inside quotes - simplified)
parts = re.split(r'[;\n]|&&|\|\|', text)
for p in parts:
    print(p.strip())
" 2>/dev/null || echo "$COMMAND")

check_statement() {
    local stmt="$1"
    # Get the first word (the command name), stripping leading whitespace
    local first_word
    first_word=$(echo "$stmt" | awk '{print $1}')

    case "$first_word" in
        env)
            # Block bare "env" or "env | ..." but not "env VAR=val cmd"
            # If second token contains '=', it's env VAR=val cmd — allow
            local second_word
            second_word=$(echo "$stmt" | awk '{print $2}')
            if [ -z "$second_word" ] || echo "$stmt" | grep -qE '^\s*env\s*\|'; then
                BLOCKED=1
            elif ! echo "$second_word" | grep -q '='; then
                # Second word has no '=', so it's not VAR=val — could be just "env somecommand"
                # That's fine (runs somecommand with current env), allow it
                :
            fi
            ;;
        printenv)
            BLOCKED=1
            ;;
        export)
            # Block bare "export" with no assignment argument
            local rest
            rest=$(echo "$stmt" | awk '{$1=""; print $0}' | xargs)
            if [ -z "$rest" ] || echo "$rest" | grep -qE '^\|'; then
                BLOCKED=1
            fi
            ;;
        declare|typeset)
            # Block declare -x, declare -p, declare -xp etc.
            local flags
            flags=$(echo "$stmt" | awk '{print $2}')
            if echo "$flags" | grep -qE '^-[a-zA-Z]*[xXpP]'; then
                BLOCKED=1
            fi
            ;;
        set)
            # Block bare "set" with no arguments
            local rest
            rest=$(echo "$stmt" | awk '{$1=""; print $0}' | xargs)
            if [ -z "$rest" ]; then
                BLOCKED=1
            fi
            ;;
    esac

    # Check for /proc/*/environ anywhere in the statement
    if echo "$stmt" | grep -qE '/proc/(self|[0-9]+)/environ'; then
        BLOCKED=1
    fi
}

# Check each pipe segment and statement
while IFS= read -r stmt; do
    [ -z "$stmt" ] && continue
    # Also split on pipes within each statement
    while IFS= read -r seg; do
        [ -z "$seg" ] && continue
        check_statement "$seg"
    done < <(echo "$stmt" | tr '|' '\n')
done < <(echo "$STATEMENTS")

if [ "$BLOCKED" = "1" ]; then
    echo '{"hookSpecificOutput": {"hookEventName": "PreToolUse", "permissionDecision": "deny", "permissionDecisionReason": "Blocked: reading environment variable values is not allowed. To check if a variable is set, use: [ -n \"$VAR_NAME\" ] && echo set || echo not set"}}'
    exit 0
fi

exit 0
