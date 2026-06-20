<?php
namespace TT\Shared\Modules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ModuleMetadata (#1536) — the single human-facing description of every
 * TalentTrack module.
 *
 * `ModuleInterface` carries no label, description, icon, or category — it
 * only knows how to register/boot. The Modules management page (and any
 * future SaaS surface) needs a presentation layer keyed by module class:
 * a readable label, a one-line description, an icon glyph from the
 * IconRenderer set, and a category for grouping cards. That map lives
 * here, deliberately as config/data — not on the interface — so adding a
 * module never forces an interface change and the view stays a pure
 * composer.
 *
 * The category list is fixed (see CATEGORY_ORDER). Every class declared in
 * `config/modules.php` MUST have an entry here; `for()` falls back to a
 * slugified class name + the Advanced/developer category for any module
 * that slips through un-described, so the page never shows a raw class
 * name where a human label is expected, and a missing entry is loud in
 * code review rather than silent at runtime.
 */
class ModuleMetadata {

    public const CAT_PLAYER       = 'player_data';
    public const CAT_COACHING     = 'coaching';
    public const CAT_PLANNING     = 'planning';
    public const CAT_COMMS        = 'communication';
    public const CAT_ANALYTICS    = 'analytics';
    public const CAT_INTEGRATIONS = 'integrations';
    public const CAT_ADMIN        = 'administration';
    public const CAT_ADVANCED     = 'advanced';

    /**
     * Canonical category order + their translated labels. Categories with
     * no modules are skipped at render time.
     *
     * @return array<string, string> category key => translated label
     */
    public static function categories(): array {
        return [
            self::CAT_PLAYER       => __( 'Player data', 'talenttrack' ),
            self::CAT_COACHING     => __( 'Coaching & development', 'talenttrack' ),
            self::CAT_PLANNING     => __( 'Planning & match day', 'talenttrack' ),
            self::CAT_COMMS        => __( 'Communication', 'talenttrack' ),
            self::CAT_ANALYTICS    => __( 'Analytics & reporting', 'talenttrack' ),
            self::CAT_INTEGRATIONS => __( 'Integrations', 'talenttrack' ),
            self::CAT_ADMIN        => __( 'Administration', 'talenttrack' ),
            self::CAT_ADVANCED     => __( 'Advanced / developer', 'talenttrack' ),
        ];
    }

    /**
     * The metadata map, keyed by fully-qualified module class. Labels and
     * descriptions are translatable; icons are IconRenderer glyph names
     * (assets/icons/<name>.svg); category is one of the CAT_* constants.
     *
     * @return array<string, array{label:string, description:string, icon:string, category:string}>
     */
    private static function map(): array {
        return [
            // — Administration (incl. the three always-on core modules) —
            'TT\\Modules\\Auth\\AuthModule' => [
                'label'       => __( 'Authentication', 'talenttrack' ),
                'description' => __( 'Sign-in, sign-out and login handling. The product is unreachable without it.', 'talenttrack' ),
                'icon'        => 'permission-debug',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Configuration\\ConfigurationModule' => [
                'label'       => __( 'Configuration', 'talenttrack' ),
                'description' => __( 'Academy settings, lookups and branding that most other modules read from.', 'talenttrack' ),
                'icon'        => 'settings',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Authorization\\AuthorizationModule' => [
                'label'       => __( 'Authorization', 'talenttrack' ),
                'description' => __( 'The permission matrix that decides who can see and do what across the academy.', 'talenttrack' ),
                'icon'        => 'roles',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\License\\LicenseModule' => [
                'label'       => __( 'Licensing', 'talenttrack' ),
                'description' => __( 'Subscription and monetization checks. Leave on for live academies.', 'talenttrack' ),
                'icon'        => 'rate-card',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Backup\\BackupModule' => [
                'label'       => __( 'Backup', 'talenttrack' ),
                'description' => __( 'Scheduled exports of academy data so nothing is lost between seasons.', 'talenttrack' ),
                'icon'        => 'import',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Invitations\\InvitationsModule' => [
                'label'       => __( 'Invitations', 'talenttrack' ),
                'description' => __( 'Invite coaches, players and parents to their own account in the academy.', 'talenttrack' ),
                'icon'        => 'invitation',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Onboarding\\OnboardingModule' => [
                'label'       => __( 'Onboarding', 'talenttrack' ),
                'description' => __( 'First-run setup guidance that helps a new academy get started quickly.', 'talenttrack' ),
                'icon'        => 'check',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Translations\\TranslationsModule' => [
                'label'       => __( 'Translations', 'talenttrack' ),
                'description' => __( 'Editable wording for the interface so each academy reads in its own language.', 'talenttrack' ),
                'icon'        => 'docs',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\I18n\\I18nModule' => [
                'label'       => __( 'Multilingual content', 'talenttrack' ),
                'description' => __( 'Translate academy data rows — lookups, categories and roles — into multiple languages.', 'talenttrack' ),
                'icon'        => 'docs',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\SeedReview\\SeedReviewModule' => [
                'label'       => __( 'Starter data review', 'talenttrack' ),
                'description' => __( 'Export, edit and re-import the starter lists shipped with a new academy.', 'talenttrack' ),
                'icon'        => 'import',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Mfa\\MfaModule' => [
                'label'       => __( 'Two-step verification', 'talenttrack' ),
                'description' => __( 'Optional extra sign-in check that protects accounts holding sensitive player data.', 'talenttrack' ),
                'icon'        => 'permission-debug',
                'category'    => self::CAT_ADMIN,
            ],
            'TT\\Modules\\Security\\SecurityModule' => [
                'label'       => __( 'Login security', 'talenttrack' ),
                'description' => __( 'Records failed sign-in attempts so suspicious activity is visible in the audit log.', 'talenttrack' ),
                'icon'        => 'warning',
                'category'    => self::CAT_ADMIN,
            ],

            // — Player data —
            'TT\\Modules\\Players\\PlayersModule' => [
                'label'       => __( 'Players', 'talenttrack' ),
                'description' => __( 'The player record at the centre of the academy — profile, contacts and history.', 'talenttrack' ),
                'icon'        => 'players',
                'category'    => self::CAT_PLAYER,
            ],
            'TT\\Modules\\Players\\PlayerStatusModule' => [
                'label'       => __( 'Player status', 'talenttrack' ),
                'description' => __( 'Tracks where each player stands — trialling, signed, on loan, released or graduated.', 'talenttrack' ),
                'icon'        => 'track',
                'category'    => self::CAT_PLAYER,
            ],
            'TT\\Modules\\People\\PeopleModule' => [
                'label'       => __( 'People', 'talenttrack' ),
                'description' => __( 'Coaches, scouts, physios and parents linked to the players they support.', 'talenttrack' ),
                'icon'        => 'people',
                'category'    => self::CAT_PLAYER,
            ],
            'TT\\Modules\\Teams\\TeamsModule' => [
                'label'       => __( 'Teams', 'talenttrack' ),
                'description' => __( 'Age-group squads players belong to, with rosters and staff assignments.', 'talenttrack' ),
                'icon'        => 'teams',
                'category'    => self::CAT_PLAYER,
            ],
            'TT\\Modules\\Journey\\JourneyModule' => [
                'label'       => __( 'Player journey', 'talenttrack' ),
                'description' => __( 'The chronological timeline of every player — trial, signing, injuries and key transitions.', 'talenttrack' ),
                'icon'        => 'track',
                'category'    => self::CAT_PLAYER,
            ],
            'TT\\Modules\\Trials\\TrialsModule' => [
                'label'       => __( 'Trials', 'talenttrack' ),
                'description' => __( 'Trials and outcomes for players being assessed before a signing decision.', 'talenttrack' ),
                'icon'        => 'check',
                'category'    => self::CAT_PLAYER,
            ],
            'TT\\Modules\\Prospects\\ProspectsModule' => [
                'label'       => __( 'Prospects', 'talenttrack' ),
                'description' => __( 'The recruitment pipeline of players being scouted before they enter the academy.', 'talenttrack' ),
                'icon'        => 'players',
                'category'    => self::CAT_PLAYER,
            ],

            // — Coaching & development —
            'TT\\Modules\\Evaluations\\EvaluationsModule' => [
                'label'       => __( 'Evaluations', 'talenttrack' ),
                'description' => __( 'Dated player assessments that build a picture of progress over months and seasons.', 'talenttrack' ),
                'icon'        => 'evaluations',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\Goals\\GoalsModule' => [
                'label'       => __( 'Goals', 'talenttrack' ),
                'description' => __( 'Individual development goals set for and with each player.', 'talenttrack' ),
                'icon'        => 'goals',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\Pdp\\PdpModule' => [
                'label'       => __( 'Personal development plans', 'talenttrack' ),
                'description' => __( 'Structured development plans that turn assessments and goals into a player roadmap.', 'talenttrack' ),
                'icon'        => 'track',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\Development\\DevelopmentModule' => [
                'label'       => __( 'Development insights', 'talenttrack' ),
                'description' => __( 'Cross-cutting development views that show where each player is heading next.', 'talenttrack' ),
                'icon'        => 'trend-up',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\Methodology\\MethodologyModule' => [
                'label'       => __( 'Methodology', 'talenttrack' ),
                'description' => __( 'The academy playing philosophy and curriculum that coaching is measured against.', 'talenttrack' ),
                'icon'        => 'methodology',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule' => [
                'label'       => __( 'Team development', 'talenttrack' ),
                'description' => __( 'Team blueprints and formation planning that frame how players develop together.', 'talenttrack' ),
                'icon'        => 'teams',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\StaffDevelopment\\StaffDevelopmentModule' => [
                'label'       => __( 'Staff development', 'talenttrack' ),
                'description' => __( 'Coach growth tracking, so the people developing players keep developing too.', 'talenttrack' ),
                'icon'        => 'people',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\Exercises\\ExercisesModule' => [
                'label'       => __( 'Exercises', 'talenttrack' ),
                'description' => __( 'A library of training exercises coaches draw on when planning training.', 'talenttrack' ),
                'icon'        => 'activities',
                'category'    => self::CAT_COACHING,
            ],
            'TT\\Modules\\Vct\\VctModule' => [
                'label'       => __( 'Conditioning training (VCT)', 'talenttrack' ),
                'description' => __( 'Age-aware conditioning-training planner for U10–U14 with workload safeguards.', 'talenttrack' ),
                'icon'        => 'activities',
                'category'    => self::CAT_COACHING,
            ],

            // — Planning & match day —
            'TT\\Modules\\Activities\\ActivitiesModule' => [
                'label'       => __( 'Activities', 'talenttrack' ),
                'description' => __( 'Training activities and matches, with attendance and the minutes each player gets.', 'talenttrack' ),
                'icon'        => 'activities',
                'category'    => self::CAT_PLANNING,
            ],
            'TT\\Modules\\Planning\\PlanningModule' => [
                'label'       => __( 'Planning calendar', 'talenttrack' ),
                'description' => __( 'A shared calendar that lays out each team\'s activities and fixtures across the season.', 'talenttrack' ),
                'icon'        => 'calendar',
                'category'    => self::CAT_PLANNING,
            ],
            'TT\\Modules\\Holidays\\HolidaysModule' => [
                'label'       => __( 'Holidays', 'talenttrack' ),
                'description' => __( 'Academy-wide holiday calendar that warns planners when activities fall on a break.', 'talenttrack' ),
                'icon'        => 'calendar',
                'category'    => self::CAT_PLANNING,
            ],
            'TT\\Modules\\Tournaments\\TournamentsModule' => [
                'label'       => __( 'Tournaments', 'talenttrack' ),
                'description' => __( 'Plan tournament squads and balance the minutes players get across the day.', 'talenttrack' ),
                'icon'        => 'podium',
                'category'    => self::CAT_PLANNING,
            ],
            'TT\\Modules\\MatchPrep\\MatchPrepModule' => [
                'label'       => __( 'Match preparation', 'talenttrack' ),
                'description' => __( 'Head-coach match plan — line-up, availability and the printable team sheet.', 'talenttrack' ),
                'icon'        => 'check',
                'category'    => self::CAT_PLANNING,
            ],
            'TT\\Modules\\MatchExecution\\MatchExecutionModule' => [
                'label'       => __( 'Match execution', 'talenttrack' ),
                'description' => __( 'Live match-day surface for the assistant coach to record minutes and events.', 'talenttrack' ),
                'icon'        => 'track',
                'category'    => self::CAT_PLANNING,
            ],

            // — Communication —
            'TT\\Modules\\Threads\\ThreadsModule' => [
                'label'       => __( 'Messages', 'talenttrack' ),
                'description' => __( 'Conversation threads between coaches, players and parents about a player.', 'talenttrack' ),
                'icon'        => 'comment',
                'category'    => self::CAT_COMMS,
            ],
            'TT\\Modules\\Comms\\CommsModule' => [
                'label'       => __( 'Notifications', 'talenttrack' ),
                'description' => __( 'Outbound email and notifications, with opt-out, quiet hours and rate limiting.', 'talenttrack' ),
                'icon'        => 'inbox',
                'category'    => self::CAT_COMMS,
            ],
            'TT\\Modules\\Push\\PushModule' => [
                'label'       => __( 'Push notifications', 'talenttrack' ),
                'description' => __( 'Browser and device push alerts for time-sensitive academy updates.', 'talenttrack' ),
                'icon'        => 'bell',
                'category'    => self::CAT_COMMS,
            ],

            // — Analytics & reporting —
            'TT\\Modules\\Reports\\ReportsModule' => [
                'label'       => __( 'Reports', 'talenttrack' ),
                'description' => __( 'Standard player and team reports — attendance, minutes and development summaries.', 'talenttrack' ),
                'icon'        => 'reports',
                'category'    => self::CAT_ANALYTICS,
            ],
            'TT\\Modules\\Stats\\StatsModule' => [
                'label'       => __( 'Statistics', 'talenttrack' ),
                'description' => __( 'Player and team statistics, including the player card summary figures.', 'talenttrack' ),
                'icon'        => 'usage-stats',
                'category'    => self::CAT_ANALYTICS,
            ],
            'TT\\Modules\\Analytics\\AnalyticsModule' => [
                'label'       => __( 'Analytics', 'talenttrack' ),
                'description' => __( 'The analytics engine plus the optional explorer for ad-hoc KPI and dimension queries.', 'talenttrack' ),
                'icon'        => 'trend-up',
                'category'    => self::CAT_ANALYTICS,
            ],
            'TT\\Modules\\Export\\ExportModule' => [
                'label'       => __( 'Exports', 'talenttrack' ),
                'description' => __( 'Download academy data as CSV, JSON or calendar files for use elsewhere.', 'talenttrack' ),
                'icon'        => 'external-link',
                'category'    => self::CAT_ANALYTICS,
            ],

            // — Integrations —
            'TT\\Modules\\Spond\\SpondModule' => [
                'label'       => __( 'Spond', 'talenttrack' ),
                'description' => __( 'Connects to Spond so team scheduling and availability stay in sync.', 'talenttrack' ),
                'icon'        => 'calendar',
                'category'    => self::CAT_INTEGRATIONS,
            ],
            'TT\\Modules\\AdminCenterClient\\AdminCenterClientModule' => [
                'label'       => __( 'Admin Center link', 'talenttrack' ),
                'description' => __( 'Connects this academy to the central TalentTrack Admin Center for support and updates.', 'talenttrack' ),
                'icon'        => 'external-link',
                'category'    => self::CAT_INTEGRATIONS,
            ],

            // — Advanced / developer —
            'TT\\Modules\\Workflow\\WorkflowModule' => [
                'label'       => __( 'Workflow', 'talenttrack' ),
                'description' => __( 'Automated task templates and scheduled background work across the academy.', 'talenttrack' ),
                'icon'        => 'workflow',
                'category'    => self::CAT_ADVANCED,
            ],
            'TT\\Modules\\Wizards\\WizardsModule' => [
                'label'       => __( 'Setup wizards', 'talenttrack' ),
                'description' => __( 'Guided step-by-step flows for creating players, teams and other records.', 'talenttrack' ),
                'icon'        => 'kanban',
                'category'    => self::CAT_ADVANCED,
            ],
            'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule' => [
                'label'       => __( 'Personal dashboards', 'talenttrack' ),
                'description' => __( 'Role-tailored dashboard layouts so each persona lands on what matters to them.', 'talenttrack' ),
                'icon'        => 'dashboard',
                'category'    => self::CAT_ADVANCED,
            ],
            'TT\\Modules\\CustomWidgets\\CustomWidgetsModule' => [
                'label'       => __( 'Custom widgets', 'talenttrack' ),
                'description' => __( 'A builder for bespoke dashboard widgets backed by custom data sources (beta).', 'talenttrack' ),
                'icon'        => 'custom-fields',
                'category'    => self::CAT_ADVANCED,
            ],
            'TT\\Modules\\CustomCss\\CustomCssModule' => [
                'label'       => __( 'Custom CSS', 'talenttrack' ),
                'description' => __( 'Inject site-specific styling without editing the plugin.', 'talenttrack' ),
                'icon'        => 'edit',
                'category'    => self::CAT_ADVANCED,
            ],
            'TT\\Modules\\Documentation\\DocumentationModule' => [
                'label'       => __( 'Documentation', 'talenttrack' ),
                'description' => __( 'In-product help pages and admin guides built into the dashboard.', 'talenttrack' ),
                'icon'        => 'docs',
                'category'    => self::CAT_ADVANCED,
            ],
            'TT\\Modules\\DemoData\\DemoDataModule' => [
                'label'       => __( 'Demo data', 'talenttrack' ),
                'description' => __( 'Generates sample players and teams for demos and testing — turn off in production.', 'talenttrack' ),
                'icon'        => 'players',
                'category'    => self::CAT_ADVANCED,
            ],
        ];
    }

    /**
     * Metadata for one module class. Falls back to a slugified class name
     * label + the Advanced/developer category for an undescribed module,
     * so the page never shows a raw FQCN and a missing entry surfaces as a
     * generic card rather than a fatal.
     *
     * @return array{label:string, description:string, icon:string, category:string}
     */
    public static function for( string $module_class ): array {
        $key = ltrim( $module_class, '\\' );
        $map = self::map();
        if ( isset( $map[ $key ] ) ) {
            return $map[ $key ];
        }
        return [
            'label'       => self::fallbackLabel( $key ),
            'description' => '',
            'icon'        => 'gear',
            'category'    => self::CAT_ADVANCED,
        ];
    }

    /** Humanise the trailing class name when no metadata entry exists. */
    private static function fallbackLabel( string $module_class ): string {
        $parts = explode( '\\', $module_class );
        $last  = (string) end( $parts );
        $last  = preg_replace( '/Module$/', '', $last );
        $last  = preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', (string) $last );
        return $last !== '' ? $last : $module_class;
    }
}
