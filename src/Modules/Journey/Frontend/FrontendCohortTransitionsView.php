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

    /**
     * B3 — enqueue the per-view 2026 stylesheet. This view does not
     * extend FrontendViewBase, so the sheet is enqueued directly with a
     * dependency on the app-chrome sheet (always present on dashboard
     * renders, where it carries the brand tokens). Data queries are
     * untouched.
     */
    private static function enqueueAssets(): void {
        wp_enqueue_style(
            'tt-frontend-cohort-transitions',
            TT_PLUGIN_URL . 'assets/css/frontend-cohort-transitions.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

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

        self::enqueueAssets();

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

            <form method="get" class="tt-cohort-filters">
                <input type="hidden" name="tt_view" value="cohort-transitions" />

                <label class="tt-field">
                    <span class="tt-field-label"><?php esc_html_e( 'Event type', 'talenttrack' ); ?></span>
                    <select class="tt-input" name="event_type" required>
                        <option value=""><?php esc_html_e( '— Select an event type —', 'talenttrack' ); ?></option>
                        <?php foreach ( EventTypeRegistry::all() as $def ) : ?>
                            <option value="<?php echo esc_attr( $def->key ); ?>" <?php selected( $event_type, $def->key ); ?>>
                                <?php echo esc_html( $def->label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tt-field">
                    <span class="tt-field-label"><?php esc_html_e( 'From', 'talenttrack' ); ?></span>
                    <input class="tt-input" type="date" name="from" value="<?php echo esc_attr( $from ); ?>" />
                </label>
                <label class="tt-field">
                    <span class="tt-field-label"><?php esc_html_e( 'To', 'talenttrack' ); ?></span>
                    <input class="tt-input" type="date" name="to" value="<?php echo esc_attr( $to ); ?>" />
                </label>
                <label class="tt-field">
                    <span class="tt-field-label"><?php esc_html_e( 'Team (optional)', 'talenttrack' ); ?></span>
                    <input class="tt-input" type="number" name="team_id" value="<?php echo esc_attr( $team_id > 0 ? (string) $team_id : '' ); ?>" inputmode="numeric" min="0" />
                </label>
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Run query', 'talenttrack' ); ?>
                </button>
            </form>

            <?php if ( $event_type === '' ) : ?>
                <p class="tt-muted"><?php esc_html_e( 'Pick an event type and a date range to begin.', 'talenttrack' ); ?></p>
            <?php elseif ( empty( $rows ) ) : ?>
                <p class="tt-empty"><?php esc_html_e( 'No matching events in the selected range.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <p class="tt-cohort-count">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: number of cohort rows returned */
                        _n( '%d match.', '%d matches.', count( $rows ), 'talenttrack' ),
                        count( $rows )
                    ) );
                    ?>
                </p>
                <div class="tt-report-card">
                <div class="tt-cohort-table-wrap">
                <table class="tt-table tt-cohort-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Detail', 'talenttrack' ); ?></th>
                            <th></th>
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
                                <td><?php echo esc_html( $name ); ?></td>
                                <td><?php echo esc_html( \TT\Shared\Dates\TTDate::date( (string) ( $row->event_date ?? '' ) ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row->summary ?? '' ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $journey_url ); ?>" class="tt-btn tt-btn-secondary">
                                        <?php esc_html_e( 'Open journey', 'talenttrack' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
}
