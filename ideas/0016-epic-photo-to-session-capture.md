<!-- type: epic -->

# Photo-to-session — capture a hand-drawn training plan and convert it into a session + exercise library entries

Raw idea:

Take a picture of a paper on which a training is drawn, including attendance, and have it translated into (a) a session, and (b) a visual representation of the exercises added to an exercise library. A wizard walks the coach through confirming and completing elements, either on the spot or later before releasing the session / adding to the library.

## Why this is an epic

Several genuinely new things on top of each other: photo capture + upload flow, an AI vision pipeline for OCR + sketch interpretation, a wizard that turns messy uncertain AI output into clean structured data, a brand-new exercise library (doesn't exist today), rendering sketched exercises into clean digital diagrams, and a workflow for "draft → confirm → release". This deserves its own roadmap slot, not a sprint.

Honest take: this is the single most ambitious idea in the current backlog. It touches every hard part of modern ML-assisted UX — quality of the input is variable (coaches draw differently, lighting is bad, paper gets crumpled), the model output is probabilistic (which means the wizard does real work, not just cosmetic confirmation), and the user's patience is thin because they want to get home after training. The spec below tries to stay realistic.

## What already exists vs what is new

**Exists:**
- `tt_sessions` table: title, date, location, team, coach, notes. Sessions are essentially metadata + a notes blob today.
- `tt_attendance` table: session_id, player_id, status ('present' / custom lookup values), notes.
- Session admin page `src/Modules/Sessions/Admin/SessionsPage.php`.

**Does not exist yet — this epic creates it:**
- Any concept of an exercise. No table, no model, no UI.
- Any concept of a session having *structure* beyond a free-text notes field. Drills/blocks/exercises as first-class items linked to a session.
- Any image/vision pipeline.
- Any wizard that spans multiple screens.

That gap matters. The photo-to-session capture feature is really three features stacked: **build an exercise library**, **restructure sessions to reference exercises**, **then** layer photo capture on top. Doing the capture without the underlying data model first produces something that can only populate `tt_sessions.notes` as free text — which defeats the point.

## Shape of the feature, end-to-end

1. Coach opens the plugin on their phone at the end of training (field-side, paper in hand).
2. Taps "New session from photo."
3. Camera opens, captures the paper. Can capture multiple photos (front and back, or one per drill).
4. Plugin uploads the image(s) to an AI vision endpoint. Spinner with honest copy ("Reading your plan — this usually takes 10–20 seconds"). 
5. AI returns a structured draft: team + date + time if visible, list of probable exercises with names/durations/field setup, attendance list if the paper has one.
6. Coach enters the wizard:
   - **Step 1. Session metadata.** Date, team, location. Pre-filled from photo + defaults, coach confirms or fixes.
   - **Step 2. Attendance.** AI-parsed player list mapped to plugin players (fuzzy match on names). Coach reviews matches, marks present/absent/late. Unmatched names are flagged.
   - **Step 3. Exercises.** Each exercise draft is a card: name, duration, type, a rendered diagram preview. Coach can edit text inline, accept/reject the diagram, or defer it ("fix later").
   - **Step 4. Review & release.** Summary of what will be created. Two buttons: "Save as draft" (session stays unreleased, not visible to players) or "Release" (session becomes live, attendance counts, exercises show on player dashboard).
7. Exercises that the coach marks as "add to library" get copied into the exercise library with the coach's edits applied, tagged to their author and team.

## The five hard parts, each worth understanding before committing

### 1. AI vision quality

Modern vision-capable models (GPT-4V / Claude with vision / Gemini) can read a hand-drawn training plan surprisingly well. They're good at:
- Handwritten text (names, durations, exercise titles) — OCR is mostly solved.
- Identifying "this is a list of player names" vs "this is a diagram."
- Rough sketch interpretation — "four cones in a square with a player in the middle" parses.

They're genuinely unreliable at:
- Handwriting that's bad even for humans. Coaches' handwriting varies wildly.
- Arrows and movement indicators — a curved line with a tick on one end is "pass" or "dribble" depending on context the model doesn't have.
- Field setup precision. The model can say "cones in a grid" but not "a 20x30 meter grid with 4 cones at the corners and 2 mannequins at the midpoints." The exact geometry rarely survives the translation.
- Anything the coach draws in their own shorthand (e.g. numbered zones, personal symbols).

Implication: **the wizard's confirmation step is not cosmetic.** It's the feature's actual UX. Every field comes in with a confidence score and the coach corrects rather than approves. Design the UI around "this is probably wrong, fix it" rather than "here's the answer, nod if ok."

### 2. Exercise library doesn't exist yet

Before photo capture can populate an exercise library, the library has to be modeled. Rough schema:

```
tt_exercises
  id, name, slug, description, duration_minutes, category_id,
  diagram_json TEXT, source ENUM('manual','photo_import'),
  created_by, team_id nullable (NULL = shared across club), 
  created_at, archived_at, archived_by

tt_exercise_categories
  id, name, sort_order  (warmup, possession, finishing, SSG, tactical, etc.)

tt_session_exercises  (new join table — sessions now have structure)
  id, session_id, exercise_id, sort_order, duration_override, 
  notes, created_at
```

That schema and UI is a sprint on its own, photo-independent. Once it exists, every coach can build a library by hand, and photo import becomes "a faster way to populate the same library."

**This is a forking decision:** build the library first as a standalone feature (usable immediately, lower risk), or build it coupled to photo capture (more exciting, riskier, library is empty if the photo feature is slow to land). Strong recommendation: library first, then capture on top.

### 3. Rendering sketched exercises as clean diagrams

This is the genuinely hard ML piece. The raw idea says "visual representation of the exercises," which means turning a hand-drawn sketch into something that looks professional on a screen.

Three levels of ambition, in order of effort:

- **(a) Attach the photo.** Simplest option: no conversion at all. Store the original photo, show it in the exercise library. Coach writes a name + description. Low effort, low wow factor, but shippable in the first version.
- **(b) AI-generated description, coach draws digitally.** AI reads the sketch and produces text + a structured JSON description of the setup. Plugin renders a clean diagram from the JSON using a football-pitch SVG library. Works when the AI gets the JSON right, which it only does for simple setups (cones in a grid, one-v-one, etc).
- **(c) End-to-end sketch-to-diagram.** Full generation. Technically possible, practically fragile. Skip for v1; revisit after v1 proves coaches actually use the feature.

My recommendation: **ship (a) first.** It's a perfectly valid product — "photo-to-session with image attachments" gives the coach everything they actually need to remember what they did. (b) is an optional upgrade for a later sprint. (c) is post-epic aspirational.

### 4. Attendance from a photo is trickier than it looks

A handwritten list of 12–20 names, some with ticks or crosses next to them, is the classic attendance paper. Parsing it works *most of the time* but:

- **Name matching.** "Joep" in the plugin might be "Joep v.d.B." on the paper. Fuzzy matching by first name + team context gets you far, but there will be misses the coach has to resolve. The existing player list for the team is the match target — no wild-card matches.
- **Status ambiguity.** Tick = present. Cross = absent. A scribble that could be either = ???. A dash = late or partial? Coaches' conventions vary. Wizard defaults the AI's best guess but highlights low-confidence rows.
- **Missing players.** If a player is on the team but not on the paper, are they absent or did the coach just forget to write them down? **Safer default: do not mark missing players absent.** Show them in the wizard as "not on the sheet — coach, please confirm." Marking present players present is low-risk; marking absent players absent without coach confirmation is a data-quality problem that compounds over a season.

### 5. API cost and provider choice

Vision calls aren't free, but for this use case the cost is genuinely not the constraint. What matters more is vision quality on messy handwriting, data-handling terms for minor athletes' information, and the billing model you expose to clubs.

**How vision pricing actually works.** All major providers bill image inputs the same way: the image gets tokenized into the input stream, you pay per-token. There is no separate "per image" SKU. A 1024×1024 photo typically converts to roughly 1,200–1,500 input tokens, plus your instruction prompt (~500 tokens), and the model returns a structured draft of ~500–1,500 output tokens.

**Realistic cost per photo** at that ~2,000 input + ~1,000 output token envelope (2026 rates):

| Model | Input / Output per M tokens | Cost per photo | One team × 1 photo/day × 365 days |
| --- | --- | --- | --- |
| Gemini 2.5 Flash-Lite | $0.10 / $0.40 | ~$0.0006 | ~$0.22 |
| GPT-5 Mini | $0.25 / $2 | ~$0.0025 | ~$0.92 |
| Gemini 2.5 Flash | $0.30 / $2.50 | ~$0.003 | ~$1.10 |
| Claude Haiku 4.5 | $1 / $5 | ~$0.007 | ~$2.55 |
| Gemini 2.5 Pro | $1.25 / $10 | ~$0.013 | ~$4.55 |
| GPT-5.4 | $5 / $10 | ~$0.020 | ~$7.30 |
| Claude Sonnet 4.6 | $3 / $15 | ~$0.021 | ~$7.70 |
| Claude Opus 4.7 | $5 / $25 | ~$0.035 | ~$12.80 |

At one-team / one-session-per-day volumes, **even the most expensive model costs ~€15/year per team.** The cheaper tiers cost less than a single cup of coffee over an entire season. Cost is trivially manageable; the decision is driven by quality and data terms, not price.

**Model choice for launch: Claude Sonnet 4.6 or Gemini 2.5 Pro.** Both are strong on handwritten content. Haiku-class and Flash-Lite models are fast and cheap but visibly weaker on messy handwriting — a coach's scrawl ("2x 4v4 rondos, 8 min elk") stresses them. Start with a mid-tier model for quality; consider cheaper tiers later as a fallback for high-confidence re-runs. Claude's recent Opus models bumped vision resolution (~3.75 MP), which matters for reading small text on A4 paper — worth testing on real sketches before committing.

**Real-time only, not batch.** All three providers offer ~50% off with batch APIs, but with a 24-hour SLA. Coaches want the draft back in 10–20 seconds while they're still at the pitch. Real-time pricing applies.

**Cheap optimizations worth implementing on day one:**

- **Prompt caching.** The system prompt explaining the photo-to-session schema is static. Caching can cut subsequent calls' input cost by up to ~90%. It's a request flag, not a redesign.
- **Client-side image downscaling.** A raw 12 MP phone photo is overkill. Resize to 1024×1024 or 1536×1536 before upload. Saves bandwidth and tokens; handwriting stays legible.
- **Route by need.** Attendance parsing (just reading names) can use Flash/Haiku. Sketch interpretation benefits from Sonnet/Pro. Different parts of the workflow can call different models if it matters. Optimization for phase 2, not day one.

**Deployment options (the who-pays question):**

- **(a) Hosted vision model, club brings own API key.** Key as a constant in `wp-config.php`, matching the pattern in #0009 (GitHub token) and #0013 (S3 credentials). Sites without a key see the feature greyed out. Simplest for us; pushes friction onto the club.
- **(b) Hosted vision model, cost passed through via tier pricing.** Club pays an Academy tier subscription (#0011) that includes N photo-uploads per month; overage at a small per-photo fee. More work operationally (we're reselling API calls) but much lower friction for clubs — they never see an API key or a Claude/Google/OpenAI bill.
- **(c) Self-host an open vision model.** Not realistic for a WordPress plugin. Skip.
- **(d) OCR-only fallback** (Tesseract or a cheap cloud OCR). Gets text but not sketch understanding. Useful as a no-API-key degraded mode that handles attendance only, not exercises. Worth considering if (a) or (b) is blocked.

**Strong recommendation: start with (a), move to (b) once monetization (#0011) is live.** Then the cost analysis becomes: what per-photo rate do we charge, and what's our margin on top of ~€0.02 actual cost? At €0.10 per photo passed through, margin is comfortable; at anything over that, the commercial model needs deliberate thought.

**Privacy implications (apply regardless of deployment option):** photos of team training with minor athletes' names on them leave the site. Provider's "do not train on my data" setting must be configured and documented — all three major providers support it; each handles it differently in their terms. Needs a clear consent checkbox on the settings page, not buried in a privacy policy. This intersects with #0011 and #0013's privacy work — solve it once across all three.

## Draft / release workflow

The raw idea explicitly wants a "complete later before releasing" flow. This matters. Translated into concrete behavior:

- A session has a `status` column: `draft` / `released` / `archived`. Draft = visible only to the creating coach (+ admin), never counts for attendance streaks or player dashboards. Released = live, visible to affected players and team coaches.
- Exercises have their own draft status in the library: `draft` / `published` / `archived`. A draft exercise is usable in a session (draft or released) but doesn't appear in library search until published. This lets a coach drop rough sketches into a session without polluting the shared library — clean-up later, on their own timeline.
- Wizard's "Save as draft" button is the default option at the end, not "Release." Coaches who are field-side in the rain should not accidentally ship bad data to player dashboards because they tapped the wrong button.

## Mobile-first UX constraints

The whole point of this feature is phone-in-hand at the side of the pitch. Assume:

- One-handed operation. Large tap targets, no precision taps.
- Wet / muddy fingers. Dark-mode-friendly. High contrast.
- Unreliable signal. Upload should tolerate an interrupted connection — a background upload job that retries, rather than a single blocking request. Plugin should cache the wizard state locally (localStorage) so closing the app doesn't lose progress.
- The coach has 90 seconds of attention before they want to leave. The wizard should be completable in under 2 minutes for a typical session; the "fix later" path is for the coach who wants out fast.

The existing admin UI is desktop-first. This feature will almost certainly need its own frontend entry point (likely a new frontend tile + shortcode view) optimized for phone screens. Coupling it to the existing `SessionsPage.php` (wp-admin) is a trap.

## Privacy + data protection

Photos of training plans are personal data in two directions:

- Player names (and photos of attendance sheets showing minors' names) go to a third-party AI service.
- Coaches may draw player names into exercise diagrams ("Joep → Sem → Tim"), which also go through the AI.

Consequences:

- Club must consent to AI-assisted processing. Settings page checkbox with clear language.
- Images should be deleted from the plugin's local storage after the wizard completes (not retained as session attachments unless the coach opts in per image).
- The AI provider's data handling terms matter. Most major providers offer "do not train on my data" toggles; those should be required, not nice-to-have.
- For EU clubs: GDPR lawful basis for the AI processing needs to be named — likely "legitimate interest" with a DPIA for the scenario of processing minor athletes' information. This is a real legal item, not boilerplate.

This intersects with the privacy work already flagged in #0011 (monetization/branding) and #0013 (backup PII). Worth threading all three together.

## Decomposition / rough sprint plan

Ordered to de-risk, not to sequence the most exciting thing first:

1. **Sprint 1 — exercise library schema + admin CRUD.** `tt_exercises`, `tt_exercise_categories`, `tt_session_exercises`. Admin pages to manage exercises. No photo feature. Manual entry only. Shippable as its own product improvement.
2. **Sprint 2 — session structure.** Refactor sessions to reference exercises via the join table. Existing free-text notes still works for legacy sessions. Draft/released status on sessions.
3. **Sprint 3 — mobile frontend for coaches.** A phone-optimized "New session" flow, still manual (no photo). Nails the wizard UX, the attendance-picker, the drag-to-reorder-exercises interaction. Proves the flow before the ML goes on top.
4. **Sprint 4 — photo capture + OCR + session-metadata draft.** Vision model integration. First milestone: take a photo, plugin extracts date + attendance + exercise names as text, drops them into the already-working wizard as a draft. No diagram rendering yet. The image itself is attached to the session.
5. **Sprint 5 — exercise library integration.** "Add to library" action in the wizard. Fuzzy-match extracted exercise names against existing library entries to avoid duplicates.
6. **Sprint 6+ — diagram rendering (optional upgrade).** The "(b)" option above — AI returns structured JSON for simple setups, plugin renders clean SVG diagrams. Optional; the feature is useful without it.

Sprints 1–3 are valuable on their own even if sprints 4+ never happen. Sprints 4+ only make sense if sprints 1–3 exist. Don't invert.

## Open questions

- **Library scope: per-team, per-club, or global across installs?** My read: per-club (all teams on one site share), with an optional per-team override. Global across installs (a public exercise catalog) is a different product.
- **Revisions.** If a coach improves an exercise later, do sessions that used the old version update retroactively, or stay pinned to the version used at the time? Pinned is safer — a session from October 2025 should keep showing the drill as it was then, not mutate if the library entry changes.
- **Who can edit library entries?** Author always. Head-of-dev yes. Other coaches… probably no, unless opted-in. Prevents one coach overwriting another's work.
- **"Confirm later" UX.** A session can release with draft exercises. A draft exercise can be completed later from the library page, not from inside the session. The session then reflects the completed version (if pinning says so — see above). Worth drawing this out before building.
- **Attendance fallback.** What if the photo is completely unreadable? The wizard should gracefully fall back to "here's your team roster, tap each player" — the current manual attendance flow. The photo path is an accelerator, never a gate.
- **Which AI provider.** Launch with Claude Sonnet 4.6 or Gemini 2.5 Pro (both handle messy handwriting well; ~€0.02 per photo). Haiku / Flash-Lite tiers are cheaper but visibly weaker on bad handwriting — reserve for a "cheap retry" path in phase 2. Run a shootout with 10+ real training-plan photos from different coaches before locking the default; reading "2x 4v4 rondos, 8 min elk" correctly is the bar. Provider choice also affects EU data-residency options — Gemini via Vertex AI (europe-west) and Claude via AWS Bedrock (eu-central / eu-west) both offer EU-resident inference; OpenAI's EU options are more limited.
- **Who pays for the API call?** Two models: (a) club brings own API key via `wp-config.php` constants (fast to ship, pushes friction to clubs), or (b) plugin passes through cost via Academy-tier subscription (#0011) with per-photo overage (lower friction, moderate operational work). Start with (a); move to (b) once monetization is live.
- **Legal sign-off.** Before sprint 4 ships to real clubs, a DPIA (Data Protection Impact Assessment) is the right step for EU installs processing minor-athlete data through external AI. Can be a lightweight exercise but should not be skipped.
- **Offline mode.** Ship without. Adding offline/queue support doubles the complexity. If coaches repeatedly report "no signal at the pitch," revisit.

## Touches

New module: `src/Modules/Exercises/`
- `ExercisesModule.php`, `Admin/ExercisesPage.php`, `Admin/ExerciseFormView.php`
- New schema: `tt_exercises`, `tt_exercise_categories`, `tt_session_exercises`

New module: `src/Modules/PhotoCapture/` (or inside Sessions module as a submodule — see open questions)
- `PhotoUploader.php`, `VisionClient.php` (provider-agnostic interface), `Providers/ClaudeVisionProvider.php`, `Providers/GeminiVisionProvider.php` (launch), `Providers/OpenAIVisionProvider.php` (optional)
- `Admin/CaptureWizardPage.php` (admin flow)
- `Shared/Frontend/CoachCaptureView.php` (mobile frontend flow)
- REST endpoint: `REST/CaptureController.php` — accepts multipart image upload, returns a draft structure

Schema changes:
- `tt_sessions`: add `status ENUM('draft','released','archived')`, migrate existing rows to `released`
- New join table and new exercise tables

Config constants in `wp-config.php`:
- `TT_VISION_PROVIDER` (claude / openai / gemini)
- `TT_VISION_API_KEY`

Config UI:
- Settings page entry for provider + consent checkboxes for AI processing
- Per-session privacy toggle: "Keep photo" vs "Delete after processing"

Asset / frontend work:
- New mobile-first CSS for the capture wizard
- IndexedDB / localStorage cache for wizard-in-progress state

Integrates with:
- `tt_audit_log` — every AI-processed photo logged (timestamp, user, inference duration)
- `src/Modules/Goals/` — extracted insights could optionally nudge goal creation (out of scope for v1)
- Privacy story in #0011, backup scope in #0013 (if photos get retained, they expand backup size), auto-backup-before-release from #0013's pre-bulk trigger (draft → release is a bulk moment worth guarding)
