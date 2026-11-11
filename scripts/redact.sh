#!/usr/bin/env bash
# Filter: stdin -> stdout with API keys replaced by [REDACTED_API_KEY].
# Redacts (1) the exact EOA_API_KEY from env or .env, (2) standalone
# 32-char hex strings, (3) long values assigned to api_key / EOA_API_KEY.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
key="${EOA_API_KEY:-}"
if [ -z "$key" ] && [ -f "$root/.env" ]; then
  key=$(grep -E '^EOA_API_KEY=' "$root/.env" | head -n 1 | cut -d= -f2- \
    | sed -E "s/^[\"']//; s/[\"']\$//")
fi
KEY="$key" perl -pe '
  BEGIN { $k = $ENV{KEY} // "" }
  s/\Q$k\E/[REDACTED_API_KEY]/g if length($k) >= 8;
  s/(?<![0-9A-Za-z])[0-9a-fA-F]{32}(?![0-9A-Za-z])/[REDACTED_API_KEY]/g;
  s/(api_key["\x27]?\s*[=:]\s*["\x27]?)[A-Za-z0-9_\-]{16,}/$1\[REDACTED_API_KEY]/gi;
'
