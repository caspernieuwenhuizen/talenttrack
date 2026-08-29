<?php
/**
 * Install profiles (#3035).
 *
 * A profile is a named shape for an install: how much of the product a
 * club is running. It is read by `TT\Shared\Modules\ProfileRegistry` and
 * applied by `TT\Shared\Modules\ProfileService` through the existing
 * module and feature registries — nothing here writes state.
 *
 * Two things a profile declares, and the asymmetry is deliberate:
 *
 *   - `modules`  — explicit true/false for every class in
 *                  `config/modules.php`. Complete, so a module added in a
 *                  release has to be placed in every profile rather than
 *                  arriving switched on by omission.
 *   - `features` — **only the overrides**. `FeatureRegistry::catalog()`
 *                  builds 14 export keys and 22 report keys from loops, so
 *                  a profile that enumerated every feature would be
 *                  unmaintainable and would go stale the day a report is
 *                  added. Anything unnamed keeps its catalog default.
 *
 * Profiles are not runtime-editable. Changing what Basics means is a
 * release, for the same reason `FeatureMap` is a release — an install
 * being quietly reshaped underneath its operator is the failure this
 * whole mechanism exists to prevent.
 *
 * `tools/check-module-toggles.php` fails the build when a profile names a
 * module class or a feature key that does not resolve, when a profile
 * misses a switchable module, or when it tries to disable an always-on one.
 *
 * @return array<string, array{label:string, description:string, modules:array<string,bool>, features:array<string,bool>}>
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [

    /*
     * BASICS — the development loop and nothing else.
     *
     * Evaluate, set goals, measure, read the journey, tell the parents.
     * A club of forty players gets the loop it came for without being
     * handed thirty surfaces it will never open, which is how the loop
     * gets abandoned in week five.
     *
     * Two modules stay ON that look like candidates for the off list, and
     * switching either off is the trap this profile exists to avoid:
     *
     *   - **Analytics.** Reports and the dashboard KPIs consume the
     *     analytics engine directly. Only the `analytics_explorer`
     *     *feature* goes off — see `docs/modules.md` § Analytics explorer.
     *   - **Comms.** It is the transactional spine invitations and account
     *     mail ride on. Only its two cost-bearing features go off.
     */
    'basics' => [
        // `_x` rather than `__`: "Basics" is already a wizard step label,
        // and one word carries a different sense as the name of an
        // install shape than it does as "the basic details" of a record.
        'label'       => _x( 'Basics', 'install profile name', 'talenttrack' ),
        'description' => __( 'The development loop: players, teams, evaluations, goals, measurements, the journey and the reports that read them back. Match day, training plans, integrations and the developer surfaces stay off until you ask for them.', 'talenttrack' ),
        'modules'     => [
            // Always-on core. Named so the map stays complete and a
            // reader does not have to cross-check three lists; the
            // service never attempts to write them.
            'TT\\Modules\\Auth\\AuthModule'                       => true,
            'TT\\Modules\\Configuration\\ConfigurationModule'     => true,
            'TT\\Modules\\Authorization\\AuthorizationModule'     => true,

            // The player spine.
            'TT\\Modules\\Teams\\TeamsModule'                     => true,
            'TT\\Modules\\Players\\PlayersModule'                 => true,
            'TT\\Modules\\Players\\PlayerStatusModule'            => true,
            'TT\\Modules\\People\\PeopleModule'                   => true,
            'TT\\Modules\\Journey\\JourneyModule'                 => true,
            'TT\\Modules\\Evaluations\\EvaluationsModule'         => true,
            'TT\\Modules\\Goals\\GoalsModule'                     => true,
            'TT\\Modules\\Activities\\ActivitiesModule'           => true,
            'TT\\Modules\\Measurements\\MeasurementsModule'       => true,

            // Reading it back.
            'TT\\Modules\\Reports\\ReportsModule'                 => true,
            'TT\\Modules\\Stats\\StatsModule'                     => true,
            'TT\\Modules\\Analytics\\AnalyticsModule'             => true,
            'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule' => true,

            // Getting data in and out, and keeping it.
            'TT\\Modules\\Import\\ImportModule'                   => true,
            'TT\\Modules\\Export\\ExportModule'                   => true,
            'TT\\Modules\\Backup\\BackupModule'                   => true,

            // Getting people in, and reaching them.
            'TT\\Modules\\Invitations\\InvitationsModule'         => true,
            'TT\\Modules\\Comms\\CommsModule'                     => true,
            'TT\\Modules\\Onboarding\\OnboardingModule'           => true,
            'TT\\Modules\\Wizards\\WizardsModule'                 => true,

            // Running the install.
            'TT\\Modules\\Documentation\\DocumentationModule'     => true,
            'TT\\Modules\\Security\\SecurityModule'               => true,
            'TT\\Modules\\Mfa\\MfaModule'                         => true,
            'TT\\Modules\\License\\LicenseModule'                 => true,
            'TT\\Modules\\AdminCenterClient\\AdminCenterClientModule' => true,

            // — Off —

            // Match day.
            'TT\\Modules\\MatchPrep\\MatchPrepModule'             => false,
            'TT\\Modules\\MatchExecution\\MatchExecutionModule'   => false,
            'TT\\Modules\\MatchAnalysis\\MatchAnalysisModule'     => false,
            'TT\\Modules\\Tournaments\\TournamentsModule'         => false,

            // Training and planning.
            'TT\\Modules\\Training\\TrainingModule'               => false,
            'TT\\Modules\\Exercises\\ExercisesModule'             => false,
            'TT\\Modules\\Planning\\PlanningModule'               => false,
            'TT\\Modules\\Holidays\\HolidaysModule'               => false,
            'TT\\Modules\\Vct\\VctModule'                         => false,
            'TT\\Modules\\Media\\MediaModule'                     => false,

            // The coaching layer beyond the loop itself.
            'TT\\Modules\\Methodology\\MethodologyModule'         => false,
            'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule' => false,
            'TT\\Modules\\StaffDevelopment\\StaffDevelopmentModule' => false,
            'TT\\Modules\\Knowledge\\KnowledgeModule'             => false,
            'TT\\Modules\\Pdp\\PdpModule'                         => false,
            'TT\\Modules\\Development\\DevelopmentModule'         => false,

            // Messaging beyond the transactional spine.
            'TT\\Modules\\Threads\\ThreadsModule'                 => false,
            'TT\\Modules\\Push\\PushModule'                       => false,
            'TT\\Modules\\Alerts\\AlertsModule'                   => false,
            'TT\\Modules\\Workflow\\WorkflowModule'               => false,

            // Intake.
            'TT\\Modules\\Trials\\TrialsModule'                   => false,
            'TT\\Modules\\Prospects\\ProspectsModule'             => false,

            // Third-party sync.
            'TT\\Modules\\Spond\\SpondModule'                     => false,
            'TT\\Modules\\Strava\\StravaModule'                   => false,

            // Advanced / developer.
            'TT\\Modules\\CustomCss\\CustomCssModule'             => false,
            'TT\\Modules\\CustomWidgets\\CustomWidgetsModule'     => false,
            'TT\\Modules\\DataBrowser\\DataBrowserModule'         => false,
            'TT\\Modules\\I18n\\I18nModule'                       => false,
            'TT\\Modules\\Translations\\TranslationsModule'       => false,
            'TT\\Modules\\SeedReview\\SeedReviewModule'           => false,
            'TT\\Modules\\DemoData\\DemoDataModule'               => false,
        ],

        /*
         * Overrides only. The rule these were derived from, which is
         * easier to check than the list is to trust: switch off any
         * report or export whose source module is off in Basics, plus
         * any leaderboard.
         *
         * `analytics_player_compare` stays at its default — it is the
         * half of Stats worth keeping. `report_test_trends` and
         * `report_player_bmi` stay on: Measurements is in Basics, and
         * they are what makes it pay off.
         */
        'features' => [
            // The analytics platform, as distinct from reading a report.
            'analytics_explorer'            => false,
            // A leaderboard. Ranking children is not the loop.
            'analytics_podium'              => false,

            // Per-message cost, and neither is part of the transactional
            // spine Comms is kept on for.
            'comms_scheduled_sends'         => false,
            'comms_sms_channel'             => false,

            // Source module off.
            'export_demo_data_xlsx'         => false,
            'report_season_trial_funnel'    => false,
            'report_scout_report_card'      => false,
            'report_learning_courses'       => false,
            'report_learning_people'        => false,
            'report_learning_teams'         => false,

            // A leaderboard, same reason as the podium.
            'report_attendance_leaderboard' => false,

            // Admin data-quality tooling, not a development report.
            'report_minutes_audit'          => false,
        ],
    ],

    /*
     * FULL ACADEMY — every module on, every feature at its catalog
     * default. This is exactly what an install gets today, named so an
     * operator can see which shape they are on and move back to it.
     */
    'full' => [
        'label'       => _x( 'Full academy', 'install profile name', 'talenttrack' ),
        'description' => __( 'Everything the plugin ships: match day, training plans, the knowledge library, the integrations and the developer surfaces alongside the development loop. What an install gets when no profile is chosen.', 'talenttrack' ),
        'modules'     => [
            'TT\\Modules\\Auth\\AuthModule'                       => true,
            'TT\\Modules\\Configuration\\ConfigurationModule'     => true,
            'TT\\Modules\\Authorization\\AuthorizationModule'     => true,
            'TT\\Modules\\Teams\\TeamsModule'                     => true,
            'TT\\Modules\\Players\\PlayersModule'                 => true,
            'TT\\Modules\\Players\\PlayerStatusModule'            => true,
            'TT\\Modules\\People\\PeopleModule'                   => true,
            'TT\\Modules\\Journey\\JourneyModule'                 => true,
            'TT\\Modules\\Evaluations\\EvaluationsModule'         => true,
            'TT\\Modules\\Goals\\GoalsModule'                     => true,
            'TT\\Modules\\Activities\\ActivitiesModule'           => true,
            'TT\\Modules\\Measurements\\MeasurementsModule'       => true,
            'TT\\Modules\\Reports\\ReportsModule'                 => true,
            'TT\\Modules\\Stats\\StatsModule'                     => true,
            'TT\\Modules\\Analytics\\AnalyticsModule'             => true,
            'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule' => true,
            'TT\\Modules\\Import\\ImportModule'                   => true,
            'TT\\Modules\\Export\\ExportModule'                   => true,
            'TT\\Modules\\Backup\\BackupModule'                   => true,
            'TT\\Modules\\Invitations\\InvitationsModule'         => true,
            'TT\\Modules\\Comms\\CommsModule'                     => true,
            'TT\\Modules\\Onboarding\\OnboardingModule'           => true,
            'TT\\Modules\\Wizards\\WizardsModule'                 => true,
            'TT\\Modules\\Documentation\\DocumentationModule'     => true,
            'TT\\Modules\\Security\\SecurityModule'               => true,
            'TT\\Modules\\Mfa\\MfaModule'                         => true,
            'TT\\Modules\\License\\LicenseModule'                 => true,
            'TT\\Modules\\AdminCenterClient\\AdminCenterClientModule' => true,
            'TT\\Modules\\MatchPrep\\MatchPrepModule'             => true,
            'TT\\Modules\\MatchExecution\\MatchExecutionModule'   => true,
            'TT\\Modules\\MatchAnalysis\\MatchAnalysisModule'     => true,
            'TT\\Modules\\Tournaments\\TournamentsModule'         => true,
            'TT\\Modules\\Training\\TrainingModule'               => true,
            'TT\\Modules\\Exercises\\ExercisesModule'             => true,
            'TT\\Modules\\Planning\\PlanningModule'               => true,
            'TT\\Modules\\Holidays\\HolidaysModule'               => true,
            'TT\\Modules\\Vct\\VctModule'                         => true,
            'TT\\Modules\\Media\\MediaModule'                     => true,
            'TT\\Modules\\Methodology\\MethodologyModule'         => true,
            'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule' => true,
            'TT\\Modules\\StaffDevelopment\\StaffDevelopmentModule' => true,
            'TT\\Modules\\Knowledge\\KnowledgeModule'             => true,
            'TT\\Modules\\Pdp\\PdpModule'                         => true,
            'TT\\Modules\\Development\\DevelopmentModule'         => true,
            'TT\\Modules\\Threads\\ThreadsModule'                 => true,
            'TT\\Modules\\Push\\PushModule'                       => true,
            'TT\\Modules\\Alerts\\AlertsModule'                   => true,
            'TT\\Modules\\Workflow\\WorkflowModule'               => true,
            'TT\\Modules\\Trials\\TrialsModule'                   => true,
            'TT\\Modules\\Prospects\\ProspectsModule'             => true,
            'TT\\Modules\\Spond\\SpondModule'                     => true,
            'TT\\Modules\\Strava\\StravaModule'                   => true,
            'TT\\Modules\\CustomCss\\CustomCssModule'             => true,
            'TT\\Modules\\CustomWidgets\\CustomWidgetsModule'     => true,
            'TT\\Modules\\DataBrowser\\DataBrowserModule'         => true,
            'TT\\Modules\\I18n\\I18nModule'                       => true,
            'TT\\Modules\\Translations\\TranslationsModule'       => true,
            'TT\\Modules\\SeedReview\\SeedReviewModule'           => true,
            'TT\\Modules\\DemoData\\DemoDataModule'               => true,
        ],
        // Catalog defaults, i.e. today's behaviour.
        'features' => [],
    ],
];
