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
    }

    public static function registerMenu(): void {
        add_submenu_page(
            'tools.php',
            __( 'TalentTrack Demo', 'talenttrack' ),
            __( 'TalentTrack Demo', 'talenttrack' ),
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

    /* ═══ Render partials ═══ */

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
            foreach ( [ 'teams', 'players', 'evaluations', 'sessions', 'goals' ] as $k ) {
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
                    <input type="text" name="confirm_text" placeholder="Type EXIT DEMO to confirm" class="regular-text" />
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
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Generate', 'talenttrack' ); ?></h2>

        <?php if ( $users_exist ) : ?>
            <div class="notice notice-info inline" style="margin:8px 0 16px;">
                <p>
                    <strong><?php esc_html_e( 'Demo users already exist from a previous run.', 'talenttrack' ); ?></strong>
                    <?php esc_html_e( 'No new WP users will be created and no welcome emails will be sent. This run only creates data rows (teams, players, evaluations, sessions, goals).', 'talenttrack' ); ?>
                </p>
            </div>
        <?php else : ?>
            <div class="notice notice-warning inline" style="margin:8px 0 16px;">
                <p>
                    <strong><?php esc_html_e( 'First run.', 'talenttrack' ); ?></strong>
                    <?php esc_html_e( 'This run will create 36 persistent demo WP users and send them WordPress welcome emails. The email domain must catch mail you control.', 'talenttrack' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_demo_generate', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_generate" />
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="tt_demo_preset"><?php esc_html_e( 'Preset', 'talenttrack' ); ?></label></th>
                        <td>
                            <select id="tt_demo_preset" name="preset">
                                <?php foreach ( DemoGenerator::PRESETS as $key => $cfg ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, 'small' ); ?>>
                                        <?php echo esc_html( sprintf(
                                            '%s — %d team(s), %d players/team, %d weeks',
                                            ucfirst( $key ), $cfg['teams'], $cfg['players_per_team'], $cfg['weeks']
                                        ) ); ?>
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
                                <?php if ( $users_exist ) :
                                    esc_html_e( 'Ignored — users already exist. Kept for reference; change only if you plan to wipe users and recreate.', 'talenttrack' );
                                else :
                                    esc_html_e( 'Every demo account will be <slot>@<this-domain>. Use a catch-all address you control.', 'talenttrack' );
                                endif; ?>
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
                    <tr>
                        <th scope="row"><label for="tt_demo_seed"><?php esc_html_e( 'Seed', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="number" id="tt_demo_seed" name="seed" value="20260504" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Fixed default 20260504 — produces the same roster every run. Change for a different roster.', 'talenttrack' ); ?></p>
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
                    <?php endif; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Generate demo data', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    private static function defaultClubName(): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE config_key = %s",
            'academy_name'
        ) );
        $n = $name ? trim( (string) $name ) : '';
        return $n !== '' ? $n : 'Demo Academy';
    }

    private static function renderWipeSection(): void {
        $user_count = count( DemoBatchRegistry::persistentEntityIds( 'wp_user' ) );
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Wipe', 'talenttrack' ); ?></h2>

        <h3><?php esc_html_e( 'Wipe demo data', 'talenttrack' ); ?></h3>
        <p style="max-width:720px;">
            <?php esc_html_e( 'Removes every demo-tagged row (evaluations, sessions, goals, attendance, ratings, players, teams) in dependency order. The 36 persistent demo WP users are preserved. Non-demo data is never touched.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_demo_wipe_data', 'tt_demo_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_wipe_data" />
            <label>
                <input type="text" name="confirm_text" placeholder="Type WIPE to confirm" class="regular-text" />
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
                <input type="text" name="expected_domain" placeholder="Expected email domain (e.g. demo.talenttrack.local)" class="regular-text" />
            </label>
            <label style="display:block; margin-bottom:6px;">
                <input type="text" name="confirm_text" placeholder="Type WIPE USERS to confirm" class="regular-text" />
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

    /* ═══ Action handlers ═══ */

    public static function handleGenerate(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_generate', 'tt_demo_nonce' );

        $domain    = isset( $_POST['domain'] )    ? sanitize_text_field( wp_unslash( (string) $_POST['domain'] ) )    : '';
        $password  = isset( $_POST['password'] )  ? (string) wp_unslash( (string) $_POST['password'] )                : '';
        $preset    = isset( $_POST['preset'] )    ? sanitize_key( (string) $_POST['preset'] )                         : 'small';
        $seed      = isset( $_POST['seed'] )      ? (int) $_POST['seed']                                              : 20260504;
        $club_name = isset( $_POST['club_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['club_name'] ) ) : '';
        $confirmed = ! empty( $_POST['domain_confirmed'] );

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );
        $users_exist = DemoGenerator::persistentUsersExist();

        if ( ! $users_exist && ! $confirmed ) {
            self::bounce( $redirect, 'Please confirm the demo email domain is yours.' );
        }
        if ( ! $users_exist && ( ! $domain || ! $password ) ) {
            self::bounce( $redirect, 'Domain and password are required for the first run.' );
        }

        try {
            // Generation paths should read tagged data across all batches.
            \TT\Modules\DemoData\DemoMode::overrideForRequest( \TT\Modules\DemoData\DemoMode::NEUTRAL );
            $result = DemoGenerator::run( [
                'preset'    => $preset,
                'domain'    => $domain,
                'password'  => $password,
                'seed'      => $seed,
                'club_name' => $club_name,
            ] );
            \TT\Modules\DemoData\DemoMode::clearOverride();
        } catch ( \Throwable $e ) {
            \TT\Modules\DemoData\DemoMode::clearOverride();
            self::bounce( $redirect, $e->getMessage() );
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
}
