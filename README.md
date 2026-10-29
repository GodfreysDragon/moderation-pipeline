# Moderation Pipeline

An AI content-moderation pipeline built with Laravel 13 and Vue 3. Uploaded images and captions go through four
queued stages. Confident results are decided automatically. Anything uncertain lands in a human review queue with
claim locks, escalation, appeals and QA audits.

It is meant to show **queues, jobs and AI in a background-processing context**: no request ever waits on the model.
It also shows the operational side of moderation that demos usually skip:

- policy thresholds you can version and backtest;
- re-uploads of known-bad images caught without an AI call;
- measured precision of the automated decisions.

![Review workspace: a live Claude classification of the "parts for sale" sample, with scores, evidence and the decision panel](docs/screenshots/review-workspace.png)

## How it works

```
Upload ─► PrepareMedia ─► MatchKnownContent ─► ClassifyContent ─► ApplyPolicy
          re-encode,       SHA-256 + dHash      Claude vision,      thresholds from the
          strip EXIF/GPS,  vs. blocklist        structured output   current policy version
          thumbnail,       (no AI call on       (own queue, rate    │
          fingerprint      a match)             limited)            ├─ approve  ─┐
                                                                    ├─ reject   ─┼─► random QA audit sample
                                                                    └─ review ──► human review queue
```

- **Queues:** `moderation` (cheap stages) and `moderation-ai` (the model call, rate limited across workers). With a
  worker on each, as `composer run dev` runs them, a slow or throttled model never holds up media processing.
- **Fail safe:** if classification fails, the model refuses, or any stage runs out of retries, the item goes to a
  person. It is never approved.
- **Blocklist lookup:** each 64-bit dHash is also stored as eight indexed 8-bit bands. Two hashes within 7 bits of
  each other must share at least one band exactly, so a near-duplicate check only compares the rows that do. With
  100,000 blocked images that is about 3% of rows: a median 79 ms instead of 2.2 s for a full scan (SQLite, this
  machine). Above a distance of 7 the guarantee no longer holds and it scans everything.
- **Text in images:** the same classification call transcribes any words in the image (about 10–20 extra output
  tokens per poster, nothing for a photo without text). Reviewers see it next to the image, staff can search it from
  the submissions list, automated decisions carry it in the decision log, and it is deleted with the image.
- **Review tasks:**

    | Task          | When it is created                                                                                        |
    | ------------- | --------------------------------------------------------------------------------------------------------- |
    | Triage        | The scores fell between the review and reject thresholds.                                                 |
    | Escalation    | A moderator handed the item up; only admins can take it.                                                  |
    | Appeal        | The uploader disputed a rejection. The original decider can't take it.                                    |
    | QA audit      | A random sample of automated decisions. Disagreeing overturns them.                                       |
    | Second review | A random 10% of triage decisions, re-decided blind by another moderator. A disagreement goes to an admin. |

    Claims are atomic, 10-minute leases.

- **Reviewer wellbeing:** images with a sexual, violence or self-harm score of 0.3 or more (or no scores at all) are
  blurred in the queue and the review workspace until the reviewer chooses to look. After 40 graphic decisions in 24
  hours, "Review next" hands that person only non-graphic work. The Reviewers page shows per-person decisions,
  handling time, appeal overturns, second-review agreement and today's graphic exposure, plus the team's agreement as
  Cohen's kappa.

- **Policy versions:** thresholds are append-only. Every decision records the version it used. The Policy page
  backtests proposed thresholds against recent items and against human decisions before you save.
- **Model evaluation:** before switching models or effort levels, an admin re-scores recent human-decided items
  with the candidate through the Message Batches API (half price, usually done in a minute or two). The report
  judges the recorded scores and the candidate's under the same thresholds against what people decided: automated
  decisions people agreed with, harm approved automatically, fine content removed, cost per item and score drift
  per category. Batch requests are built by the same code as live ones, prompt caching included.

## Stack

Laravel 13 · PHP 8.4 · Inertia v3 · Vue 3 + TypeScript · Tailwind 4 · shadcn-vue · Pest · GD · Claude
(`claude-opus-5-5`) via the official Anthropic PHP SDK.

