<?php
namespace TT\Modules\PersonaDashboard\Defaults;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\GridLayout;
use TT\Modules\PersonaDashboard\Domain\PersonaTemplate;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Registry\PersonaTemplateRegistry;

/**
 * CoreTemplates — registers the 8 ship-default per-persona templates.
 *
 * Maps the April 2026 persona research ('Documents/talenttrack-design-brief.md')
 * onto the codebase persona slugs from the Authorization\PersonaResolver.
 * Coach is split into head_coach + assistant_coach but shares one
 * template; what they see is filtered at data-fetch time by team
 * assignments.
 *
 * Each template returns hero (XL widget rendered above the grid),
 * optional task band (L/XL widget between hero and grid), and a 12-col
 * bento grid of navigation/KPI/info widgets.
 */
final class CoreTemplates {

    public static function register(): void {
        PersonaTemplateRegistry::registerDefault( 'player',              [ self::class, 'player' ] );
        PersonaTemplateRegistry::registerDefault( 'parent',              [ self::class, 'parent' ] );
        PersonaTemplateRegistry::registerDefault( 'head_coach',          [ self::class, 'coach' ] );
        PersonaTemplateRegistry::registerDefault( 'assistant_coach',     [ self::class, 'coach' ] );
        PersonaTemplateRegistry::registerDefault( 'head_of_development', [ self::class, 'headOfDevelopment' ] );
        PersonaTemplateRegistry::registerDefault( 'scout',               [ self::class, 'scout' ] );
        PersonaTemplateRegistry::registerDefault( 'team_manager',        [ self::class, 'teamManager' ] );
        PersonaTemplateRegistry::registerDefault( 'academy_admin',       [ self::class, 'academyAdmin' ] );
    }

    public static function player( int $club_id ): PersonaTemplate {
        // Player tile order from the design brief:
        // My journey · My card · My team · My evaluations · My activities · My goals · My PDP · My profile.
        $tiles = [
            [ 'my-journey',      __( 'My journey',     'talenttrack' ), 1 ],
            [ 'overview',        __( 'My card',        'talenttrack' ), 2 ],
            [ 'my-team',         __( 'My team',        'talenttrack' ), 3 ],
            [ 'my-evaluations',  __( 'My evaluations', 'talenttrack' ), 4 ],
            [ 'my-activities',   __( 'My activities',  'talenttrack' ), 5 ],
            [ 'my-goals',        __( 'My goals',       'talenttrack' ), 6 ],
            [ 'my-pdp',          __( 'My PDP',         'talenttrack' ), 7 ],
            [ 'profile',         __( 'My profile',     'talenttrack' ), 8 ],
        ];
        $grid = new GridLayout();
        foreach ( $tiles as $i => [ $slug, $label, $priority ] ) {
            $col = ( $i % 4 ) * 3;
            $row = (int) floor( $i / 4 );
            $grid->add( new WidgetSlot(
                'navigation_tile', $slug, Size::S, $col, $row, 1, $priority + 10, true, $label
            ) );
        }
        return new PersonaTemplate(
            'player',
            $club_id,
            new WidgetSlot( 'rate_card_hero', '', Size::XL, 0, 0, 2, 1 ),
            new WidgetSlot( 'info_card', 'coach_nudge', Size::L, 0, 0, 1, 5 ),
            $grid
        );
    }

    public static function parent( int $club_id ): PersonaTemplate {
        $tiles = [
            [ 'overview',       __( "My child's card",  'talenttrack' ), 11 ],
            [ 'my-evaluations', __( 'Evaluations',     'talenttrack' ), 12 ],
            [ 'my-activities',  __( 'Activities',      'talenttrack' ), 13 ],
            [ 'my-pdp',         __( 'PDP',             'talenttrack' ), 14 ],
        ];
        $grid = new GridLayout();
        foreach ( $tiles as $i => [ $slug, $label, $priority ] ) {
            $col = ( $i % 4 ) * 3;
            $grid->add( new WidgetSlot(
                'navigation_tile', $slug, Size::S, $col, 0, 1, $priority, true, $label
            ) );
        }
        return new PersonaTemplate(
            'parent',
            $club_id,
            new WidgetSlot( 'child_switcher_with_recap', '', Size::XL, 0, 0, 2, 1 ),
            new WidgetSlot( 'info_card', 'pending_pdp_ack', Size::L, 0, 0, 1, 5 ),
            $grid
        );
    }

