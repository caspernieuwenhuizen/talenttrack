<?php
namespace TT\Modules\Vct;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * VctModule — Voetbal Conditionele Training (#0095, epic #905).
 *
 * Phase 1 ships:
 *   - Schema (migration 0122): 11 tables (10 tt_vct_* + tt_player_phv_flags)
 *   - Capabilities (#907, v4.3.1): 3 matrix-only caps + bridges + matrix seed
 *   - Lookup vocabularies (#908, v4.3.2): 5 lookup_types with 5-locale translations
 *   - Reference seeds (#909, v4.3.3): age profiles, session templates, phase profiles
 *   - **This ship (#910, v4.3.5)**: Rules Engine + repositories + supporting services
 *
 * What this ship adds (deterministic core, no AI, age-safe by design):
 *   - 11 repositories under src/Modules/Vct/Repositories/ (one per table; every
 *     read filters by club_id = CurrentClub::id())
 *   - 8 rule passes + RulesEngine orchestrator with two entry points:
 *       compose()  — full pipeline including ExerciseSelectionPass
 *                    (POST /vct/sessions/generate uses this)
 *       validate() — passes 1-5 + 7-8 only; skips selection (PATCH uses this
 *                    so a coach's manual swap is validated but not overwritten)
 *   - SessionGenerator service: context build → compose → persist
 *   - WorkloadCalculator (pure function: session → total load)
 *   - MdContextResolver (date + team schedule + match calendar → MD label)
 *   - Provider interfaces: ActivitiesReader, RecentPicksProvider, VctPhvFlagsProvider
 *     (this-module-owned so VCT doesn't reach into Activities internals)
 *
 * Phase 2 (separate ships) wires REST (VCT-6 / #911), the nightly workload
 * aggregation task (VCT-7 / #912), the coach mobile UI + wizard, and the
 * optional AI presentation layer.
 *
 * No surfaces here register REST routes or workflow triggers yet — the
 * module's responsibility in this ship is to expose the deterministic
 * engine + repositories that the next two ships consume.
 */
class VctModule implements ModuleInterface {

    public function getName(): string { return 'vct'; }

    public function register( Container $container ): void {
        // Repositories + services are lazily instantiated by callers
        // for now (REST controllers in VCT-6 will instantiate the
        // SessionGenerator they need). No DI bindings required at
        // boot time; keeps the module dormant until something else
        // wakes it.
    }

    public function boot( Container $container ): void {
        // Intentionally empty in this ship. VCT-6 (#911) adds:
        //   - REST controller registration via `rest_api_init`
        //   - Wizard registration via WizardRegistry
        //   - `tt_activity_deleted` action handler for session-binding cleanup
        //
        // VCT-7 (#912) registers the workflow cron trigger here.
    }
}
