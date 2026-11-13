#!/usr/bin/env bash
# Generates an EOA API key and saves it to .env without printing it.
# Creates .env from .env.example if needed. Safe to re-run: EOA keys expire
# (roughly daily), and each key has its own, initially empty, game library.
set -euo pipefail
cd "$(dirname "$0")/.."

base="${EOA_API_BASE_URL:-https://codechallenge.essensedesigns.info}"

if ! response=$(curl -fsS -m 20 -X POST "$base/api-key/generate"); then
  echo "Could not reach $base/api-key/generate." >&2
  exit 1
fi

key=$(printf '%s' "$response" | sed -nE 's/.*"key"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/p')
if [ -z "$key" ]; then
  echo "The API answered, but without a key. Try again in a moment." >&2
  exit 1
fi

[ -f .env ] || cp .env.example .env

# The key goes through the environment, not the command line, so it never
# shows up in a process listing.
tmp=$(mktemp)
if grep -q '^EOA_API_KEY=' .env; then
  KEY="$key" awk '/^EOA_API_KEY=/ { print "EOA_API_KEY=" ENVIRON["KEY"]; next } { print }' .env > "$tmp"
else
  cat .env > "$tmp"
  # Start on a new line even if .env doesn't end with one.
  [ -s .env ] && [ -n "$(tail -c 1 .env)" ] && echo >> "$tmp"
  printf 'EOA_API_KEY=%s\n' "$key" >> "$tmp"
fi
mv "$tmp" .env
chmod 600 .env

echo "Saved a new EOA_API_KEY to .env (${#key} characters, not shown)."
echo "If the app is already running, apply it with: docker-compose up -d php"
