<?php
namespace TT\Modules\Teams\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Players\Frontend\PlayerStatusRenderer;

/**
 * TeamPlayersPanel — renders the "Players on this team" panel shown on
 * the team edit page, alongside the existing Staff Assignments panel.
 *
 * Read-only by design: adds/removes happen on the Players admin pages
 * via the player's `team_id` field. This panel surfaces the current
 * roster so an admin opening a team can immediately see who is on it,
 * click through to any player, and count heads.
 *
 * v3.6.0 (demo prep). Goes through `QueryHelpers::get_players($team_id)`
 * so demo-mode scoping and status filters are honoured consistently
 * with the rest of the plugin.
 */
class TeamPlayersPanel {

    public static function render( int $team_id ): void {
        if ( $team_id <= 0 ) return;

        $players = QueryHelpers::get_players( $team_id );
        $count   = count( $players );
        ?>
        <h2 style="margin-top:30px;">
            <?php printf(
                /* translators: %d = active player count for this team */
                esc_html__( 'Players on this team (%d)', 'talenttrack' ),
                (int) $count
            ); ?>
        </h2>

        <?php if ( $count === 0 ) : ?>
            <p style="max-width:720px;">
                <em><?php esc_html_e( 'No active players are assigned to this team yet. Go to Players → Add New (or edit an existing player) and set this team as their team.', 'talenttrack' ); ?></em>
            </p>
        <?php else : ?>
            <?php
            // #0057 Sprint 4 — render the traffic-light dot inline.
            // Calculator is read-time, no caching at this layer; for
            // a 25-player team that's ~25 calculator runs per page
            // load. Acceptable for v1; a per-team batch query lands
            // when the read-model layer caches in v3.51+.
            wp_enqueue_style( 'tt-player-status', plugins_url( 'assets/css/player-status.css', TT_PLUGIN_FILE ), [], (string) ( defined( 'TT_VERSION' ) ? TT_VERSION : '1' ) );
            $status_calc = new PlayerStatusCalculator();
            ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Jersey', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Positions', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Foot', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></th>
                        <th style="width:90px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $players as $pl ) :
                        $positions = json_decode( (string) ( $pl->preferred_positions ?? '' ), true );
                        $pos_str = is_array( $positions ) ? implode( ', ', array_map( 'strval', $positions ) ) : '';
                        $edit_url = admin_url( 'admin.php?page=tt-players&action=edit&id=' . (int) $pl->id );
                        $foot = (string) ( $pl->preferred_foot ?? '' );
                        $verdict = $status_calc->calculate( (int) $pl->id );
                    ?>
                        <tr>
                            <td><?php echo PlayerStatusRenderer::dot( $verdict->color ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>">
                                    <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                                </a>
                            </td>
                            <td><?php echo $pl->jersey_number ? '#' . esc_html( (string) $pl->jersey_number ) : '—'; ?></td>
                            <td><?php echo $pos_str !== '' ? esc_html( $pos_str ) : '—'; ?></td>
                            <td><?php echo $foot !== '' ? esc_html( __( $foot, 'talenttrack' ) ) : '—'; ?></td>
                            <td><?php echo ! empty( $pl->date_of_birth ) ? esc_html( (string) $pl->date_of_birth ) : '—'; ?></td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
                                    <?php esc_html_e( 'Edit', 'talenttrack' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( current_user_can( 'tt_edit_players' ) ) : ?>
                <p style="margin-top:10px;">
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tt-players&action=new&team_id=' . (int) $team_id ) ); ?>">
                        <?php esc_html_e( 'Add player to this team', 'talenttrack' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        <?php endif;
    }
}
