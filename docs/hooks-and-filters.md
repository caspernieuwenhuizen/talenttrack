---
title: Hooks & filters
group: developer
summary: Every action and filter the plugin exposes for extension.
audience: [dev]
order: 20
---

# Hooks & filters

Every action and filter the plugin exposes for extension. Names are prefixed `tt_*` to keep the namespace clean. Adding a new hook? Append a row here in the same PR.

## Actions (`do_action`)

| Hook                                     | When it fires                                                                  | Args                                       |
| ---                                      | ---                                                                            | ---                                        |
| `tt_after_player_save`                   | After a player row is created or updated via wp-admin or REST                  | `int $player_id`, `array $data`            |
| `tt_before_save_evaluation`              | Just before an evaluation row is written; mutate the payload via the filter pair below | `array $payload`                  |
| `tt_evaluation_saved`                    | After an evaluation is written, from REST and from the evaluation wizard alike | `int $player_id`, `int $evaluation_id`     |
| `tt_person_assigned_to_team`             | After a person (staff) row gets a team assignment                              | `int $team_id`, `int $person_id`, `string $role_key`, `int $functional_role_id` |
| `tt_team_created`                        | After a team row is created, on both create paths — the new-team wizard's review step and `POST /teams`. Fires after the wizard's staff assignments are written, so a subscriber reading `tt_team_people` sees the finished team rather than a bare row. Not fired on update or restore | `int $team_id` |
| `tt_activity_saved`                      | After an activity row is created or updated. Any edit — distinct from `tt_activity_completed`, which is one transition | `int $activity_id`, `array $data` |
| `tt_activity_attendance_changed`         | After attendance rows for an activity are created, changed or removed. Says nothing about the new contents; re-read if you care | `int $activity_id` |
| `tt_activity_deleted`                    | After an activity row is hard-deleted, on both delete paths (wp-admin and the recycle bin's purge). Not fired on archive or trash — those keep the row. Subscribers clear their references to a row that is already gone. `$context` carries whatever `tt_activity_delete_context` collected before the delete, for references the delete itself erased | `int $activity_id`, `array $context` |
| `tt_invitation_sent`                     | After a held invitation is delivered and `sent_at` is stamped. Distinct from `tt_invitation_created`, which fires whether or not anybody was mailed | `int $invitation_id` |
| `tt_measurement_result_saved`            | After a measurement result is created, edited or archived                      | `int $result_id`, `int $player_id`         |
| `tt_staff_certification_saved`           | After a staff certificate is recorded, renewed or archived                     | `int $certification_id`, `int $person_id`  |
| `tt_pdp_conversation_saved`              | After a conversation in a PDP cycle is updated (including being marked conducted) | `int $conversation_id`, `int $pdp_file_id` |
| `tt_prospect_consent_outcome_recorded`   | After a prospect consent request is logged or its outcome changed. Fired from the repository, so the REST route, the workflow form and the demo generator all announce; fires on any outcome, `awaiting` included | `int $prospect_id`, `int $entry_id`, `string $outcome` |
| `tt_functional_role_mapping_updated`     | After a functional-role mapping is created / removed                           | `int $assignment_id`, `array $changes`     |
| `tt_onboarding_step_completed`           | At the end of each setup wizard step                                           | `string $step_key`, `array $context`       |
| `tt_onboarding_completed`                | Wizard finished                                                                | `array $summary`                           |
| `tt_onboarding_reset`                    | Wizard re-entered via `?force_welcome=1`                                       | (none)                                     |
| `tt_license_trial_started`               | A trial activation succeeds                                                    | `int $user_id`, `int $expires_at`          |
| `tt_freemius_sdk_booted`                 | The optional Freemius SDK has finished loading                                  | (none)                                     |

## Filters (`apply_filters`)

| Hook                                  | What it filters                                                          | Args                                                |
| ---                                   | ---                                                                      | ---                                                 |
| `tt_activity_delete_context`          | References to an activity that is about to be hard-deleted, collected before any write and handed to `tt_activity_deleted`. Add only what the delete itself destroys — a cascade that nulls your foreign key leaves nothing to look up afterwards | `array $context`, `int $activity_id` |
| `tt_dashboard_data`                   | The data array passed to the dashboard renderer                          | `array $data`                                       |
| `tt_modify_categories`                | The evaluation category list before render                               | `array $categories`, `int $player_id`               |
| `tt_auth_check`                       | Authorization check entry point — return `true`/`false` to override     | `bool $allow`, `string $cap`, `int|null $entity_id` |
| `tt_auth_check_result`                | The final allow/deny after all internal checks                           | `bool $result`, `array $context`                    |
| `tt_auth_resolve_permissions`         | Resolve a user's effective permissions array                             | `array $perms`, `int $user_id`                      |
| `tt_free_tier_cap_players`            | Free-tier cap on player count                                            | `int $cap`                                          |
| `tt_free_tier_cap_teams`              | Free-tier cap on team count                                              | `int $cap`                                          |
| `tt_backup_bulk_safety_threshold`     | Number of rows that triggers the bulk-restore confirm dialog             | `int $threshold`                                    |
| `tt_freemius_is_trial`                | Override Freemius's "is this user on a trial" answer                     | `bool $is_trial`                                    |
| `tt_freemius_plan_slug`               | Override Freemius's reported plan slug                                   | `string $slug`                                      |
| `tt_register_alerts`                  | The alert definitions the engine runs; append your own                   | `array $alerts`                                     |
| `tt_alert_invalidation_map`           | Domain event => extractor naming what that event touched, so a fixed alert clears on save instead of on the hourly sweep | `array<string,callable> $map` |
| `tt_parent_account_eligible_args`     | The `get_users()` arguments for accounts that may be linked as a parent. WordPress users are site-wide, so a multi-tenant install scopes the candidates to one club here | `array $args`, `int $club_id` |

### `tt_alert_invalidation_map`

An extractor receives the hook's own arguments and returns either one
`[ $subject_type, $ids ]` pair or a list of them. `$subject_type` must match
the `subjectType()` of the alert definitions it should re-check.

```php
add_filter( 'tt_alert_invalidation_map', function ( array $map ): array {
    $map['tt_my_thing_saved'] = fn( $id ) => [ 'my_thing', [ (int) $id ] ];
    return $map;
} );
```

Extractors must be cheap — they run inside the save request and their only
job is to name ids. The re-evaluation itself happens on `shutdown`, after
the response has been sent. An extractor that throws is logged and skipped;
it can never break the save it was listening to.

## Conventions

- All hook names are prefixed `tt_` to avoid collisions with WordPress core.
- Action handlers do not return values; their return is ignored.
- Filter handlers must return the modified value. Guard against unexpected types — if you change the shape, document it here.
- Hooks fired inside a transaction (e.g. mid-evaluation save) should be free of side effects that depend on the row already existing in the DB. Use `tt_after_*` actions for "row is committed" notifications.

## Adding a new hook

1. Pick a name. Format: `tt_<resource>_<event>` for actions, `tt_<area>_<verb>` for filters.
2. Document it in this file with the args list and intent.
3. If the hook lives inside a long-running flow (e.g. a save path), prefer firing it after the DB write so external listeners can re-read the row safely.
4. Don't fire hooks inside loops without thinking about cost — a single `tt_after_player_save` per save is fine; one per row in a 1000-row import isn't.
