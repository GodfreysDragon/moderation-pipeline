# Project Summary: Moderation Pipeline

_Last updated: 2026-10-08_

## What it is

An AI content-moderation pipeline with a human review workflow. Members upload images and/or captions. A chain of
queued jobs then:
- sanitises and fingerprints the media;
- checks it against a blocklist of known-bad images;
- classifies it with Claude vision;
- applies a versioned policy.

Uncertain items go to a review queue. Moderators claim tasks, decide, escalate and handle appeals. Admins tune the
policy with a backtest. A staff dashboard reports automation rate, audit-measured precision and pipeline latency.

It covers the portfolio list's **"AI content moderation pipeline"** idea. It is deliberately scoped so it doesn't
overlap `ai-support-triage`:

| | ai-support-triage | moderation-pipeline |
|---|---|---|
| Input | Text tickets | Images + captions (Claude vision) |
| AI pattern | One queued job + a streamed reply | Multi-stage job chain, AI on its own rate-limited queue |
| Non-AI work | None | Media sanitising, perceptual hashing, blocklist matching |
| Humans | One agent reads results | Review queue with claim leases, escalation, appeals, QA audits |
| Operations | Simple dashboard | Versioned policy with backtest, precision and latency metrics |

**Stack:** Laravel 13 · PHP 8.4 · Inertia v3 · Vue 3 + TypeScript · Tailwind 4 · shadcn-vue · Pest 5 · GD ·
Anthropic PHP SDK (`claude-opus-5-5`, structured outputs, server-side refusal fallback)

## What was built

### Pipeline (`app/Jobs`)
1. **`PrepareMedia`**:
   - checks the real image type from the bytes;
   - rejects decompression bombs (over 40 MP);
   - applies EXIF orientation, then re-encodes, which drops all metadata, and records whether GPS data was present;
   - caps the long edge at 1568 px, makes a 320 px thumbnail, and computes SHA-256 plus a 64-bit dHash;
   - deletes the original upload;
   - unreadable files are rejected as `unreadable_media`.
2. **`MatchKnownContent`**: an exact SHA-256 or near-duplicate dHash match (Hamming distance ≤ the policy's limit)
   against `blocked_hashes`. On a match the item is rejected and the AI stage is skipped.
3. **`ClassifyContent`**:
   - runs on the `moderation-ai` queue behind `RateLimited` job middleware, with `retryUntil` 30 min,
     `maxExceptions` 3 and backoff;
   - transient SDK errors (429, 5xx, connection) are retried;
   - refusals, 4xx errors and malformed output are recorded and the item continues to policy, which sends it to a
     person;
   - when retries run out, `failed()` routes the item to review.
4. **`ApplyPolicy`**: a pure `PolicyEngine` turns the scores into approve / review / reject using the current
   `PolicyVersion`, and computes a review priority from score × category severity. A random sample of automated
   decisions (5% by default) opens a QA audit task.
- Every stage attempt writes a `pipeline_steps` row: status, attempt, duration and detail. This drives the
  per-submission timeline and the latency table.

### AI (`app/Ai`)
- **`ClaudeContentClassifier`**:
  - sends a base64 image block plus the caption in `<caption>` tags;
  - uses a strict JSON schema with a `{score, evidence}` object per category, plus a summary;
  - effort `low`, with `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`);
  - the prompt is hardened against instructions in the caption or in text inside the image.
- **`FakeContentClassifier`**: keyword rules on the caption and filename, pinned scores (`[spam:0.9]`) and
  `[refuse]`, so the app and its tests run offline.
- **Seven categories:** sexual, violence, hate, harassment, self-harm, illegal goods, spam. Self-harm is never
  auto-rejected: a person always looks.

### Review workflow (`app/Moderation/ModerationService.php`)
- Four task kinds:
  - **Triage:** uncertain scores, or the AI stage failed.
  - **Escalation:** admins only.
  - **Appeal:** one per rejection, within 14 days. The original decider is excluded.
  - **QA audit:** a person re-checks a sampled automated decision.
- **Claims:**
  - atomic conditional `UPDATE` with a 10-minute lease, so two moderators racing for a task can't both win;
  - "Review next" returns the task you already hold before claiming a new one;
  - deciding requires a live claim.
- Rejecting can add the image to the blocklist. Approving, for example on an appeal, removes it again.
- Audits only change the decision when the person disagrees. Agreement is recorded to measure precision.

