<?php
namespace TT\Modules\MatchPrep\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchPrepPdfExporter (#838) — landscape A4 print sheet for a match
 * preparation. Renders the lineup per half (slot order), the bench
 * per half, the match goals, and the per-player attention notes with
 * specific-goal / analyst markers.
 *
 * Pitch-diagram rendering is intentionally NOT pixel-perfect — v1
 * ships a clear printable list-and-table layout that captures all the
 * data. A future iteration can replace the lineup blocks with SVG
 * formation diagrams.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/match_prep_pdf?format=pdf&activity_id=42`
 *
 * Cap: `tt_view_activities`.
 */
final class MatchPrepPdfExporter implements ExporterInterface {

    public function key(): string { return 'match_prep_pdf'; }

    public function label(): string { return __( 'Match preparation (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function validateFilters( array $raw ): ?array {
        $activity_id = isset( $raw['activity_id'] ) ? (int) $raw['activity_id'] : 0;
        if ( $activity_id <= 0 ) return null;
        return [ 'activity_id' => $activity_id ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $activity_id = (int) ( $request->filters['activity_id'] ?? 0 );

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.location, a.team_id, a.activity_type_key,
                    a.opponent, a.home_away, a.kickoff_time, a.formation,
                    t.name AS team_name
                FROM {$p}tt_activities a
                LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                WHERE a.id = %d AND a.club_id = %d
                LIMIT 1",
            $activity_id,
            (int) $request->clubId
        ) );

        if ( ! $activity ) {
            return [
                'html'    => '<p>' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'landscape' ],
            ];
        }

        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) {
            return [
                'html'    => '<p>' . esc_html__( 'No match prep exists for this activity yet. Open the wizard from the activity detail page.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'landscape' ],
            ];
        }

        $prep_id      = (int) $prep->id;
        $availability = $repo->listAvailability( $prep_id );
        $lineup       = $repo->listLineup( $prep_id );
        $player_goals = $repo->listPlayerGoals( $prep_id );

        // Index helpers.
        $players_by_id = [];
        $player_ids = [];
        foreach ( $availability as $a ) {
            $player_ids[] = (int) $a->player_id;
        }
        foreach ( $player_goals as $g ) {
            $player_ids[] = (int) $g->player_id;
        }
        foreach ( $lineup as $l ) {
            $player_ids[] = (int) $l->player_id;
        }
        $player_ids = array_values( array_unique( array_filter( $player_ids ) ) );
        if ( $player_ids ) {
            $in  = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
            $sql = $wpdb->prepare(
                "SELECT id, first_name, last_name, jersey_number FROM {$p}tt_players WHERE id IN ($in) AND club_id = %d",
                array_merge( $player_ids, [ (int) $request->clubId ] )
            );
            $rows = $wpdb->get_results( $sql );
            foreach ( (array) $rows as $row ) {
                $players_by_id[ (int) $row->id ] = $row;
            }
        }

        $lineup_by_half = [ 1 => [], 2 => [] ];
        $pitch_ids_by_half = [ 1 => [], 2 => [] ];
        foreach ( $lineup as $l ) {
            $h   = (int) $l->half;
            $slot = (int) $l->slot_number;
            $pid = (int) $l->player_id;
            $lineup_by_half[ $h ][ $slot ] = $pid;
            $pitch_ids_by_half[ $h ][] = $pid;
        }
        ksort( $lineup_by_half[1] );
        ksort( $lineup_by_half[2] );

        $absent = [];
        foreach ( $availability as $a ) {
            if ( strcasecmp( (string) $a->status, 'Present' ) !== 0 ) {
                $absent[] = [
                    'pid'    => (int) $a->player_id,
                    'status' => (string) $a->status,
                    'reason' => (string) ( $a->reason ?? '' ),
                ];
            }
        }

        $pgoal_by_pid = [];
        foreach ( $player_goals as $g ) {
            $pgoal_by_pid[ (int) $g->player_id ] = $g;
        }

        $title = trim( sprintf(
            '%s · %s · %s',
            (string) ( $activity->team_name ?? '' ),
            (string) ( $activity->title ?? '' ),
            (string) ( $activity->session_date ?? '' )
        ), ' ·' );

        ob_start();
        ?>
        <style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1d21; }
            h1 { font-size: 18px; margin: 0 0 4px; }
            h2 { font-size: 13px; margin: 12px 0 4px; }
            .meta { color: #5b6e75; font-size: 11px; }
            .two-col { display: table; width: 100%; }
            .two-col > div { display: table-cell; width: 50%; padding-right: 12px; vertical-align: top; }
            table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
            th, td { border: 1px solid #d6dadd; padding: 4px 6px; text-align: left; font-size: 11px; }
            th { background: #f3f4f6; font-weight: 700; }
            .bench { font-size: 10px; color: #5b6e75; }
            .goal-list li { margin-bottom: 4px; }
            .specific { background: #fff5d6; }
        </style>
        <h1><?php echo esc_html( $title ); ?></h1>
        <p class="meta">
            <?php
            $kick = trim( (string) ( $activity->kickoff_time ?? '' ) );
            $home = ( ( $activity->home_away ?? '' ) === 'home' ) ? __( 'home', 'talenttrack' ) : __( 'away', 'talenttrack' );
            $opp  = (string) ( $activity->opponent ?? '' );
            echo esc_html( sprintf(
                /* translators: 1: opponent, 2: home/away, 3: kickoff_time, 4: formation */
                __( 'vs %1$s · %2$s · KO %3$s · %4$s', 'talenttrack' ),
                $opp !== '' ? $opp : '—',
                $home,
                $kick !== '' ? $kick : '—',
                (string) ( $activity->formation ?? $prep->formation_template_id ?? '—' )
            ) );
            ?>
        </p>

        <div class="two-col">
            <div>
                <h2><?php esc_html_e( '1e half', 'talenttrack' ); ?></h2>
                <table>
                    <thead><tr><th style="width:30px;">#</th><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th></tr></thead>
                    <tbody>
                    <?php for ( $s = 1; $s <= 11; $s++ ) :
                        $pid = $lineup_by_half[1][ $s ] ?? 0;
                        $pl = $pid && isset( $players_by_id[ $pid ] ) ? $players_by_id[ $pid ] : null;
                        ?>
                        <tr><td><?php echo (int) $s; ?></td><td><?php echo $pl ? esc_html( QueryHelpers::player_display_name( $pl ) ) : '—'; ?></td></tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
                <?php
                $bench_1 = [];
                foreach ( $availability as $a ) {
                    if ( strcasecmp( (string) $a->status, 'Present' ) !== 0 ) continue;
                    $pid = (int) $a->player_id;
                    if ( ! in_array( $pid, $pitch_ids_by_half[1], true ) && isset( $players_by_id[ $pid ] ) ) {
                        $bench_1[] = QueryHelpers::player_display_name( $players_by_id[ $pid ] );
                    }
                }
                ?>
                <p class="bench"><strong><?php esc_html_e( 'Bench:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $bench_1 ) ?: '—' ); ?></p>
            </div>
            <div>
                <h2><?php esc_html_e( '2e half', 'talenttrack' ); ?></h2>
                <table>
                    <thead><tr><th style="width:30px;">#</th><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th></tr></thead>
                    <tbody>
                    <?php for ( $s = 1; $s <= 11; $s++ ) :
                        $pid = $lineup_by_half[2][ $s ] ?? 0;
                        $pl = $pid && isset( $players_by_id[ $pid ] ) ? $players_by_id[ $pid ] : null;
                        ?>
                        <tr><td><?php echo (int) $s; ?></td><td><?php echo $pl ? esc_html( QueryHelpers::player_display_name( $pl ) ) : '—'; ?></td></tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
                <?php
                $bench_2 = [];
                foreach ( $availability as $a ) {
                    if ( strcasecmp( (string) $a->status, 'Present' ) !== 0 ) continue;
                    $pid = (int) $a->player_id;
                    if ( ! in_array( $pid, $pitch_ids_by_half[2], true ) && isset( $players_by_id[ $pid ] ) ) {
                        $bench_2[] = QueryHelpers::player_display_name( $players_by_id[ $pid ] );
                    }
                }
                ?>
                <p class="bench"><strong><?php esc_html_e( 'Bench:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $bench_2 ) ?: '—' ); ?></p>
            </div>
        </div>

        <div class="two-col">
            <div>
                <h2><?php esc_html_e( 'Match goals', 'talenttrack' ); ?></h2>
                <table>
                    <tr><th><?php esc_html_e( 'General', 'talenttrack' ); ?></th><td><?php echo esc_html( (string) ( $prep->goals_general ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Attacking', 'talenttrack' ); ?></th><td><?php echo esc_html( (string) ( $prep->goals_attack ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Defending', 'talenttrack' ); ?></th><td><?php echo esc_html( (string) ( $prep->goals_defend ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Set pieces (atk)', 'talenttrack' ); ?></th><td><?php echo esc_html( (string) ( $prep->goals_attack_setpiece ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Set pieces (def)', 'talenttrack' ); ?></th><td><?php echo esc_html( (string) ( $prep->goals_defend_setpiece ?? '' ) ); ?></td></tr>
                </table>
                <?php if ( ! empty( $absent ) ) : ?>
                    <h2><?php esc_html_e( 'Unavailable', 'talenttrack' ); ?></h2>
                    <ul>
                    <?php foreach ( $absent as $row ) :
                        $pl = $players_by_id[ $row['pid'] ] ?? null;
                        $name = $pl ? QueryHelpers::player_display_name( $pl ) : '#' . $row['pid'];
                        echo '<li>' . esc_html( $name ) . ' · ' . esc_html( $row['status'] );
                        if ( $row['reason'] !== '' ) echo ' (' . esc_html( $row['reason'] ) . ')';
                        echo '</li>';
                    endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div>
                <h2><?php esc_html_e( 'Per-player goals + attention', 'talenttrack' ); ?></h2>
                <ul class="goal-list">
                    <?php foreach ( $pgoal_by_pid as $pid => $g ) :
                        $text = trim( (string) ( $g->attention_text ?? '' ) );
                        if ( $text === '' && empty( $g->is_specific_goal ) && empty( $g->analyst_appointed ) ) continue;
                        $pl = $players_by_id[ (int) $pid ] ?? null;
                        $name = $pl ? QueryHelpers::player_display_name( $pl ) : '#' . (int) $pid;
                        $css = ! empty( $g->is_specific_goal ) ? ' class="specific"' : '';
                        $markers = [];
                        if ( ! empty( $g->is_specific_goal ) )  $markers[] = '!';
                        if ( ! empty( $g->analyst_appointed ) ) $markers[] = '🎥';
                        $marker_str = $markers ? '[' . implode( ' ', $markers ) . '] ' : '';
                        ?>
                        <li<?php echo $css; ?>><strong><?php echo esc_html( $marker_str . $name ); ?></strong> — <?php echo esc_html( $text ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php
        $html = (string) ob_get_clean();

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'landscape' ],
        ];
    }
}