    public static function coach( int $club_id ): PersonaTemplate {
        $grid = new GridLayout();
        // Row 0: workflow tasks (L) + recent evaluations rail (M).
        $grid->add( new WidgetSlot( 'task_list_panel',  '',                     Size::L, 0, 0, 2, 10 ) );
        $grid->add( new WidgetSlot( 'mini_player_list', 'recent_evaluations',   Size::M, 9, 0, 2, 15 ) );
        // Row 1: my evals KPI + my open tasks KPI + my team attendance KPI + my team rating KPI.
        $coach_kpis = [
            'my_evaluations_this_week',
            'my_open_workflow_tasks',
            'my_team_attendance_pct',
            'my_team_avg_rating',
        ];
        foreach ( $coach_kpis as $i => $kpi_id ) {
            $grid->add( new WidgetSlot( 'kpi_card', $kpi_id, Size::S, $i * 3, 2, 1, 20 + $i ) );
        }
        // Row 2: navigation tiles for primary coach surfaces.
        $tiles = [
            [ 'activities',   __( 'Activities',  'talenttrack' ), 30 ],
            [ 'evaluations',  __( 'Evaluations', 'talenttrack' ), 31 ],
            [ 'goals',        __( 'Goals',       'talenttrack' ), 32 ],
            [ 'players',      __( 'My players',  'talenttrack' ), 33 ],
            [ 'teams',        __( 'My teams',    'talenttrack' ), 34 ],
            [ 'pdp',          __( 'PDP',         'talenttrack' ), 35 ],
            [ 'methodology',  __( 'Methodology', 'talenttrack' ), 36 ],
            [ 'my-tasks',     __( 'My tasks',    'talenttrack' ), 37 ],
        ];
        foreach ( $tiles as $i => [ $slug, $label, $priority ] ) {
            $col = ( $i % 4 ) * 3;
            $row = 3 + (int) floor( $i / 4 );
            $grid->add( new WidgetSlot(
                'navigation_tile', $slug, Size::S, $col, $row, 1, $priority, true, $label
            ) );
        }
        // Row 5: quick actions panel.
        $grid->add( new WidgetSlot(
            'quick_actions_panel',
            'new_evaluation,new_goal,new_activity,new_player',
            Size::M, 0, 5, 1, 40
        ) );
        // v3.110.69 (#0092) — hero is the new mark-attendance entry
        // point. Replaces `today_up_next_hero` whose "Attendance" CTA
        // dropped the coach on the activities list rather than the
        // upcoming activity's roster. The old widget stays registered
        // for back-compat so any custom template that pinned it
        // explicitly keeps working.
        return new PersonaTemplate(
            'head_coach',
            $club_id,
            new WidgetSlot( 'mark_attendance_hero', '', Size::XL, 0, 0, 2, 1 ),
            null,
            $grid
        );
    }

