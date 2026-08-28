<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FeatureMap — tier → feature mapping.
 *
 * **DEFAULT_MAP** below is the source of truth, and ships in code.
 * Inheritance: pro inherits standard inherits free.
 *
 * Customers cannot edit it. Changing which features sit in which tier
 * is a release, not a runtime toggle — the runtime-override path that
 * once synced this from a marketplace dashboard went with Freemius.
 *
 * ## Two tiers, not three (#2922)
 *
 * The map used to describe a three-tier self-hosted product. Under
 * managed hosting (#2920) a club either pays for a subdomain or has no
 * install, so **a Free install is not a thing that can exist**. There are
 * two tiers anyone is sold: `standard` and `pro`.
 *
 * `TIER_FREE` survives as the **unentitled** state — the answer for an
 * install whose entitlement never arrived or has aged past its grace
 * window. It is deliberately thin. It is not a product, and nobody is
 * sold it.
 *
 * The public demo is **not** this tier. A demo that cannot show the
 * product is worthless, so the demo subdomain is provisioned with a Pro
 * entitlement and the `FreeTierCaps` limits (1 team, 25 players) applied
 * on top. That is a provisioning decision, recorded in
 * `docs/license-and-account.md`, not a tier in this file.
 *
 * ## The split, and the two rules that decided it
 *
 * **1. Safeguarding is never a tier.** The audit log, the permission
 * matrix, MFA, record deletion, the recycle bin, media consent and
 * subject access sit in every tier including the unentitled one. Selling
 * child-data safety as an upsell is not something this product does, and
 * an unentitled install must still be able to answer a safeguarding
 * question about data it already holds.
 *
 * **2. Then operator cost, then build weight.** Anything that costs money
 * to run under hosting — media storage, video, outbound sends, third-party
 * sync — is Pro regardless of how cheap it was to build. After that, the
 * 2026 epics that carry the build weight.
 *
 * **Standard is the academy product, not a crippled tier.** A club on
 * Standard runs a real academy: players, teams, people, evaluations, PDP,
 * goals, journey, trials, prospects, activities, attendance, minutes,
 * measurements, reports, methodology, planning, season rollover, the
 * persona dashboards. Pro is what 2026 added on top of that.
 *
 * ## Two axes, and why they are not the same list
 *
 * `Core\FeatureRegistry` is the **operator switchability** axis — what
 * this club has chosen to turn on. `FeatureMap` is the **paid tier** axis
 * — what this club is entitled to. A club can switch off something it
 * pays for; it cannot switch on something it does not. The two share key
 * names here where they describe the same surface, which makes them
 * readable side by side, but they answer different questions and neither
 * derives from the other. `docs/modules.md` says so at greater length.
 */
class FeatureMap {

    public const TIER_FREE     = 'free';
    public const TIER_STANDARD = 'standard';
    public const TIER_PRO      = 'pro';

    /**
     * @var array<string, array<string,bool>>
     */
    public const DEFAULT_MAP = [
        /*
         * UNENTITLED — no entitlement recorded, or one that aged out.
         *
         * Enough to read what the academy already has and to meet a
         * safeguarding obligation, and nothing else. Not a product.
         */
        self::TIER_FREE => [
            // Rule 1: safeguarding is never gated, at any tier.
            'audit_log'            => true,
            'authorization_matrix' => true,
            'mfa'                  => true,
            'record_deletion'      => true,
            'recycle_bin'          => true,
            'impersonation_log'    => true,
            'media_consent'        => true,
            'subject_access'       => true,

            // Enough to see the data and get it out. An academy that
            // stops paying does not lose the ability to read or export
            // its own records — holding a club's player data hostage is
            // not a commercial lever this product uses.
            'core_dashboard'       => true,
            'core_player_card'     => true,
            'backup_local'         => true,
            'backup_email'         => true,
            'exports_basic'        => true,

            // Setup, so a newly provisioned install works before its
            // entitlement lands.
            'onboarding'           => true,
            'demo_data'            => true,
        ],

        /*
         * STANDARD — the academy product.
         *
         * Everything a club needs to track a player from trial to
         * graduation. Inherits the unentitled set above.
         */
        self::TIER_STANDARD => [
            // The player spine.
            'core_players'             => true,
            'core_teams'               => true,
            'core_people'              => true,
            'core_evaluations'         => true,
            'core_goals'               => true,
            'core_attendance'          => true,
            // Renamed from `core_sessions` — the product says activities,
            // and #0035 gates the old word. No call site used the key.
            'core_activities'          => true,
            'player_journey'           => true,
            'player_pdp'               => true,
            'player_team'              => true,
            'player_evaluations'       => true,
            'player_activities'        => true,
            'player_goals'             => true,
            'player_status'            => true,
            'behaviour_rating'         => true,
            'journey_medical_visibility' => true,
            'measurements'             => true,
            'season_rollover'          => true,

            // Intake.
            'trial_module'             => true,
            'prospects'                => true,
            'onboarding_pipeline_workflow' => true,
            'scout_access'             => true,

            // Reading it back. Radar, comparison and rate cards were
            // Standard before and stay there — they are how a coach
            // reads an evaluation, not an analytics platform.
            'radar_charts'             => true,
            'player_comparison'        => true,
            'analytics_player_compare' => true,
            'rate_cards_full'          => true,
            'reports_standard'         => true,
            'cohort_transitions'       => true,
            'analytics_cohort_board'   => true,
            'analytics_eval_coverage'  => true,
            'analytics_podium'         => true,
            'persona_dashboard'        => true,

            // Working the squad.
            'methodology'              => true,
            'planning_calendar_view'   => true,
            'pdp_calendar_integration' => true,
            'holidays'                 => true,
            'staff_development'        => true,
            'team_development'         => true,
            'vct'                      => true,

            // Getting data in and out.
            'csv_import'               => true,
            'excel_import'             => true,
            'import_history'           => true,
            'functional_roles'         => true,
            'partial_restore'          => true,
            'undo_bulk'                => true,
            'data_browser'             => true,
            'seed_review'              => true,
            'translations'             => true,

            // Making it feel like the club's.
            'branding'                 => true,
            'custom_css'               => true,
            'custom_fields'            => true,

            // Talking to people, on the transactional paths a club
            // cannot operate without — invitations and account mail.
            'invitations'              => true,
            'threads'                  => true,
            'alerts'                   => true,
            'workflow'                 => true,
        ],

        /*
         * PRO — the 2026 layer, and everything with a running cost.
         *
         * Inherits Standard.
         */
        self::TIER_PRO => [
            // Match day. The largest 2026 build, and the surfaces a club
            // buying "the analysis product" is buying.
            'match_analysis'              => true,
            'match_analysis_sharing'      => true,
            'match_prep'                  => true,
            'match_prep_sharing'          => true,
            'match_execution'             => true,
            'export_match_analysis_pdf'   => true,
            'export_match_prep_pdf'       => true,
            'export_match_day_team_sheet' => true,
            'tournaments'                 => true,
            'tournaments_auto_balance'    => true,

            // Training. Plans, the exercise library, and the per-player
            // exposure that hangs off them.
            'training'                    => true,
            'exercises'                   => true,
            'exercises_vision_extraction' => true,

            // Rule 2 — storage and bandwidth cost the operator money.
            'media'                       => true,
            's3_backup'                   => true,

            // The analytics platform, as distinct from reading a report.
            'analytics_explorer'          => true,
            'scheduled_reports'           => true,
            'custom_widgets'              => true,
            'persona_dashboard_editor'    => true,

            // Rule 2 — every send has a per-message cost.
            'comms_scheduled_sends'       => true,
            'comms_sms_channel'           => true,
            'push_notifications'          => true,

            // Rule 2 — third-party sync the operator runs and supports.
            'spond_integration'           => true,
            'strava_integration'          => true,

            // Coach development.
            'knowledge_courses'           => true,

            // Squad construction.
            'team_chemistry'              => true,
            'team_blueprints_sharing'     => true,

            // Grids — desktop bulk-entry surfaces, above the single-record
            // capture every tier gets.
            'attendance_grid'             => true,
            'minutes_grid'                => true,
            'ratings_grid'                => true,

            // Retired: `multi_academy` and `photo_session`. Multi-academy
            // is what hosting *is* now — one install per club — so it
            // cannot be a feature to sell. `photo_session` was superseded
            // by the media library and never had a gate call site.
        ],
    ];

    /**
     * Resolve whether a tier has a feature, applying inheritance.
     */
    public static function tierHas( string $tier, string $feature ): bool {
        $tier = self::normalizeTier( $tier );

        // The `?? []` these three lines used to carry is gone: with every
        // tier's key now written out above, PHPStan can see the shape and
        // reads the coalesce as dead. A missing tier key is a fatal typo in
        // this file, not a runtime case to absorb.
        $effective = self::DEFAULT_MAP[ self::TIER_FREE ];
        if ( $tier === self::TIER_STANDARD || $tier === self::TIER_PRO ) {
            $effective = array_merge( $effective, self::DEFAULT_MAP[ self::TIER_STANDARD ] );
        }
        if ( $tier === self::TIER_PRO ) {
            $effective = array_merge( $effective, self::DEFAULT_MAP[ self::TIER_PRO ] );
        }
        return ! empty( $effective[ $feature ] );
    }

    /**
     * Every feature key the map knows, across all tiers.
     *
     * Used by the account page's tier matrix and by the drift test, which
     * is what stops a Pro feature being added here and quietly never
     * getting a gate.
     *
     * @return string[]
     */
    public static function allFeatures(): array {
        $keys = [];
        foreach ( self::DEFAULT_MAP as $features ) {
            $keys = array_merge( $keys, array_keys( $features ) );
        }
        $keys = array_values( array_unique( $keys ) );
        sort( $keys );
        return $keys;
    }

    /**
     * The lowest tier that grants `$feature`, or null when no tier does.
     */
    public static function tierFor( string $feature ): ?string {
        foreach ( self::tiers() as $tier ) {
            if ( ! empty( self::DEFAULT_MAP[ $tier ][ $feature ] ) ) return $tier;
        }
        return null;
    }

    /**
     * @return string[]
     */
    public static function tiers(): array {
        return [ self::TIER_FREE, self::TIER_STANDARD, self::TIER_PRO ];
    }

    /**
     * The two tiers a club can actually buy.
     *
     * `free` is the unentitled state, not a product, so a pricing table
     * or an upgrade prompt should read from here rather than `tiers()`.
     *
     * @return string[]
     */
    public static function sellableTiers(): array {
        return [ self::TIER_STANDARD, self::TIER_PRO ];
    }

    public static function tierLabel( string $tier ): string {
        switch ( self::normalizeTier( $tier ) ) {
            case self::TIER_PRO:      return __( 'Pro',      'talenttrack' );
            case self::TIER_STANDARD: return __( 'Standard', 'talenttrack' );
            // Not "Free" — nobody is on a free plan. This is the state an
            // install is in when its entitlement has not arrived or has
            // lapsed, and the label has to say that rather than imply a
            // product the club chose.
            default:                  return __( 'Not activated', 'talenttrack' );
        }
    }

    public static function normalizeTier( string $tier ): string {
        $tier = strtolower( trim( $tier ) );
        return in_array( $tier, [ self::TIER_FREE, self::TIER_STANDARD, self::TIER_PRO ], true )
            ? $tier
            : self::TIER_FREE;
    }
}
