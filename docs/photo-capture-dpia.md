<!-- audience: admin -->

# Photo-to-session capture — DPIA template

> Required by EU GDPR Art. 35 before broad deployment of #0016 (photo-to-session capture) to any club whose photographs may include minor athletes.

> ## Nearly ready for signature
>
> **Legal clearance was given on 2026-08-23.** Prerequisites 2 through 5 are decided and recorded below; 7 is a residual risk for the DPO to accept knowingly rather than a gap. What remains before a signature is honest:
>
> 1. **Two blanks in § 4** — where consent is captured and how it is withdrawn. The product cannot know these.
> 2. **The destination in § 2** — the endpoint and region this install will declare. Until they are set in `wp-config.php`, nothing can be sent at all.
> 3. **Prerequisite 6**, the provider shootout. Not a legal blocker, but a signature implies the extraction is fit for the purpose § 5 describes, and it has never been tried against real coach handwriting.
>
### How this document got here
>
> An audit against the shipped code (2026-08-22) found that several of its technical assertions described safeguards that did not exist. Those sections were rewritten to describe what the code actually does, and **§ 0** tracks every prerequisite that came out of it.
>
> **Two were then closed in code** (2026-08-23): there is no longer a default endpoint — nothing is sent until this install declares where photographs go — and the extraction prompt now keeps player names out of free-text fields. Prerequisite 7 is only *partly* closed; read its residual risk before signing.
>
> **Corrected 2026-08-23, second pass:** this document briefly claimed the `exercises_vision_extraction` feature flag was off on a default install and leaned on that as a safeguard. It is **on** by default. The claim was wrong, it was written here during the audit that was supposed to remove exactly this kind of error, and it also reached the v4.96.0 release notes. Nothing about the install's actual safety changed — a fresh install still sends nothing, because it has declared no destination — but the reason is the destination gate, not the flag.
>
> The correction that prompted all of this: the previous version said photographs routed to an EU-resident endpoint by default and that leaving EU residency required a deliberate opt-out. Neither was true.

This template captures the Data Protection Impact Assessment for the photo-capture flow. Each section has space for the deploying academy's specifics; the technical defaults actually shipped are pre-filled where applicable. Print, complete, sign, retain — that's the operator's record of due diligence.

## 0. Before this can be signed

Each item is an engineering or decision prerequisite. A signature obtained while any of these is open records a due-diligence check that did not happen.

