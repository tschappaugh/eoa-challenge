#!/usr/bin/env bash
# Tests for redact.sh and the pre-commit hook. Uses fake keys only.
cd "$(dirname "$0")/.." || exit 1
fail=0
check() { # name, expected, actual
  if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi
}
R=[REDACTED_API_KEY]
# Fake values are assembled at runtime so this file itself never matches
# the secret patterns (and the pre-commit hook lets it through).
HEX16=0123456789abcdef
FAKE="$HEX16$HEX16"
CUSTOM=Zq9-custom_key_value_123
AK=api_key
EXACT=Zq9customkeyvalue

check hex32 "key $R end" "$(echo "key $FAKE end" | scripts/redact.sh)"
SHA="$HEX16$HEX16${HEX16:0:8}"
check hex40-untouched "commit $SHA" \
  "$(echo "commit $SHA" | scripts/redact.sh)"
check uuid-untouched "16730bd4-8a39-42b0-8ee5-dd320d5640b3" \
  "$(echo '16730bd4-8a39-42b0-8ee5-dd320d5640b3' | scripts/redact.sh)"
check query "?$AK=$R&x=1" \
  "$(echo "?$AK=$CUSTOM&x=1" | scripts/redact.sh)"
check json "\"$AK\": \"$R\"" \
  "$(echo "\"$AK\": \"$CUSTOM\"" | scripts/redact.sh)"
check env-line "EOA_API_KEY=$R" \
  "$(echo "EOA_API_KEY=$CUSTOM" | scripts/redact.sh)"
check blank-env "EOA_API_KEY=" "$(echo 'EOA_API_KEY=' | scripts/redact.sh)"
check doc-text "api_key: string" "$(echo 'api_key: string' | scripts/redact.sh)"
check exact-env "x $R y" \
  "$(echo "x $EXACT y" | EOA_API_KEY=$EXACT scripts/redact.sh)"

# pre-commit hook in a throwaway repo
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
git -C "$tmp" init -q
mkdir -p "$tmp/scripts"
cp scripts/redact.sh "$tmp/scripts/"
echo "k=$FAKE" > "$tmp/leak.txt"; echo ok > "$tmp/clean.txt"; echo x > "$tmp/.env"
git -C "$tmp" add leak.txt && (cd "$tmp" && "$OLDPWD/scripts/git-hooks/pre-commit" 2>/dev/null)
check hook-blocks-key 1 $?
git -C "$tmp" reset -q; git -C "$tmp" add -f .env
(cd "$tmp" && "$OLDPWD/scripts/git-hooks/pre-commit" 2>/dev/null)
check hook-blocks-env 1 $?
git -C "$tmp" reset -q; git -C "$tmp" add clean.txt
(cd "$tmp" && "$OLDPWD/scripts/git-hooks/pre-commit" 2>/dev/null)
check hook-allows-clean 0 $?

exit $fail
