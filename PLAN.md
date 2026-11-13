# Game Voting App — Project Plan

Essense of Australia code challenge: a full-stack app where employees suggest
games for the office library and vote on the ones they want most.

- Challenge instructions: https://codechallenge.essensedesigns.info/instructions
- API reference: https://codechallenge.essensedesigns.info/docs

## Requirements

### Hard constraints

- Backend is PHP.
- Games, votes, and daily-limit tracking persist in a real database. Cookies
  or local storage may only identify the user.
- Self-contained and runnable locally with `docker-compose up`.
- Full AI chat transcript included in the repo; AI work stays in a single
  session.
- `README.md` explaining how to run the app and the decisions made.

### Features

1. Display every game, sorted by vote count (highest first).
2. Add a game by title; duplicate titles (case-insensitive) rejected by the
   backend.
3. Vote for a game.
4. Remove a vote you've cast.
5. Remove a game from the list.
6. Works well on mobile and desktop.
7. Errors (network, validation) surfaced in a friendly way — no silent
   failures.

### Business rule

A user may cast **one vote OR add one new game per day** (midnight to
midnight, user's local time). The upstream API doesn't enforce this; our
backend must.

### Evaluation focus

Code quality, UX/UI polish, correct and idiomatic use of the REST API, how
thoughtfully the daily rule is implemented, error handling and edge cases,
and the AI-tooling conversation.

## Architecture

```
Browser (React SPA) ──/api──▶ nginx ──▶ PHP-FPM (Slim 4) ──▶ EOA REST API
                                              │              (games, vote counts)
                                              ▼
                                           MySQL 8
                         (users, votes, daily actions, game title index)
```

### Data ownership

| Data | Owner | Reason |
| --- | --- | --- |
| Games and vote counts | EOA REST API | System of record; the listing reads from it |
| Users, vote ledger, game ownership, daily actions, normalized titles | MySQL | The API can't know who voted or who added what |

### Write path

1. Validate business rules against MySQL.
2. Call the EOA API.
3. Commit the local DB transaction.

- If the API call fails, nothing is written locally and the user sees a
  friendly error.
- If the API succeeds but the DB commit fails, the API change is compensated
  (e.g. `removeVote` after a failed vote record, `remove` after a failed add).

### API key

A single server-side key in `.env`, shared by the whole office library. It is
never sent to the browser. See [Secret handling](#secret-handling).

## Business rule decisions

1. **Identity:** anonymous UUID in an httpOnly cookie set by the backend,
   backed by a `users` row. Clearing cookies creates a new user — documented
   as an accepted limitation.
2. **Local day:** the browser sends its IANA time zone on first visit; it is
   stored on the user and used from then on, so switching time zones can't
   earn extra actions.
3. **One action per day:** a vote or an add consumes the day's action. A
   unique `(user_id, local_date)` index enforces this even under concurrent
   requests.
4. **Voting:** one vote per user per game, as a toggle.
5. **Removing a vote:** never requires an action. Removing a vote cast today
   refunds today's action (undo). Removing an older vote refunds nothing.
6. **Removing a game:**
   - Only the user who added the game may remove it. The button is hidden for
     everyone else and the backend returns `403 NOT_OWNER`.
   - Removing a game you added today refunds today's action (same undo rule).
   - Users who voted for that game *today* get their action refunded, since
     they lost it through no fault of their own. Older votes are deleted.
   - Games present in the API but not in our DB (e.g. added via the docs'
     "Try it" form) are displayed but can't be removed.
7. **Limit status in the UI:** a banner always shows whether today's action
   is available or used, and when it resets.

## Duplicate titles

- Stored and shown title: NFC form, invisible format characters removed,
  control characters turned to spaces, whitespace trimmed and collapsed.
  The first person's punctuation is kept as typed.
- Comparison key (`Title::normalize`, mirrored in the frontend's
  `normalizeTitle`): the cleaned title with look-alike punctuation folded to
  one ASCII form, then NFKC, then case-folded. The folding is a short,
  explicit list, applied first because NFKC would rewrite some of these
  characters itself:
  - `’ ‘ ‛ ʼ ′` `` ` `` `´` → `'`
  - `“ ” „ ‟ ″` → `"`
  - `‐ ‑ ‒ – — ― −` → `-`

  So `Baldur’s Gate`, `Baldur's Gate`, and `baldur´s gate` are the same game,
  and `Half–Life` matches `Half-Life`. Punctuation is otherwise significant:
  `Baldurs Gate` and `Half Life` are different titles. A full Unicode
  confusables check (ICU `Spoofchecker`) was rejected as too aggressive for
  game titles.
- A unique index on `title_normalized`. The backend reserves the row, calls
  `/games/add`, then stores the returned `api_id`. If the API call fails, the
  reservation is deleted. This closes the race where two users add the same
  title at once.

## Database schema (draft)

- `users`: `id` (UUID), `timezone`, `created_at`
- `games`: `id`, `api_id` (unique), `title`, `title_normalized` (unique),
  `created_by` → `users`, `created_at`
- `votes`: `user_id`, `game_id`, `cast_local_date`, `created_at`;
  PK `(user_id, game_id)`
- `daily_actions`: `user_id`, `local_date`, `type` (`vote` | `add`),
  `ref_id`; unique `(user_id, local_date)`

## Backend API

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/me` | Today's limit status and the user's time zone |
| GET | `/api/games` | Games sorted by votes, with `votedByMe` and `canRemove` |
| POST | `/api/games` | Add a game (uses the daily action) |
| DELETE | `/api/games/{id}` | Remove a game (creator only) |
| POST | `/api/games/{id}/vote` | Vote (uses the daily action) |
| DELETE | `/api/games/{id}/vote` | Remove the user's vote |

- Errors are returned as `{ "error": { "code": "...", "message": "..." } }`
  with codes such as `DUPLICATE_TITLE`, `DAILY_LIMIT_REACHED`, `NOT_OWNER`,
  `VALIDATION_FAILED`, and `UPSTREAM_UNAVAILABLE`.
- Upstream failures map to 502/503 with a friendly message, never raw errors.

## Stack

- **Backend:** PHP 8.3, Slim 4, PDO, Guzzle; PHPUnit.
- **Database:** MySQL 8, migrations run on container startup.
- **Frontend:** React, TypeScript, Vite, Tailwind v4; Vitest + React Testing
  Library. Montserrat is self-hosted through the `@fontsource-variable`
  package: the CSP allows only same-origin fonts and styles, and it keeps
  visitors' requests off third-party servers.
- **Docker Compose:** `web` (nginx serving the built SPA and proxying `/api`),
  `php` (php-fpm), `db` (MySQL).

## Frontend UX

- Ranked game list with vote counts and a vote toggle. A remove button
  appears only on the user's own games, behind an accessible confirmation
  dialog.
- Add form with inline validation and the backend's duplicate error.
- Limit banner; vote and add controls are disabled with an explanation once
  today's action is used.
- Loading states, inline or toast errors, retry on network failure, and
  optimistic updates rolled back on failure.
- WCAG 2.2 AA, fully keyboard navigable, mobile-first layout.
- Warm neutral styling consistent with the Essense site.

## Testing

Focused on the logic that matters, not coverage numbers:

- **Daily-limit service:** one action per day, refund rules, midnight
  boundaries across time zones, concurrent requests.
- **Titles and ownership:** duplicate normalization, creator-only removal.
- **API client:** error mapping and compensation, against a mocked HTTP
  layer.
- **Frontend:** errors are surfaced, disabled state when the limit is used,
  optimistic update rollback.

## Observed API behavior (Phase 2)

Probed with `scripts/probe-api.sh` (re-runnable; reads the key from `.env`).
All write endpoints take **form-encoded** bodies; JSON bodies fail.

| Behavior | Observed | Consequence for our backend |
| --- | --- | --- |
| Duplicate titles | Accepted; each gets a new id (`Zelda`, `zelda`, `  Zelda  ` all added) | Our unique `title_normalized` index is the only guard — confirmed essential |
| Empty or missing `name` | `200 {"id":-1}` | Validate before calling; treat any `id < 1` as failure |
| Name storage | Not trimmed; no length limit seen (300 chars OK); HTML-escaped on store (`&` → `&amp;`, `<` → `&lt;`, `"` → `&quot;`) | Send the trimmed/collapsed title, cap length ourselves, `html_entity_decode` API names on read (prefer our local title for known games). React escapes on render, so never pre-escape |
| `/games/list` order | Id order, **not** sorted by votes | Sort ourselves: votes desc, ties by id asc |
| Vote | Anonymous counter; the same id can be voted repeatedly (count increments each time) | Per-user dedupe lives in our `votes` table |
| `removeVote` | Decrements, floors at 0, returns `success: true` even when count is already 0 | Success doesn't prove a vote existed — our ledger decides whether a call is allowed |
| Unknown or removed id | `search` → `200 {}`; `vote`/`removeVote`/`remove` → `200 {"success":false}` | Check the `success` flag and the empty-object case; HTTP 200 doesn't mean OK |
| Removing twice | Second call → `{"success":false}` | Treat as already gone |
| Ids | Sequential, never reused after a remove; `lastGameId` unchanged by a remove; both reset after `/cache/flush` | Don't assume ids are contiguous; never expose `flush` |
| Id parsing | Loose: `id=2abc` acts as 2, `id=1.5` as 1 | Validate integer ids ourselves before forwarding |
| Auth errors | `400 {"error":"MISSING_KEY"}`, `403 {"error":"INVALID_KEY"}` | Map to `UPSTREAM_UNAVAILABLE` for users; log the cause |
| Wrong method / unknown route | HTML error page (405/404) | Client must handle non-JSON responses |
| Key lifetime | A key generated on Nov 11 was rejected (`INVALID_KEY`) the next day | Keys expire. The client logs a hint ("generate a new key, set `EOA_API_KEY`, restart php"); users just see the friendly upstream error. README must tell reviewers to generate a fresh key |
| Isolation | Each key has its own library | Test data was flushed after probing; library is empty |

Compensation notes: undoing an add is `/games/remove`; undoing a vote is
`/games/removeVote`. Both are safe to call after a local failure because the
counters are anonymous and floor at 0.

## Backend implementation notes (Phase 3)

- **Write path:** claim locally in one transaction, call the API, undo the
  claim if the call fails. Remove-game is the exception: it calls the API
  first (nothing to roll back if it fails; a later list sync heals any
  local leftovers).
- **List sync:** the API decides which games exist and their vote counts.
  Each list call imports games added elsewhere (no owner, so not removable;
  duplicate titles get a `#<apiId>` suffix in `title_normalized`) and purges
  local rows that vanished upstream, with a 60 s grace period so a game
  added a moment ago is never purged by a stale list.
- **Tests:** unit tests for titles, clock and the EOA client; DB-backed
  tests (real MySQL, `gamevoting_test`) for the daily rule, refunds,
  ownership and upstream failures; HTTP tests for identity cookies and the
  error shape. `FakeEoa` mimics the observed API quirks.
- **Known limitation:** the same-user concurrency guarantee rests on the
  `(user_id, local_date)` primary key; it is exercised sequentially in
  tests, not with parallel connections.

## Security hardening (post-backend review)

| # | Issue | Fix |
| --- | --- | --- |
| 1 | No `php.ini`: errors displayed in responses, not logged; PHP version exposed | `php.ini-production` plus overrides (`backend/docker/php.ini`) |
| 2 | Writes accepted form/XML bodies; CSRF relied on SameSite alone | JSON-only bodies (415 otherwise); `X-Requested-With` required on writes; `Origin` must match the real host |
| 3 | No security headers; per-user API responses cacheable; nginx version exposed | nosniff, DENY framing, Referrer-Policy, Permissions-Policy, strict CSP; `Cache-Control: no-store` on `/api`; `server_tokens off` |
| 4 | Every cookie-less read created a users row | Identity is created on the first write only; reads get an anonymous view |
| 5 | No rate limiting | nginx per-IP limits on `/api` (10 r/s overall, 30 writes/min) with a JSON 429 |
| 6 | Unicode variants bypassed duplicate detection | NFC for storage; NFKC + case folding for comparison; format characters removed (intl) |
| 7 | Cookie never `Secure` | `Secure` when served over HTTPS (scheme or `X-Forwarded-Proto`) |
| 8 | Runtime image shipped dev deps and tests | Multi-stage Dockerfile: `prod` (no dev deps, tests, or Composer) and `dev` (Compose `test` profile) |
| 9 | Required PHP extensions undeclared | `ext-intl`, `ext-mbstring`, `ext-pdo_mysql`, `ext-json` in `composer.json` |
| 10 | Failed vote restore could turn a 502 into a 500 | Restore is best-effort and logged |
| 11 | 1 MB request bodies allowed | 16 KB on `/api` |
| 12 | Daily-limit 429 had no `Retry-After` | Seconds until the user's local midnight |
| 13 | No CI; local pre-commit hook is bypassable | GitHub Actions: PHPCS, PHPStan, PHPUnit (MySQL service), composer/npm audit, frontend lint/test/build, image build, gitleaks over full history (custom EOA key rule in `.gitleaks.toml`) |
| 14 | No static analysis | PHPStan level 8 and PHPCS PSR-12, both clean |

Found during live verification: Slim's PSR-7 request rewrites `Host` without
the port, so the origin check compares against the raw `HTTP_HOST`. Tests
now build requests the same way production does.

Known limitations: cookie clearing still yields a new identity (accepted by
the brief; rate limits bound the damage). Behind Docker Desktop all local
clients share one IP, so rate limits are effectively global locally.

## Frontend implementation notes (Phase 4)

- **Structure:** `lib/` (API client, messages, title and time helpers),
  `hooks/useGameLibrary` (all state: load, optimistic vote/unvote/remove,
  add, resync), `components/` (LimitBanner, AddGameForm, GameList,
  GameItem, ConfirmDialog, ErrorAlert, StatusRegion).
- **Optimistic updates:** vote, unvote, and remove apply immediately and
  roll back on failure; add waits for the server id. After every change a
  quiet re-fetch reconciles with the server (it also picks up refunds).
  Stale responses are discarded (newest request wins). Errors that mean the
  view is stale (`DAILY_LIMIT_REACHED`, `ALREADY_VOTED`, `NOT_FOUND`)
  trigger a resync.
- **Daily limit UX:** a banner always states whether today's action is
  available and, if not, when it unlocks (local time plus countdown). Vote
  and Add use `aria-disabled` rather than `disabled`, so they stay
  focusable; clicking explains why in the page alert and the button is
  described by the banner. Removing a vote is never blocked. The banner
  re-checks itself at the user's local midnight.
- **Sorting (added after review):** the list defaults to most votes first.
  A Rank button and an A–Z button sort it in the browser (the full list is
  already fetched, so there is no backend work); clicking the active button
  reverses it (fewest votes first, Z to A), and clicking the other one
  switches to its default direction. The controls sit above the list, greyed
  out (`aria-disabled`) with fewer than two games, and a second set appears
  below the list from 11 games. Rank numbers always show the true vote
  rank, whatever the order. A–Z ignores case and accents and orders numbers
  as numbers ("Portal 2" before "Portal 10"); ties fall back to the lowest
  id. The choice is not stored (a reload returns to rank order), the
  heading names the current order, and each change is announced politely.
- **Page design:** the intro sits in a wider section (`max-w-4xl`) than the
  form and list (`max-w-2xl`): an illustration beside the heading and intro
  text from the `md` breakpoint, above it on mobile (cropped to 4:3 so it
  doesn't push the form down). The image lives in `src/assets` so Vite hashes
  it and nginx serves it with the immutable cache header; its size
  attributes reserve the space. The intro copy says "once per day, either
  suggest a game or vote", matching the one-action rule.
- **Errors:** every failure is shown in words (network, rate limit, daily
  limit, duplicate, upstream outage); field errors appear next to the
  input, everything else in the page alert. No silent failures.
- **Accessibility:** WCAG 2.2 AA. Semantic landmarks, one `h1`, skip link,
  `aria-pressed` vote toggle whose accessible name contains its visible
  label, native modal `<dialog>` (focus trap, Escape, Cancel focused
  first), polite live region for confirmations, `role="alert"` for errors,
  44 px minimum targets, visible focus rings, `prefers-reduced-motion`
  respected. The brand tan `#8b7355` is 4.49:1 on white (just under AA), so
  text uses `#6f5b3e` (6.5:1) and control borders use `#8a7a66` (3:1+).
- **Testing:** Vitest + Testing Library against an in-memory fake of the
  backend (real fetch client, real hook); axe checks in jsdom. Real Chrome
  verification (not in CI) covered contrast, mobile layout, keyboard order,
  and CSP, which jsdom can't.
- **Bugs the tests and browser run caught:** a `setTimeout` delay over
  2^31 ms fires immediately (clamped); sr-only name spans dropped their
  space ("Votefor Zelda"), so buttons use `aria-label`; focus fell to
  `<body>` after confirming a removal until the dialog closed.

## Secret handling

The EOA API key must never be committed — not in code, config, or AI logs.

1. **Code and config:** the key lives only in `.env` (gitignored from the
   first commit). `.env.example` ships with `EOA_API_KEY=` blank. Compose
   passes it to PHP via the environment; it never reaches the frontend bundle
   or any API response. The README tells reviewers to generate their own key.
2. **AI logs:** `.claude/hooks/log-ai.sh` redacts before writing to both the
   `.md` and `.jsonl` logs — the exact key value from `.env`, any standalone
   32-character hex string, and any `api_key=…` value become
   `[REDACTED_API_KEY]`.
3. **Working practice:** the key is written straight to `.env` when
   generated, never printed. curl tests read it from the environment, so it
   never appears in tool inputs or replies.
4. **Transcript export:** `scripts/scrub-transcript.sh` applies the same
   redaction to the final `/export` before it is committed.
5. **Pre-commit hook:** blocks any commit whose staged diff contains the key
   value or a key-shaped string. CI backs this up with gitleaks over the
   full history, since local hooks can be skipped.
6. **If a key leaks:** discard it and generate a new one. Rewriting git
   history alone is not enough.

## Phases

1. **Repo setup:** `git init`; `.gitignore` (`.env`, `ai-logs/*.jsonl`,
   `vendor/`, `node_modules/`, build output) and `.env.example`; log
   redaction plus tests; pre-commit secret check; transcript scrub script;
   connect the remote; initial commit. No push without approval.
2. **API probing (done):** generate a key into `.env` and record actual behavior —
   duplicate handling, negative vote counts, unknown IDs, status codes, body
   format. Findings go in the README.
3. **Backend:** schema, API client, services, routes, PHPUnit tests.
4. **Frontend:** UI, loading and error states, tests.
5. **Docker:** verify `docker-compose up` from a fresh clone.
6. **Polish and submit:** accessibility pass, README with decisions, AI
   transcript (hook logs plus scrubbed `/export`), push.
