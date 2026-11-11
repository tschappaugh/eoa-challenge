#!/usr/bin/env bash
# Appends a readable markdown log of the AI session. Silent; always exits 0.
exec 2>/dev/null
trap 'exit 0' EXIT

command -v jq >/dev/null || exit 0

input=$(cat)
[ -n "$input" ] || exit 0

# Redact API keys before anything is parsed or written
redact="$(cd "$(dirname "$0")/../.." && pwd)/scripts/redact.sh"
[ -x "$redact" ] || exit 0
input=$(printf '%s' "$input" | "$redact") || exit 0

root="${CLAUDE_PROJECT_DIR:-$(printf '%s' "$input" | jq -r '.cwd // empty')}"
[ -n "$root" ] || root="$PWD"
dir="$root/ai-logs"
mkdir -p "$dir" || exit 0

sid=$(printf '%s' "$input" | jq -er '.session_id') || exit 0
short="${sid:0:8}"
day=$(date +%Y-%m-%d)

# Keep one file per session even if it spans midnight
existing=$(ls "$dir"/session-*-"$short".md 2>/dev/null | head -n 1)
base="${existing%.md}"
[ -n "$base" ] || base="$dir/session-$day-$short"
md="$base.md"

printf '%s\n' "$input" >> "$base.jsonl"

event=$(printf '%s' "$input" | jq -r '.hook_event_name // empty')
ts=$(date '+%H:%M:%S')

if [ ! -f "$md" ]; then
  {
    printf '# AI session log\n\n'
    printf -- '- Session: `%s`\n- Started: %s %s\n\n---\n\n' "$sid" "$day" "$ts"
  } >> "$md"
fi

case "$event" in
  UserPromptSubmit)
    {
      printf '## [%s] User\n\n' "$ts"
      printf '%s' "$input" | jq -r '.prompt // ""' | sed 's/^/> /'
      printf '\n'
    } >> "$md"
    ;;
  PostToolUse)
    {
      printf '### [%s] Tool: %s\n\n' "$ts" \
        "$(printf '%s' "$input" | jq -r '.tool_name // "unknown"')"
      printf '```json\n'
      printf '%s' "$input" | jq -c '.tool_input // {}' | cut -c1-500
      printf '\n```\n\n'
    } >> "$md"
    ;;
  Stop)
    reply=$(printf '%s' "$input" | jq -r '.last_assistant_message // empty')
    if [ -z "$reply" ]; then
      tp=$(printf '%s' "$input" | jq -r '.transcript_path // empty')
      if [ -f "$tp" ]; then
        reply=$(jq -rs '
          (map(.type == "user" and (.message.content | type == "string"))
            | rindex(true) // -1) as $i
          | [.[$i+1:][] | select(.type == "assistant")
             | .message.content[]? | select(.type == "text") | .text]
          | join("\n\n")' "$tp")
      fi
    fi
    {
      printf '## [%s] Claude\n\n' "$ts"
      printf '%s' "$reply" | "$redact" | head -c 8000
      printf '\n\n---\n\n'
    } >> "$md"
    ;;
esac
exit 0
