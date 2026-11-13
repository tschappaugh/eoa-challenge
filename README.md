# Vote for Your Favorite Game

A full-stack app for the Essense of Australia code challenge: employees
suggest games for the office library and vote for the ones they want most.
Each person gets **one action per day**, either a vote or a new suggestion,
measured in their own time zone.

- **Backend:** PHP 8.3, Slim 4, MySQL 8.4
- **Frontend:** React 19, TypeScript, Vite, Tailwind CSS v4
- **Runs with:** `docker-compose up`, nothing else to install

## Quick start

You need Docker with Compose (Docker Desktop is fine). Both `docker-compose`
and `docker compose` work.

```bash
git clone https://github.com/tschappaugh/eoa-challenge.git
cd eoa-challenge
./scripts/generate-key.sh      # creates .env with a fresh EOA API key
docker-compose up --build      # first build takes a few minutes
```

Open **http://localhost:8080**.

Without a key, Compose stops right away with
`Set EOA_API_KEY in .env (see .env.example)`.

### About the EOA API key

- `scripts/generate-key.sh` asks the EOA API for a new key and writes it to
  `.env` without printing it. To do it by hand, generate a key at
  https://codechallenge.essensedesigns.info/api-key and put it in `.env` as
  `EOA_API_KEY=...` (see `.env.example`).
- **Keys expire, roughly daily.** If the app says "The game library is
  unavailable right now", generate a new key and restart PHP:
  ```bash
  ./scripts/generate-key.sh && docker-compose up -d php
  ```
  The PHP log says so directly (`docker-compose logs php`):
  `EOA error: INVALID_KEY (generate a new key ...)`.
- **Each key has its own library**, and a new one starts empty. Because the
  EOA API is the system of record, the app clears its local copy of games
  that no longer exist upstream on the next refresh.

### Stopping and resetting

```bash
docker-compose down       # stop; data is kept
docker-compose down -v    # stop and delete the local database
```

The schema in `backend/migrations` is applied when the database volume is
first created.

## Trying it out

- The banner under the introduction always shows whether today's action is
  available, and if not, when it unlocks (local time plus a countdown).
- To act as several people, use separate browsers or a normal and a private
  window. (All private windows in one browser share cookies.) You can also
  delete the `uid` cookie in DevTools to become a new user.
- The list has Rank and A–Z sort buttons. A second set appears below the list
  once it has more than 10 games.

## Tests and checks

```bash
# Backend: PHPUnit against a real MySQL test database (86 tests)
docker-compose --profile test run --rm --build test

# Backend: coding standard (PHPCS, PSR-12) + PHPStan (level 8) + PHPUnit
docker-compose --profile test run --rm --build test composer check

# Frontend: lint, Vitest + Testing Library + axe (74 tests), production build
# (needs Node on the host: 20.19+ works, 24 LTS recommended, see .nvmrc)
cd frontend && npm ci && npm run lint && npm test && npm run build
```

Use `--build` with the test runs: `run` doesn't rebuild on its own and would
otherwise test a stale image.

CI (GitHub Actions) runs on every push: backend checks, frontend checks,
the Docker build, a gitleaks secret scan over the full history, and
`npm audit` / `composer audit`.

## How it works

```
Browser (React SPA) ──/api──▶ nginx ──▶ PHP-FPM (Slim 4) ──▶ EOA REST API
                                              │              (games, vote counts)
                                              ▼
                                          MySQL 8.4
                    (users, vote ledger, game ownership, daily actions)
```

| Data | Lives in | Why |
| --- | --- | --- |
| Which games exist, vote counts | EOA REST API | The system of record; every listing reads from it |
| Users, who voted for what, who added what, daily actions, the duplicate-title index | MySQL | The API is anonymous: it can't know who did what |

nginx serves the built SPA and proxies `/api` to PHP. The API key stays on
the server: it is never sent to the browser.

### The daily rule

The rule is enforced on the server; the UI only explains it.

- **Identity:** an anonymous UUID in an HttpOnly cookie, created on the
  first write (reads never create users). The cookie only identifies the
  user; everything else is in MySQL.
- **Local day:** the browser sends its IANA time zone. It is saved on the
  user's first write and used from then on, so changing time zones can't
  earn extra actions.
- **One action:** a `daily_actions` row per user per local day, with
  `(user_id, local_date)` as the primary key. The claim and the vote (or the
  title reservation) happen in one transaction, so two simultaneous requests
  can't both succeed.
- **Refunds:** undoing something you did today returns the action: removing
  a vote cast today, or removing a game you added today. If a game is
  removed, people who voted for it today get their action back too, since
  they lost it through no fault of their own. Older votes refund nothing.
