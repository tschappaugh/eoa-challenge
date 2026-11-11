# EOA Challenge — Game Voting App

Essense of Australia code challenge: a full-stack game voting app for an
office library. The full plan and all product decisions are in
[PLAN.md](PLAN.md) — read it before making changes.

## Current status

- **Done:** AI session logging (`.claude/settings.json` hooks →
  `.claude/hooks/log-ai.sh` → `ai-logs/session-<date>-<id>.md`), verified
  firing. `.gitignore` ignores `ai-logs/*.jsonl`. Plan finalized in
  `PLAN.md`.
- **Not done:** not yet a git repo; remote
  `https://github.com/tschappaugh/eoa-challenge.git` not connected. No app
  code yet. No API key generated yet.
- **Next:** Phase 1 (repo setup and secret safeguards), then Phase 2 (API
  probing). See PLAN.md → Phases.

## Hard constraints

- Backend must be PHP (Slim 4); persistence in MySQL, not cookies or local
  storage.
- Must run from a fresh clone with `docker-compose up`.
- All AI work stays in a single session; the full transcript is part of the
  submission.
- Never push to GitHub without the user's explicit approval.

## API key rules (critical)

- The EOA API key lives only in `.env` (gitignored). Never write it into
  code, config, docs, commit messages, or chat replies.
- When generating the key, write it directly to `.env` without printing it.
- In shell commands, reference it from the environment (e.g. `$EOA_API_KEY`
  after sourcing `.env`); never paste the literal value.
- Don't generate a key until log redaction and the pre-commit secret check
  (Phase 1) are in place and tested.

## Key decisions (summary)

- EOA API is the system of record for games and vote counts; MySQL holds
  users, the vote ledger, game ownership, daily actions, and normalized
  titles.
- Write path: validate in DB, call the API, commit locally; compensate the
  API call if the local commit fails.
- One vote or one add per user per local day, enforced by a unique
  `(user_id, local_date)` index. Undoing today's vote or add refunds it.
- Only a game's creator can remove it.

## Code standards

- TypeScript frontend (React, Vite, Tailwind v4); no `any`.
- No semicolons, single quotes, 2-space indent, trailing commas (es5),
  80-char width.
- WCAG 2.2 AA by default.
- Conventional commit messages.
