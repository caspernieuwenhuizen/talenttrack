<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendEvaluationsView — coach-tier evaluations surface.
 *
 * List mode (default): recent evaluations across the coach's teams,
 * with filters and a "New evaluation" CTA.
 *
 * Create mode (?action=new): shows the evaluation form via
 * CoachForms::renderEvalForm. After save the form's existing redirect
 * sends the user back to the list.
 */
class FrontendEvaluationsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $teams  = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New evaluation', 'talenttrack' ) );
            CoachForms::renderEvalForm( $teams, $is_admin );
            return;
        }

        self::renderHeader( __( 'Evaluations', 'talenttrack' ) );

        $base_url = remove_query_arg( [ 'action', 'id', 'f_team_id', 'f_player_id', 'f_date_from', 'f_date_to' ] );
        $flat_url = add_query_arg( [ 'tt_view' => 'evaluations', 'action' => 'new' ], $base_url );
        $new_url  = \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'new-evaluation', $flat_url );

        echo '<p style="margin:0 0 12px;"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New evaluation', 'talenttrack' )
            . '</a></p>';

        $filters = self::filtersFromQuery();
        self::renderFilters( $filters, $teams );
        self::renderTable( $user_id, $is_admin, $filters );
    }

    /** @return array<string, mixed> */
    private static function filtersFromQuery(): array {
        $f = [];
        if ( ! empty( $_GET['f_team_id'] ) )   $f['team_id']   = absint( $_GET['f_team_id'] );
        if ( ! empty( $_GET['f_player_id'] ) ) $f['player_id'] = absint( $_GET['f_player_id'] );
        if ( ! empty( $_GET['f_date_from'] ) ) $f['date_from'] = sanitize_text_field( wp_unslash( (string) $_GET['f_date_from'] ) );
        if ( ! empty( $_GET['f_date_to'] ) )   $f['date_to']   = sanitize_text_field( wp_unslash( (string) $_GET['f_date_to'] ) );
        return $f;
    }

    /**
     * @param array<string,mixed> $filters
     * @param object[]            $teams
     */
    private static function renderFilters( array $filters, array $teams ): void {
        $sel_team   = (int) ( $filters['team_id']   ?? 0 );
        $sel_player = (int) ( $filters['player_id'] ?? 0 );
        $sel_from   = (string) ( $filters['date_from'] ?? '' );
        $sel_to     = (string) ( $filters['date_to']   ?? '' );
        ?>
        <form method="get" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:12px;">
            <input type="hidden" name="tt_view" value="evaluations" />

            <div class="tt-field" style="flex:1 1 200px;">
                <label class="tt-field-label" for="tt-eval-f-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
                <select id="tt-eval-f-team" name="f_team_id" class="tt-input">
                    <option value="0"><?php esc_html_e( 'All teams', 'talenttrack' ); ?></option>
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>" <?php selected( $sel_team, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-eval-f-from"><?php esc_html_e( 'From', 'talenttrack' ); ?></label>
                <input id="tt-eval-f-from" type="date" name="f_date_from" value="<?php echo esc_attr( $sel_from ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-eval-f-to"><?php esc_html_e( 'To', 'talenttrack' ); ?></label>
                <input id="tt-eval-f-to" type="date" name="f_date_to" value="<?php echo esc_attr( $sel_to ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 auto; align-self:flex-end;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button>
                <a href="<?php echo esc_url( remove_query_arg( [ 'f_team_id', 'f_player_id', 'f_date_from', 'f_date_to' ] ) ); ?>" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Clear', 'talenttrack' ); ?></a>
            </div>
        </form>
        <?php
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function renderTable( int $user_id, bool $is_admin, array $filters ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Scope to the coach's teams when not admin. Joins to player +
        // team for display columns and so the team filter actually
        // filters by the player's team rather than a denormalised one.
        $where  = '1=1';
        $params = [];

        if ( ! $is_admin ) {
            $teams = QueryHelpers::get_teams_for_coach( $user_id );
            $team_ids = array_map( static fn( $t ) => (int) $t->id, $teams );
            if ( empty( $team_ids ) ) {
                echo '<p><em>' . esc_html__( 'You are not assigned to any teams yet.', 'talenttrack' ) . '</em></p>';
                return;
            }
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where  .= " AND pl.team_id IN ($placeholders)";
            $params = array_merge( $params, $team_ids );
        }

        if ( ! empty( $filters['team_id'] ) ) {
            $where   .= ' AND pl.team_id = %d';
            $params[] = (int) $filters['team_id'];
        }
        if ( ! empty( $filters['player_id'] ) ) {
            $where   .= ' AND e.player_id = %d';
            $params[] = (int) $filters['player_id'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $df = self::parseDate( (string) $filters['date_from'] );
            if ( $df !== '' ) {
                $where   .= ' AND e.eval_date >= %s';
                $params[] = $df;
            }
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $dt = self::parseDate( (string) $filters['date_to'] );
            if ( $dt !== '' ) {
                $where   .= ' AND e.eval_date <= %s';
                $params[] = $dt;
            }
        }

        // #0070 — also resolve the coach's tt_people.id so the trainer
        // cell can link to the person detail. Same pattern as the
        // admin EvaluationsPage uses for its trainer column.
        $sql = "SELECT e.id, e.eval_date, e.notes, e.player_id, e.coach_id,
                       pl.first_name, pl.last_name, pl.team_id,
                       t.name AS team_name,
                       u.display_name AS coach_name,
                       coach_p.id AS coach_person_id
                  FROM {$p}tt_evaluations e
                  LEFT JOIN {$p}tt_players pl ON pl.id = e.player_id
                  LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id
                  LEFT JOIN {$wpdb->users} u  ON u.ID  = e.coach_id
                  LEFT JOIN {$p}tt_people coach_p ON coach_p.wp_user_id = e.coach_id AND coach_p.club_id = e.club_id
                 WHERE $where AND e.archived_at IS NULL
                 ORDER BY e.eval_date DESC, e.id DESC
                 LIMIT 100";

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );

        if ( empty( $rows ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No evaluations match these filters yet.', 'talenttrack' ) . '</p>';
            return;
        }

        ?>
        <div class="tt-table-wrap" style="overflow-x:auto;">
            <table class="tt-table" style="width:100%;">
                <thead><tr>
                    <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) :
                    $name = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
                    if ( $name === '' ) $name = '#' . (int) $r->player_id;
                    $team_id    = (int) ( $r->team_id ?? 0 );
                    $team_name  = (string) ( $r->team_name ?? '' );
                    $coach_name = (string) ( $r->coach_name ?? '' );
                    $coach_pid  = (int) ( $r->coach_person_id ?? 0 );
                    ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc_html( (string) $r->eval_date ); ?></td>
                        <td><?php
                            // #0070 — player name links to player detail.
                            echo \TT\Shared\Frontend\Components\RecordLink::inline(
                                $name,
                                \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'players', (int) $r->player_id )
                            );
                        ?></td>
                        <td><?php
                            if ( $team_id > 0 && $team_name !== '' ) {
                                echo \TT\Shared\Frontend\Components\RecordLink::inline(
                                    $team_name,
                                    \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'teams', $team_id )
                                );
                            } else {
                                echo esc_html( $team_name !== '' ? $team_name : '—' );
                            }
                        ?></td>
                        <td><?php
                            if ( $coach_name !== '' && $coach_pid > 0 ) {
                                echo \TT\Shared\Frontend\Components\RecordLink::inline(
                                    $coach_name,
                                    \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'people', $coach_pid )
                                );
                            } else {
                                echo esc_html( $coach_name !== '' ? $coach_name : '—' );
                            }
                        ?></td>
                        <td style="max-width:300px; overflow-wrap:anywhere;"><?php echo esc_html( wp_trim_words( (string) ( $r->notes ?? '' ), 14 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function parseDate( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) return '';
        $d = \DateTime::createFromFormat( 'Y-m-d', $raw );
        return ( $d && $d->format( 'Y-m-d' ) === $raw ) ? $raw : '';
    }
}
