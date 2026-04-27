<!-- audience: admin -->

# Modules (admin guide)

**TalentTrack → Access Control → Modules**

Each TalentTrack module can be turned off here. Disabled modules don't `register()` or `boot()` — their tiles, REST routes, admin pages, and capabilities all silently disappear until re-enabled. The toggle is per-install, so a multi-tenant deployment would need a separate per-tenant flag (deferred to v2 of #0011).

## Why turn a module off?

- **Demo to a non-paying prospect.** Disable License so the upgrade banner stays out of the way.
- **Pre-launch dev.** Disable Backup until the cron job is configured on the host.
- **Per-club product surface.** A youth club doesn't run a Methodology, so the Methodology tab clutters their setup.
- **Feature debug.** A new admin needs the Workflow tab disabled while they figure out the rest of the product.

## What the toggle actually does

When a module is disabled, **on the next page load**:

- `Kernel::loadModules()` skips the class entirely — `register()` + `boot()` never run.
- Hooks, REST routes, capability declarations, scheduled events the module owns — all silently absent.
- **Frontend dashboard tiles** the module owns disappear from the user's tile grid.
- **wp-admin sidebar entries** the module owns disappear from the menu, and their direct URLs stop resolving.
- **wp-admin dashboard tiles + stat cards** for the module's entity hide.
- A user who lands on `?tt_view=<slug>` for a disabled module's surface (bookmarked link, stale tab) sees a friendly "this section is currently unavailable" notice with a back button — not a 404 or fatal.
- `MatrixGate::can()` short-circuits any matrix row whose `module_class` is the disabled module — even if a persona has the permission, the entity is unreachable. One auth check, no parallel "is this on?" branch.
- Existing data rows in the module's tables are **untouched** — turning the module back on later restores access to all historical data.

## Always-on modules

Three modules cannot be disabled. Their toggle renders inert with a tooltip:

| Module | Why |
| - | - |
| `Auth` | Login + logout. The product is unreachable without it. |
| `Configuration` | The settings table + lookups. Most other modules read from `tt_config`. |
| `Authorization` | The matrix itself. Disabling it would lock everyone out of the toggle. |

## License module — special case

The License module's toggle ships **enabled by default** + with an inline warning when disabled:

> ⚠️ **Don't forget to implement the gate before going live.**
> Disabling License removes all monetization checks (`LicenseGate::*`).
> Pre-launch this is fine for demos and dev. Before public launch,
> either hardcode `LicenseModule` enabled or implement a `TT_DEV_MODE`
> constant that disables this toggle in production.

The warning is intentional. Right now (pre-monetization-launch) the runtime toggle is the easy path; once the product is live, the toggle becomes a hard gate that needs constant-driven enforcement so a malicious admin can't switch it off to escape billing.

## Dependencies between modules

**Not yet enforced.** Disabling a module that another module depends on may break the dependent silently. Examples:

- `WorkflowModule` builds task templates that reference `EvaluationsModule` entities. Disabling Evaluations leaves Workflow templates pointing at nothing — they no-op gracefully but render confusingly.
- `InvitationsModule` writes to `tt_player_parents` (introduced by #0032). Disabling Players leaves the pivot referencing dead foreign keys.

A dependency graph + warning UI is on the v2 roadmap for the Modules surface.

## Audit

Every module-state change writes a row to `tt_module_state` with the `updated_by` user id and timestamp. Until #0021 ships and the audit log viewer surfaces this, the row is the only trail.

## See also

- [Authorization matrix](authorization-matrix.md) — module disable feeds into the matrix gate.
- [Access control](access-control.md) — the broader role + capability model.