    public static function headOfDevelopment( int $club_id ): PersonaTemplate {
        // #0073 — activity-first landing. KPI strip stays at the top; team
        // overview grid takes the prime real estate; new-trial action sits
        // in the right gutter; upcoming activities + trials-needing-decision
        // tables stack below; navigation tiles drop to the bottom.
        $grid = new GridLayout();
        // Rows 0-2: team overview grid (L, 9 cols) + new-trial action (S, 3 cols, right gutter).
        $grid->add( new WidgetSlot( 'team_overview_grid', 'days=30,sort=concern_first', Size::L, 0, 0, 3, 5 ) );
        $grid->add( new WidgetSlot( 'action_card',        'new_trial',                  Size::S, 9, 0, 1, 6 ) );
        // Row 3: upcoming activities table (XL, full width, 2 rows).
        $grid->add( new WidgetSlot( 'data_table', 'upcoming_activities', Size::XL, 0, 3, 2, 8 ) );
        // Row 5: trials needing decision (existing, moved down).
        $grid->add( new WidgetSlot( 'data_table', 'trials_needing_decision', Size::XL, 0, 5, 2, 12 ) );
        // Row 7+: navigation tiles. v3.80.1 — expanded the curated set
        // after operator feedback that HoD only saw a handful. HoD holds
        // caps on every tile listed here; classic-tile-grid mode shows
        // the same set via TileRegistry filtering.
        $tiles = [
            // Day-to-day work (top row)
            [ 'trials',         __( 'Trials',           'talenttrack' ), 20 ],
            [ 'pdp',            __( 'PDP',              'talenttrack' ), 21 ],
            [ 'players',        __( 'Players',          'talenttrack' ), 22 ],
            [ 'teams',          __( 'Teams',            'talenttrack' ), 23 ],
            // Master-data + analytics
            [ 'people',         __( 'People',           'talenttrack' ), 24 ],
            [ 'evaluations',    __( 'Evaluations',      'talenttrack' ), 25 ],
            [ 'goals',          __( 'Goals',            'talenttrack' ), 26 ],
            [ 'activities',     __( 'Activities',       'talenttrack' ), 27 ],
            // Methodology + planning
            [ 'methodology',    __( 'Methodology',      'talenttrack' ), 28 ],
            [ 'pdp-planning',   __( 'PDP planning',     'talenttrack' ), 29 ],
            [ 'team-chemistry', __( 'Team chemistry',   'talenttrack' ), 30 ],
            [ 'podium',         __( 'Podium',           'talenttrack' ), 31 ],
            // Reports + tooling
            [ 'rate-cards',     __( 'Rate cards',       'talenttrack' ), 32 ],
            [ 'compare',        __( 'Compare players',  'talenttrack' ), 33 ],
            [ 'reports',        __( 'Reports',          'talenttrack' ), 34 ],
            [ 'tasks-dashboard',__( 'Tasks dashboard',  'talenttrack' ), 35 ],
            // Governance
            [ 'functional-roles', __( 'Functional roles', 'talenttrack' ), 36 ],
            [ 'audit-log',      __( 'Audit log',        'talenttrack' ), 37 ],
        ];
        foreach ( $tiles as $i => [ $slug, $label, $priority ] ) {
            $col = ( $i % 4 ) * 3;
            $row = 7 + (int) floor( $i / 4 );
            $grid->add( new WidgetSlot(
                'navigation_tile', $slug, Size::S, $col, $row, 1, $priority, true, $label
            ) );
        }
        return new PersonaTemplate(
            'head_of_development',
            $club_id,
            new WidgetSlot(
                'kpi_strip',
                'active_players_total,evaluations_this_month,attendance_pct_rolling,open_trial_cases,pdp_verdicts_pending,goal_completion_pct',
                Size::XL, 0, 0, 1, 1
            ),
            null,
            $grid
        );
    }

