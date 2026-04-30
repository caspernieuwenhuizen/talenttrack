<?php
namespace TT\Modules\Pdp\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * PdpPrintRouter — isolated print route for a single PDP file.
 *
 * URL: ?tt_pdp_print=1&file_id=N (optionally &include_evidence=1)
 *
 * Same isolation pattern as Stats\PrintRouter: intercept before the
 * admin / theme shell renders, emit a standalone document, exit.
 *
 * Single A4 default — photo, season label, current goals + status,
 * agreed actions, signature lines. The `include_evidence` toggle
 * appends a second page with evaluation summary + methodology pins.
 */
class PdpPrintRouter {

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET['tt_pdp_print'] ) ) return;
        $file_id = absint( $_GET['file_id'] ?? 0 );
        if ( $file_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this PDP file.', 'talenttrack' ) );
        }

        $files = new PdpFilesRepository();
        $file  = $files->find( $file_id );
        if ( ! $file ) {
            wp_die( esc_html__( 'PDP file not found.', 'talenttrack' ) );
        }
        if ( ! self::canAccess( $file ) ) {
            wp_die( esc_html__( 'You do not have access to this PDP file.', 'talenttrack' ) );
        }

        $include_evidence = ! empty( $_GET['include_evidence'] );

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        self::emit( $file, $include_evidence );
        exit;
    }

    private static function canAccess( object $file ): bool {
        if ( current_user_can( 'tt_edit_settings' ) ) return true;
        $user_id = get_current_user_id();
        if ( current_user_can( 'tt_view_pdp' )
            && QueryHelpers::coach_owns_player( $user_id, (int) $file->player_id ) ) {
            return true;
        }
        // Linked player or parent.
        global $wpdb; $p = $wpdb->prefix;
        $self_player = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_players WHERE wp_user_id = %d LIMIT 1",
            $user_id
        ) );
        if ( $self_player === (int) $file->player_id ) return true;
        $is_parent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}tt_player_parents WHERE player_id = %d AND parent_user_id = %d LIMIT 1",
            (int) $file->player_id, $user_id
        ) );
        return $is_parent === 1;
    }

    private static function emit( object $file, bool $include_evidence ): void {
        $player  = QueryHelpers::get_player( (int) $file->player_id );
        $season  = ( new SeasonsRepository() )->find( (int) $file->season_id );
        $convs   = ( new PdpConversationsRepository() )->listForFile( (int) $file->id );
        $verdict = ( new PdpVerdictsRepository() )->findForFile( (int) $file->id );

        global $wpdb; $p = $wpdb->prefix;
        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, priority, due_date, description FROM {$p}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY priority DESC, created_at DESC",
            (int) $file->player_id
        ) );

        $name  = $player ? QueryHelpers::player_display_name( $player ) : '';
        $photo = $player && ! empty( $player->photo_url ) ? (string) $player->photo_url : '';

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <title><?php echo esc_html( sprintf(
        /* translators: %s = player name */
        __( 'PDP — %s', 'talenttrack' ),
        $name
    ) ); ?></title>
    <style>
        @page { size: A4; margin: 18mm; }
        body { font-family: -apple-system, system-ui, "Segoe UI", Helvetica, Arial, sans-serif; color: #1a1d21; font-size: 11pt; line-height: 1.4; }
        h1, h2, h3 { color: #1a1d21; margin: 0 0 6mm; }
        h1 { font-size: 18pt; }
        h2 { font-size: 13pt; margin-top: 8mm; border-bottom: 1px solid #e5e7ea; padding-bottom: 2mm; }
        h3 { font-size: 11pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; text-align: left; vertical-align: top; }
        th { font-weight: 600; color: #5b6e75; border-bottom: 1px solid #e5e7ea; }
        td { border-bottom: 1px solid #f0f2f4; }
        .header { display: flex; gap: 12mm; margin-bottom: 6mm; }
        .photo { width: 28mm; height: 28mm; object-fit: cover; border-radius: 4mm; background: #fafbfc; }
        .meta p { margin: 0 0 2mm; color: #5b6e75; font-size: 10pt; }
        .signature { margin-top: 12mm; display: flex; gap: 16mm; }
        .signature .sig-line { flex: 1; border-top: 1px solid #1a1d21; padding-top: 2mm; font-size: 9pt; color: #5b6e75; }
        .verdict { background: #f0f7f6; border-left: 3px solid #1d7874; padding: 4mm 6mm; margin-top: 6mm; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 6mm; }
        .toolbar button, .toolbar a { padding: 6px 12px; border: 1px solid #c5c8cc; background: #fff; cursor: pointer; border-radius: 4px; font-size: 10pt; color: #1a1d21; text-decoration: none; }
        @media print { .toolbar { display: none; } }
        .pagebreak { page-break-before: always; }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print();"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
        <?php if ( ! $include_evidence ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'include_evidence', '1' ) ); ?>"><?php esc_html_e( 'Re-render with evidence page', 'talenttrack' ); ?></a>
        <?php else : ?>
            <a href="<?php echo esc_url( remove_query_arg( 'include_evidence' ) ); ?>"><?php esc_html_e( 'Single A4 only', 'talenttrack' ); ?></a>
        <?php endif; ?>
        <?php
        // #0063 — when the print page opens in a new tab `window.opener`
        // is often null thanks to noopener policies, and `history.back()`
        // from a fresh tab is a no-op — so the previous Close button
        // silently failed. Fall back to the file's own detail URL,
        // computed server-side, which always works.
        $close_url = add_query_arg(
            [ 'tt_view' => 'pdp', 'id' => (int) $file->id ],
            home_url( '/' )
        );
        ?>
        <a href="<?php echo esc_url( $close_url ); ?>"
           onclick="if (window.opener) { window.close(); return false; }">
            <?php esc_html_e( 'Close', 'talenttrack' ); ?>
        </a>
    </div>

    <div class="header">
        <?php if ( $photo !== '' ) : ?>
            <img class="photo" src="<?php echo esc_url( $photo ); ?>" alt="" />
        <?php else : ?>
            <div class="photo"></div>
        <?php endif; ?>
        <div class="meta">
            <h1><?php echo esc_html( $name ); ?></h1>
            <p><strong><?php esc_html_e( 'Season:', 'talenttrack' ); ?></strong> <?php echo esc_html( $season ? (string) $season->name : '—' ); ?></p>
            <p><strong><?php esc_html_e( 'Status:', 'talenttrack' ); ?></strong> <?php echo esc_html( ucfirst( (string) $file->status ) ); ?></p>
            <p><strong><?php esc_html_e( 'Cycle size:', 'talenttrack' ); ?></strong> <?php echo (int) ( $file->cycle_size ?? 0 ); ?></p>
        </div>
    </div>

    <h2><?php esc_html_e( 'Current goals', 'talenttrack' ); ?></h2>
    <?php if ( $goals ) : ?>
        <table>
            <thead><tr>
                <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Priority', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Due', 'talenttrack' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $goals as $g ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $g->title ); ?></td>
                        <td><?php echo esc_html( (string) ( $g->priority ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $g->status ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $g->due_date ?? '—' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p><em><?php esc_html_e( 'No active goals.', 'talenttrack' ); ?></em></p>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Agreed actions', 'talenttrack' ); ?></h2>
    <?php
    $any_action = false;
    foreach ( $convs as $c ) {
        if ( ! empty( $c->agreed_actions ) ) {
            $any_action = true;
            echo '<h3>' . esc_html( sprintf(
                /* translators: %d = sequence */
                __( 'Conversation %d', 'talenttrack' ),
                (int) $c->sequence
            ) ) . '</h3>';
            echo '<div>' . wp_kses_post( (string) $c->agreed_actions ) . '</div>';
        }
    }
    if ( ! $any_action ) {
        echo '<p><em>' . esc_html__( 'No agreed actions recorded yet.', 'talenttrack' ) . '</em></p>';
    }
    ?>

    <?php if ( $verdict !== null ) : ?>
        <div class="verdict">
            <h3><?php esc_html_e( 'End-of-season verdict', 'talenttrack' ); ?></h3>
            <p><strong><?php esc_html_e( 'Decision:', 'talenttrack' ); ?></strong> <?php echo esc_html( ucfirst( (string) $verdict->decision ) ); ?></p>
            <?php if ( ! empty( $verdict->summary ) ) : ?>
                <div><?php echo wp_kses_post( (string) $verdict->summary ); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="signature">
        <div class="sig-line"><?php esc_html_e( 'Coach signature', 'talenttrack' ); ?></div>
        <div class="sig-line"><?php esc_html_e( 'Player signature', 'talenttrack' ); ?></div>
        <div class="sig-line"><?php esc_html_e( 'Parent signature', 'talenttrack' ); ?></div>
    </div>

    <?php if ( $include_evidence ) : ?>
        <div class="pagebreak"></div>
        <h2><?php esc_html_e( 'Evidence', 'talenttrack' ); ?></h2>
        <?php self::renderEvidencePage( (int) $file->player_id ); ?>
    <?php endif; ?>
</body>
</html><?php
    }

    /**
     * Evidence page (second A4): last N evaluations + methodology pins
     * + recent activities. Read-only summary.
     */
    private static function renderEvidencePage( int $player_id ): void {
        global $wpdb; $p = $wpdb->prefix;
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT eval_date, notes FROM {$p}tt_evaluations
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY eval_date DESC LIMIT 5",
            $player_id
        ) );

        echo '<h3>' . esc_html__( 'Recent evaluations', 'talenttrack' ) . '</h3>';
        if ( $evals ) {
            echo '<table><thead><tr>';
            echo '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Notes', 'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $evals as $e ) {
                echo '<tr><td>' . esc_html( (string) $e->eval_date ) . '</td>';
                echo '<td>' . esc_html( (string) ( $e->notes ?? '' ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>' . esc_html__( 'No evaluations on record.', 'talenttrack' ) . '</em></p>';
        }

        $acts = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.session_date, a.title, att.status
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
              ORDER BY a.session_date DESC LIMIT 10",
            $player_id
        ) );
        echo '<h3>' . esc_html__( 'Recent activities', 'talenttrack' ) . '</h3>';
        if ( $acts ) {
            echo '<table><thead><tr>';
            echo '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Activity', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $acts as $a ) {
                echo '<tr><td>' . esc_html( (string) $a->session_date ) . '</td>';
                echo '<td>' . esc_html( (string) $a->title ) . '</td>';
                echo '<td>' . esc_html( (string) $a->status ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>' . esc_html__( 'No attendance records.', 'talenttrack' ) . '</em></p>';
        }
    }
}
