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
        add_action( 'admin_menu', [ self::class, 'registerMenu' ] );
        add_action( 'admin_post_tt_demo_generate',  [ self::class, 'handleGenerate'  ] );
        add_action( 'admin_post_tt_demo_wipe_data', [ self::class, 'handleWipeData'  ] );
        add_action( 'admin_post_tt_demo_wipe_users',[ self::class, 'handleWipeUsers' ] );
        add_action( 'admin_post_tt_demo_mode',      [ self::class, 'handleModeToggle'] );
        // #0059 — Excel-driven demo data.
        add_action( 'admin_post_tt_demo_excel_template', [ self::class, 'handleTemplateDownload' ] );
        add_action( 'admin_post_tt_demo_excel_import',   [ self::class, 'handleExcelImport' ] );
    }

    public static function registerMenu(): void {
        // #0063 — moved from `tools.php` parent to the TalentTrack
        // top-level menu so demo-data lives next to the rest of the
        // plugin's configuration. Old direct-URL `?page=` slug stays
        // the same so any saved bookmarks keep working.
        add_submenu_page(
            'talenttrack',
            __( 'Demo data', 'talenttrack' ),
            __( 'Demo data', 'talenttrack' ),
            self::CAP,
            self::SLUG,
            [ self::class, 'render' ]
        );
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
            ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Demo data wiped.', 'talenttrack' ); ?></p></div>
            <?php
        } elseif ( $notice === 'users_wiped' ) {
            ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Demo users removed.', 'talenttrack' ); ?></p></div>
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
                </div>
            </fieldset>
            <script>
            (function(){
                var radios = document.querySelectorAll('input[data-tt-demo-source]');
                var box    = document.querySelector('[data-tt-demo-source-file]');
                if ( ! radios.length || ! box ) return;
                function toggle(){
                    var on = false;
                    radios.forEach(function(r){ if ( r.checked && r.value !== 'procedural' ) on = true; });
                    box.style.display = on ? 'block' : 'none';
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

    private static function renderWipeSection(): void {
        $user_count = count( DemoBatchRegistry::persistentEntityIds( 'wp_user' ) );
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Wipe', 'talenttrack' ); ?></h2>

        <h3><?php esc_html_e( 'Wipe demo data', 'talenttrack' ); ?></h3>
        <p style="max-width:720px;">
            <?php esc_html_e( 'Removes every demo-tagged row (evaluations, activities, goals, attendance, ratings, players, teams) in dependency order. The 36 persistent demo WP users are preserved. Non-demo data is never touched.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_demo_wipe_data', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_wipe_data" />
            <label>
                <input type="text" name="confirm_text" placeholder="<?php esc_attr_e( 'Type WIPE to confirm', 'talenttrack' ); ?>" class="regular-text" />
            </label>
            <?php submit_button( __( 'Wipe demo data', 'talenttrack' ), 'delete', '', false ); ?>
        </form>

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
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_generate', 'tt_demo_nonce' );

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

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );
        $users_exist = DemoGenerator::persistentUsersExist();

        if ( ! $users_exist && ! $confirmed ) {
            self::bounce( $redirect, 'Please confirm the demo email domain is yours.' );
        }
        if ( ! $users_exist && ( ! $domain || ! $password ) ) {
            self::bounce( $redirect, 'Domain and password are required for the first run.' );
        }

        // Resolve uploaded Excel path once for excel + hybrid sources.
        $excel_path = '';
        if ( $source !== 'procedural' ) {
            if ( ! isset( $_FILES['demo_excel'] ) || ! is_array( $_FILES['demo_excel'] )
                 || ( $_FILES['demo_excel']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
                self::bounce( $redirect, 'Excel and Hybrid sources require an uploaded workbook.' );
            }
            if ( ( $_FILES['demo_excel']['error'] ?? 0 ) !== UPLOAD_ERR_OK ) {
                self::bounce( $redirect, 'Upload failed (error code ' . (int) $_FILES['demo_excel']['error'] . ').' );
            }
            $excel_path = (string) ( $_FILES['demo_excel']['tmp_name'] ?? '' );
            if ( ! is_uploaded_file( $excel_path ) ) {
                self::bounce( $redirect, 'Invalid upload.' );
            }
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

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );
        $typed    = isset( $_POST['confirm_text'] ) ? trim( (string) wp_unslash( (string) $_POST['confirm_text'] ) ) : '';
        if ( $typed !== 'WIPE' ) {
            self::bounce( $redirect, 'Type WIPE exactly to confirm.' );
        }

        DemoDataCleaner::wipeData();

        wp_safe_redirect( add_query_arg( 'tt_demo_msg', 'wiped', $redirect ) );
        exit;
    }

    public static function handleWipeUsers(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_wipe_users', 'tt_demo_nonce' );

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
     */
    public static function handleExcelImport(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_excel_import', 'tt_demo_nonce' );

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );
        if ( ! isset( $_FILES['demo_excel'] ) || ! is_array( $_FILES['demo_excel'] ) ) {
            self::bounce( $redirect, 'No file uploaded.' );
        }
        $file = $_FILES['demo_excel'];
        if ( ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
            self::bounce( $redirect, 'Upload failed (error code ' . (int) $file['error'] . ').' );
        }
        $tmp_path      = (string) ( $file['tmp_name'] ?? '' );
        $original_name = (string) ( $file['name'] ?? 'upload.xlsx' );
        if ( ! is_uploaded_file( $tmp_path ) ) {
            self::bounce( $redirect, 'Invalid upload.' );
        }

        $importer = new \TT\Modules\DemoData\Excel\ExcelImporter();
        $result   = $importer->importFile( $tmp_path, $original_name );

        if ( ! $result['ok'] ) {
            $msg = $result['blockers'][0] ?? 'Import failed.';
            self::bounce( $redirect, (string) $msg );
        }

        $redirect_with_msg = add_query_arg( [
            'tt_demo_msg'   => 'excel_imported',
            'tt_demo_batch' => $result['batch_id'],
        ], $redirect );
        wp_safe_redirect( $redirect_with_msg );
        exit;
    }
}
