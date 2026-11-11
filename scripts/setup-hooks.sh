#!/usr/bin/env bash
# Enables the repo's git hooks. Run once after cloning.
cd "$(dirname "$0")/.." && git config core.hooksPath scripts/git-hooks
echo "git hooks enabled (core.hooksPath=scripts/git-hooks)"
