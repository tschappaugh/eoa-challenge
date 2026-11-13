# EOA Challenge — Game Voting App

Essense of Australia code challenge: a full-stack game voting app for an
office library. The full plan and all product decisions are in
[PLAN.md](PLAN.md) — read it before making changes.

## Current status

- **Done (Phase 1):** git repo with remote
  `https://github.com/tschappaugh/eoa-challenge.git`; AI session logging
  (`.claude/hooks/log-ai.sh` -> `ai-logs/session-<date>-<id>.md`) with API
  key redaction via `scripts/redact.sh`; pre-commit secret check
  (`scripts/git-hooks/pre-commit`, enabled with `scripts/setup-hooks.sh`);
  `scripts/scrub-transcript.sh` for the final `/export`; `.gitignore` and
  `.env.example`. Tests: `scripts/test-redact.sh`.
- **Done (Phase 2):** API key generated into `.env` (gitignored, never
  printed); API probed with `scripts/probe-api.sh`; findings recorded in
  PLAN.md -> "Observed API behavior". The API is permissive (no dedupe, loose
  ids, HTTP 200 for failures, HTML-escaped names), so our backend validates
  everything and checks `success` flags.
- **Done (Phase 3 scaffold, branch `chore/scaffold`):** Docker Compose
  stack (`web` nginx + built SPA, `php` php-fpm, `db` MySQL 8.4) verified:
  `/api/health`, SPA deep links, schema created from
  `backend/migrations/001_init.sql`, PHPUnit (3 tests) and Vitest (1 test)
  passing. Backend skeleton in `backend/src` (Config, Database, Json,
  AppFactory with JSON error handler); frontend is Vite + React + TS +
  Tailwind v4 with a placeholder App.
- **Done (Phase 3 backend, branch `feat/backend`):** domain helpers
  (Title, Clock), repositories, EOA client (`GuzzleEoaClient`), services
  (Game, Vote, DailyLimit), identity-cookie middleware and routes
  (`/api/me`, `/api/games`, vote endpoints). 71 PHPUnit tests pass against a
  real MySQL test DB; verified live against the EOA API.
- **Gotcha:** EOA API keys expire (a key from the previous day was
  rejected), and each key has its own library. If the API starts answering
  `INVALID_KEY`, run `scripts/generate-key.sh` (never prints the key) and
  `docker-compose up -d php`.
- **Done (hardening, merged):** security review fixes and CI (five green
  jobs: backend, frontend, docker, secret scan, dependency audit).
- **Done (frontend, merged):** React UI for the full feature set (ranked
  list, add form, vote toggle, creator-only remove with confirm dialog,
  daily-limit banner with countdown, loading/empty/error states, optimistic
  updates with rollback), two Opus review rounds of fixes, duplicate titles
  compared with look-alike quotes and dashes folded, and Rank / A-Z sorting
  (`feat/sort`, merged). Verified in real Chrome (axe: 0 violations incl.
  contrast, mobile 390px, keyboard, focus, CSP clean).
- **Done (design, merged):** Montserrat (self-hosted), new heading and intro
  copy, header with "Code Challenge" on the right, intro image beside the
  text (above it on mobile). CI now runs on every branch push.
- **Done (final review, merged):** full-repo security and best-practices
  pass (secrets, dependencies, code, Docker, CI); Node 20 (EOL) replaced by
  Node 24; nginx gzip for static files. Recorded in PLAN.md -> "Final
  review".
- **Done (submission, branch `docs/submission`):** README for reviewers,
  `scripts/generate-key.sh` (writes a new key to `.env` without printing
  it), checkmark favicon, illustration credit (Unsplash), PLAN.md
  write-path correction. Quick start verified from a fresh clone of the
  branch, following only the README. The user reviewed the code before the
  final merge.
- **Submission state:** complete. Tests: 86 PHPUnit, 74 Vitest; CI green
  (five jobs, every push). The AI record is `ai-logs/`: the live session
  log (complete, written by the hook) and `transcript.md` (the final
  `/export`, scrubbed with `scripts/scrub-transcript.sh`).
- Run the backend tests with `--build` so the test image isn't stale.

## Frontend contract (from hardening)

- Every write (POST/DELETE) must send `X-Requested-With: XMLHttpRequest`;
  `POST /api/games` must send `Content-Type: application/json`.
- Send `X-Timezone` (IANA name from `Intl.DateTimeFormat`) on every
  request; it is saved on the user's first write.
- 429 can mean `DAILY_LIMIT_REACHED` (has `Retry-After` in seconds) or
  `RATE_LIMITED` (from nginx); branch on `error.code`, not the status.
- Never render game titles with `dangerouslySetInnerHTML` (titles are
  stored decoded). The CSP allows only same-origin scripts and styles.

## Commands

```bash
docker-compose up -d --build        # app at http://localhost:8080
docker-compose --profile test run --rm test # backend tests (gamevoting_test DB)
docker-compose --profile test run --rm test composer check  # phpcs + phpstan + phpunit
cd frontend && npm test             # frontend tests
cd frontend && npm run dev          # Vite dev server (proxies /api to :8080)
```

Migrations only run when the `db-data` volume is first created; after
changing `backend/migrations`, run `docker-compose down -v` to reapply.

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
- Duplicate titles are compared on a folded key: NFKC, case-folded, with
  look-alike quotes and dashes (`’`/`´`/`` ` `` → `'`, `–`/`—` → `-`, etc.)
  mapped to ASCII. The map lives in `Title::LOOKALIKES` (PHP) and `LOOKALIKES`
  in `frontend/src/lib/title.ts`; change both together. Stored titles keep the
  typed punctuation.

## Code standards

- TypeScript frontend (React, Vite, Tailwind v4); no `any`.
- No semicolons, single quotes, 2-space indent, trailing commas (es5),
  80-char width.
- WCAG 2.2 AA by default.
- Conventional commit messages.