- **Removing a vote** is never blocked by the limit.
- **Only the person who added a game can remove it.** The button only
  appears for them, and the server returns `403 NOT_OWNER` otherwise.
- A rejected request returns `429 DAILY_LIMIT_REACHED` with `Retry-After`
  set to the seconds until the user's local midnight.

### Write path

1. Claim locally in one transaction (the day's action plus the vote or the
   title).
2. Call the EOA API.
3. If the call fails, undo the local claim. If a local step fails after the
   API succeeded, compensate upstream (remove the game that was just added).

Removing a game calls the API first, then cleans up locally. Each list
request also syncs with the API: games added elsewhere are imported (they
have no owner, so nobody can remove them here) and games that disappeared
upstream are dropped, with a 60-second grace period for games being added.

### Duplicate titles

The API accepts duplicates, so the backend's unique index on a normalized
title is the real guard. Titles are compared after:

- trimming and collapsing whitespace and removing invisible characters,
- folding look-alike punctuation: curly or straight apostrophes and quotes
  (`’ ‘ ´` → `'`, `“ ”` → `"`) and dash variants (`– — −` → `-`),
- Unicode NFKC normalization (full-width letters match plain ones),
- case folding (`ß` matches `ss`).

So `Baldur’s Gate`, `Baldur's Gate` and `baldur´s gate` are one game, while
`Baldurs Gate` is a different one. The title is stored and shown as the
first person typed it. The frontend mirrors the same rules for instant
feedback; the server decides.

### Working with the EOA API