## Quick start

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# Without an API key, use the offline classifier:
#   MODERATION_CLASSIFIER=fake   (in .env)
php artisan migrate --seed       # two weeks of demo history + sample images
composer run dev                 # web server, a worker per pipeline queue, scheduler, Reverb, Vite
```

Demo accounts (password `password`):

| Email                 | Role                                              |
| --------------------- | ------------------------------------------------- |
| admin@example.com     | Admin: can edit the policy and decide escalations |
| moderator@example.com | Moderator (also priya@, tomas@)                   |
| member@example.com    | Uploader                                          |

Open **Submit content** and use the sample buttons. Each one follows a different path through the pipeline:
auto-approve, auto-reject, human review, an exact and a near-duplicate blocklist match, a text-only insult that needs a
judgement call, and a self-harm post that always reaches a person.

### Configuration

```dotenv
MODERATION_CLASSIFIER=claude          # or "fake" (keyword rules, no network)
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-5-5
MODERATION_AI_CALLS_PER_MINUTE=30
MODERATION_AUDIT_SAMPLE_RATE=0.05     # default for the first policy version
MODERATION_SECOND_REVIEW_RATE=0.1     # share of triage decisions re-checked blind
MODERATION_GRAPHIC_DAILY_LIMIT=40     # graphic decisions per reviewer per 24 hours
MODERATION_RETENTION_DAYS=30          # days before rejected images are deleted
MODERATION_EVALUATION_POLL_SECONDS=60 # how often to check a running batch
MODERATION_WEBHOOKS_ALLOW_PRIVATE=false # true only to test webhooks against a local receiver
MODERATION_BACKLOG_ALERT_MINUTES=60   # tell staff when a task has waited this long
```

Rejected images are deleted by `moderation:prune-media`, scheduled daily at 03:30. `composer run dev` runs the
scheduler; in production add the usual cron entry (`* * * * * php artisan schedule:run`). It never deletes before the 14-day appeal window closes or
while a task is open, and it keeps the hashes so the blocklist still matches re-uploads. Admins can download every
decision in a period, automated and human, from the dashboard as a CSV decision log.

**Notifications** appear under the bell in the header, and by email where the person has it switched on under
**Settings → Notifications**:

| Who      | When                                                                                                                         | Email by default |
| -------- | ---------------------------------------------------------------------------------------------------------------------------- | ---------------- |
| Uploader | A post is removed, or a moderator, an appeal or an audit decides it (instant automatic approvals are not announced)          | Yes              |
| Admins   | A moderator escalates an item                                                                                                | Yes              |
| Staff    | A task has waited longer than `MODERATION_BACKLOG_ALERT_MINUTES` (checked every 15 minutes, at most one alert per two hours) | No               |

Emails are queued, so they need a worker and a configured mailer (`MAIL_MAILER=log` writes them to the log).

**Live updates** run over [Reverb](https://reverb.laravel.com) (`php artisan reverb:start`, included in
`composer run dev`). A submission's page follows each pipeline stage as it happens, submission lists and the review
queue refresh when items change, the sidebar queue count stays current, and new notifications appear under the bell.
Events carry only an id and a status on private channels (the uploader's own channel, one per submission, and a
staff channel); the page then reloads its own props, so nobody learns more than they could already see. Without
Reverb (`BROADCAST_CONNECTION=log`, or the server not running) pages fall back to polling while something is in the
pipeline. `.env.example` ships local-only Reverb keys; run `php artisan reverb:install` for real ones.

The fake classifier reads the caption and filename only. It also accepts pinned scores such as `[violence:0.6]`,
and `[refuse]` simulates a model refusal, which is handy for demos and tests.

### API and webhooks

Other systems can submit content and hear back about decisions. Create a token under **Settings → API & webhooks**
(abilities `submissions:write` and `submissions:read`), then:

```bash
curl -X POST http://localhost:8000/api/submissions \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F caption="Evening at the harbour" -F image=@photo.jpg -F reference=your-own-id-123
```

| Endpoint                    | Returns                                                                                                                      |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `POST /api/submissions`     | 202 with the submission while the pipeline runs. A `reference` already used returns the original (200), so retries are safe. |
| `GET /api/submissions/{id}` | Status, and once decided the outcome, reason and appeal deadline. Never scores or reviewer notes.                            |
| `GET /api/submissions`      | The token owner's submissions, newest first, 50 a page, optional `?status=`.                                                 |

Add up to three webhook endpoints on the same page. Whenever one of your submissions is approved or rejected,
including after review, appeal or audit, each endpoint gets a `submission.decided` POST with the same submission
JSON. The `Moderation-Signature` header is `t=<unix time>,v1=<hex>`, where hex is the HMAC-SHA256 of
`"<t>.<raw body>"` with the endpoint's signing secret (`App\Webhooks\Signature::verify` shows the check). Non-2xx
answers are retried with backoff for about three hours, and the page lists recent deliveries. Endpoint URLs must
resolve to public addresses: the server checks this when the URL is saved and again before each delivery, and
connects to the address it checked, so a webhook can't be used to reach internal services.

Measured against the live API (`claude-opus-5-5`, effort `low`), one classification takes 3 to 5 seconds and uses
200 to 300 output tokens. Its input is a fixed 2,093-token prefix (the instructions and the output schema), about
50 tokens of caption and about 640 more for an image. The prefix is marked for prompt caching, so after the first
call it is read from the cache at a twentieth of the input price:

| Per item      | Before caching | With a warm cache |
| ------------- | -------------- | ----------------- |
| Text only     | about $0.014   | about $0.006      |
| With an image | about $0.016   | about $0.007      |

Every classification records its token counts, and the dashboard shows the estimated spend, the share of input
served from the cache and what caching saved.

## Screenshots

| Policy backtest                                                                                         | Staff dashboard                                                      |
| ------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| ![Policy editor with a backtest of a lower spam reject threshold](docs/screenshots/policy-backtest.png) | ![Staff dashboard in dark mode](docs/screenshots/dashboard-dark.png) |

## Tests

```bash
php artisan test        # 108 tests: pipeline, review workflow, policy, access, SDK request shape
npm run types:check
npm run check
```

`tests/e2e/smoke.mjs` drives the real app in an installed Edge or Chrome. It runs every sample through the queued
pipeline, appeals, reviews and escalates, backtests the policy, and checks the staff pages in light, dark and phone
widths. Run it against a freshly seeded app with workers running (`composer run dev`):

```bash
E2E_URL=http://127.0.0.1:8000 npm run test:e2e   # screenshots go to storage/e2e
```

## Project layout

| Path                  | What's there                                                                                                   |
| --------------------- | -------------------------------------------------------------------------------------------------------------- |
| `app/Jobs/`           | The four pipeline stages and the step recorder                                                                 |
| `app/Moderation/`     | `PolicyEngine` (pure), `ModerationService` (decisions, claims, appeals), `PolicyBacktest`, `ModerationMetrics` |
| `app/Ai/`             | `ContentClassifier` contract, Claude and fake implementations, structured-output schema                        |
| `app/Support/`        | `MediaSanitizer` (re-encode, EXIF/GPS strip, thumbnails), `PerceptualHash` (dHash)                             |
| `resources/js/pages/` | Submissions, review queue and workspace, policy editor, staff dashboard                                        |
