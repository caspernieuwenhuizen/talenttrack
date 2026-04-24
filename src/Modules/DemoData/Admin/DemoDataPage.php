<?php
namespace TT\Modules\DemoData\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoGenerator;

/**
 * DemoDataPage — wp-admin entry point for the demo data generator.
 *
 * Checkpoint 1 ships a single-screen form (domain, password, seed,
 * preset) that runs UserGenerator + TeamGenerator + PlayerGenerator
 * in one go. The spec's four-step wizard, async progress polling,
 * scope toggle, and wipe actions land in Checkpoint 2.
 *
 * Gated behind manage_options (permanent rule per spec). Lives under
 * Tools menu so it stays clearly separated from the club-data admin
 * tree.
 */
class DemoDataPage {

    private const CAP  = 'manage_options';
    private const SLUG = 'tt-demo-data';

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'registerMenu' ] );
        add_action( 'admin_post_tt_demo_generate', [ self::class, 'handleGenerate' ] );
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

        $notice = isset( $_GET['tt_demo_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_demo_msg'] ) ) : '';
        $batch  = isset( $_GET['tt_demo_batch'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_demo_batch'] ) ) : '';
        $error  = isset( $_GET['tt_demo_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_demo_error'] ) ) : '';

        $counts   = DemoGenerator::counts();
        $batches  = DemoGenerator::batches();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TalentTrack Demo Data', 'talenttrack' ); ?></h1>

            <p style="max-width:720px;">
                <?php esc_html_e(
                    'Generate a realistic Dutch-academy dataset for demos. Checkpoint 1 creates 36 persistent demo WP users, teams (JO-age groups), and age-appropriate players. Evaluations, sessions, goals, demo-mode scope filter, and the wipe flow land in Checkpoint 2.',
                    'talenttrack'
                ); ?>
            </p>

            <?php if ( $notice === 'generated' && $batch ) : ?>
                <div class="notice notice-success">
                    <p>
                        <?php printf(
                            /* translators: %s is the batch id */
                            esc_html__( 'Demo data generated. Batch id: %s', 'talenttrack' ),
                            '<code>' . esc_html( $batch ) . '</code>'
                        ); ?>
                    </p>
                </div>
            <?php elseif ( $error ) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html( $error ); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Current demo footprint', 'talenttrack' ); ?></h2>
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
            <?php endif; ?>

            <h2 style="margin-top:32px;"><?php esc_html_e( 'Generate', 'talenttrack' ); ?></h2>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_demo_generate', 'tt_demo_nonce' ); ?>
                <input type="hidden" name="action" value="tt_demo_generate" />

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="tt_demo_preset"><?php esc_html_e( 'Preset', 'talenttrack' ); ?></label>
                            </th>
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
                            <th scope="row">
                                <label for="tt_demo_domain"><?php esc_html_e( 'Demo email domain', 'talenttrack' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="tt_demo_domain" name="domain" value="demo.talenttrack.local" class="regular-text" required />
                                <p class="description">
                                    <?php esc_html_e( 'Every demo account will be <slot>@<this-domain>. Use a catch-all address you control.', 'talenttrack' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="tt_demo_password"><?php esc_html_e( 'Shared password', 'talenttrack' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="tt_demo_password" name="password" value="demo1234!" class="regular-text" required />
                                <p class="description">
                                    <?php esc_html_e( 'Applied to all 36 demo accounts on first creation. Existing accounts are not updated.', 'talenttrack' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="tt_demo_seed"><?php esc_html_e( 'Seed', 'talenttrack' ); ?></label>
                            </th>
                            <td>
                                <input type="number" id="tt_demo_seed" name="seed" value="20260504" class="small-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Fixed default 20260504 (demo date) — produces the same roster every run. Change for a different roster.', 'talenttrack' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="tt_demo_confirm"><?php esc_html_e( 'I confirm this domain catches mail I own', 'talenttrack' ); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="tt_demo_confirm" name="domain_confirmed" value="1" required />
                                    <?php esc_html_e( 'Required — 36 WP welcome emails will be sent on first run.', 'talenttrack' ); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Generate demo data', 'talenttrack' ) ); ?>
            </form>

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
            <?php endif; ?>

            <p style="margin-top:32px; color:#888; font-style:italic;">
                <?php esc_html_e( 'Wipe actions arrive in Checkpoint 2 along with evaluations, sessions, goals, and the demo-mode scope filter.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    public static function handleGenerate(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_generate', 'tt_demo_nonce' );

        $domain   = isset( $_POST['domain'] )   ? sanitize_text_field( wp_unslash( (string) $_POST['domain'] ) )   : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( (string) $_POST['password'] ) : '';
        $preset   = isset( $_POST['preset'] )   ? sanitize_key( (string) $_POST['preset'] ) : 'small';
        $seed     = isset( $_POST['seed'] )     ? (int) $_POST['seed'] : 20260504;
        $confirmed = ! empty( $_POST['domain_confirmed'] );

        $redirect = admin_url( 'tools.php?page=' . self::SLUG );

        if ( ! $confirmed ) {
            wp_safe_redirect( add_query_arg( 'tt_demo_error', rawurlencode( 'Please confirm the demo email domain is yours.' ), $redirect ) );
            exit;
        }
        if ( ! $domain || ! $password ) {
            wp_safe_redirect( add_query_arg( 'tt_demo_error', rawurlencode( 'Domain and password are required.' ), $redirect ) );
            exit;
        }

        try {
            $result = DemoGenerator::run( [
                'preset'   => $preset,
                'domain'   => $domain,
                'password' => $password,
                'seed'     => $seed,
            ] );
        } catch ( \Throwable $e ) {
            wp_safe_redirect( add_query_arg( 'tt_demo_error', rawurlencode( $e->getMessage() ), $redirect ) );
            exit;
        }

        $redirect = add_query_arg(
            [ 'tt_demo_msg' => 'generated', 'tt_demo_batch' => rawurlencode( $result['batch_id'] ) ],
            $redirect
        );
        wp_safe_redirect( $redirect );
        exit;
    }
}
