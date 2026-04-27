<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;

/**
 * FrontendMyActivitiesView — the "My sessions" tile destination.
 *
 * v3.0.0 slice 3. Lists training sessions attended by the logged-in
 * player, most-recent first. Shows date, session title, status
 * (present / absent / other, color-coded), and notes.
 */
class FrontendMyActivitiesView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My sessions', 'talenttrack' ) );

        global $wpdb;
        $p = $wpdb->prefix;

        // #0026 — surface guest appearances on the player's own
        // sessions list too. Match either roster (player_id) or
        // linked-guest (guest_player_id) entries for this player.
        $att = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, s.title AS session_title, s.session_date
             FROM {$p}tt_attendance a
             LEFT JOIN {$p}tt_activities s ON a.activity_id = s.id
             WHERE a.player_id = %d OR a.guest_player_id = %d
             ORDER BY s.session_date DESC",
            (int) $player->id, (int) $player->id
        ) );

        if ( empty( $att ) ) {
            echo '<p><em>' . esc_html__( 'No attendance records yet.', 'talenttrack' ) . '</em></p>';
            return;
        }

        ?>
        <table class="tt-table tt-table-sortable">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Activity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $att as $a ) :
                    $status_lower = strtolower( (string) $a->status );
                    $cls = $status_lower === 'present'
                        ? 'tt-att-present'
                        : ( $status_lower === 'absent' ? 'tt-att-absent' : 'tt-att-other' );
                    ?>
                    <tr class="<?php echo esc_attr( $cls ); ?>">
                        <td><?php echo esc_html( (string) $a->session_date ); ?></td>
                        <td><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( (string) $a->session_title ) ); ?></td>
                        <td><?php echo esc_html( LabelTranslator::attendanceStatus( (string) $a->status ) ); ?></td>
                        <td><?php
                            $notes = (string) ( $a->notes ?: '' );
                            echo $notes !== '' ? esc_html( \TT\Modules\Translations\TranslationLayer::render( $notes ) ) : '—';
                        ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
