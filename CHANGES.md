# TalentTrack v3.97.0 — Onboarding pipeline child 2a: workflow chain entry point + first two task templates (#0081)

First half of the second child PR for the #0081 onboarding-pipeline epic. Child 2 was split into 2a + 2b after scope assessment — five templates plus REST endpoints plus migration plus dedup ran ~3-4× the spec's original ~600 LOC estimate. Splitting into two PRs lets each be independently reviewable and the chain progresses end-to-end at PR 2b.

## What this PR does

Lights up **the first half of the chain**: a scout clicks "+ New prospect" → fills in a quick-capture form → the prospect row exists in `tt_prospects` → the HoD gets a workflow task to invite the prospect to a test training. The chain ends at the HoD's invite task in this PR; PR 2b adds the parent-confirmation, outcome-recording, and trial-group-review templates plus the no-login parent endpoint.

## `tt_workflow_tasks.prospect_id` (migration 0068)

The workflow engine carries entity links as nullable FK columns on the task row (`player_id`, `team_id`, `activity_id`, `evaluation_id`, `goal_id`, `trial_case_id`, `parent_task_id`). Adding `prospect_id` to the same shape — same nullability, same `BIGINT UNSIGNED` width, same `KEY` index — gives the onboarding-pipeline chain a first-class link rather than threading the ID through `extras` JSON.

`TaskContext` gains a matching constructor parameter, `toEntityLinks()` includes it, `with()` lets callers override it. `ChainStep::inheritContext()` propagates it from parent to child task. `TasksRepository::create()` accepts it.

## `LogProspectTemplate` (chain link 1/5)

```
key:               log_prospect
default schedule:  manual (REST entry point dispatches it)
default deadline:  +14 days
default assignee:  the user who initiates (LambdaResolver reading
                   TaskContext.extras['initiated_by'])
form class:        LogProspectForm
entity links:      ['prospect_id']
chain steps:       1 — spawn InviteToTestTrainingTemplate when the
                   prospect row was successfully created
```

**Why "the initiator" rather than "all scouts."** Assigning to all users with the `tt_scout` role would fan a single click into N tasks for every scout in the academy. The chain wants exactly one task — the scout who clicked. The REST entry point (`POST /prospects/log`) puts `get_current_user_id()` into `TaskContext.extras['initiated_by']`; a `LambdaResolver` reads it back at dispatch time. `RoleBasedResolver` would have been wrong here.

## `LogProspectForm` (entity creation lives in the form)

Three sections: identity (first/last name, DOB, current club), discovery (event, scouting notes), parent contact (name, email, phone, consent checkbox). The "two-screen UX" the spec asks for is intentionally deferred to child 3's UI polish — the response payload shape is final, only the presentation lifts in the next pass.

**Duplicate detection** at validate time. `ProspectsRepository::findDuplicateCandidates()` matches on first/last name + current club. If matches exist, validation fails with a `__form` error listing the candidates by name; the user can override with the "I have checked, this is a new entry" checkbox. Threshold is exact-name match for now (the spec called for Levenshtein 85% which lands as a follow-up — exact-match is a strict superset of fuzzy match for the default case where scouts type the same spelling, and false positives are preferred over false negatives in this domain).

**`serializeResponse()` writes the entity.** This is a deliberate departure from existing templates like `PostGameEvaluationForm` whose `serializeResponse()` only formats the response payload. The prospect row creation IS the chain step — splitting it into a separate REST call (or `onComplete()` hook) would invite race conditions where the task completes but the prospect doesn't exist (or vice versa). Form returns the new `prospect_id` in the response; the template's `onComplete()` stamps it onto the task row's column.

```php
public function serializeResponse(array $raw, array $task): array {
    $repo = new ProspectsRepository();
    $prospect_id = $repo->create([...]);
    return ['prospect_id' => $prospect_id, ...echo of input...];
}
```

## `InviteToTestTrainingTemplate` (chain link 2/5)

```
key:               invite_to_test_training
default schedule:  manual (chain-spawned by LogProspect)
default deadline:  +7 days
default assignee:  RoleBasedResolver('tt_head_dev')
form class:        InviteToTestTrainingForm
entity links:      ['prospect_id']
chain steps:       (none — PR 2b adds the spawn of
                   ConfirmTestTrainingTemplate when that ships)
```

The chain ends here in PR 2a. Completing this task closes the inbox loop without a spawn, which is the correct behaviour while the parent-confirmation surface doesn't exist yet. PR 2b adds the chain step.

`InviteToTestTrainingForm` lets the HoD either pick an existing upcoming session from `tt_test_trainings` (dropdown of sessions where `date >= today`) OR schedule a new session in the same form (date/time + location + coach assignment — the new row is created on submit, no separate flow). Validation requires exactly one path. The invitation message defaults to a translated stub referencing the prospect's first name; the HoD edits before sending. Email/SMS dispatch is intentionally out of scope — that ties into #0066 (communication module). For now the form captures the intended message in the response payload; PR 2b will surface a copy-pasteable string the HoD can send manually until #0066 lands.