### Policy and metrics
- `PolicyVersion` is append-only. Each submission stores the version that decided it.
- **`PolicyBacktest`** replays recent classified items under the current and the proposed thresholds and reports:
  - the outcome counts under each;
  - which decisions would change (transitions);
  - against human-decided items, how much harm each would auto-approve and how much fine content it would
    auto-remove.
- **`ModerationMetrics`**:
  - automation rate and blocklist hits;
  - queue depth, oldest waiting item and live claims;
  - p50 decision times (automated vs human) and handling time per task;
  - audit precision for auto-approve and auto-reject, and the appeal overturn rate;
  - daily volume, rejections by category, and stage latency at p50/p95.

### Frontend (Vue 3 + Inertia)
- **Submit:**
  - drag-and-drop upload with a preview and progress bar;
  - six sample buttons, each exercising a different path (auto-approve, auto-reject as spam, human review, hash
    match, text-only harassment, self-harm).
- **Submissions** (members see their own, staff see all):
  - a status-filtered grid that polls while anything is processing;
  - a detail page with the decision, a pipeline timeline and the appeal form;
  - the staff view adds AI scores with threshold markers, review history and fingerprints.
- **Review queue:** the most urgent tasks first, filters by kind, lock indicators, and a "Review next" button.
- **Review workspace:**
  - the task context: why it's here, an escalation note, or the appeal text;
  - a claim countdown;
  - approve, reject with a policy reason, or escalate with a required note;
  - a "block re-uploads" option and an "open the next task" option.
- **Policy:**
  - per-category review/reject thresholds and an auto-reject toggle;
  - audit sample rate and near-duplicate distance;
  - backtest results and version history;
  - read-only for moderators.
- **Dashboard (staff):**
  - 7/14/30-day ranges and KPI tiles;
  - stacked daily columns (automated, human-reviewed, open), with a tooltip, keyboard focus and a table view;
  - rejections by category (automated vs human);
  - a stage latency table and time-to-decision figures.
  - The chart colours were validated with the dataviz palette checker in light and dark modes.
- **Sidebar:** role-aware navigation, with an open-queue badge for staff.

### Data and security
- Images live on a private disk and are served only to the uploader and to staff, with `nosniff` and private caching.
- Uploads are rate limited (20/min per user). The original upload is deleted after re-encoding.
- Members never receive AI scores, hashes or review internals: the presenter strips them.
- `DatabaseSeeder` builds about two weeks of believable history, around 370 submissions, through the real
  `PolicyEngine` and `MediaSanitizer`. It includes two policy versions, audits, appeals, a blocklisted image, a
  recent backlog and an open escalation. It also writes the sample images to `public/samples`.

## Progress report: verification status

| Area | Status |
|---|---|
| Pest suite | ✅ 100 tests, 498 assertions, all passing (unit, pipeline, review workflow, policy, page access) |
| GPS EXIF actually removed (generated JPEG with a real GPS IFD) | ✅ Tested |
| Exact and resized near-duplicate blocklist matches; no AI call on a match | ✅ Tested |
| Refusal or exhausted retries go to human review, never auto-approve | ✅ Tested |
| Claim races, lease expiry, appeal exclusion, escalation permissions | ✅ Tested |
| Backtest counts and error metrics | ✅ Tested |
| Claude request shape (image block, schema, effort, fallbacks, beta header) and refusal/JSON handling | ✅ Tested through the real SDK with a stubbed HTTP transport |
| Vue type check (`vue-tsc`), lint and format (`vp check`), production build | ✅ Clean |
| Pint | ✅ Clean (after auto-fix) |
| Demo seed | ✅ Runs in about 30 s: 374 submissions, 78% automated, 14 open tasks including an escalation |
| PHPStan (starter-kit config) | ❌ 51 errors: type strictness (GD `int\|false` colours, SDK content-block unions, collection generics, `list<>` returns). Not runtime failures, but should be fixed |
| Browser click-through and screenshots | ⚠️ Not done yet. The pages render in Inertia tests, but nobody has used the UI in a real browser |
| Live Claude call | ⚠️ Not run: no `ANTHROPIC_API_KEY` on this machine |
| Queue worker end to end (`composer run dev`) | ⚠️ Not run. The dev script was just fixed to listen on `moderation-ai,moderation,default`; before that, the pipeline jobs would never have been picked up |
| Git | ✅ Repo on `main` with a backend commit; the frontend, seeder and docs are committed with this summary |

