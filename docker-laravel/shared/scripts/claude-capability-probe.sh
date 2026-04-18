#!/bin/bash
set -u

OUTPUT_JSON="/tmp/claude-capability-probe.json"
OUTPUT_ENV="/tmp/claude-capability-probe.env"
PROMPT_FILE="/tmp/claude-capability-probe-prompt.txt"

printf 'Respond with exactly: ok\n' > "$PROMPT_FILE"

if ! command -v claude >/dev/null 2>&1; then
  cat > "$OUTPUT_JSON" <<'JSON'
{"ok":false,"reason":"claude_binary_missing","adaptive_allowed":false,"results":[]}
JSON
  cat > "$OUTPUT_ENV" <<'ENV'
PD_CLAUDE_ADAPTIVE_ALLOWED=false
PD_CLAUDE_PROBE_STATUS=claude_binary_missing
ENV
  exit 0
fi

claude_version="$(claude --version 2>/dev/null | head -1 | awk '{print $1}')"
if [ -z "$claude_version" ]; then
  claude_version="unknown"
fi

extract_context_window() {
  python3 - "$1" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
if not path.exists():
    print('')
    raise SystemExit(0)

windows = []

def walk(node):
    if isinstance(node, dict):
        for k, v in node.items():
            if k == 'contextWindow' and isinstance(v, (int, float)):
                iv = int(v)
                if iv > 0:
                    windows.append(iv)
            else:
                walk(v)
    elif isinstance(node, list):
        for item in node:
            walk(item)

for line in path.read_text(errors='ignore').splitlines():
    line = line.strip()
    if not line:
        continue
    try:
        obj = json.loads(line)
    except Exception:
        continue
    walk(obj)

print(max(windows) if windows else '')
PY
}

run_probe() {
  local name="$1"
  local model="$2"
  local beta="${3:-}"
  local out_file="/tmp/claude-capability-probe-${name}.log"
  local status="error"
  local rc=0
  local context_window=""
  local err_preview=""

  local -a cmd
  cmd=(claude --print --verbose --output-format stream-json --include-partial-messages --dangerously-skip-permissions --model "$model")
  if [ -n "$beta" ]; then
    cmd+=(--betas "$beta")
  fi

  if timeout 75s "${cmd[@]}" < "$PROMPT_FILE" > "$out_file" 2>&1; then
    rc=0
  else
    rc=$?
  fi

  context_window="$(extract_context_window "$out_file")"

  if [ "$rc" -eq 124 ]; then
    status="timeout"
  elif [ "$rc" -ne 0 ]; then
    if grep -qi "rate limit" "$out_file"; then
      status="rate_limited"
    else
      status="error"
    fi
  elif [ -n "$context_window" ]; then
    status="ok"
  else
    status="no_context"
  fi

  err_preview="$(tail -n 5 "$out_file" | tr '\n' ' ' | sed 's/"/\\"/g' | cut -c1-300)"

  printf '{"name":"%s","model":"%s","beta":"%s","status":"%s","exit_code":%s,"context_window":%s,"output_preview":"%s"}' \
    "$name" "$model" "$beta" "$status" "$rc" "${context_window:-null}" "$err_preview"
}

result_standard="$(run_probe standard claude-sonnet-4-6)"
result_suffix="$(run_probe suffix_1m claude-sonnet-4-6[1m])"
result_beta="$(run_probe beta_1m claude-sonnet-4-6 context-1m-2025-08-07)"

max_window="$(python3 - <<'PY' "$result_standard" "$result_suffix" "$result_beta"
import json
import sys
w = 0
for raw in sys.argv[1:]:
    try:
        obj = json.loads(raw)
    except Exception:
        continue
    cw = obj.get('context_window')
    if isinstance(cw, int) and cw > w:
        w = cw
print(w)
PY
)"

adaptive_allowed=false
if [ -n "$max_window" ] && [ "$max_window" -ge 900000 ]; then
  adaptive_allowed=true
fi

overall_ok=false
for result in "$result_standard" "$result_suffix" "$result_beta"; do
  case "$result" in
    *"\"status\":\"ok\""*)
      overall_ok=true
      break
      ;;
  esac
done

probe_status="failed"
if [ "$overall_ok" = true ]; then
  probe_status="ok"
fi

cat > "$OUTPUT_JSON" <<JSON
{
  "ok": $overall_ok,
  "claude_version": "$claude_version",
  "adaptive_allowed": $adaptive_allowed,
  "max_context_window": ${max_window:-0},
  "results": [
    $result_standard,
    $result_suffix,
    $result_beta
  ]
}
JSON

cat > "$OUTPUT_ENV" <<ENV
PD_CLAUDE_ADAPTIVE_ALLOWED=$adaptive_allowed
PD_CLAUDE_PROBE_STATUS=$probe_status
ENV

exit 0
