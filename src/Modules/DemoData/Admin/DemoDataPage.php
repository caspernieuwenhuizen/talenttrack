<?php
namespace TT\Modules\DemoData\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoGenerator;
use TT\Modules\DemoData\DemoMode;
use TT\Modules\DemoData\DemoDataCleaner;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * DemoDataPage — wp-admin entry point for the demo data generator.
 *
 * Checkpoint 2 adds: demo-mode toggle, wipe-data form, wipe-users form,
 * and credentials-on-success display (via transient that survives the
 * post-then-redirect).
 *
 * Gated behind manage_options (permanent rule per spec). Lives under
 * Tools so it stays clearly separated from the club-data admin tree.
 *
 * During render, the page forces DemoMode into a request-scoped
 * `neutral` override so it always sees the full demo footprint,
 * regardless of the site-wide toggle state.
 */
class DemoDataPage {

    private const CAP  = 'manage_options';
    private const SLUG = 'tt-demo-data';
    private const TRANSIENT_ACCOUNTS   = 'tt_demo_last_accounts';
    private const TRANSIENT_COUNTS     = 'tt_demo_last_counts';
    private const TRANSIENT_USER_STATS = 'tt_demo_last_user_stats';

    public static function init(): void {
        // v3.70.1 hotfix — menu registration moved to
        // CoreSurfaceRegistration via AdminMenuRegistry so the entry
        // is declarative + survives top-level menu renames. The
        // admin_menu hook here previously raced with the registry
        // and the page would silently drop off the menu.
        add_action( 'admin_post_tt_demo_generate',  [ self::class, 'handleGenerate'  ] );
        add_action( 'admin_post_tt_demo_wipe_data', [ self::class, 'handleWipeData'  ] );
        add_action( 'admin_post_tt_demo_wipe_users',[ self::class, 'handleWipeUsers' ] );
        add_action( 'admin_post_tt_demo_mode',      [ self::class, 'handleModeToggle'] );
        // #0059 — Excel-driven demo data.
        add_action( 'admin_post_tt_demo_excel_template', [ self::class, 'handleTemplateDownload' ] );
        add_action( 'admin_post_tt_demo_excel_import',   [ self::class, 'handleExcelImport' ] );
        // v3.91.7 — rebuild journey events (one-shot, idempotent).
        add_action( 'admin_post_tt_demo_journey_rebuild', [ self::class, 'handleJourneyRebuild' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        // The demo admin page always sees everything, regardless of toggle.
        DemoMode::overrideForRequest( DemoMode::NEUTRAL );
        wp_enqueue_script(
            'tt-demo-page',
            TT_PLUGIN_URL . 'assets/js/demo-page.js',
            [],
            TT_VERSION,
            true
        );

        $notice = isset( $_GET['tt_demo_msg'] )   ? sanitize_text_field( wp_unslash( (string) $_GET['tt_demo_msg'] ) )   : '';
        $batch  = isset( $_GET['tt_demo_batch'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_demo_batch'] ) ) : '';
        $error  = isset( $_GET['tt_demo_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_demo_error'] ) ) : '';

        $counts  = DemoGenerator::counts();
        $batches = DemoGenerator::batches();
        $mode    = DemoMode::current();

        // Transient is populated by a successful generate and consumed once.
        $raw_accounts    = get_transient( self::TRANSIENT_ACCOUNTS );
        $raw_counts      = get_transient( self::TRANSIENT_COUNTS );
        $raw_user_stats  = get_transient( self::TRANSIENT_USER_STATS );
        $last_accounts   = is_array( $raw_accounts )   ? $raw_accounts   : [];
        $last_counts     = is_array( $raw_counts )     ? $raw_counts     : [];
        $last_user_stats = is_array( $raw_user_stats ) ? $raw_user_stats : [];
        if ( $notice === 'generated' ) {
            delete_transient( self::TRANSIENT_ACCOUNTS );
            delete_transient( self::TRANSIENT_COUNTS );
            delete_transient( self::TRANSIENT_USER_STATS );
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TalentTrack Demo Data', 'talenttrack' ); ?></h1>

            <p style="max-width:720px;">
                <?php esc_html_e(
                    'Generate a realistic Dutch-academy dataset for demos, toggle a site-wide demo mode that hides real club data, and wipe cleanly when the demo is over.',
                    'talenttrack'
                ); ?>
            </p>

            <?php self::renderNotices( $notice, $batch, $error, $last_counts, $last_user_stats ); ?>
            <?php self::renderModeSection( $mode ); ?>
            <?php self::renderFootprint( $counts ); ?>
            <?php self::renderCredentials( $last_accounts ); ?>
            <?php self::renderGenerateSection(); ?>
            <?php self::renderJourneyRebuildSection(); ?>
            <?php self::renderWipeSection(); ?>
            <?php self::renderBatches( $batches ); ?>
        </div>
        <?php

        DemoMode::clearOverride();
    }

    // Render partials

    /**
     * @param array<string,int> $counts
     * @param array<string,int> $user_stats
     */
    private static function renderNotices( string $notice, string $batch, string $error, array $counts, array $user_stats ): void {
        if ( $notice === 'generated' && $batch ) {
            $created_users = (int) ( $user_stats['created'] ?? 0 );
            $reused_users  = (int) ( $user_stats['reused'] ?? 0 );

            // Split into data counts (created this run) vs user counts (created + reused)
            $data_parts = [];
            foreach ( [ 'teams', 'persons', 'players', 'evaluations', 'activities', 'goals' ] as $k ) {
                if ( isset( $counts[ $k ] ) ) {
                    $data_parts[] = (int) $counts[ $k ] . ' ' . $k;
                }
            }
            $data_line = implode( ', ', $data_parts );

            if ( $created_users === 0 && $reused_users > 0 ) {
                $user_line = sprintf( '%d users reused (0 created)', $reused_users );
            } elseif ( $created_users > 0 && $reused_users === 0 ) {
                $user_line = sprintf( '%d users created', $created_users );
            } else {
                $user_line = sprintf( '%d users created, %d reused', $created_users, $reused_users );
            }
            ?>
            <div class="notice notice-success">
                <p>
                    <?php printf(
                        /* translators: 1: batch id, 2: data counts, 3: user counts */
                        esc_html__( 'Generation complete. Batch: %1$s. Data: %2$s. %3$s.', 'talenttrack' ),
                        '<code>' . esc_html( $batch ) . '</code>',
                        esc_html( $data_line ),
                        esc_html( $user_line )
                    ); ?>
                </p>
            </div>
            <?php
        } elseif ( $notice === 'wiped' ) {
            $cats_str = isset( $_GET['tt_demo_wiped_cats'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_demo_wiped_cats'] ) ) : '';
            $cats     = $cats_str !== '' ? array_filter( explode( ',', $cats_str ) ) : [];
            $rows_n   = isset( $_GET['tt_demo_wiped_n'] ) ? (int) $_GET['tt_demo_wiped_n'] : 0;
            ?>
            <div class="notice notice-success">
                <p>
                    <?php
                    if ( $cats ) {
                        echo esc_html( sprintf(
                            /* translators: 1: row count, 2: comma-list of categories */
                            __( 'Demo data wiped — %1$d rows across %2$s.', 'talenttrack' ),
                            $rows_n,
                            implode( ', ', array_map( 'esc_html', $cats ) )
                        ) );
                    } else {
                        esc_html_e( 'Demo data wiped.', 'talenttrack' );
                    }
                    ?>
                </p>
            </div>
            <?php
        } elseif ( $notice === 'users_wiped' ) {
            ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Demo users removed.', 'talenttrack' ); ?></p></div>
            <?php
        } elseif ( $notice === 'journey_rebuilt' ) {
            $walked = isset( $_GET['tt_demo_journey_walked'] ) ? (int) $_GET['tt_demo_journey_walked'] : 0;
            ?>
            <div class="notice notice-success">
                <p>
                    <?php echo esc_html( sprintf(
                        /* translators: %d is the row-walk count across evaluations + goals + verdicts + players + trials */
                        __( 'Journey events rebuilt — walked %d source rows. New events were inserted where missing; existing events were left untouched.', 'talenttrack' ),
                        $walked
                    ) ); ?>
                </p>
            </div>
            <?php
        } elseif ( $notice === 'mode' ) {
            ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Demo mode updated.', 'talenttrack' ); ?></p></div>
            <?php
        }
        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
    }

    private static function renderModeSection( string $mode ): void {
        $is_on = ( $mode === DemoMode::ON );
        ?>
        <h2 style="margin-top:24px;"><?php esc_html_e( 'Demo mode', 'talenttrack' ); ?></h2>
        <p style="max-width:720px;">
            <?php if ( $is_on ) : ?>
                <span style="background:#b32d2e;color:#fff;padding:3px 10px;border-radius:3px;font-weight:600;">🎭 ON</span>
                <?php esc_html_e( ' — real club data is hidden from every read path. Only demo rows are visible elsewhere in the plugin.', 'talenttrack' ); ?>
            <?php else : ?>
                <strong><?php esc_html_e( 'OFF', 'talenttrack' ); ?></strong>
                <?php esc_html_e( ' — demo data is hidden from every read path. Normal operation.', 'talenttrack' ); ?>
            <?php endif; ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
            <?php wp_nonce_field( 'tt_demo_mode', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_mode" />
            <?php if ( $is_on ) : ?>
                <input type="hidden" name="target" value="off" />
                <label>
                    <input type="text" name="confirm_text" placeholder="<?php esc_attr_e( 'Type EXIT DEMO to confirm', 'talenttrack' ); ?>" class="regular-text" />
                </label>
                <?php submit_button( __( 'Exit demo mode', 'talenttrack' ), 'secondary', '', false ); ?>
            <?php else : ?>
                <input type="hidden" name="target" value="on" />
                <?php submit_button( __( 'Enter demo mode', 'talenttrack' ), 'primary', '', false ); ?>
            <?php endif; ?>
        </form>
        <?php
    }

    /** @param array<string,int> $counts */
    private static function renderFootprint( array $counts ): void {
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Current demo footprint', 'talenttrack' ); ?></h2>
        <?php if ( ! $counts ) : ?>
            <p><em><?php esc_html_e( 'No demo data exists yet.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:520px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Entity type', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Tagged rows', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $counts as $type => $n ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $type ); ?></code></td>
                            <td style="text-align:right;"><?php echo (int) $n; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /** @param array<string,array{user_id:int,email:string}> $accounts */
    private static function renderCredentials( array $accounts ): void {
        if ( ! $accounts ) return;
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Accounts from the last run', 'talenttrack' ); ?></h2>
        <p><?php esc_html_e( 'Shown once, then cleared on next page load. Copy these now if you need them.', 'talenttrack' ); ?></p>
        <textarea rows="10" cols="70" readonly style="font-family:monospace;">
<?php foreach ( $accounts as $slot => $info ) echo esc_textarea( sprintf( "%-12s  %s  (user id: %d)\n", $slot, $info['email'], $info['user_id'] ) ); ?>
        </textarea>
        <?php
    }

    private static function renderGenerateSection(): void {
        $users_exist       = DemoGenerator::persistentUsersExist();
        $default_club_name = self::defaultClubName();
        ?>
        <h2 class="tt-demo-h2"><?php esc_html_e( 'Generate', 'talenttrack' ); ?></h2>

        <?php if ( $users_exist ) : ?>
            <div class="notice notice-info inline tt-demo-leadnote">
                <p>
                    <strong><?php esc_html_e( 'Demo users already exist from a previous run.', 'talenttrack' ); ?></strong>
                    <?php esc_html_e( 'No new WP users will be created and no welcome emails will be sent. This run only creates data rows (teams, players, evaluations, activities, goals).', 'talenttrack' ); ?>
                </p>
            </div>
        <?php else : ?>
            <div class="notice notice-warning inline tt-demo-leadnote">
                <p>
                    <strong><?php esc_html_e( 'First run.', 'talenttrack' ); ?></strong>
                    <?php esc_html_e( 'This run will create 36 persistent demo WP users and send them WordPress welcome emails. The email domain must be one whose inbox you control (a catch-all is best — see below for details).', 'talenttrack' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="tt-runner-overlay" data-tt-demo-overlay hidden role="alertdialog" aria-live="assertive" aria-labelledby="tt-demo-overlay-title">
            <div class="tt-runner-overlay-card">
                <div class="tt-runner-overlay-spinner" aria-hidden="true"></div>
                <h3 id="tt-demo-overlay-title" class="tt-runner-overlay-title"><?php esc_html_e( 'Generating demo data…', 'talenttrack' ); ?></h3>
                <p class="tt-runner-overlay-msg"><?php esc_html_e( 'This usually takes 15–45 seconds depending on the preset. Leave this tab open until it finishes.', 'talenttrack' ); ?></p>
            </div>
        </div>

        <div class="tt-demo-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Generate-form layout', 'talenttrack' ); ?>">
            <button type="button" class="tt-demo-tab tt-demo-tab-active" data-tt-demo-tab="basic" role="tab" aria-selected="true">
                <?php esc_html_e( 'Basic', 'talenttrack' ); ?>
            </button>
            <button type="button" class="tt-demo-tab" data-tt-demo-tab="advanced" role="tab" aria-selected="false">
                <?php esc_html_e( 'Advanced', 'talenttrack' ); ?>
            </button>
        </div>

        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tt-demo-generate-form" class="tt-demo-form" data-tt-demo-form>
            <?php wp_nonce_field( 'tt_demo_generate', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_generate" />

            <fieldset style="border:1px solid #d6dadd;border-radius:6px;padding:12px 14px;margin:0 0 16px;background:#f8fafc;">
                <legend style="font-weight:600;padding:0 6px;">
                    <?php esc_html_e( 'Step 0 — Source', 'talenttrack' ); ?>
                </legend>
                <p style="margin:0 0 10px;color:#5b6e75;">
                    <?php esc_html_e( 'Procedural for a fast, believable academy. Excel for a deterministic dataset matching the prospect\'s real teams. Hybrid for the best of both — Excel where you have it, procedural fills the rest.', 'talenttrack' ); ?>
                </p>
                <label style="display:block;padding:6px 0;cursor:pointer;">
                    <input type="radio" name="source" value="procedural" checked data-tt-demo-source />
                    <strong><?php esc_html_e( 'Procedural only', 'talenttrack' ); ?></strong>
                    <span style="color:#5b6e75;"> — <?php esc_html_e( 'pick a preset and let the generator do everything.', 'talenttrack' ); ?></span>
                </label>
                <label style="display:block;padding:6px 0;cursor:pointer;">
                    <input type="radio" name="source" value="excel" data-tt-demo-source />
                    <strong><?php esc_html_e( 'Excel upload', 'talenttrack' ); ?></strong>
                    <span style="color:#5b6e75;"> — <?php esc_html_e( 'use only what\'s in the workbook; nothing is generated.', 'talenttrack' ); ?></span>
                </label>
                <label style="display:block;padding:6px 0;cursor:pointer;">
                    <input type="radio" name="source" value="hybrid" data-tt-demo-source />
                    <strong><?php esc_html_e( 'Hybrid: upload + procedural top-up', 'talenttrack' ); ?></strong>
                    <span style="color:#5b6e75;"> — <?php esc_html_e( 'Excel sheets win; the procedural generator fills any sheet you left blank.', 'talenttrack' ); ?></span>
                </label>

                <div data-tt-demo-source-file style="display:none;margin-top:12px;padding:10px 12px;background:#fff;border:1px solid #d6dadd;border-radius:6px;">
                    <p style="margin:0 0 8px;">
                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tt_demo_excel_template' ), 'tt_demo_excel_template' ) ); ?>">
                            <?php esc_html_e( 'Download template (.xlsx)', 'talenttrack' ); ?>
                        </a>
                    </p>
                    <input type="file" name="demo_excel" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" />
                    <p style="margin:6px 0 0;color:#5b6e75;font-size:12px;">
                        <?php esc_html_e( 'v1.5: Teams, People, Players, Trial cases, Activities (Sessions), Attendance, Evaluations, Eval ratings, Goals, Player-journey events. Reference sheets (Eval categories, Category weights, Lookups) are documentation-only — admin-edit those via the existing Configuration surfaces.', 'talenttrack' ); ?>
                    </p>
                    <p style="margin:6px 0 0;color:#5b6e75;font-size:11px;">
                        <?php
                        printf(
                            /* translators: 1: upload_max_filesize, 2: post_max_size, 3: memory_limit */
                            esc_html__( 'Server limits on this install: upload_max_filesize = %1$s · post_max_size = %2$s · memory_limit = %3$s. Workbooks above the smaller of the first two will be rejected by PHP before the plugin sees them.', 'talenttrack' ),
                            esc_html( (string) ini_get( 'upload_max_filesize' ) ),
                            esc_html( (string) ini_get( 'post_max_size' ) ),
                            esc_html( (string) ini_get( 'memory_limit' ) )
                        );
                        ?>
                    </p>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #d6dadd;border-radius:6px;padding:12px 14px;margin:0 0 16px;background:#f8fafc;">
                <legend style="font-weight:600;padding:0 6px;">
                    <?php esc_html_e( 'Step 0.5 — What to generate', 'talenttrack' ); ?>
                </legend>

                <div data-tt-demo-selective>
                    <p style="margin:0 0 10px;color:#5b6e75;">
                        <?php esc_html_e( 'Master data — uncheck to use the rows already in your club instead of generating new ones. These three toggles only apply to the procedural source (Excel + hybrid read master data from the workbook regardless).', 'talenttrack' ); ?>
                    </p>
                    <label style="display:block;padding:4px 0;cursor:pointer;">
                        <input type="checkbox" name="gen_teams" value="1" checked />
                        <?php esc_html_e( 'Generate teams', 'talenttrack' ); ?>
                        <span style="color:#5b6e75;"> — <?php esc_html_e( 'uncheck to use existing teams in the club.', 'talenttrack' ); ?></span>
                    </label>
                    <label style="display:block;padding:4px 0;cursor:pointer;">
                        <input type="checkbox" name="gen_people" value="1" checked />
                        <?php esc_html_e( 'Generate people + WP users', 'talenttrack' ); ?>
                        <span style="color:#5b6e75;"> — <?php esc_html_e( 'uncheck to skip the 36-account creation. Existing People rows + their WP users stay untouched.', 'talenttrack' ); ?></span>
                    </label>
                    <label style="display:block;padding:4px 0;cursor:pointer;">
                        <input type="checkbox" name="gen_players" value="1" checked />
                        <?php esc_html_e( 'Generate players', 'talenttrack' ); ?>
                        <span style="color:#5b6e75;"> — <?php esc_html_e( 'uncheck to use existing players in the club.', 'talenttrack' ); ?></span>
                    </label>
                </div>

                <p style="margin:14px 0 10px;color:#5b6e75;">
                    <?php esc_html_e( 'Dependent entities — uncheck any to skip generating that category on top of whatever master data ends up present. Applies to every source (procedural, Excel, hybrid).', 'talenttrack' ); ?>
                </p>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="gen_activities" value="1" checked />
                    <?php esc_html_e( 'Generate activities', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php esc_html_e( 'uncheck to skip session/match generation. Attendance is generated alongside; turning this off skips both.', 'talenttrack' ); ?></span>
                </label>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="gen_evaluations" value="1" checked />
                    <?php esc_html_e( 'Generate evaluations', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php esc_html_e( 'uncheck to skip evaluation rounds + per-category ratings.', 'talenttrack' ); ?></span>
                </label>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="gen_goals" value="1" checked />
                    <?php esc_html_e( 'Generate goals', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php esc_html_e( 'uncheck to skip per-player development goals.', 'talenttrack' ); ?></span>
                </label>
            </fieldset>

            <script>
            (function(){
                var radios = document.querySelectorAll('input[data-tt-demo-source]');
                var box    = document.querySelector('[data-tt-demo-source-file]');
                var selective = document.querySelector('[data-tt-demo-selective]');
                if ( ! radios.length || ! box ) return;
                function toggle(){
                    var nonProc = false;
                    radios.forEach(function(r){ if ( r.checked && r.value !== 'procedural' ) nonProc = true; });
                    box.style.display = nonProc ? 'block' : 'none';
                    if ( selective ) selective.style.display = nonProc ? 'none' : '';
                }
                radios.forEach(function(r){ r.addEventListener('change', toggle); });
                toggle();
            })();
            </script>

            <table class="form-table">
                <tbody data-tt-demo-tab-pane="basic">
                    <tr>
                        <th scope="row"><label for="tt_demo_preset"><?php esc_html_e( 'Preset', 'talenttrack' ); ?></label></th>
                        <td>
                            <select id="tt_demo_preset" name="preset">
                                <?php foreach ( DemoGenerator::PRESETS as $key => $cfg ) :
                                    $preset_label = self::presetLabel( (string) $key );
                                    ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, 'small' ); ?>>
                                        <?php
                                        echo esc_html( sprintf(
                                            /* translators: 1: preset name, 2: number of teams, 3: players per team, 4: weeks of activity */
                                            _n(
                                                '%1$s — %2$d team, %3$d players/team, %4$d weeks',
                                                '%1$s — %2$d teams, %3$d players/team, %4$d weeks',
                                                (int) $cfg['teams'],
                                                'talenttrack'
                                            ),
                                            $preset_label,
                                            (int) $cfg['teams'],
                                            (int) $cfg['players_per_team'],
                                            (int) $cfg['weeks']
                                        ) );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tt_demo_club_name"><?php esc_html_e( 'Club name for this demo', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="text" id="tt_demo_club_name" name="club_name" value="<?php echo esc_attr( $default_club_name ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Used as the prefix for every generated team name (e.g. "FC Groningen JO11"). Defaults to the academy name from Configuration. Only affects this generate run — your Configuration setting is not changed.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tt_demo_domain"><?php esc_html_e( 'Demo email domain', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="text" id="tt_demo_domain" name="domain" value="demo.talenttrack.local" class="regular-text" <?php echo $users_exist ? '' : 'required'; ?> />
                            <p class="description">
                                <?php if ( $users_exist ) : ?>
                                    <?php esc_html_e( 'Ignored — users already exist. Kept for reference; change only if you plan to wipe users and recreate.', 'talenttrack' ); ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'Every demo account will be <slot>@<this-domain> (e.g. coach1@demo.example.com).', 'talenttrack' ); ?>
                                    <strong><?php esc_html_e( 'WordPress will email welcome credentials to each address.', 'talenttrack' ); ?></strong>
                                    <?php esc_html_e( 'Pick a domain whose inbox you actually receive — a catch-all (anything@yourdomain.tld → real inbox) is the safest, since 36 different addresses are generated. Loopback values like demo.talenttrack.local work locally but bounce on a real server. If your DNS doesn\'t support catch-all, see the manual user list below and create the WordPress users yourself first.', 'talenttrack' ); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tt_demo_password"><?php esc_html_e( 'Shared password', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="text" id="tt_demo_password" name="password" value="demo1234!" class="regular-text" <?php echo $users_exist ? '' : 'required'; ?> />
                            <p class="description">
                                <?php if ( $users_exist ) :
                                    esc_html_e( 'Ignored — existing accounts keep their current password. Only used when new users are created.', 'talenttrack' );
                                else :
                                    esc_html_e( 'Applied to all 36 demo accounts on first creation.', 'talenttrack' );
                                endif; ?>
                            </p>
                        </td>
                    </tr>
                    <?php if ( ! $users_exist ) : ?>
                    <tr>
                        <th scope="row"><label for="tt_demo_confirm"><?php esc_html_e( 'I confirm this domain catches mail I own', 'talenttrack' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="tt_demo_confirm" name="domain_confirmed" value="1" required />
                                <?php esc_html_e( 'Required — 36 WP welcome emails will be sent.', 'talenttrack' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <details class="tt-demo-manual-users">
                                <summary><?php esc_html_e( 'Skip user creation — show me the list to create manually', 'talenttrack' ); ?></summary>
                                <div class="tt-demo-manual-users-body">
                                    <p>
                                        <?php esc_html_e( 'Prefer to create the WordPress users yourself (e.g. your hosting environment doesn\'t support catch-all email)? Create the 36 accounts below before running the generator. Use any role for now — TalentTrack reassigns roles based on slot during data generation. Once they all exist, this page will detect them and skip the user-creation step automatically.', 'talenttrack' ); ?>
                                    </p>
                                    <pre class="tt-demo-manual-users-list"><?php
                                        $slots = self::expectedDemoSlots();
                                        foreach ( $slots as $slot ) {
                                            echo esc_html( $slot . '@<your-domain>' ) . "\n";
                                        }
                                    ?></pre>
                                    <p class="description">
                                        <?php esc_html_e( 'Replace <your-domain> with the value you typed in the field above. After creating them, refresh this page — the warning above turns to "Demo users already exist".', 'talenttrack' ); ?>
                                    </p>
                                </div>
                            </details>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tbody data-tt-demo-tab-pane="advanced" hidden>
                    <tr>
                        <th scope="row"><label for="tt_demo_seed"><?php esc_html_e( 'Seed', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="number" id="tt_demo_seed" name="seed" value="20260504" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Fixed default 20260504 — produces the same roster every run. Change for a different roster.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tt_demo_content_language"><?php esc_html_e( 'Content language', 'talenttrack' ); ?></label></th>
                        <td>
                            <?php
                            // Supported set comes from the generators that actually have
                            // a translated content dictionary. Showing every installed WP
                            // locale would let the operator pick a language we'd silently
                            // fall back from — worse than just listing what works.
                            $tt_site_locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
                            $tt_supported   = \TT\Modules\DemoData\Generators\GoalGenerator::supportedLanguages();
                            $tt_default     = in_array( $tt_site_locale, $tt_supported, true ) ? $tt_site_locale : 'en_US';
                            ?>
                            <select id="tt_demo_content_language" name="content_language">
                                <?php foreach ( $tt_supported as $loc ) : ?>
                                    <option value="<?php echo esc_attr( $loc ); ?>" <?php selected( $loc, $tt_default ); ?>>
                                        <?php echo esc_html( $loc . ( $loc === $tt_site_locale ? ' ' . __( '(site locale)', 'talenttrack' ) : '' ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Language the generated content (goal titles, descriptions, activity titles, default location) is written in. Only languages the generators ship content dictionaries for are listed — add a key to GoalGenerator / ActivityGenerator constants to support a new one.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( __( 'Generate demo data', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    /** Localised label for a preset slug. */
    private static function presetLabel( string $key ): string {
        switch ( $key ) {
            case 'small':  return __( 'Small',  'talenttrack' );
            case 'medium': return __( 'Medium', 'talenttrack' );
            case 'large':  return __( 'Large',  'talenttrack' );
        }
        return ucfirst( $key );
    }

    /**
     * The 36 demo-account slot prefixes we'd create on first run.
     * Surfaced in the "skip user creation" disclosure so admins who
     * prefer to create the WP users themselves (e.g. no catch-all
     * available) know which addresses to set up before continuing.
     *
     * @return string[]
     */
    private static function expectedDemoSlots(): array {
        $slots = [
            'admin', 'hod', 'coach1', 'coach2', 'coach3', 'coach4',
            'physio', 'team-manager', 'scout', 'observer',
        ];
        for ( $i = 1; $i <= 26; $i++ ) {
            $slots[] = sprintf( 'player%02d', $i );
        }
        return $slots;
    }

    private static function defaultClubName(): string {
        $name = trim( \TT\Infrastructure\Query\QueryHelpers::get_config( 'academy_name', '' ) );
        return $name !== '' ? $name : 'Demo Academy';
    }

    /**
     * v3.91.7 — one-click "Rebuild journey events" section.
     *
     * Demo data created before v3.91.7 didn't fire the runtime hooks
     * (`tt_player_created`, `tt_evaluation_saved`, etc.) so journey
     * events never landed. The button calls `JourneyBackfillService::
     * rebuildAll()` which walks the source tables and emits events via
     * `EventEmitter::emit()` — idempotent on uk_natural so safe to
     * re-run any time. Useful for both demo data and any real data
     * that bulk-imported without hooks firing.
     */
    private static function renderJourneyRebuildSection(): void {
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Rebuild journey events', 'talenttrack' ); ?></h2>
        <p style="max-width:720px;">
            <?php esc_html_e( 'Walk every evaluation, goal, PDP verdict, player join-date, and trial case in this club and emit any missing journey events. Idempotent — re-running is safe and fills only the gap. Use this after a demo run on installs that pre-date the v3.91.7 generator hooks, or anytime a bulk import bypassed the runtime save hooks.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'tt_demo_journey_rebuild', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_journey_rebuild" />
            <?php submit_button( __( 'Rebuild journey events', 'talenttrack' ), 'secondary', '', false ); ?>
        </form>
        <?php
    }

    private static function renderWipeSection(): void {
        $user_count = count( DemoBatchRegistry::persistentEntityIds( 'wp_user' ) );
        // v3.90.1 — per-category counts for the wipe-form preview. Each
        // count is the cascade total: e.g. checking "teams" wipes
        // teams + team_person + activities + attendance + evaluations
        // + eval_ratings, so the count reflects the full fan-out.
        $cat_counts = DemoDataCleaner::categoryCounts();
        // #0080 Wave B2 — per-batch scope. The dropdown is populated
        // with all known batches plus an "All batches" default. The
        // JS preview computes per-batch counts on the fly so the
        // operator sees the cascade size narrow as they pick a batch.
        $batches = DemoBatchRegistry::listBatches();
        $per_batch_counts = [];
        foreach ( $batches as $b ) {
            $bid = (string) $b['batch_id'];
            $per_batch_counts[ $bid ] = DemoDataCleaner::categoryCounts( $bid );
        }
        $per_batch_counts['__all__'] = $cat_counts;
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Wipe', 'talenttrack' ); ?></h2>

        <h3><?php esc_html_e( 'Wipe demo data', 'talenttrack' ); ?></h3>
        <p style="max-width:720px;">
            <?php esc_html_e( 'Pick which categories of demo-tagged rows to remove. Each box wipes the category plus its dependents (e.g. "Teams" also wipes the activities + attendance + evaluations + eval-ratings tied to those teams). Non-demo data is never touched. The 36 persistent demo WP users are preserved across this action — use "Wipe demo users too" below for those.', 'talenttrack' ); ?>
        </p>
        <p style="max-width:720px;color:#5b6e75;font-size:12px;">
            <?php esc_html_e( 'Counts shown are the demo-tagged rows that match the cascade right now. Double-counts across overlapping categories are deduplicated server-side at delete time.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tt-demo-wipe-form" data-tt-batch-counts="<?php echo esc_attr( (string) wp_json_encode( $per_batch_counts ) ); ?>">
            <?php wp_nonce_field( 'tt_demo_wipe_data', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_wipe_data" />

            <?php if ( ! empty( $batches ) ) : ?>
                <p style="max-width:720px;margin:0 0 12px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">
                        <?php esc_html_e( 'Batch', 'talenttrack' ); ?>
                    </label>
                    <select name="batch_id" id="tt-demo-batch-id" style="min-width:320px;">
                        <option value="all"><?php esc_html_e( 'All batches', 'talenttrack' ); ?></option>
                        <?php foreach ( $batches as $b ) : ?>
                            <option value="<?php echo esc_attr( (string) $b['batch_id'] ); ?>">
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: 1: batch id, 2: created-at timestamp, 3: total tag count. */
                                    __( '%1$s — %2$s (%3$d tagged rows)', 'talenttrack' ),
                                    (string) $b['batch_id'],
                                    (string) $b['created_at'],
                                    (int) $b['tag_count']
                                ) );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span style="display:block;font-size:12px;color:#5b6e75;margin-top:4px;">
                        <?php esc_html_e( 'Pick a batch to scope the wipe to that batch only. "All batches" preserves the historical behaviour.', 'talenttrack' ); ?>
                    </span>
                </p>
            <?php endif; ?>

            <fieldset style="border:1px solid #d6dadd;border-radius:6px;padding:12px 14px;margin:0 0 12px;background:#f8fafc;max-width:720px;">
                <legend style="font-weight:600;padding:0 6px;">
                    <?php esc_html_e( 'Categories to wipe', 'talenttrack' ); ?>
                </legend>

                <strong style="display:block;margin:6px 0 4px;font-size:12px;color:#5b6e75;text-transform:uppercase;letter-spacing:0.04em;">
                    <?php esc_html_e( 'Master data', 'talenttrack' ); ?>
                </strong>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="wipe_cat[]" value="teams" />
                    <?php esc_html_e( 'Teams', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php
                    /* translators: %d is the row count */
                    echo esc_html( sprintf( __( '%d demo rows incl. team_person, activities, attendance, evaluations, eval_ratings on those teams', 'talenttrack' ), (int) $cat_counts['teams'] ) );
                    ?></span>
                </label>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="wipe_cat[]" value="people" />
                    <?php esc_html_e( 'People', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php
                    echo esc_html( sprintf( __( '%d demo rows incl. team_person assignments. The matching WP users stay (separate "Wipe demo users" form below).', 'talenttrack' ), (int) $cat_counts['people'] ) );
                    ?></span>
                </label>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="wipe_cat[]" value="players" />
                    <?php esc_html_e( 'Players', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php
                    echo esc_html( sprintf( __( '%d demo rows incl. attendance, evaluations, eval_ratings, goals tied to those players', 'talenttrack' ), (int) $cat_counts['players'] ) );
                    ?></span>
                </label>

                <strong style="display:block;margin:14px 0 4px;font-size:12px;color:#5b6e75;text-transform:uppercase;letter-spacing:0.04em;">
                    <?php esc_html_e( 'Dependent entities', 'talenttrack' ); ?>
                </strong>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="wipe_cat[]" value="activities" />
                    <?php esc_html_e( 'Activities', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php
                    echo esc_html( sprintf( __( '%d demo rows incl. attendance for those activities', 'talenttrack' ), (int) $cat_counts['activities'] ) );
                    ?></span>
                </label>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="wipe_cat[]" value="evaluations" />
                    <?php esc_html_e( 'Evaluations', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php
                    echo esc_html( sprintf( __( '%d demo rows incl. per-category eval_ratings', 'talenttrack' ), (int) $cat_counts['evaluations'] ) );
                    ?></span>
                </label>
                <label style="display:block;padding:4px 0;cursor:pointer;">
                    <input type="checkbox" name="wipe_cat[]" value="goals" />
                    <?php esc_html_e( 'Goals', 'talenttrack' ); ?>
                    <span style="color:#5b6e75;"> — <?php
                    echo esc_html( sprintf( __( '%d demo rows', 'talenttrack' ), (int) $cat_counts['goals'] ) );
                    ?></span>
                </label>
            </fieldset>

            <label>
                <input type="text" name="confirm_text" placeholder="<?php esc_attr_e( 'Type WIPE to confirm', 'talenttrack' ); ?>" class="regular-text" />
            </label>
            <?php submit_button( __( 'Wipe selected categories', 'talenttrack' ), 'delete', '', false ); ?>
            <p id="tt-demo-wipe-preview" style="margin-top:8px;font-size:13px;color:#1e3a5c;font-weight:600;" aria-live="polite">
                <?php esc_html_e( 'Nothing selected.', 'talenttrack' ); ?>
            </p>
        </form>
        <script>
        // #0080 Wave B1 + B2 — live cascade preview on the wipe form.
        // Reads per-batch category counts from the form's
        // `data-tt-batch-counts` attribute (server-rendered) and
        // recomputes the union total on every checkbox / batch change.
        (function(){
            var form = document.getElementById('tt-demo-wipe-form');
            if (!form) return;
            var preview = document.getElementById('tt-demo-wipe-preview');
            if (!preview) return;
            var raw = form.getAttribute('data-tt-batch-counts') || '{}';
            var batchCounts;
            try { batchCounts = JSON.parse(raw); } catch (e) { batchCounts = { '__all__': {} }; }

            var batchSel = document.getElementById('tt-demo-batch-id');
            var checkboxes = form.querySelectorAll('input[type="checkbox"][name="wipe_cat[]"]');

            var labelTpl = <?php echo wp_json_encode(
                /* translators: 1: total cascade row count, 2: number of selected categories */
                __( 'Will wipe ~%1$d rows across %2$d categories.', 'talenttrack' )
            ); ?>;
            var labelEmpty = <?php echo wp_json_encode( __( 'Nothing selected.', 'talenttrack' ) ); ?>;

            function recompute(){
                var key = batchSel ? batchSel.value : 'all';
                if (key === 'all') key = '__all__';
                var counts = batchCounts[key] || batchCounts['__all__'] || {};
                var total = 0;
                var picked = 0;
                checkboxes.forEach(function(cb){
                    if (!cb.checked) return;
                    picked++;
                    total += parseInt(counts[cb.value] || 0, 10);
                });
                if (picked === 0) {
                    preview.textContent = labelEmpty;
                } else {
                    preview.textContent = labelTpl.replace('%1$d', total).replace('%2$d', picked);
                }
            }
            checkboxes.forEach(function(cb){ cb.addEventListener('change', recompute); });
            if (batchSel) batchSel.addEventListener('change', recompute);
            recompute();
        })();
        </script>

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Wipe demo users too', 'talenttrack' ); ?></h3>
        <p style="max-width:720px;">
            <?php printf(
                /* translators: %d is the count of persistent demo users */
                esc_html__( 'Removes the persistent set of %d demo WP users. Rare — typically only when changing demo domain or uninstalling. Three safety rails fire per user (domain match, not-current-user, not-last-admin).', 'talenttrack' ),
                (int) $user_count
            ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_demo_wipe_users', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_wipe_users" />
            <label style="display:block; margin-bottom:6px;">
                <input type="text" name="expected_domain" placeholder="<?php esc_attr_e( 'Expected email domain (e.g. demo.talenttrack.local)', 'talenttrack' ); ?>" class="regular-text" />
            </label>
            <label style="display:block; margin-bottom:6px;">
                <input type="text" name="confirm_text" placeholder="<?php esc_attr_e( 'Type WIPE USERS to confirm', 'talenttrack' ); ?>" class="regular-text" />
            </label>
            <?php submit_button( __( 'Wipe demo users', 'talenttrack' ), 'delete', '', false ); ?>
        </form>
        <?php
    }

    /** @param object[] $batches */
    private static function renderBatches( array $batches ): void {
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Past batches', 'talenttrack' ); ?></h2>
        <?php if ( ! $batches ) : ?>
            <p><em><?php esc_html_e( 'No batches yet.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:720px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Batch id', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Tagged entities', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $batches as $b ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $b->batch_id ); ?></code></td>
                            <td><?php echo esc_html( (string) $b->created_at ); ?></td>
                            <td style="text-align:right;"><?php echo (int) $b->total_entities; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    // Action handlers

    public static function handleGenerate(): void {
        $redirect = admin_url( 'tools.php?page=' . self::SLUG );

        // v3.89.2 — same post_max_size guard as handleExcelImport so a
        // too-big workbook in the unified generator form bounces with a
        // useful message instead of falling through to admin-post.php's
        // generic empty-POST response.
        if ( self::postMaxSizeExceeded() ) {
            self::bounce( $redirect, sprintf(
                /* translators: %s: post_max_size from php.ini, e.g. "8M" */
                __( 'Upload exceeded the server\'s POST size limit (post_max_size = %s). Ask your hoster to raise it, or split the workbook into smaller pieces.', 'talenttrack' ),
                (string) ini_get( 'post_max_size' )
            ) );
        }

        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_generate', 'tt_demo_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'demo.generate' );

        // Generating the Large preset can run 90+ seconds on shared hosts;
        // raise the ceiling so we don't time out halfway through.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $domain    = isset( $_POST['domain'] )    ? sanitize_text_field( wp_unslash( (string) $_POST['domain'] ) )    : '';
        $password  = isset( $_POST['password'] )  ? (string) wp_unslash( (string) $_POST['password'] )                : '';
        $preset    = isset( $_POST['preset'] )    ? sanitize_key( (string) $_POST['preset'] )                         : 'small';
        $seed      = isset( $_POST['seed'] )      ? (int) $_POST['seed']                                              : 20260504;
        $club_name        = isset( $_POST['club_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['club_name'] ) ) : '';
        $content_language = isset( $_POST['content_language'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['content_language'] ) ) : '';
        $confirmed        = ! empty( $_POST['domain_confirmed'] );

        // #0059 — Source step: procedural / excel / hybrid. Default
        // procedural unless an Excel file was uploaded, in which case
        // hybrid (per the spec — "hybrid is the default once a file is
        // selected").
        $source = isset( $_POST['source'] ) ? sanitize_key( (string) $_POST['source'] ) : 'procedural';
        if ( ! in_array( $source, [ 'procedural', 'excel', 'hybrid' ], true ) ) $source = 'procedural';

        // v3.85.0 / v3.90.1 — selective generation toggles. Master-data
        // toggles (teams/people/players) only apply to procedural; the
        // three dependent-entity toggles (activities/evaluations/goals)
        // apply to every source.
        $gen_teams       = ! empty( $_POST['gen_teams'] );
        $gen_people      = ! empty( $_POST['gen_people'] );
        $gen_players     = ! empty( $_POST['gen_players'] );
        $gen_activities  = ! empty( $_POST['gen_activities'] );
        $gen_evaluations = ! empty( $_POST['gen_evaluations'] );
        $gen_goals       = ! empty( $_POST['gen_goals'] );

        $users_exist = DemoGenerator::persistentUsersExist();

        if ( $source === 'procedural' && $gen_people && ! $users_exist && ! $confirmed ) {
            self::bounce( $redirect, 'Please confirm the demo email domain is yours.' );
        }
        if ( $source === 'procedural' && $gen_people && ! $users_exist && ( ! $domain || ! $password ) ) {
            self::bounce( $redirect, 'Domain and password are required for the first run.' );
        }

        // v3.85.0 — selective generation validation: if a master-data
        // category is opted out, ensure the corresponding rows exist
        // in the current club. Empty downstream output isn't useful
        // and the silent failure mode is a footgun.
        if ( $source === 'procedural' ) {
            global $wpdb;
            $club_id = (int) \TT\Infrastructure\Tenancy\CurrentClub::id();
            if ( ! $gen_teams ) {
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tt_teams WHERE club_id = %d AND archived_at IS NULL",
                    $club_id
                ) );
                if ( $count === 0 ) self::bounce( $redirect, 'Cannot skip Generate teams — no teams exist in this club yet. Either check Generate teams, or create teams manually first.' );
            }
            if ( ! $gen_players ) {
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players WHERE club_id = %d AND status = 'active'",
                    $club_id
                ) );
                if ( $count === 0 ) self::bounce( $redirect, 'Cannot skip Generate players — no active players exist in this club yet.' );
            }
        }

        // Resolve uploaded Excel path once for excel + hybrid sources.
        $excel_path = '';
        if ( $source !== 'procedural' ) {
            if ( ! isset( $_FILES['demo_excel'] ) || ! is_array( $_FILES['demo_excel'] )
                 || ( $_FILES['demo_excel']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
                self::bounce( $redirect, __( 'Excel and Hybrid sources require an uploaded workbook.', 'talenttrack' ) );
            }
            $err = (int) ( $_FILES['demo_excel']['error'] ?? 0 );
            if ( $err !== UPLOAD_ERR_OK ) {
                self::bounce( $redirect, self::uploadErrorMessage( $err ) );
            }
            $excel_path = (string) ( $_FILES['demo_excel']['tmp_name'] ?? '' );
            if ( ! is_uploaded_file( $excel_path ) ) {
                self::bounce( $redirect, __( 'Invalid upload.', 'talenttrack' ) );
            }

            // v3.89.2 — XLSX parsing wants 64-128MB even with read-only
            // mode; raise the ceiling before PhpSpreadsheet loads to
            // prevent a fatal that would surface as a hoster 500.
            wp_raise_memory_limit( 'admin' );
        }

        try {
            // Generation paths should read tagged data across all batches.
            \TT\Modules\DemoData\DemoMode::overrideForRequest( \TT\Modules\DemoData\DemoMode::NEUTRAL );
            $result = DemoGenerator::run( [
                'preset'           => $preset,
                'domain'           => $domain,
                'password'         => $password,
                'seed'             => $seed,
                'club_name'        => $club_name,
                'content_language' => $content_language,
                'source'           => $source,
                'excel_path'       => $excel_path,
                'gen_teams'        => $gen_teams,
                'gen_people'       => $gen_people,
                'gen_players'      => $gen_players,
                'gen_activities'   => $gen_activities,
                'gen_evaluations'  => $gen_evaluations,
                'gen_goals'        => $gen_goals,
            ] );
            \TT\Modules\DemoData\DemoMode::clearOverride();
        } catch ( \Throwable $e ) {
            \TT\Modules\DemoData\DemoMode::clearOverride();
            self::bounce( $redirect, $e->getMessage() );
        }

        if ( ! empty( $result['excel_blockers'] ) ) {
            self::bounce( $redirect, 'Excel import failed: ' . implode( ' · ', (array) $result['excel_blockers'] ) );
        }

        set_transient( self::TRANSIENT_ACCOUNTS,   $result['accounts'],   10 * MINUTE_IN_SECONDS );
        set_transient( self::TRANSIENT_COUNTS,     $result['counts'],     10 * MINUTE_IN_SECONDS );
        set_transient( self::TRANSIENT_USER_STATS, $result['user_stats'], 10 * MINUTE_IN_SECONDS );

        $redirect = add_query_arg(
            [ 'tt_demo_msg' => 'generated', 'tt_demo_batch' => rawurlencode( $result['batch_id'] ) ],
            $redirect
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handleWipeData(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_wipe_data', 'tt_demo_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'demo.wipe_data' );

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );
        $typed    = isset( $_POST['confirm_text'] ) ? trim( (string) wp_unslash( (string) $_POST['confirm_text'] ) ) : '';
        if ( $typed !== 'WIPE' ) {
            self::bounce( $redirect, 'Type WIPE exactly to confirm.' );
        }

        // v3.90.1 — selective wipe. Operator picks one or more categories
        // from `DemoDataCleaner::CATEGORIES`; the cleaner expands each
        // pick to its dependency cascade. Empty selection bounces — the
        // form has zero default-checked boxes, so an accidental "WIPE +
        // submit" with nothing selected is a no-op the operator should
        // be told about.
        $raw = $_POST['wipe_cat'] ?? [];
        if ( ! is_array( $raw ) ) $raw = [];
        $valid_keys = array_keys( DemoDataCleaner::CATEGORIES );
        $cats = [];
        foreach ( $raw as $c ) {
            $c = sanitize_key( (string) $c );
            if ( in_array( $c, $valid_keys, true ) && ! in_array( $c, $cats, true ) ) {
                $cats[] = $c;
            }
        }
        if ( ! $cats ) {
            self::bounce( $redirect, 'Pick at least one category to wipe.' );
        }

        // #0080 Wave B2 — optional batch_id scope. Empty / "all" leaves
        // the historical all-batches behaviour in place.
        $batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( (string) wp_unslash( (string) $_POST['batch_id'] ) ) : '';
        if ( $batch_id === 'all' ) $batch_id = '';

        $deleted = DemoDataCleaner::wipeData( $cats, $batch_id !== '' ? $batch_id : null );
        $total   = array_sum( $deleted );

        $redirect = add_query_arg(
            [
                'tt_demo_msg'        => 'wiped',
                'tt_demo_wiped_cats' => rawurlencode( implode( ',', $cats ) ),
                'tt_demo_wiped_n'    => (int) $total,
            ],
            $redirect
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handleWipeUsers(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_wipe_users', 'tt_demo_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'demo.wipe_users' );

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );
        $typed    = isset( $_POST['confirm_text'] )    ? trim( (string) wp_unslash( (string) $_POST['confirm_text'] ) )    : '';
        $domain   = isset( $_POST['expected_domain'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expected_domain'] ) ) : '';
        if ( $typed !== 'WIPE USERS' ) {
            self::bounce( $redirect, 'Type WIPE USERS exactly to confirm.' );
        }
        if ( ! $domain ) {
            self::bounce( $redirect, 'Expected email domain is required — must match the demo users\' domain.' );
        }

        $result = DemoDataCleaner::wipeUsers( $domain );

        if ( $result['refused'] ) {
            $reasons = [];
            foreach ( $result['refused'] as $uid => $why ) {
                $reasons[] = "#{$uid} ({$why})";
            }
            $msg = sprintf(
                'Wiped %d users; %d refused: %s',
                $result['deleted'],
                count( $result['refused'] ),
                implode( ', ', $reasons )
            );
            self::bounce( $redirect, $msg );
        }

        wp_safe_redirect( add_query_arg( 'tt_demo_msg', 'users_wiped', $redirect ) );
        exit;
    }

    public static function handleJourneyRebuild(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_journey_rebuild', 'tt_demo_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'demo.journey_rebuild' );

        // Backfill walks every player/eval/goal/etc. — generous time
        // budget on shared hosts.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $stats = \TT\Infrastructure\Journey\JourneyBackfillService::rebuildAll();
        $total = (int) array_sum( $stats );

        $redirect = add_query_arg(
            [
                'tt_demo_msg'             => 'journey_rebuilt',
                'tt_demo_journey_walked'  => $total,
            ],
            admin_url( 'tools.php?page=' . self::SLUG )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handleModeToggle(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_mode', 'tt_demo_nonce' );

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );
        $target   = isset( $_POST['target'] ) ? sanitize_key( (string) $_POST['target'] ) : '';

        if ( $target === 'on' ) {
            DemoMode::set( DemoMode::ON );
        } elseif ( $target === 'off' ) {
            $typed = isset( $_POST['confirm_text'] ) ? trim( (string) wp_unslash( (string) $_POST['confirm_text'] ) ) : '';
            if ( $typed !== 'EXIT DEMO' ) {
                self::bounce( $redirect, 'Type EXIT DEMO exactly to leave demo mode.' );
            }
            DemoMode::set( DemoMode::OFF );
        } else {
            self::bounce( $redirect, 'Unknown target mode.' );
        }

        wp_safe_redirect( add_query_arg( 'tt_demo_msg', 'mode', $redirect ) );
        exit;
    }

    private static function bounce( string $url, string $error ): void {
        wp_safe_redirect( add_query_arg( 'tt_demo_error', rawurlencode( $error ), $url ) );
        exit;
    }

    /**
     * #0059 — stream the .xlsx template built fresh from `SheetSchemas`.
     */
    public static function handleTemplateDownload(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_excel_template' );

        $ok = \TT\Modules\DemoData\Excel\TemplateBuilder::streamDownload();
        if ( ! $ok ) {
            self::bounce(
                admin_url( 'tools.php?page=' . self::SLUG ),
                'PhpSpreadsheet is not installed. Run `composer install --no-dev` from the plugin root.'
            );
        }
        exit;
    }

    /**
     * #0059 — accept an uploaded .xlsx, validate, import literally.
     *
     * v3.89.2 — hardened against the "looks like a hosting server side
     * error" failure mode. Three reinforcements: (1) detect the
     * `post_max_size` overflow case (a too-big upload makes PHP discard
     * `$_POST` + `$_FILES` entirely; admin-post.php would 400 with no
     * useful message). (2) Raise memory + execution time before handing
     * to PhpSpreadsheet — XLSX parsing wants 64-128MB even with
     * `setReadDataOnly(true)`, and shared hosts default below that.
     * (3) Wrap the importer call in a `\Throwable` catch so an OOM /
     * `\Error` / `\TypeError` becomes a friendly red bounce instead of
     * a fatal that the host's reverse proxy turns into a generic 500.
     */
    public static function handleExcelImport(): void {
        $redirect = admin_url( 'tools.php?page=' . self::SLUG );

        if ( self::postMaxSizeExceeded() ) {
            self::bounce( $redirect, sprintf(
                /* translators: %s: post_max_size from php.ini, e.g. "8M" */
                __( 'Upload exceeded the server\'s POST size limit (post_max_size = %s). Ask your hoster to raise it, or split the workbook into smaller pieces.', 'talenttrack' ),
                (string) ini_get( 'post_max_size' )
            ) );
        }

        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_excel_import', 'tt_demo_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'demo.excel_import' );

        if ( ! isset( $_FILES['demo_excel'] ) || ! is_array( $_FILES['demo_excel'] ) ) {
            self::bounce( $redirect, __( 'No file uploaded.', 'talenttrack' ) );
        }
        $file = $_FILES['demo_excel'];
        $err  = (int) ( $file['error'] ?? UPLOAD_ERR_OK );
        if ( $err !== UPLOAD_ERR_OK ) {
            self::bounce( $redirect, self::uploadErrorMessage( $err ) );
        }
        $tmp_path      = (string) ( $file['tmp_name'] ?? '' );
        $original_name = (string) ( $file['name'] ?? 'upload.xlsx' );
        if ( ! is_uploaded_file( $tmp_path ) ) {
            self::bounce( $redirect, __( 'Invalid upload.', 'talenttrack' ) );
        }

        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );

        try {
            $importer = new \TT\Modules\DemoData\Excel\ExcelImporter();
            $result   = $importer->importFile( $tmp_path, $original_name );
        } catch ( \Throwable $e ) {
            \TT\Infrastructure\Logging\Logger::error( 'demo.excel.import.fatal', [
                'error' => $e->getMessage(),
                'file'  => $original_name,
                'class' => get_class( $e ),
            ] );
            self::bounce( $redirect, sprintf(
                /* translators: %s: error message */
                __( 'Excel import crashed: %s. The plugin caught it before WordPress fell over, but the workbook was not imported. Check the TalentTrack log for details.', 'talenttrack' ),
                $e->getMessage()
            ) );
        }

        if ( ! $result['ok'] ) {
            $msg = $result['blockers'][0] ?? __( 'Import failed.', 'talenttrack' );
            self::bounce( $redirect, (string) $msg );
        }

        $redirect_with_msg = add_query_arg( [
            'tt_demo_msg'   => 'excel_imported',
            'tt_demo_batch' => $result['batch_id'],
        ], $redirect );
        wp_safe_redirect( $redirect_with_msg );
        exit;
    }

    /**
     * Detect when PHP discarded `$_POST` + `$_FILES` because the request
     * body exceeded `post_max_size`. Symptom: REQUEST_METHOD is POST,
     * Content-Length is non-zero, but `$_POST` is empty. Without this
     * check the user gets WP's generic "Are you sure you want to do
     * this?" response (the nonce isn't even readable) which is the
     * confusing failure mode.
     */
    private static function postMaxSizeExceeded(): bool {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return false;
        if ( ! empty( $_POST ) ) return false;
        $content_length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
        return $content_length > 0;
    }

    /**
     * Translate a PHP `UPLOAD_ERR_*` code to an operator-readable
     * message that names the relevant php.ini directive when one is at
     * fault. The fact that an upload failed at the file-size limit
     * vs. the post-size limit vs. a partial upload is the difference
     * between "raise upload_max_filesize" and "raise post_max_size".
     */
    private static function uploadErrorMessage( int $err ): string {
        switch ( $err ) {
            case UPLOAD_ERR_INI_SIZE:
                return sprintf(
                    /* translators: %s: upload_max_filesize value */
                    __( 'Upload exceeded the server\'s upload_max_filesize (%s). Ask your hoster to raise it, or split the workbook.', 'talenttrack' ),
                    (string) ini_get( 'upload_max_filesize' )
                );
            case UPLOAD_ERR_FORM_SIZE:
                return __( 'Upload exceeded the form\'s declared maximum size.', 'talenttrack' );
            case UPLOAD_ERR_PARTIAL:
                return __( 'Upload was interrupted mid-transfer. Try again on a stable connection.', 'talenttrack' );
            case UPLOAD_ERR_NO_FILE:
                return __( 'No file was selected for upload.', 'talenttrack' );
            case UPLOAD_ERR_NO_TMP_DIR:
                return __( 'PHP could not write to its temporary directory. Ask your hoster to fix `upload_tmp_dir`.', 'talenttrack' );
            case UPLOAD_ERR_CANT_WRITE:
                return __( 'PHP could not write the uploaded file to disk.', 'talenttrack' );
            case UPLOAD_ERR_EXTENSION:
                return __( 'A PHP extension blocked the upload.', 'talenttrack' );
            default:
                return sprintf(
                    /* translators: %d: PHP UPLOAD_ERR_* code */
                    __( 'Upload failed (error code %d).', 'talenttrack' ),
                    $err
                );
        }
    }
}
