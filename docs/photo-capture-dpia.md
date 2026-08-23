<!-- audience: admin -->

# Photo-to-session capture — DPIA template

> Required by EU GDPR Art. 35 before broad deployment of #0016 (photo-to-session capture) to any club whose photographs may include minor athletes.

> ## ⚠️ Not ready for signature
>
> **Do not sign this document, and do not enable photo capture on any install, until [#2695](https://github.com/caspernieuwenhuizen/talenttrack/issues/2695) is closed.**
>
> An audit of this document against the shipped code (2026-08-22) found that several of its technical assertions described safeguards that do not exist. Those sections have been rewritten below to describe what the code actually does, and the gaps are listed in **§ 0. Before this can be signed**.
>
> The most important correction: **this feature does not route to an EU-resident endpoint by default.** The previous version of this document said it did, and that breaking it required a deliberate opt-out. The opposite is true.

This template captures the Data Protection Impact Assessment for the photo-capture flow. Each section has space for the deploying academy's specifics; the technical defaults actually shipped are pre-filled where applicable. Print, complete, sign, retain — that's the operator's record of due diligence.

## 0. Before this can be signed

Each item is an engineering or decision prerequisite. A signature obtained while any of these is open records a due-diligence check that did not happen.

| # | What is missing | Who closes it |
| --- | --- | --- |
| 1 | **EU residency is not enforced.** The default endpoint is Anthropic's direct API, not an EU-resident one, and nothing validates an operator-supplied override. Either implement an enforced EU path, or accept and document that residency is the operator's unchecked responsibility. | Product decision, then engineering — see #2695 |
| 2 | **Retention of the offline queue.** Wave 9 (#2502) holds photographs in IndexedDB on the coach's device until reconnect. That is a processing location this document does not yet describe, and it needs a retention answer. | Engineering + DPO |
| 3 | **Lawful basis** — § 4 is still unticked. | Data controller |
| 4 | **Consent surface** — whether a coach must acknowledge something in-product before the first upload is a legal answer, not a product one (#2502). | DPO |
| 5 | **Provider terms validation** — confirm the current contract's non-retention and non-training clauses as at the signing date. | Data controller |
| 6 | **Provider shootout** — the default provider has never been validated against real coach handwriting (#0016). Not a legal blocker, but a signature implies the extraction is fit for the purpose described in § 5. | Engineering |
| 7 | **Player names in free text.** The extraction can write a player's name into `tt_activity_exercises.notes`, which has no player identifier, so that name is reachable by neither the subject-access export nor an erasure request. Either instruct the model not to transcribe names into the notes, strip them before save, or accept and document the limitation. | Product decision, then engineering |

## 1. Processing description

**What the feature does**: A coach photographs their hand-written training plan with a phone camera. The image is sent to a vision-capable LLM — Claude Sonnet by default, via **Anthropic's direct API** unless the operator overrides the endpoint; Gemini Pro is a configured alternate. The model extracts a structured list of exercises + durations + (optionally) attendance markings. The coach reviews the extraction, edits as needed, and saves the session.

**Personal data potentially in scope**:

- The training-plan photograph itself.
- Any visible player names on the plan (when the coach has scribbled attendance markings on the same sheet).
- Coach handwriting (which is itself biometric-adjacent in some interpretations).
- The structured extraction text returned by the model (which echoes whatever player names were on the photo).

**Data subjects**: youth football players (some minors), parents (rarely, e.g. when carpool notes appear on the plan), coaches.

## 2. Data flow

```
[Phone camera]
     │
     │ HTTP POST multipart/form-data (or JSON photo_base64)
     ▼
[TalentTrack server]
     │   Image bytes are read from PHP's temporary upload location
     │   straight into memory. They are NEVER written to
     │   wp-content/uploads or any other persistent store.
     │
     │ HTTPS
     ▼
[Vision provider — endpoint is operator-configured; see EU residency below]
     │
     │ Inference
     ▼
[Structured JSON response]
     │
     ▼
[TalentTrack server] — returns the extraction to the browser.
     │                 The image bytes go out of scope at the end of
     │                 the request; PHP removes the temp file.
     ▼
[Coach review — nothing is persisted before the coach confirms]
     │
     ▼
[Saved session — tt_activities + tt_activity_exercises]
```

**Access gate**: the endpoint refuses every request unless the `exercises_vision_extraction` feature flag is switched on **and** the caller holds `tt_edit_activities`. On a default install the flag is off, so the feature cannot process anything until an operator deliberately enables it. (`VisionExtractRestController::register()`)

**EU residency — read this carefully.** The default endpoint is `https://api.anthropic.com/v1/messages`, Anthropic's direct API. It is **not** AWS Bedrock, and there is no Bedrock code path: Bedrock requires SigV4 request signing, which is not implemented, and the `TT_VISION_BEDROCK_*` constants named in older versions of this document are not read by any code.

An operator who requires an EU-resident endpoint must set `TT_VISION_ENDPOINT` themselves. **Nothing validates that value.** A typo, an omission, or a later edit silently sends photographs to whatever endpoint is configured, with no warning and no log entry distinguishing the two cases.

This is prerequisite 1 in § 0 and must be resolved before signature.

The OpenAI provider is shipped as a stub and flagged DPIA-incompatible for EU clubs in its own label, because OpenAI's vision endpoint is US-routed only — do not enable it on a club whose data subjects include minors.

**Provider non-persistence**: whichever provider the operator configures, confirm its data-processing terms exclude retention and training on inference inputs, as at the signing date. Do not rely on a claim in this document about a provider you have not contracted with.

## 3. Retention

| Data | Retention | Mechanism |
|---|---|---|
| Source photograph (raw bytes), server-side | **Duration of the HTTP request only** | The bytes are read from PHP's temporary upload location into memory and passed to the provider. Nothing writes them to disk, so there is no upload directory and no sweep. PHP removes its own temp file when the request ends. Earlier versions of this document described a 7-day retention with a cron sweep and a `TT_VISION_PHOTO_RETENTION_DAYS` constant; **neither exists**, and the actual behaviour is stricter. |
| Source photograph, **on the coach's device** | **Not yet decided** | Wave 9 (#2502) queues photographs in IndexedDB when the phone is offline and uploads them on reconnect. Retention there is prerequisite 2 in § 0 and must be answered before this document is signed. |
| Structured extraction text | Indefinite (joined to the saved session) | Persists in `tt_activity_exercises` as part of the session record. Subject to the academy's overall retention policy. |
| Provider-side input data | Per the operator's contract with the provider | Validate against the current contract; see § 2. |

Operator can disable photo capture entirely via `define( 'TT_VISION_PROVIDER', '' );` in `wp-config.php`, or by switching off the `exercises_vision_extraction` feature — which is the state a default install is already in. The manual session-edit flow is unaffected either way.

## 4. Lawful basis

Document the academy's chosen lawful basis under GDPR Art. 6:

- [ ] **Legitimate interest** (Art. 6(1)(f)) — the academy has a legitimate interest in efficient training-data capture. Operator must complete a Legitimate Interests Assessment.
- [ ] **Consent** (Art. 6(1)(a)) — when minors are in scope, consent is given by the parent/guardian. Document where consent is captured (registration form, annual renewal, etc.) and how it can be withdrawn.
- [ ] **Performance of contract** (Art. 6(1)(b)) — the academy has a service contract with the family that includes training-data capture as a deliverable.

Pick at most two; document why.

## 5. Necessity + proportionality

- **Why a photo + AI?**: Coaches systematically fail to log sessions manually after training (the "data missed" problem the spec calls out). Without this feature, ≥40% of training data is permanently lost.
- **Less invasive alternatives considered**:
  - Coach types directly into the session form → high friction; fails in practice.
  - Voice capture → considered for v2; deferred per spec.
  - On-device-only extraction (no cloud LLM) → not feasible at v1 quality bar; revisit when local vision models match Claude Sonnet 4.x quality.
- **Proportionality**: The data sent off-site is the photograph the coach made on a personal phone anyway, and the server keeps only the structured extraction — the image bytes are never written to disk. Whether the provider persists the input depends on the operator's contract with it, which § 0 prerequisite 5 requires confirming.

## 6. Data subject rights

| Right | How TalentTrack supports it |
|---|---|
| Access (Art. 15) | **Partly.** Who attended a session is covered — `tt_attendance` is registered in `PlayerDataMap` and appears in the export. The extraction itself is not: it lands in `tt_activity_exercises`, which records *which drills a session contained* and carries **no player identifier at all**, so it is not player-keyed data and cannot be registered (`PlayerDataMap::register()` requires a column joining to player identity). ⚠️ **The residual risk is the free-text `notes` column.** § 1 of this document notes that the extraction echoes any player names visible on the photo, and a name written into free text is not reachable by a table-and-column export mechanism — so it would be neither exported nor erased on request. See § 0 prerequisite 7. An earlier version of this document claimed both tables were included in the export; they are not. |
| Rectification (Art. 16) | The session edit form lets a coach correct any extracted exercise / attendance. Sprint 4's review wizard makes this the default path before save. |
| Erasure (Art. 17) | Deleting the **activity** cascades to `tt_activity_exercises` (`CascadeRegistry`), and `tt_activities.archived_at` soft-deletes. But erasing **one player** does not delete the session — the session belongs to a team and the other players' records depend on it — so anything about that player sitting in `tt_activity_exercises.notes` survives their erasure request. Same root cause as the Access row; § 0 prerequisite 7. |
| Restriction (Art. 18) | Operator can flag an activity as `is_draft` to prevent it from rolling into reports. |
| Portability (Art. 20) | Whatever the subject-access export covers is portable, in the same ZIP — so the attendance record is, and the extraction is not. See the Access row. |
| Object (Art. 21) | Disabling `TT_VISION_PROVIDER` immediately removes the provider's involvement. |

## 7. Risks + mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Photo of a minor is sent to a non-EU-resident endpoint | **High while prerequisite 1 is open** — this is the default behaviour, not a misconfiguration | High | **Currently unmitigated.** EU residency is opt-in via `TT_VISION_ENDPOINT` and unvalidated. The only real mitigations today are that the feature is off by default and that the OpenAI adapter's label flags its own incompatibility. See § 0 prerequisite 1. |
| Provider trains on input data | Depends on the operator's contract | Very high | Validate the configured provider's data-processing terms at signing and at every annual refresh. This document makes no claim on the operator's behalf. |
| Extracted text contains incorrect attendance attribution | Medium | Medium | Review wizard requires explicit coach approval before save; fuzzy-matcher confidence < 0.6 surfaces the row as "manual review needed" (`ExerciseFuzzyMatcher::DEFAULT_MIN_SIMILARITY`). |
| A queued photo lingers on a lost or shared device | Unknown until #2502 lands | Medium | Prerequisite 2 — the offline queue's retention is undecided. |
| A player's name is transcribed into free-text notes and then cannot be found | Medium — the plan and the attendance markings are often the same sheet | Medium | **Currently unmitigated.** `tt_activity_exercises.notes` has no player identifier, so such a name is invisible to both the subject-access export and an erasure request. Prerequisite 7. |
| API key leak | Low (constant in wp-config) | High | Document key rotation procedure; never commit `wp-config.php` to git. |
| The feature is enabled before this document is signed | Low | High | The `exercises_vision_extraction` feature flag is off on a default install and the endpoint returns 403 until it is switched on. Treat switching it on as the act this signature authorises. |

## 8. Annual review

DPIA refresh schedule: every 12 months from the date of broad deployment. Earlier refresh is required if any of:

- The configured provider's terms change.
- `TT_VISION_ENDPOINT` is added, removed or edited — that changes where photographs go.
- Provider region changes.
- A new vision provider is added to the registry.
- The retention period is extended.
- New data subject categories enter scope (e.g. parents' names start appearing on plans).

## 9. Sign-off

| Role | Name | Date | Signature |
|---|---|---|---|
| Data controller (academy admin) | __________________ | _______ | _________ |
| Data protection officer (if appointed) | __________________ | _______ | _________ |
| TalentTrack technical lead | __________________ | _______ | _________ |

Retain one copy in the academy's DPIA register; keep one in the wp-config-adjacent compliance folder.

---

## Implementation reference

Default configuration (TalentTrack v3.110.40+):

```php
// wp-config.php — constants the code actually reads
define( 'TT_VISION_PROVIDER', 'claude_sonnet' );  // '' disables the feature entirely
define( 'TT_VISION_API_KEY',  'sk-ant-...' );     // sent as the x-api-key header
define( 'TT_VISION_ENDPOINT', '...' );            // overrides the default endpoint
```

The feature also requires the `exercises_vision_extraction` flag to be switched on; it is off by default.

**Constants that do NOT exist**, despite appearing in earlier versions of this document and in `ClaudeSonnetProvider`'s docblock — setting any of them has no effect whatsoever:

- `TT_VISION_BEDROCK_REGION`, `TT_VISION_BEDROCK_ACCESS_KEY`, `TT_VISION_BEDROCK_SECRET_KEY` — there is no Bedrock code path.
- `TT_VISION_PHOTO_RETENTION_DAYS` — there is no stored photo to retain.

Without `TT_VISION_ENDPOINT`, requests go to `https://api.anthropic.com/v1/messages`.

To disable photo capture entirely:

```php
define( 'TT_VISION_PROVIDER', '' );  // empty string → resolveProvider() returns null → manual flow only
```

See also:

- `specs/shipped/0016-epic-photo-to-session-capture.md` — the original spec.
- `docs/i18n-architecture.md` — how the extracted strings flow through the translation layer.
- The data-processing terms of whichever provider the operator has configured. Identifying and validating the right ones is the operator's responsibility, at signing and at every refresh — this document deliberately no longer names a provider's terms, because it cannot know which endpoint an install is pointed at.