    public static function scout( int $club_id ): PersonaTemplate {
        // v3.110.68 — rebuilt around the prospects funnel (#0081).
        //
        // Before: hero was `assigned_players_grid` (legacy from before
        // the prospects funnel existed), tile grid pointed at
        // `scout-history` and `scout-my-players` (old "report" model),
        // and the new-prospect wizard / onboarding pipeline weren't
        // surfaced anywhere on the scout's persona dashboard. Scouts
        // had to navigate to `?tt_view=onboarding-pipeline` first,
        // then click `+ New prospect`.
        //
        // After: hero is the `+ New prospect` launch tile (action #1
        // per `docs/scout-actions.md`, 5–15× per week during a
        // season). Row below is the onboarding pipeline (action #2,
        // daily glance). Legacy tiles stay further down for installs
        // that still use the report-history flow; nothing is removed.
        $grid = new GridLayout();
        $grid->add( new WidgetSlot(
            'onboarding_pipeline', '', Size::XL, 0, 0, 3, 5
        ) );
        $grid->add( new WidgetSlot(
            'navigation_tile', 'scout-history', Size::S, 0, 1, 1, 10, true, __( 'My reports', 'talenttrack' )
        ) );
        $grid->add( new WidgetSlot(
            'navigation_tile', 'scout-my-players', Size::S, 3, 1, 1, 11, true, __( 'My assigned players', 'talenttrack' )
        ) );
        $grid->add( new WidgetSlot(
            'data_table', 'recent_scout_reports', Size::XL, 0, 2, 2, 20
        ) );
        return new PersonaTemplate(
            'scout',
            $club_id,
            new WidgetSlot( 'add_prospect_hero', '', Size::XL, 0, 0, 3, 1 ),
            null,
            $grid
        );
    }

    public static function teamManager( int $club_id ): PersonaTemplate {
        // Team manager — close to coach but stripped of the eval-authoring
        // emphasis. Brief doesn't cover this persona explicitly so we
        // ship a sensible default the academy can override.
        $grid = new GridLayout();
        $tiles = [
            [ 'activities', __( 'Activities', 'talenttrack' ), 10 ],
            [ 'teams',      __( 'My teams',   'talenttrack' ), 11 ],
            [ 'players',    __( 'Players',    'talenttrack' ), 12 ],
            [ 'my-tasks',   __( 'My tasks',   'talenttrack' ), 13 ],
        ];
        foreach ( $tiles as $i => [ $slug, $label, $priority ] ) {
            $grid->add( new WidgetSlot(
                'navigation_tile', $slug, Size::S, $i * 3, 0, 1, $priority, true, $label
            ) );
        }
        return new PersonaTemplate(
            'team_manager',
            $club_id,
            new WidgetSlot( 'today_up_next_hero', '', Size::XL, 0, 0, 2, 1 ),
            null,
            $grid
        );
    }

    public static function academyAdmin( int $club_id ): PersonaTemplate {
        $grid = new GridLayout();
        $grid->add( new WidgetSlot( 'data_table', 'audit_log_recent', Size::XL, 0, 0, 2, 20 ) );
        $tiles = [
            [ 'configuration',     __( 'Configuration',      'talenttrack' ), 30 ],
            [ 'roles',             __( 'Authorization',      'talenttrack' ), 31 ],
            [ 'usage-stats',       __( 'Usage statistics',   'talenttrack' ), 32 ],
            [ 'audit-log',         __( 'Audit log',          'talenttrack' ), 33 ],
            [ 'invitations-config',__( 'Invitations',        'talenttrack' ), 34 ],
            [ 'migrations',        __( 'Migrations',         'talenttrack' ), 35 ],
            [ 'docs',              __( 'Help & docs',        'talenttrack' ), 36 ],
            [ 'methodology',       __( 'Methodology',        'talenttrack' ), 37 ],
        ];
        foreach ( $tiles as $i => [ $slug, $label, $priority ] ) {
            $col = ( $i % 4 ) * 3;
            $row = 2 + (int) floor( $i / 4 );
            $grid->add( new WidgetSlot(
                'navigation_tile', $slug, Size::S, $col, $row, 1, $priority, true, $label
            ) );
        }
        return new PersonaTemplate(
            'academy_admin',
            $club_id,
            new WidgetSlot( 'system_health_strip', '', Size::XL, 0, 0, 1, 1 ),
            null,
            $grid
        );
    }
}
