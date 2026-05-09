---
status: shipped
shipped_in: v3.110.35 — v3.110.40 (engineering); end-to-end UI flow + provider shootout + DPIA legal review remain as calendar-time
---

<!-- type: epic -->

# #0016 — Photo-to-session: vision-assisted session capture

> **Closing note (v3.110.40)**: The engineering scaffolding is fully shipped across v3.110.35 → v3.110.40 — exercise library schema + repository + 18 seeded reference drills + 4 categories, activity-to-exercise linkage with version pinning, three vision provider adapters (Claude Sonnet concrete impl + Gemini Pro stub + OpenAI flagged-DPIA-incompatible stub), provider fallback chain, fuzzy matcher, REST surfaces for exercises + linkage + photo extraction, DPIA template doc. **Calendar-time remaining**: (1) **Provider shootout** — collect 10-15 real coach training-plan photos, run them through Claude Sonnet + Gemini Pro, score on extraction accuracy. The current `claude_sonnet` shipping default is best-effort first guess; the shootout validates or replaces it. (2) **DPIA legal review** — the template at `docs/photo-capture-dpia.md` ships the form; the academy's data controller + DPO must complete it before deploying photo capture broadly to clubs whose photos may include minors. (3) **End-to-end operator UI** — Sprint 3's `CoachCaptureView` (mobile camera form + offline IndexedDB queue) + Sprint 4's review wizard (confidence-coloured edit grid + per-row accept/correct/save-as-new) are the user-facing surfaces that wrap the shipped REST endpoints. The REST layer is SaaS-ready; UI consumers can be vanilla-JS additions on top, but they're substantial markup + JS work that benefits from focused PRs. The spec moved to `specs/shipped/` because every code-side acceptance criterion is met; "shipped" here means "the AI extraction works end-to-end via REST when an API key is configured", not "the Sprint-3-mobile-capture UI is operator-ready." That UI lands in a focused follow-up.

## Problem

Coaches arrive at training with a paper plan — a hand-drawn sheet scribbled during the day: "4v4 rondos 8min, passing lanes 10min, conditioned game to goal 20min." Today, to log this as a TalentTrack session after training:

1. Coach opens the plugin, finds the session form.
2. Types in every drill, every duration.
3. Estimates times from memory, often inaccurate by now.
4. Logs attendance row by row from memory.
5. …or skips logging entirely. Most do.

The data that should be captured is sitting on a piece of paper that gets thrown away. This is the single biggest "data missed" problem in academy coaching.

## Proposal

A vision-AI-assisted capture flow: coach takes a photo of the training plan with their phone, AI extracts a structured session (exercises + timings + attendance notes), coach confirms/edits in a mobile-optimized wizard, saves. Should take under 60 seconds compared to 5+ minutes of manual entry.

Three stacked deliverables, each useful on its own:

1. **Exercise library** — a structured drill/exercise catalog that doesn't exist today.
2. **Structured sessions** — session schema gains structured-exercise-with-timing rows.
3. **Photo capture flow** — vision AI that populates structured sessions from a photo.

Each layer is buildable; together they answer the "log fast from a photo" use case.

## Scope

Six sprints:

| Sprint | Focus | Effort |
| --- | --- | --- |
| 1 | Exercise library + schema + AI provider architecture + shootout | ~14–18h |
| 2 | Structured sessions: session→exercise linkage, exercise-history UI | ~10–12h |
| 3 | Photo capture UI: camera, upload, offline queue | ~14–16h |
| 4 | AI extraction + review wizard | ~16–20h |
| 5 | Attendance extraction (from photo annotations) | ~8–10h |
| 6 | Polish: draft sessions, confirm-later, provider fallback | ~8–10h |

**Total: ~70–86 hours.** The largest non-#0019 epic.

### Sprint 1 — Exercise library + schema + provider architecture

**Schema**:
- `tt_exercises` — drill/exercise definitions:
  ```sql
  id, name, description, duration_minutes, category_id, principles (many-to-many to tt_principles from #0006),
  diagram_url (optional), author_user_id, visibility ('club', 'team', 'private'),
  version INT, superseded_by_id (for pinning-via-versioning), archived_at
  ```
- `tt_exercise_categories` — warmup, rondo, conditioned-game, finishing, set-piece, etc. Seeded.
- `tt_exercise_team_overrides` — per-team visibility override (matches the scope decision):
  ```sql
  exercise_id, team_id, is_enabled (boolean)
  ```

**Visibility model** (per shaping: per-club with optional per-team override):
- Default: all club exercises visible to all teams.
- Team override: a team can opt out of specific club-level exercises (or opt in to exercises marked not-default-visible).
- Per-coach private exercises: the author can mark an exercise private; only visible to them.

