<?php
namespace TT\Modules\Journey\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\EventTypeRegistry;
use TT\Infrastructure\Journey\PlayerEventsRepository;

/**
 * FrontendCohortTransitionsView — HoD-facing cohort query.
 *
 * "Show me every player promoted to U15 between 2025-08 and 2026-04."
 * "Show me every long-term injury this season."
 * "Show me every release in the last six months."
 *
 * The query produces a list of (player, date, summary) rows; clicking a
 * row navigates to that player's full journey. Results are bounded to
 * 500 rows by the repository — wider queries should narrow the date
 * range first.
 */
class FrontendCohortTransitionsView {

    public static function render( int $user_id, bool $is_admin ): void {
        // v3.94.1 — gate now consults the matrix instead of the umbrella
        // `tt_view_settings` cap. The seed grants `cohort_transitions:r:global`
        // to head_of_development as well as academy_admin (see
        // `config/authorization_seed.php`); the previous cap-only check
        // ignored that grant and blocked HoD with a misleading
        // "head-of-academy access" message even though HoD is exactly
        // the persona this view is built for. The helper short-circuits
        // on `tt_edit_settings` + WP administrator so legacy admins
        // and matrix-dormant installs keep working.
        if ( ! \TT\Infrastructure\Query\QueryHelpers::user_has_global_entity_read( $user_id, 'cohort_transitions' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to cohort transitions.', 'talenttrack' ) . '</p>';
            return;
        }

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Cohort transitions', 'talenttrack' ) );

        $event_type = isset( $_GET['event_type'] ) ? sanitize_key( (string) $_GET['event_type'] ) : '';
        $from       = isset( $_GET['from'] ) ? sanitize_text_field( (string) $_GET['from'] ) : '';
        $to         = isset( $_GET['to'] ) ? sanitize_text_field( (string) $_GET['to'] ) : '';
        $team_id    = isset( $_GET['team_id'] ) ? (int) $_GET['team_id'] : 0;

        $now = strtotime( current_time( 'mysql' ) ) ?: time();
        if ( $from === '' ) $from = gmdate( 'Y-m-d', $now - ( 60 * 60 * 24 * 365 ) );
        if ( $to === '' )   $to   = gmdate( 'Y-m-d', $now );

        $allowed = PlayerEventsRepository::visibilitiesForUser( $user_id );

        $rows = [];
        if ( $event_type !== '' ) {
            $rows = ( new PlayerEventsRepository() )->cohortByType(
                $event_type, $from . ' 00:00:00', $to . ' 23:59:59', $team_id > 0 ? $team_id : null, $allowed
            );
        }

        ?>
        <section class="tt-cohort">
            <header>
                <h2><?php esc_html_e( 'Cohort transitions', 'talenttrack' ); ?></h2>
                <p class="tt-muted"><?php esc_html_e( 'Find every player whose journey contains a particular event in a date range.', 'talenttrack' ); ?></p>
            </header>

            <form method="get" class="tt-cohort-filters" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin: 16px 0; align-items:end;">
                <input type="hidden" name="tt_view" value="cohort-transitions" />

                <label>
                    <span><?php esc_html_e( 'Event type', 'talenttrack' ); ?></span>
                    <select name="event_type" required>
                        <option value=""><?php esc_html_e( '— Select an event type —', 'talenttrack' ); ?></option>
                        <?php foreach ( EventTypeRegistry::all() as $def ) : ?>
                            <option value="<?php echo esc_attr( $def->key ); ?>" <?php selected( $event_type, $def->key ); ?>>
                                <?php echo esc_html( $def->label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'From', 'talenttrack' ); ?></span>
                    <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e( 'To', 'talenttrack' ); ?></span>
                    <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e( 'Team (optional)', 'talenttrack' ); ?></span>
                    <input type="number" name="team_id" value="<?php echo esc_attr( $team_id > 0 ? (string) $team_id : '' ); ?>" inputmode="numeric" min="0" />
                </label>
                <button type="submit" class="tt-btn tt-btn-primary" style="min-height:48px;">
                    <?php esc_html_e( 'Run query', 'talenttrack' ); ?>
                </button>
            </form>

            <?php if ( $event_type === '' ) : ?>
                <p class="tt-muted"><?php esc_html_e( 'Pick an event type and a date range to begin.', 'talenttrack' ); ?></p>
            <?php elseif ( empty( $rows ) ) : ?>
                <p class="tt-empty"><?php esc_html_e( 'No matching events in the selected range.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <p class="tt-muted">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: number of cohort rows returned */
                        _n( '%d match.', '%d matches.', count( $rows ), 'talenttrack' ),
                        count( $rows )
                    ) );
                    ?>
                </p>
                <table class="tt-table" style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left; padding:6px 8px; border-bottom:1px solid #d6dadd;"><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                            <th style="text-align:left; padding:6px 8px; border-bottom:1px solid #d6dadd;"><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                            <th style="text-align:left; padding:6px 8px; border-bottom:1px solid #d6dadd;"><?php esc_html_e( 'Detail', 'talenttrack' ); ?></th>
                            <th style="text-align:left; padding:6px 8px; border-bottom:1px solid #d6dadd;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            $name = trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) );
                            $journey_url = add_query_arg( [
                                'tt_view'   => 'player-journey',
                                'player_id' => (int) ( $row->player_id ?? 0 ),
                            ], remove_query_arg( [ 'event_type', 'from', 'to', 'team_id' ] ) );
                        ?>
                            <tr>
                                <td style="padding:6px 8px; border-bottom:1px solid #eef0f2;"><?php echo esc_html( $name ); ?></td>
                                <td style="padding:6px 8px; border-bottom:1px solid #eef0f2;"><?php echo esc_html( substr( (string) ( $row->event_date ?? '' ), 0, 10 ) ); ?></td>
                                <td style="padding:6px 8px; border-bottom:1px solid #eef0f2;"><?php echo esc_html( (string) ( $row->summary ?? '' ) ); ?></td>
                                <td style="padding:6px 8px; border-bottom:1px solid #eef0f2;">
                                    <a href="<?php echo esc_url( $journey_url ); ?>" class="tt-btn tt-btn-secondary" style="min-height:32px;">
                                        <?php esc_html_e( 'Open journey', 'talenttrack' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <?php
    }
}
