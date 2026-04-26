<!-- audience: dev -->

# Hooks & filters

Every action and filter the plugin exposes for extension. Names are prefixed `tt_*` to keep the namespace clean. Adding a new hook? Append a row here in the same PR.

## Actions (`do_action`)

| Hook                                     | When it fires                                                                  | Args                                       |
| ---                                      | ---                                                                            | ---                                        |
| `tt_after_player_save`                   | After a player row is created or updated via wp-admin or REST                  | `int $player_id`, `array $data`            |
| `tt_before_save_evaluation`              | Just before an evaluation row is written; mutate the payload via the filter pair below | `array $payload`                  |
| `tt_person_assigned_to_team`             | After a person (staff) row gets a team assignment                              | `int $person_id`, `int $team_id`           |
| `tt_functional_role_mapping_updated`     | After a functional-role mapping is created / removed                           | `int $assignment_id`, `array $changes`     |
| `tt_onboarding_step_completed`           | At the end of each setup wizard step                                           | `string $step_key`, `array $context`       |
| `tt_onboarding_completed`                | Wizard finished                                                                | `array $summary`                           |
| `tt_onboarding_reset`                    | Wizard re-entered via `?force_welcome=1`                                       | (none)                                     |
| `tt_license_trial_started`               | A trial activation succeeds                                                    | `int $user_id`, `int $expires_at`          |
| `tt_freemius_sdk_booted`                 | The optional Freemius SDK has finished loading                                  | (none)                                     |

## Filters (`apply_filters`)

| Hook                                  | What it filters                                                          | Args                                                |
| ---                                   | ---                                                                      | ---                                                 |
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