## REST entry point — `POST /prospects/log`

```
POST /wp-json/talenttrack/v1/prospects/log
Body: {} (empty — just starts the chain)
Permission: tt_edit_prospects (scout / HoD / admin per matrix)
Response: { task_id: 123, redirect_url: "/?tt_view=my-tasks&task_id=123" }
```

Lives in a new file `src/Modules/Prospects/Rest/ProspectsRestController.php`. Initialised from `ProspectsModule::boot()` alongside the retention cron. Exists as a REST route — rather than just an inline button — so the future pipeline widget (child 3) and PR 2b's no-login parent endpoint share the same dispatch path.

## New cap `tt_invite_prospects`

Bridged in `LegacyCapMapper` to `[ 'test_trainings', 'change' ]`. Inviting is materially a write to the test-training schedule (picking the session + composing the parent-facing message). HoD and Academy Admin both hold `test_trainings:rcd:global` from child 1's matrix seed — they get this cap. Scout has only `test_trainings:r:global` — can read sessions but can't invite. No new matrix entity needed.

## Retention cron tightening

Child 1 shipped the stale-no-progress purge as a created_at-only check, with a TODO to swap in the workflow-task join once `tt_workflow_tasks.prospect_id` existed. This PR adds that column AND tightens the query:

```sql
SELECT pr.id, pr.club_id
  FROM tt_prospects pr
 WHERE pr.created_at < {cutoff}
   AND pr.promoted_to_player_id IS NULL
   AND pr.archived_at IS NULL
   AND NOT EXISTS (
         SELECT 1 FROM tt_workflow_tasks wt
          WHERE wt.prospect_id = pr.id
            AND wt.status IN ('open','in_progress','overdue')
   )
 LIMIT 50
```

A prospect with any non-terminal workflow task is now correctly excluded from the purge regardless of `created_at` age. Column-existence guard preserves the created_at-only fallback for installs where migration 0068 hasn't run yet (paranoid — migrations run in order so this should never happen, but the cron stays defensive against partial-install states).

## What's NOT in this PR (PR 2b)

- `ConfirmTestTrainingTemplate` (chain link 3/5) + the no-login parent-confirmation REST endpoint with signed-token-in-URL
- `RecordTestTrainingOutcomeTemplate` (chain link 4/5) — coach records observation per attendee + HoD decides admit/decline/second-session
- `ReviewTrialGroupMembershipTemplate` (chain link 5/5) — quarterly HoD review with offer/continue/decline-final decisions
- New caps `tt_decide_test_training_outcome`, `tt_decide_trial_outcome`
- Levenshtein 85% fuzzy matching (this PR ships exact-match dedup)

## Translations

30 new NL msgids covering the form labels (Discovery, Parent contact, Current club, Discovered at, Scouting notes, Parent name/email/phone, consent text, override text), validation messages (name required, DOB format, email format, duplicate hint), template metadata (Log prospect, Invite prospect to test training, descriptions), invitation form labels (Choose or schedule, Existing upcoming session, New session date + time, Invitation message to the parent), validation messages (pick exactly one, write a short message), default invitation copy, REST error messages.

5 strings reuse existing entries (`First name`, `Last name`, `Identity`, `Date of birth`, `Location`).

## Affected files

- `database/migrations/0068_workflow_tasks_prospect_id.php` — new column + index.
- `src/Modules/Workflow/TaskContext.php` — `prospect_id` field.
- `src/Modules/Workflow/Chain/ChainStep.php` — `inheritContext()` propagates `prospect_id`.
- `src/Modules/Workflow/Repositories/TasksRepository.php` — `create()` accepts `prospect_id`.
- `src/Modules/Workflow/Templates/LogProspectTemplate.php` — new.
- `src/Modules/Workflow/Templates/InviteToTestTrainingTemplate.php` — new.
- `src/Modules/Workflow/Forms/LogProspectForm.php` — new.
- `src/Modules/Workflow/Forms/InviteToTestTrainingForm.php` — new.
- `src/Modules/Workflow/WorkflowModule.php` — registers the two new templates.
- `src/Modules/Prospects/Rest/ProspectsRestController.php` — new.
- `src/Modules/Prospects/ProspectsModule.php` — initialises the REST controller in `boot()`.
- `src/Modules/Prospects/Cron/ProspectRetentionCron.php` — workflow-task join in the stale query.
- `src/Modules/Authorization/LegacyCapMapper.php` — new `tt_invite_prospects` cap.
- `languages/talenttrack-nl_NL.po` — 30 new NL strings.
- `readme.txt`, `talenttrack.php`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

