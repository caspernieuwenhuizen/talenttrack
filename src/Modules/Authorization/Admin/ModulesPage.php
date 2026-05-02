<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;

/**
 * ModulesPage — Authorization → Modules admin tab (#0033 Sprint 5).
 *
 * Lists every module declared in `config/modules.php` with its current
 * enabled state. Each row has a toggle (admin-only). Always-on core
 * modules render the toggle disabled with a tooltip.
 *
 * The License module row gets an inline warning banner when disabled
 * or recently toggled — pre-launch the disable-toggle is a dev/demo
 * convenience; post-launch it must be replaced with a hard-coded
 * enable or a `TT_DEV_MODE` constant gate (deferred).
 */
class ModulesPage {

    public static function init(): void {
        add_action( 'admin_post_tt_modules_save', [ __CLASS__, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $modules = ModuleRegistry::allWithState();
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_msg'] ) ) : '';

        $license_class = 'TT\\Modules\\License\\LicenseModule';
        $license_disabled = false;
        foreach ( $modules as $m ) {
            if ( $m['class'] === $license_class && ! $m['enabled'] ) {
                $license_disabled = true;
                break;
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Modules', 'talenttrack' ); ?></h1>
            <p style="color:#5b6e75; max-width:800px;">
                <?php esc_html_e( 'Each TalentTrack module can be turned off here. Disabled modules don\'t register hooks, REST routes, admin pages, or capabilities — they\'re completely invisible until re-enabled. Core modules (Auth, Configuration, Authorization) cannot be disabled.', 'talenttrack' ); ?>
            </p>

            <?php if ( $msg === 'saved' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Module state saved. Reload any open admin tabs to see the effect.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $license_disabled ) : ?>
                <div class="notice notice-error" style="border-left-color:#b32d2e;">
                    <p><strong>⚠️ <?php esc_html_e( 'License module is disabled.', 'talenttrack' ); ?></strong></p>
                    <p><?php
                        printf(
                            /* translators: %s: dev-mode constant suggestion */
                            esc_html__( 'All monetization gates are off (LicenseGate::* returns true unconditionally). Pre-launch this is fine for demos and dev. Before public launch, hardcode LicenseModule enabled or implement a %s constant that disables this toggle in production.', 'talenttrack' ),
                            '<code>TT_DEV_MODE</code>'
                        );
                    ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_modules_save', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_modules_save" />

                <?php
                // #0063 — group the flat list into logical buckets +
                // add a one-line description per module so the page
                // reads as a "what does this turn on?" reference,
                // not a wall of class names. Description map below;
                // any module not listed gets the auto-humanised
                // class name.
                $groups        = self::groupModules( $modules );
                $descriptions  = self::moduleDescriptions();
                $group_labels  = [
                    'core'          => __( 'Core (always on)', 'talenttrack' ),
                    'people'        => __( 'People & teams', 'talenttrack' ),
                    'performance'   => __( 'Performance', 'talenttrack' ),
                    'analytics'     => __( 'Analytics', 'talenttrack' ),
                    'configuration' => __( 'Configuration', 'talenttrack' ),
                    'access'        => __( 'Access control', 'talenttrack' ),
                    'optional'      => __( 'Optional / commercial', 'talenttrack' ),
                    'other'         => __( 'Other', 'talenttrack' ),
                ];
                ?>
                <?php foreach ( $group_labels as $gkey => $glabel ) :
                    if ( empty( $groups[ $gkey ] ) ) continue;
                    ?>
                    <details open style="margin: 14px 0; border: 1px solid #d6dadd; border-radius: 6px; background: #fff;">
                        <summary style="padding: 10px 14px; cursor: pointer; font-weight: 600;">
                            <?php echo esc_html( $glabel ); ?>
                            <span style="color:#5b6e75; font-weight: normal; font-size: 12px; margin-left: 6px;">
                                · <?php echo (int) count( $groups[ $gkey ] ); ?>
                            </span>
                        </summary>
                        <table class="widefat striped" style="border:0;">
                            <tbody>
                            <?php foreach ( $groups[ $gkey ] as $m ) :
                                $class      = $m['class'];
                                $short      = self::shortName( $class );
                                $enabled    = $m['enabled'];
                                $always_on  = $m['always_on'];
                                $is_license = $class === $license_class;
                                $desc       = $descriptions[ $class ] ?? '';
                                ?>
                                <tr>
                                    <td style="width:30%;">
                                        <strong><?php echo esc_html( $short ); ?></strong>
                                        <?php if ( $always_on ) : ?>
                                            <span style="color:#5b6e75; font-size:11px; margin-left:8px;">
                                                <?php esc_html_e( '(core)', 'talenttrack' ); ?>
                                            </span>
                                        <?php elseif ( $is_license ) : ?>
                                            <span style="color:#b32d2e; font-size:11px; margin-left:8px;">
                                                <?php esc_html_e( '(monetization)', 'talenttrack' ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $desc !== '' ) : ?>
                                            <span style="color:#3a4047; font-size:13px;"><?php echo esc_html( $desc ); ?></span>
                                        <?php endif; ?>
                                        <div style="color:#5b6e75; font-size:11px; margin-top:2px;"><code><?php echo esc_html( $class ); ?></code></div>
                                    </td>
                                    <td style="width:10%;">
                                        <label style="display:inline-flex; align-items:center; gap:6px;">
                                            <input type="checkbox"
                                                   name="enabled[]"
                                                   value="<?php echo esc_attr( $class ); ?>"
                                                   <?php checked( $enabled ); ?>
                                                   <?php disabled( $always_on ); ?>
                                                   <?php echo $always_on ? 'title="' . esc_attr__( 'Core module — cannot be disabled.', 'talenttrack' ) . '"' : ''; ?> />
                                            <?php echo $enabled ? esc_html__( 'On', 'talenttrack' ) : esc_html__( 'Off', 'talenttrack' ); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endforeach; ?>

                <p style="margin-top:14px;">
                    <?php submit_button( __( 'Save module state', 'talenttrack' ), 'primary', 'submit', false ); ?>
                </p>
            </form>

            <p style="margin-top:24px; color:#5b6e75; font-size:12px;">
                <?php esc_html_e( 'Note: dependencies between modules are not yet enforced. Disabling a module that another depends on may break the dependent module silently. The Module Registry will surface a dependency graph in a later sprint.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_modules_save', 'tt_nonce' );

        $checked = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['enabled'] ) )
            : [];
        $checked_set = array_flip( $checked );

        $modules = ModuleRegistry::allWithState();
        foreach ( $modules as $m ) {
            $class = $m['class'];
            if ( $m['always_on'] ) continue;
            $now_enabled = isset( $checked_set[ $class ] );
            if ( $now_enabled !== $m['enabled'] ) {
                ModuleRegistry::setEnabled( $class, $now_enabled );
            }
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-modules', 'tt_msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function shortName( string $class ): string {
        $parts = explode( '\\', $class );
        $last  = end( $parts );
        return preg_replace( '/Module$/', '', $last ) ?: $last;
    }

    /**
     * #0063 — partition modules into logical buckets so the admin
     * page reads as a categorised reference instead of a flat list.
     *
     * @param array<int, array{class:string, enabled:bool, always_on:bool}> $modules
     * @return array<string, array<int, array<string,mixed>>>
     */
    private static function groupModules( array $modules ): array {
        $by_class = [
            // Core / always-on — Auth, Configuration, Authorization
            'TT\\Modules\\Auth\\AuthModule'                       => 'core',
            'TT\\Modules\\Configuration\\ConfigurationModule'     => 'core',
            'TT\\Modules\\Authorization\\AuthorizationModule'     => 'core',
            // People & teams
            'TT\\Modules\\Teams\\TeamsModule'                     => 'people',
            'TT\\Modules\\Players\\PlayersModule'                 => 'people',
            'TT\\Modules\\Players\\PlayerStatusModule'            => 'people',
            'TT\\Modules\\People\\PeopleModule'                   => 'people',
            'TT\\Modules\\Invitations\\InvitationsModule'         => 'people',
            'TT\\Modules\\Trials\\TrialsModule'                   => 'people',
            // Performance
            'TT\\Modules\\Evaluations\\EvaluationsModule'         => 'performance',
            'TT\\Modules\\Activities\\ActivitiesModule'           => 'performance',
            'TT\\Modules\\Goals\\GoalsModule'                     => 'performance',
            'TT\\Modules\\Methodology\\MethodologyModule'         => 'performance',
            'TT\\Modules\\Pdp\\PdpModule'                         => 'performance',
            'TT\\Modules\\Threads\\ThreadsModule'                 => 'performance',
            'TT\\Modules\\Workflow\\WorkflowModule'               => 'performance',
            'TT\\Modules\\Journey\\JourneyModule'                 => 'performance',
            'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule' => 'performance',
            'TT\\Modules\\StaffDevelopment\\StaffDevelopmentModule' => 'performance',
            // Analytics
            'TT\\Modules\\Reports\\ReportsModule'                 => 'analytics',
            'TT\\Modules\\Stats\\StatsModule'                     => 'analytics',
            'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule' => 'analytics',
            // Configuration
            'TT\\Modules\\Documentation\\DocumentationModule'     => 'configuration',
            'TT\\Modules\\Backup\\BackupModule'                   => 'configuration',
            'TT\\Modules\\Wizards\\WizardsModule'                 => 'configuration',
            'TT\\Modules\\Translations\\TranslationsModule'       => 'configuration',
            'TT\\Modules\\DemoData\\DemoDataModule'               => 'configuration',
            'TT\\Modules\\Onboarding\\OnboardingModule'           => 'configuration',
            'TT\\Modules\\Spond\\SpondModule'                     => 'configuration',
            'TT\\Modules\\Push\\PushModule'                       => 'configuration',
            // Access control
            // (none currently — AuthorizationModule is core)
            // Optional / commercial
            'TT\\Modules\\License\\LicenseModule'                 => 'optional',
            'TT\\Modules\\Development\\DevelopmentModule'         => 'optional',
        ];

        $out = [];
        foreach ( $modules as $m ) {
            $class = (string) $m['class'];
            $bucket = $by_class[ $class ] ?? ( ! empty( $m['always_on'] ) ? 'core' : 'other' );
            $out[ $bucket ][] = $m;
        }
        return $out;
    }

    /**
     * @return array<string,string>
     */
    private static function moduleDescriptions(): array {
        return [
            'TT\\Modules\\Auth\\AuthModule'                       => __( 'Login, role mapping, and the user-meta surface every other module reads.', 'talenttrack' ),
            'TT\\Modules\\Configuration\\ConfigurationModule'     => __( 'Site-wide settings, lookups, branding, theme, rating scale.', 'talenttrack' ),
            'TT\\Modules\\Authorization\\AuthorizationModule'     => __( 'Persona resolver, capability matrix, role assignments.', 'talenttrack' ),
            'TT\\Modules\\Teams\\TeamsModule'                     => __( 'Teams CRUD, staff assignments, age groups.', 'talenttrack' ),
            'TT\\Modules\\Players\\PlayersModule'                 => __( 'Player records, profiles, parent links.', 'talenttrack' ),
            'TT\\Modules\\Players\\PlayerStatusModule'            => __( 'Behaviour + potential capture and the traffic-light status calculator.', 'talenttrack' ),
            'TT\\Modules\\People\\PeopleModule'                   => __( 'Staff, parents, scouts and any other non-player human in the academy.', 'talenttrack' ),
            'TT\\Modules\\Invitations\\InvitationsModule'         => __( 'Email + WhatsApp invite links for player / parent / staff onboarding.', 'talenttrack' ),
            'TT\\Modules\\Trials\\TrialsModule'                   => __( 'Trial cases — track a prospective player from first activity through the decision.', 'talenttrack' ),
            'TT\\Modules\\Evaluations\\EvaluationsModule'         => __( 'Player evaluations, categories, age-group weights.', 'talenttrack' ),
            'TT\\Modules\\Activities\\ActivitiesModule'           => __( 'Training, games, tournaments, attendance.', 'talenttrack' ),
            'TT\\Modules\\Goals\\GoalsModule'                     => __( 'Per-player development goals + conversational threads.', 'talenttrack' ),
            'TT\\Modules\\Methodology\\MethodologyModule'         => __( 'Football framework, formations, learning goals, set pieces.', 'talenttrack' ),
            'TT\\Modules\\Pdp\\PdpModule'                         => __( 'Player Development Plans — the per-season planning + verdict surface.', 'talenttrack' ),
            'TT\\Modules\\Threads\\ThreadsModule'                 => __( 'The polymorphic conversation primitive used by goals (and future surfaces).', 'talenttrack' ),
            'TT\\Modules\\Workflow\\WorkflowModule'               => __( 'Scheduled tasks: post-game evals, self-evals, HoD reviews.', 'talenttrack' ),
            'TT\\Modules\\Journey\\JourneyModule'                 => __( 'Chronological player events — trial, signing, injury, transitions.', 'talenttrack' ),
            'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule' => __( 'Team chemistry board + formation suggestions.', 'talenttrack' ),
            'TT\\Modules\\StaffDevelopment\\StaffDevelopmentModule' => __( 'Personal goals + evaluations + certifications + PDP for coaches and staff.', 'talenttrack' ),
            'TT\\Modules\\Reports\\ReportsModule'                 => __( 'Cross-team reports — progress, ratings, coach activity.', 'talenttrack' ),
            'TT\\Modules\\Stats\\StatsModule'                     => __( 'Per-player rate cards and head-to-head comparison.', 'talenttrack' ),
            'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule' => __( 'Persona-aware landing pages, widget catalog, KPI catalog.', 'talenttrack' ),
            'TT\\Modules\\Documentation\\DocumentationModule'     => __( 'In-product help, audience-filtered docs, role-aware TOC.', 'talenttrack' ),
            'TT\\Modules\\Backup\\BackupModule'                   => __( 'Scheduled snapshots + partial restore + 14-day undo.', 'talenttrack' ),
            'TT\\Modules\\Wizards\\WizardsModule'                 => __( 'Step-by-step record-creation wizards for players, teams, evals, goals.', 'talenttrack' ),
            'TT\\Modules\\Translations\\TranslationsModule'       => __( 'Auto-translation of user-entered free text via DeepL or Google.', 'talenttrack' ),
            'TT\\Modules\\DemoData\\DemoDataModule'               => __( 'Generate or import a demo dataset for sales / training.', 'talenttrack' ),
            'TT\\Modules\\Onboarding\\OnboardingModule'           => __( 'First-run setup wizard for fresh installs.', 'talenttrack' ),
            'TT\\Modules\\Spond\\SpondModule'                     => __( 'Read-only Spond → TalentTrack iCal sync per team.', 'talenttrack' ),
            'TT\\Modules\\Push\\PushModule'                       => __( 'PWA push notifications + per-team dispatcher chain (#0042).', 'talenttrack' ),
            'TT\\Modules\\License\\LicenseModule'                 => __( 'Tier gating + usage caps. Disable for demos / dev only.', 'talenttrack' ),
            'TT\\Modules\\Development\\DevelopmentModule'         => __( 'Idea-to-spec workflow, pull-request hooks, dev tooling.', 'talenttrack' ),
        ];
    }
}