The API was probed before writing the client (`scripts/probe-api.sh`; the
findings are in [PLAN.md](PLAN.md#observed-api-behavior-phase-2)). It is
permissive, so the backend validates everything:

| API behavior | How it's handled |
| --- | --- |
| Failures often return HTTP 200 (`{"success":false}`, `{"id":-1}`, `{}`) | Every response is checked for shape and success flags, not just status |
| Duplicates allowed; names stored HTML-escaped | Local unique index; names decoded on read, never rendered as HTML |
| Votes are an anonymous counter (same id can be voted repeatedly) | Per-user votes live in our ledger |
| `/games/list` isn't sorted | Sorted by votes, then by when the game reached the app |
| Loose id parsing (`2abc` acts as `2`) | Ids validated as positive integers before forwarding |
| Write endpoints only accept form-encoded bodies | The client sends form bodies; the key goes in the body, never the URL |
| Keys expire; each key has its own library | Friendly error for users, an actionable hint in the logs, `scripts/generate-key.sh` |

Upstream problems reach users as a friendly `502 UPSTREAM_UNAVAILABLE`;
the details go to the PHP log.

### Frontend

- **Optimistic updates:** vote, unvote and remove update at once and roll
  back only the affected game if the request fails. Each change is followed
  by a quiet re-sync; stale responses are discarded, and a refresh that
  could undo a pending change waits until the change settles.
- **Errors in words:** network failures, rate limits, the daily limit,
  duplicates and upstream outages each get a specific message. Field errors
  appear next to the input, everything else in a dismissible alert.
- **Sorting:** Rank (most or fewest votes first) and A–Z (or Z–A), in the
  browser. Rank numbers always show the true vote rank. A–Z ignores case and
  accents and orders numbers naturally (`Portal 2` before `Portal 10`).
- **Accessibility (WCAG 2.2 AA):** semantic landmarks and headings, skip
  link, `aria-pressed` toggles, a native modal `<dialog>` for confirmations,
  live regions for confirmations and errors, 44 px touch targets, visible
  focus, reduced-motion support. Checked with axe in tests and in real
  Chrome at 390, 768 and 1280 px.
- **Mobile first:** the intro image stacks above the text on phones and sits
  beside it from tablet width up.

## Security

- **CSRF:** writes require `X-Requested-With` (which forces a CORS preflight
  that is never approved) and, when the browser sends `Origin`, a same-origin
  match. Bodies must be JSON, so plain HTML forms can't post. The cookie is
  `SameSite=Lax`.
- **Cookie:** `HttpOnly`, `SameSite=Lax`, and `Secure` when served over
  HTTPS.
- **Headers:** a strict Content Security Policy (same-origin scripts, styles,
  fonts and images only), `nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, `Permissions-Policy`, and `Cache-Control: no-store` on
  every API response.
- **Rate limits (nginx):** 10 requests per second per IP on `/api`, plus 30
  writes per minute, answered with a JSON `429 RATE_LIMITED`. Request bodies
  are capped at 16 KB.
- **Database:** native prepared statements everywhere; MySQL isn't published
  on a host port and gets a random root password.
- **Runtime image:** no dev dependencies, tests or Composer; PHP runs with
  production settings (errors logged, never displayed).
- **Self-hosted font:** Montserrat is bundled, so the CSP needs no
  third-party origins and visitors' requests stay on this server.

### Keeping the API key out of the repo

The key lives only in `.env`, which has been gitignored since the first
commit. On top of that:

- **Pre-commit hook** (`scripts/git-hooks/pre-commit`, enable with
  `scripts/setup-hooks.sh`): blocks any commit containing the key value or a
  key-shaped string.
- **CI:** gitleaks scans the full history on every push, since local hooks
  can be skipped.
- **AI logs:** the logging hook redacts the key (and anything key-shaped)
  before writing, and `scripts/scrub-transcript.sh` applies the same
  redaction to the exported transcript. `scripts/test-redact.sh` tests the
  redaction.
- During development the key was written straight into `.env` and never
  printed; commands read it from the environment.

## Project layout

```
backend/
  src/        Slim app: Http (routes, middleware), Service (games, votes,
              daily limit), Repository (SQL), Eoa (API client), Domain
  tests/      PHPUnit: unit, database-backed, and HTTP tests
  migrations/ schema, applied when the database volume is created
frontend/
  src/        components, hooks/useGameLibrary (state and optimistic
              updates), lib (API client, titles, sorting, time)
docker/       nginx config, security headers, web image
scripts/      generate-key, git hooks, redaction, API probe
ai-logs/      the AI session log (see below)
PLAN.md       the full plan, decisions, and API findings
```

## Limitations and trade-offs

- **Identity is a cookie.** Clearing cookies or switching browsers makes a
  new user with a fresh action. The challenge allows cookie-based identity;
  rate limits bound the abuse. Real accounts would fix this.
- **Locally, rate limits are effectively global:** behind Docker Desktop,
  all clients share one IP address.
- **No HTTPS locally.** The cookie becomes `Secure` behind HTTPS; HSTS and
  TLS belong on whatever terminates TLS in production.
- **Default database credentials** (`gamevoting`) are fine for local use
  because MySQL isn't exposed; override them in `.env` anywhere else.
- **Removing a game** calls the API first. If the local cleanup then fails,
  the next list sync removes the leftover row, but refunds for that game's
  voters are skipped.
- **Concurrency** is guaranteed by database keys and transactions; the tests
  exercise the rules sequentially, not with parallel connections.
- **Supply chain:** CI actions and base images use version tags rather than
  pinned commit SHAs or image digests.

## Future improvements

- A filter box, and pagination if the library grows large.
- Re-sync when a tab becomes visible again, so a second open tab doesn't
  show stale limits.
- Real sign-in, if identity ever needs to be stronger than a cookie.

## How AI was used

The whole project was built in a single Claude Code session, as the
challenge requires. The full record is in [`ai-logs/`](ai-logs/):

- `session-*.md`: **the complete record.** Every prompt, tool call and
  response, written live by a Claude Code hook (`.claude/hooks/log-ai.sh`,
  configured in `.claude/settings.json`), with the API key redacted before
  anything is written. It starts the moment logging was enabled: the very
  first request, which set up this logging, came just before, and its result
  is the first entry.
- `transcript.md`: Claude Code's `/export` of the conversation at the end,
  scrubbed with `scripts/scrub-transcript.sh`. The session ran long enough
  that Claude Code compacted its earlier history, so the export covers the
  later part (running the app, the code reviews, sorting, design, the final
  review and submission). The session log covers everything.

How the work was split:

- **Planning and reviews** used Claude Opus: the plan, a security and
  best-practices review after the backend, two rounds of code review on the
  frontend, and a full pre-submission review.
- **Implementation** used Claude Sonnet, one feature branch per phase, with
  tests written alongside the code and changes verified in a real browser.
- **Decisions were mine:** the product rules (who may remove a game, refund
  behavior, sorting, punctuation folding), the design and copy, and what to
  keep out of scope. I reviewed and approved the changes on each branch
  before they were committed, and did every push and merge myself.

## Credits

- [Montserrat](https://github.com/JulietaUla/Montserrat) by Julieta Ulanovsky
  and contributors, SIL Open Font License 1.1, bundled via Fontsource.
- [Illustration](https://unsplash.com/illustrations/retro-gaming-setup-with-lava-lamps-and-joysticks-tu8UHcHumDA?utm_source=unsplash&utm_medium=referral&utm_content=creditCopyText)
  by [Masantocreative](https://unsplash.com/@masantocreative/illustrations?utm_source=unsplash&utm_medium=referral&utm_content=creditCopyText)
  on [Unsplash](https://unsplash.com/illustrations?utm_source=unsplash&utm_medium=referral&utm_content=creditCopyText),
  used under the Unsplash License.