| # | What is missing | Who closes it |
| --- | --- | --- |
| 1 | ✅ **Closed.** There is no longer a default endpoint. The feature refuses to send anything until the operator declares both `TT_VISION_ENDPOINT` and `TT_VISION_DATA_REGION`; until they do, it reports itself unconfigured and callers fall back to manual entry. **What this does not do is verify the declaration** — no plugin can tell whether an endpoint really processes data where its operator says it does. What it guarantees is that the destination is always a choice somebody made, which is the thing a DPIA can honestly record. The declared region string belongs in § 2 below. | Done — the operator still owns the accuracy of the declaration |
| 2 | ✅ **Closed 2026-08-23: 7 days, and now enforced.** A photograph held on a coach's phone while they are out of range is dropped after seven days, whether or not it has been reviewed (#2735). The window is swept on every load of the capture screen and hourly while it is open, so a phone that was closed for a fortnight drops what it was holding before it can offer it back. The coach is told the photograph expired rather than finding it silently absent. | Done |
| 3 | ✅ **Decided 2026-08-23: consent, Art. 6(1)(a)**, given by the parent or guardian since minors are in scope. § 4 records it. The controller must still name **where** that consent is captured and how it is withdrawn — see the two blanks in § 4. | Decided; two blanks to complete at signing |
| 4 | ✅ **Decided 2026-08-23: no in-product acknowledgement.** Consent is handled at registration, outside the product. The capture screen states where the photograph goes and nothing more; the placeholder for a first-use panel has been removed from the design rather than left implying something is coming. | Decided — nothing to build |
| 5 | ✅ **Confirmed 2026-08-23** by the data controller as part of the legal clearance. Re-confirm at each annual refresh and whenever the destination changes. | Done |
| 6 | **Provider shootout** — the default provider has never been validated against real coach handwriting (#0016). Not a legal blocker, but a signature implies the extraction is fit for the purpose described in § 5. | Engineering |
| 7 | ⚠️ **Partly closed — read the residual risk.** The extraction prompt now instructs the model to keep player names in the structured `attendance` array and never to write one into any free-text `notes` field or exercise name. **A prompt is a request, not a guarantee**, and a server-side strip against the squad list was considered and deliberately not built. So a name the model transcribes anyway still lands somewhere neither a subject-access export nor an erasure request can reach. Sign only if the DPO accepts that residual risk knowingly. | Instruction shipped; accepting the remainder is a DPO decision |

## 1. Processing description

**What the feature does**: A coach photographs their hand-written training plan with a phone camera. The image is sent to a vision-capable LLM — Claude Sonnet by default, at **whatever endpoint this install has declared** (§ 2; there is no default, and nothing is sent until one is declared). The model extracts a structured list of exercises + durations + (optionally) attendance markings. The coach reviews the extraction, edits as needed, and saves the session.

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
     │ ── out of range? ──▶ [Held on the device: IndexedDB `tt_photo_hold`]
     │                            │  Never leaves the phone. Dropped after
     │                            │  7 days, or the moment the extraction
     │                            │  has been reviewed. Resumes here when
     │                            │  the connection returns.
     │       ◀────────────────────┘
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

**Access gate**: the endpoint refuses every request unless the `exercises_vision_extraction` feature flag is on **and** the caller holds `tt_edit_activities` (`VisionExtractRestController::register()`).

⚠️ **That flag is on by default** (`FeatureRegistry`: `'default_enabled' => true`). An earlier version of this document said the opposite and treated the flag as the thing standing between a fresh install and processing. It is not. **The gate that actually stops a default install from sending anything is the destination declaration below** — no endpoint and no region means nothing leaves, whatever the flag says. An academy that wants a second, deliberate switch should turn the feature off explicitly rather than assume it starts that way.

**Data residency — the operator declares it, and nothing is sent until they do.**

There is no default endpoint. The feature will not send a photograph anywhere until this install has stated, in `wp-config.php`, both where requests go and what that means:

```php
define( 'TT_VISION_ENDPOINT',    'https://…' );          // where requests go
define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' );     // where that processes data
```

Until both are present the provider reports itself unconfigured, exactly as it would with no API key, and callers fall back to manual entry. The REST endpoint answers `503 destination_not_declared` and says so in as many words.

**Write the declared region here, verbatim, as part of completing this document:**

> `TT_VISION_DATA_REGION` on this install: `________________________________`

**What this does not do.** It cannot verify the declaration. No plugin can tell whether an endpoint really processes data where its operator says it does — that is a contractual fact, not a network one. Confirming it is prerequisite 5, and it stays the operator's responsibility. What the code now guarantees is narrower and worth more: **the destination is always a choice somebody made.** A DPIA can honestly record a declaration; it could not honestly record a default nobody read.

There is still no AWS Bedrock code path — Bedrock requires SigV4 request signing, which is not implemented, and the `TT_VISION_BEDROCK_*` constants named in older versions of this document have been removed from the codebase because nothing ever read them. Point `TT_VISION_ENDPOINT` at something that speaks the Anthropic Messages API.

The OpenAI provider is shipped as a stub and flagged DPIA-incompatible for EU clubs in its own label, because OpenAI's vision endpoint is US-routed only — do not enable it on a club whose data subjects include minors.

**Provider non-persistence**: whichever provider the operator configures, confirm its data-processing terms exclude retention and training on inference inputs, as at the signing date. Do not rely on a claim in this document about a provider you have not contracted with.

## 3. Retention

| Data | Retention | Mechanism |
|---|---|---|
| Source photograph (raw bytes), server-side | **Duration of the HTTP request only** | The bytes are read from PHP's temporary upload location into memory and passed to the provider. Nothing writes them to disk, so there is no upload directory and no sweep. PHP removes its own temp file when the request ends. Earlier versions of this document described a 7-day retention with a cron sweep and a `TT_VISION_PHOTO_RETENTION_DAYS` constant; **neither exists**, and the actual behaviour is stricter. |
| Source photograph, **on the coach's device** | **7 days** — decided 2026-08-23, enforced since #2735 | A photograph taken out of range is held in the browser's IndexedDB (`tt_photo_hold`) on that device only, and sent when the connection returns. It is deleted the moment its extraction has been reviewed, and dropped unconditionally seven days after it was taken — swept on load and hourly, so a phone closed across the expiry drops it before offering it back. The coach is told a photo expired; a photograph that vanishes without a word is worse than one that expires loudly. The seven days are the ceiling, not the target. |
| Structured extraction text | Indefinite (joined to the saved session) | Persists in `tt_activity_exercises` as part of the session record. Subject to the academy's overall retention policy. |
| Provider-side input data | Per the operator's contract with the provider | Validate against the current contract; see § 2. |

Operator can disable photo capture entirely via `define( 'TT_VISION_PROVIDER', '' );` in `wp-config.php`, or by switching off the `exercises_vision_extraction` feature — which is **on** by default, so switching it off is an action to take rather than a state to rely on. Simply not configuring the two destination constants already means nothing is sent. The manual session-edit flow is unaffected either way.

## 4. Lawful basis

Document the academy's chosen lawful basis under GDPR Art. 6:

- [ ] **Legitimate interest** (Art. 6(1)(f)) — the academy has a legitimate interest in efficient training-data capture. Operator must complete a Legitimate Interests Assessment.
- [x] **Consent** (Art. 6(1)(a)) — when minors are in scope, consent is given by the parent/guardian. **Chosen 2026-08-23.**
- [ ] **Performance of contract** (Art. 6(1)(b)) — the academy has a service contract with the family that includes training-data capture as a deliverable.

Pick at most two; document why.

**Why consent.** The data subjects are children, and the processing sends their likeness — or their names, where the coach wrote attendance on the same sheet — to a third party that the academy chose. Legitimate interest would put the academy in the position of weighing its own convenience against a child's privacy and marking its own homework; consent puts the decision with the parent, which is where it belongs for this kind of processing.

Two things the controller must still complete, because the product cannot know them:

> Where consent is captured: `________________________________________`
>
> How it is withdrawn: `________________________________________`

**Consent is captured outside the product** (prerequisite 4). There is deliberately no in-product acknowledgement before a coach's first upload: an extra tap on the capture screen would look like consent while collecting it from the wrong person — the coach is not the data subject, and not their guardian.

**What withdrawal means here.** Because the server never stores the photograph, withdrawing consent does not require deleting one — there is none to delete. What it reaches is the structured extraction joined to the saved session. See § 6 for what is and is not reachable by an erasure request, including the free-text limitation in prerequisite 7.

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
| Access (Art. 15) | **Partly.** Who attended a session is covered — `tt_attendance` is registered in `PlayerDataMap` and appears in the export. The extraction itself is not: it lands in `tt_activity_exercises`, which records *which drills a session contained* and carries **no player identifier at all**, so it is not player-keyed data and cannot be registered (`PlayerDataMap::register()` requires a column joining to player identity). ⚠️ **The residual risk is the free-text `notes` column.** The extraction can echo player names visible on the photo, and a name written into free text is not reachable by a table-and-column export mechanism — so it would be neither exported nor erased on request. The prompt now instructs the model to keep names in the structured `attendance` array instead, where they stay attached to a player; that reduces the risk without removing it, because an instruction is not an enforcement. See § 0 prerequisite 7. An earlier version of this document claimed both tables were included in the export; they are not. |
| Rectification (Art. 16) | The session edit form lets a coach correct any extracted exercise / attendance. Sprint 4's review wizard makes this the default path before save. |
| Erasure (Art. 17) | Deleting the **activity** cascades to `tt_activity_exercises` (`CascadeRegistry`), and `tt_activities.archived_at` soft-deletes. But erasing **one player** does not delete the session — the session belongs to a team and the other players' records depend on it — so anything about that player sitting in `tt_activity_exercises.notes` survives their erasure request. Same root cause as the Access row; § 0 prerequisite 7. |
| Restriction (Art. 18) | Operator can flag an activity as `is_draft` to prevent it from rolling into reports. |
| Portability (Art. 20) | Whatever the subject-access export covers is portable, in the same ZIP — so the attendance record is, and the extraction is not. See the Access row. |
| Object (Art. 21) | Disabling `TT_VISION_PROVIDER` immediately removes the provider's involvement. |

## 7. Risks + mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Photo of a minor is sent to a non-EU-resident endpoint | Low — it can no longer happen by default, only by a wrong declaration | High | The feature refuses to send anything until the operator declares an endpoint and a region; there is no default to fall through to. The residual risk is a declaration that is mistaken or out of date, which prerequisite 5 and the annual review address. The OpenAI adapter additionally flags its own EU incompatibility in its label. |
| Provider trains on input data | Depends on the operator's contract | Very high | Validate the configured provider's data-processing terms at signing and at every annual refresh. This document makes no claim on the operator's behalf. |
| Extracted text contains incorrect attendance attribution | Medium | Medium | Review wizard requires explicit coach approval before save; fuzzy-matcher confidence < 0.6 surfaces the row as "manual review needed" (`ExerciseFuzzyMatcher::DEFAULT_MIN_SIMILARITY`). |
| A held photo lingers on a lost or shared device | None today — nothing is held; Medium once #2735 lands | Medium | Wave 9 is online-only, so no photograph currently sits on any device. When holding is built, seven days is the ceiling (§ 3). Seven is the most permissive of the options considered and was chosen knowingly: it is the window in which a coach who photographs on a Friday evening and looks on the following weekend still has their session. The shorter alternatives lose that coach's work silently. |
| A player's name is transcribed into free-text notes and then cannot be found | Reduced but not removed — the plan and the attendance markings are often the same sheet | Medium | The extraction prompt instructs the model to keep names in the structured `attendance` array, where they stay attached to a player, and out of every free-text field. **This is an instruction to a model, not an enforcement**: a server-side strip against the squad was considered and not built, so a transcribed name still reaches a column no export or erasure can see. Prerequisite 7 — accept knowingly or revisit. |
| API key leak | Low (constant in wp-config) | High | Document key rotation procedure; never commit `wp-config.php` to git. |
| The feature is used before this document is signed | Low | High | **Not** because the feature flag is off — it is on by default. Because a fresh install has declared no destination, so the endpoint answers `503` and sends nothing. Treat *declaring the destination* as the act this signature authorises, and switch `exercises_vision_extraction` off explicitly if you want a second lock. |

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
// wp-config.php — all four are required; there is no working default
define( 'TT_VISION_PROVIDER',    'claude_sonnet' );      // '' disables the feature entirely
define( 'TT_VISION_API_KEY',     'sk-ant-...' );         // sent as the x-api-key header
define( 'TT_VISION_ENDPOINT',    'https://…' );          // where photographs are sent
define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' );     // where that endpoint processes them
```

The feature also requires the `exercises_vision_extraction` flag to be on — **and it is on by default**, so it is not a barrier a fresh install has to cross. The barrier is the pair of constants above: without them the endpoint answers `503 destination_not_declared` and nothing is sent, whatever the flag says.

`TT_VISION_DATA_REGION` is free text on purpose. A dropdown would invite picking the nearest-looking option; writing the words out is a small act of attention, and the string is what § 2 of this document records.

**Constants that do NOT exist.** Setting any of these has no effect whatsoever; they appeared in earlier versions of this document and have been removed from the codebase:

- `TT_VISION_BEDROCK_REGION`, `TT_VISION_BEDROCK_ACCESS_KEY`, `TT_VISION_BEDROCK_SECRET_KEY` — there is no Bedrock code path.
- `TT_VISION_PHOTO_RETENTION_DAYS` — there is no stored photo to retain.

To disable photo capture entirely:

```php
define( 'TT_VISION_PROVIDER', '' );  // empty string → resolveProvider() returns null → manual flow only
```

See also:

- `specs/shipped/0016-epic-photo-to-session-capture.md` — the original spec.
- `docs/i18n-architecture.md` — how the extracted strings flow through the translation layer.
- The data-processing terms of whichever provider the operator has configured. Identifying and validating the right ones is the operator's responsibility, at signing and at every refresh — this document deliberately no longer names a provider's terms, because it cannot know which endpoint an install is pointed at.