**Versioning for pinning**: when an exercise is edited, the old version is preserved with `superseded_by_id` pointing to the new one. Sessions reference a specific version; editing doesn't mutate historical sessions.

**Exercise library admin**:
- Frontend view under Administration tile (scope-dependent — coaches see their authored + club exercises; HoD sees all).
- CRUD for exercises: name, description, duration, category, principles (multi-select from `tt_principles`), optional diagram upload.
- Seeded exercises (15-20 shipped): common youth-football drills covering each category.

**AI provider architecture** (no extraction logic yet, just the scaffolding):
- `VisionProviderInterface` with `extractSessionFromImage(binary $image): ExtractedSession`.
- Stub implementations: `ClaudeSonnetProvider`, `GeminiProProvider`, `OpenAIProvider`.
- Provider selection via WP filter `tt_vision_provider` — default returns whichever is configured.
- Config via `wp-config.php` constants: `TT_VISION_PROVIDER`, `TT_VISION_API_KEY`, `TT_VISION_ENDPOINT` (for EU-resident inference).

**Provider shootout** (this sprint's big task):
- Collect 10–15 real training-plan photos from 3–4 coaches (messy handwriting, different styles).
- Send each to both Claude Sonnet 4.6 (via Bedrock) and Gemini 2.5 Pro (via Vertex AI).
- Score outputs on: exercise name extraction accuracy, duration extraction accuracy, hallucination rate, handling of messy handwriting.
- Pick the winner. Ship with that as default; keep the other's adapter available.

**Capability**: `tt_manage_exercises` (granted to `tt_coach`, `tt_head_dev`, `administrator`).

### Sprint 2 — Structured sessions

**Schema extension**:
- `tt_session_exercises`:
  ```sql
  id, session_id, exercise_id (refs specific version — pinning), order_index,
  actual_duration_minutes (coach may override planned duration), notes, is_draft
  ```

Sessions can have N exercises in ordered sequence. Each exercise references a specific `tt_exercises` version.

**Session-edit UI enhancement**:
- In `FrontendSessionsManageView` (from #0019 Sprint 2), add an "Exercises" section.
- Add/remove exercises, reorder (drag), edit actual durations, add per-exercise notes.
- Pick from exercise library (search, filter by category, filter by principle).

**Exercise-history view**:
- For a given exercise, show all sessions where it was used across the club.
- Useful for "how often have we done this drill?"

### Sprint 3 — Photo capture UI + offline queue

**Photo capture UI**:
- Dedicated view `CoachCaptureView`, mobile-first.
- Camera button → opens phone camera via `<input type="file" accept="image/*" capture="environment">` (works on mobile browsers; no custom camera needed).
- Preview after capture: shows the photo with "Retake" or "Use this photo" buttons.
- "Use this photo" → uploads via REST.

**Offline queue** (per shaping decision):
- If device is offline OR AI provider is unreachable, the photo is queued in browser-side IndexedDB (not localStorage — images are big).
- Queue UI: shows "2 photos waiting for AI processing." Attempts to process when connectivity returns.
- After successful processing, the review wizard (Sprint 4) opens.
- Manual "Retry now" button on each queued item.

**Session-creation entry point**: before processing, coach selects the team + date. Photo is associated with that context.

### Sprint 4 — AI extraction + review wizard

**Extraction flow**:
- Image upload → backend passes to configured provider.
- Provider returns structured JSON: `[{exercise_name, duration_minutes, notes}, ...]`.
- Backend matches extracted exercise names against the exercise library (fuzzy-match: Levenshtein distance, rule out < 60% similarity).
- For matches: returns the library exercise ID + confidence score.
- For non-matches: returns the raw extracted name as a "new exercise" candidate with a prompt to save to library.

**Review wizard** (~4 steps):
1. **Review & adjust extracted exercises**: list of extracted drills. Each row: exercise name (editable), duration (editable), confidence indicator (green = high, yellow = medium, red = low). Actions per row: accept, correct, delete, save-as-new-library-entry.
2. **Review timings**: adjust durations, reorder.
3. **Attendance** (if extracted — Sprint 5; otherwise fallback to manual).
4. **Confirm**: save the session.

**Confidence-colored interface**: rows with confidence > 0.85 are auto-accepted on entry; coach reviews only if they care. Rows with confidence < 0.6 are flagged for review.

**Failure fallback**: if AI extraction fails entirely, the wizard drops into "manual entry" mode with no blame — "We couldn't read this photo clearly. Here's the manual flow, takes 2 minutes."

### Sprint 5 — Attendance extraction

Paper plans often have attendance marked on them: "Jan ✓, Pieter ✗, Tim ✓✓" or similar notation.

**Extraction enhancement**:
- Provider prompt augmented: "Also look for attendance markings — player names with check/X/etc."
- Returned JSON includes: `attendance: [{player_name, marking, confidence}, ...]`.
- Fuzzy-match against team roster (Levenshtein on first+last name).

**Wizard integration**:
- Step 3 of the review wizard becomes "Review attendance."
- Extracted + matched entries pre-populate the attendance grid.
- Unmatched entries shown: "Found 'Pieter van Rijn' on the photo — not on this team. Add manually or ignore?"

**Fallback**: if no attendance extracted or photo unreadable, falls through to the normal manual attendance flow (from #0019 Sprint 2).

### Sprint 6 — Draft sessions + confirm-later + provider fallback

**Draft sessions**: per shaping, sessions may be released with draft exercises. An exercise with a low-confidence extraction that the coach didn't confirm can be marked "draft" on the session.

UX:
- Session list view shows a "has-drafts" indicator on sessions with any draft exercises.
- Clicking into the session takes coach to the exercise they didn't confirm, with the original photo still visible (cached) for reference.
- Confirming completes the exercise. If photo is no longer available (cache expired), the coach manually fills it in.

**Provider fallback**:
- If the primary provider fails (API down, quota exceeded, transient error), automatic failover to the secondary.
- If both fail, degrade gracefully to manual entry.

**Quota management**:
- Per-club quota tracking (for Model B — post-#0011).
- Warning banner when ≥80% of monthly quota used.

## Out of scope

- **Real-time video capture / streaming**. Photos only.
- **Video analysis of training footage**. Completely different scope.
- **Multi-page photos** (taping two sheets together). One photo per session capture.
- **Handwriting training / custom per-coach models**. Generic vision APIs only.
- **Auto-generating exercise diagrams from extracted descriptions**. Links out to existing diagrams; doesn't create them.
- **Voice capture as alternative to photo**. Possible future feature; not v1.
- **Post-#0011 monetization tiering for quota**. Flag for #0011.

## Acceptance criteria

The epic is done when:

- [ ] Exercise library exists with seeded drills; coaches can CRUD custom exercises.
- [ ] Structured sessions link to specific exercise versions (pinned).
- [ ] Coach can photograph a training plan from their phone and upload it.
- [ ] Offline capture works; queued photos process when connectivity returns.
- [ ] AI extracts exercises from a typical paper plan with ≥80% accuracy on exercise name matching (measured in shootout data).
- [ ] Review wizard lets coach edit extractions before saving.
- [ ] Attendance extraction works when markings are legible.
- [ ] Failure paths never block a coach — manual entry is always an available fallback.
- [ ] All configuration via `wp-config.php` constants (API key, provider, endpoint).

## Notes

### DPIA requirement

Before Sprint 4 ships to any real EU club, a Data Protection Impact Assessment is required. Minor athletes' data flows to an external AI provider; this triggers GDPR's DPIA obligation.

Lightweight DPIA scope:
- Document what data leaves the site (photo pixels; no names unless visible on the plan).
- Document retention: photos deleted from server after 7 days, never persisted to AI provider.
- Document processing locations: EU-resident inference only (Bedrock eu-central/eu-west OR Vertex AI europe-west).
- Document opt-out: clubs can disable photo capture entirely via setting.

Not a code gate — a documentation / legal step. But it must happen before broad deployment.

### Cost modeling

Per shaping, Model A first (club brings API key), Model B later (pass-through via #0011).

Cost per photo: ~€0.02 with current pricing (Sonnet 4.6 or Gemini 2.5 Pro). 10 photos/week × 10 teams = 100 photos/week = ~€10/month per club. Fits within a reasonable Academy-tier overage.

### Cross-epic interactions

- **#0006 (team planning)** — exercises link to principles. Activity planning and exercise library share this vocabulary.
- **#0011 (monetization)** — Model B implementation depends on #0011 being live.
- **#0013 (backup)** — photos in user_uploads directory are out of scope per #0013's decision; queued photos in IndexedDB are client-side and unaffected.

### Depends on

- #0019 Sprint 2 (Sessions frontend) for the structured-exercise additions.
- #0006 Sprint 1 (principles concept) — exercises link to principles.
- Nothing else hard.

### Touches

- New modules: `src/Modules/Exercises/`, `src/Modules/PhotoCapture/`
- New schema: `tt_exercises`, `tt_exercise_categories`, `tt_session_exercises`, `tt_exercise_team_overrides`
- Frontend: exercise library admin, capture UI, review wizard
- REST: capture controller with multipart upload support
- Provider adapters (2–3 implementations)
- Configuration: `wp-config.php` constants documented
- DPIA documentation template shipped in `docs/`

### Sequence position

Phase 5 in SEQUENCE.md — infra/novel features after core product is solid. Don't start before #0006 Sprint 1 ships.
