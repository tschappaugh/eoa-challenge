#!/usr/bin/env bash
# Usage: scripts/scrub-transcript.sh <input> [output]
# Redacts API keys from an exported transcript. Edits in place if no output.
set -euo pipefail
in="${1:?usage: scrub-transcript.sh <input> [output]}"
out="${2:-$in}"
tmp=$(mktemp)
"$(dirname "$0")/redact.sh" < "$in" > "$tmp"
mv "$tmp" "$out"
echo "Scrubbed: $out"
