<!-- audience: admin -->

# Photo-to-session capture — DPIA template

> Required by EU GDPR Art. 35 before broad deployment of #0016 (photo-to-session capture) to any club whose photographs may include minor athletes.

This template captures the Data Protection Impact Assessment for the photo-capture flow. Each section has space for the deploying academy's specifics; the technical defaults shipped by TalentTrack v3.110.40 are pre-filled where applicable. Print, complete, sign, retain — that's the operator's record of due diligence.

## 1. Processing description

**What the feature does**: A coach photographs their hand-written training plan with a phone camera. The image is sent to a vision-capable LLM (Claude Sonnet 4.x via AWS Bedrock by default; Gemini Pro via Vertex AI as alternate). The model extracts a structured list of exercises + durations + (optionally) attendance markings. The coach reviews the extraction, edits as needed, and saves the session.

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
     │ HTTP POST multipart/form-data
     ▼
[TalentTrack server]
     │
     │ HTTPS (TLS 1.2+)
     ▼
[Vision provider — AWS Bedrock eu-central-1]
     │
     │ Inference, no persistence per Bedrock terms
     ▼
[Structured JSON response]
     │
     ▼
[TalentTrack server] — stores extraction; deletes the source photo
     │              within 7 days (next cron run)
     ▼
[Coach review — wp-admin / frontend]
     │
     ▼
[Saved session — tt_activities + tt_activity_exercises]
```

**EU residency**: AWS Bedrock `eu-central-1` (Frankfurt) is the default endpoint. Vertex AI `europe-west` is the alternate. The OpenAI provider is shipped as a stub but flagged DPIA-incompatible for EU clubs because OpenAI's vision endpoint is US-routed only — do not enable it on a club whose data subjects include minors.

**Provider non-persistence**: AWS Bedrock + Vertex AI both contractually do not retain or train on inference inputs (per their respective data-processing terms — confirm against your contract date). Validate this against the current contract before each annual DPIA refresh.

## 3. Retention

| Data | Retention | Mechanism |
|---|---|---|
| Source photograph (raw bytes) | 7 days max | Background cron job sweeps the upload directory and deletes any `tt-vision-photo-*` blob older than 7 days. Operator can shorten by setting `TT_VISION_PHOTO_RETENTION_DAYS` in `wp-config.php`. |
| Structured extraction text | Indefinite (joined to the saved session) | Persists in `tt_activity_exercises` as part of the session record. Subject to the academy's overall retention policy. |
| Provider-side input data | 0 — no persistence per provider terms | Validate against current contract. |

Operator can disable photo capture entirely via `define( 'TT_VISION_PROVIDER', '' );` in `wp-config.php`; the existing manual session-edit flow is unaffected.

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
- **Proportionality**: The data sent off-site is the photograph the coach made on a personal phone anyway. The AI provider does not persist the input. The academy retains only the structured extraction, not the raw photo (after 7 days).

## 6. Data subject rights

| Right | How TalentTrack supports it |
|---|---|
| Access (Art. 15) | The structured extraction lives in `tt_activity_exercises` joined to `tt_activities`. The existing #0063 use case 10 GDPR subject-access ZIP exporter includes both tables in the data subject's ZIP. |
| Rectification (Art. 16) | The session edit form lets a coach correct any extracted exercise / attendance. Sprint 4's review wizard makes this the default path before save. |
| Erasure (Art. 17) | Deleting the activity cascades to `tt_activity_exercises`. The `tt_activities.archived_at` flag soft-deletes; the GDPR erasure follow-up spec hard-deletes. |
| Restriction (Art. 18) | Operator can flag an activity as `is_draft` to prevent it from rolling into reports. |
| Portability (Art. 20) | Extracted data is part of the GDPR subject-access ZIP. |
| Object (Art. 21) | Disabling `TT_VISION_PROVIDER` immediately removes the provider's involvement. |

## 7. Risks + mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Photo of a minor is sent to a US-routed AI provider | Low (default routes EU-only) | High | wp-config gate forces EU-resident endpoints. The OpenAI adapter's `label()` flags the issue. Operator must explicitly opt out of EU residency to break this. |
| Provider trains on input data | Low (contractually excluded) | Very high | Validate against the current AWS Bedrock + Vertex AI data-processing terms at every annual DPIA refresh. |
| Extracted text contains incorrect attendance attribution | Medium | Medium | Review wizard requires explicit coach approval before save; fuzzy-matcher confidence < 0.6 surfaces the row as "manual review needed". |
| Photo retention exceeds 7 days due to cron failure | Low | Low | Cron is monitored via #0086 audit log; failures alert. |
| API key leak | Low (constant in wp-config) | High | Document key rotation procedure; never commit `wp-config.php` to git. |

## 8. Annual review

DPIA refresh schedule: every 12 months from the date of broad deployment. Earlier refresh is required if any of:

- Provider terms change (AWS Bedrock, Vertex AI, etc.).
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
// wp-config.php
define( 'TT_VISION_PROVIDER',         'claude_sonnet' );
define( 'TT_VISION_API_KEY',          'sk-ant-...' );           // Anthropic direct OR via Bedrock IAM
define( 'TT_VISION_BEDROCK_REGION',   'eu-central-1' );         // EU residency
define( 'TT_VISION_PHOTO_RETENTION_DAYS', 7 );                  // Default; lower if your DPIA requires it
```

To disable photo capture entirely:

```php
define( 'TT_VISION_PROVIDER', '' );  // empty string → resolveProvider() returns null → manual flow only
```

See also:

- `specs/shipped/0016-epic-photo-to-session-capture.md` — the original spec.
- `docs/i18n-architecture.md` — how the extracted strings flow through the translation layer.
- AWS Bedrock data-processing terms: https://aws.amazon.com/service-terms/ (link is operator's responsibility to validate at DPIA refresh time).