## Notes from the build
- **Bug caught by a test:** a near-duplicate whose dHash happened to equal the blocked one (distance 0) was labelled
  an "exact" match. "Exact" now means the SHA-256 matched.
- **Test fixtures:** `tests/Support/Images.php` draws images with GD and builds a JPEG with a hand-written EXIF/GPS
  block, so the suite needs no binary fixtures. The first version drew differently at each size, which made the
  "same picture resized" test meaningless. Scenes are now drawn once at 640×480 and resampled.
- **Inertia page tests need a current Vite manifest.** After adding pages, run `npm run build` (or keep `npm run dev`
  running), or those tests return 500 with "Unable to locate file in Vite manifest".
- **Windows uploads:** `AppServiceProvider` passes `TEMP`/`TMP` through `artisan serve`, the same fix as in
  rag-doc-qa.
- **Ports:** 8000, 5173 and 8020 are often taken by other projects here, and Windows allows silent double-binds.
  Check the owner of a port before trusting what loads.
- **Folder housekeeping (same session):**
  - git repos were initialised and first commits made in every project in `software_development/`;
  - root `.gitignore` files were added for `gather/` and `lamp-modernization-case-study/`;
  - nothing has been pushed; no project has a remote;
  - `observability-dashboard/` was being created by another session at the time and was left alone.

## To-dos (loose ends in what exists)

- [ ] **Browser pass.** Run `composer run dev` with `MODERATION_CLASSIFIER=fake` and click through every sample on
      the Submit page, the review workspace (claim, reject with a reason, escalate, "next"), an appeal as
      member@example.com, the policy backtest and the dashboard. Check light, dark and 390 px widths. Commit the
      script as `tests/e2e/smoke.mjs` (playwright-core + installed Edge, as in realtime-kanban).
- [ ] **Fix the 51 PHPStan errors** without baselines:
  - narrow GD colour allocations;
  - check the SDK block type with `instanceof BetaTextBlock`;
  - pass a validated media-type enum;
  - type the collections and return `->values()->all()` lists;
  - map `TaskKind` to values before `array_intersect`.
- [ ] **Live Claude run.** Add `ANTHROPIC_API_KEY`, submit each sample, and check that the scores are calibrated
      (the "parts for sale" poster should land in review, not reject). Note real latency and token usage.
- [ ] **Real worker run.** Confirm that the `moderation-ai` queue and the `RateLimited` middleware behave under
      `queue:listen` / `queue:work`, including a forced 429 (point the base URL at a stub) to watch backoff and the
      `failed()` path.
- [ ] **Update the sample image for "known meme".** It is blocked by exact hash from the seeder. Add a resized copy
      as a second sample to demonstrate the near-duplicate path in the UI too.
- [ ] **README screenshots** of the review workspace, policy backtest and dashboard.
- [ ] **CI:** the starter's `.github` workflow has not been reviewed for this project. Make it run Pest, Pint,
      PHPStan, `vue-tsc` and `vp check`.

## Next steps (new features)

1. **Real-time queue:** broadcast task claims and resolutions with Reverb, so two moderators see locks change live
   instead of on refresh.
2. **Scale the blocklist:** a BK-tree or multi-index hashing instead of the linear dHash scan, plus PDQ or PhotoDNA
   style hashes for robustness to crops.
3. **Ingestion API:** a Sanctum-token `POST /api/v1/submissions` with signed webhooks when a decision is made, so
   other apps can use the pipeline.
4. **Batch re-classification:** re-run stored items through a new model or prompt with the Message Batches API (50%
   cost), then compare with the backtest.
5. **Reviewer quality:** inter-rater agreement (two reviewers on a sample), per-reviewer audit stats, and wellbeing
   features for graphic content (blur by default, exposure limits).
6. **Uploader notifications:** email or in-app notice of decisions and appeal outcomes.
7. **Text-in-image OCR stage** for cheap keyword matching before the model.
8. **Retention:** prune rejected media after N days, keeping the hashes, and an audit log export.

## Key commands

```bash
composer run dev                              # server + pipeline worker + Vite
php artisan migrate:fresh --seed              # rebuild the demo data
php artisan test                              # Pest
npm run build && npm run types:check && npm run check
vendor/bin/pint && vendor/bin/phpstan analyse
```
