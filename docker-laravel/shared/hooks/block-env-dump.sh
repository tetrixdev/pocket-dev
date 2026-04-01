#!/bin/bash
# Block commands that expose environment variable values.
# AI may check if a variable is set ([ -n "$VAR" ]) but may not read its value.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""')

BLOCKED=0

# Truncate at the first heredoc marker — everything after << is data, not commands.
COMMAND=$(echo "$COMMAND" | python3 -c "
import sys, re
text = sys.stdin.read()
m = re.search(r'<<', text)
if m:
    text = text[:m.start()]
print(text)
" 2>/dev/null || echo "$COMMAND")

# Split on statement separators into individual segments
STATEMENTS=$(echo "$COMMAND" | python3 -c "
import sys, re
text = sys.stdin.read()
parts = re.split(r'[;\n]|&&|\|\|', text)
for p in parts:
    print(p.strip())
" 2>/dev/null || echo "$COMMAND")

check_statement() {
    local stmt="$1"
    local first_word
    first_word=$(echo "$stmt" | awk '{print $1}')

    case "$first_word" in
        env)
            # Allow "env VAR=val cmd" (sets env for a command)
            # Block bare "env" with no args or piped
            local second_word
            second_word=$(echo "$stmt" | awk '{print $2}')
            if [ -z "$second_word" ]; then
                BLOCKED=1
            elif ! echo "$second_word" | grep -q '='; then
                : # "env somecommand" — fine
            fi
            ;;
        printenv)
            BLOCKED=1
            ;;
        export)
            # Block bare "export" (dumps all vars); allow "export VAR=val"
            local rest
            rest=$(echo "$stmt" | awk '{$1=""; print $0}' | xargs)
            if [ -z "$rest" ]; then
                BLOCKED=1
            fi
            ;;
        declare|typeset)
            # Block declare -x / -p / -xp (dumps exported/all vars)
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
        cat|less|more|head|tail|strings|xxd|od|hexdump)
            # Block reading /proc/*/environ via file-reading commands only
            local args
            args=$(echo "$stmt" | awk '{$1=""; print $0}')
            if echo "$args" | grep -qE '/proc/(self|[0-9]+)/environ'; then
                BLOCKED=1
            fi
            ;;
    esac
}

while IFS= read -r stmt; do
    [ -z "$stmt" ] && continue
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
