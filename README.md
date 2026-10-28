# Moderation Pipeline

An AI content-moderation pipeline built with Laravel 13 and Vue 3. Uploaded images and captions go through four
queued stages. Confident results are decided automatically. Anything uncertain lands in a human review queue with
claim locks, escalation, appeals and QA audits.

It is meant to show **queues, jobs and AI in a background-processing context**: no request ever waits on the model.
It also shows the operational side of moderation that demos usually skip:

- policy thresholds you can version and backtest;
- re-uploads of known-bad images caught without an AI call;
- measured precision of the automated decisions.

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
- **Review tasks:**

    | Task       | When it is created                                                     |
    | ---------- | ---------------------------------------------------------------------- |
    | Triage     | The scores fell between the review and reject thresholds.              |
    | Escalation | A moderator handed the item up; only admins can take it.               |
    | Appeal     | The uploader disputed a rejection. The original decider can't take it. |
    | QA audit   | A random sample of automated decisions. Disagreeing overturns them.    |

    Claims are atomic, 10-minute leases.

- **Policy versions:** thresholds are append-only. Every decision records the version it used. The Policy page
  backtests proposed thresholds against recent items and against human decisions before you save.

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
composer run dev                 # web server + one worker per pipeline queue + Vite
```

Demo accounts (password `password`):

| Email                 | Role                                              |
| --------------------- | ------------------------------------------------- |
| admin@example.com     | Admin: can edit the policy and decide escalations |
| moderator@example.com | Moderator (also priya@, tomas@)                   |
| member@example.com    | Uploader                                          |

Open **Submit content** and use the sample buttons. Each one follows a different path through the pipeline:
auto-approve, auto-reject, human review, hash-blocklist match, and a self-harm post that always reaches a person.

### Configuration

```dotenv
MODERATION_CLASSIFIER=claude          # or "fake" (keyword rules, no network)
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-5-5
MODERATION_AI_CALLS_PER_MINUTE=30
MODERATION_AUDIT_SAMPLE_RATE=0.05     # default for the first policy version
```

The fake classifier reads the caption and filename only. It also accepts pinned scores such as `[violence:0.6]`,
and `[refuse]` simulates a model refusal, which is handy for demos and tests.

## Tests

```bash
php artisan test        # 100 tests: pipeline, review workflow, policy, access, SDK request shape
npm run types:check
npm run check
```

## Project layout

| Path                  | What's there                                                                                                   |
| --------------------- | -------------------------------------------------------------------------------------------------------------- |
| `app/Jobs/`           | The four pipeline stages and the step recorder                                                                 |
| `app/Moderation/`     | `PolicyEngine` (pure), `ModerationService` (decisions, claims, appeals), `PolicyBacktest`, `ModerationMetrics` |
| `app/Ai/`             | `ContentClassifier` contract, Claude and fake implementations, structured-output schema                        |
| `app/Support/`        | `MediaSanitizer` (re-encode, EXIF/GPS strip, thumbnails), `PerceptualHash` (dHash)                             |
| `resources/js/pages/` | Submissions, review queue and workspace, policy editor, staff dashboard                                        |
