<?php
namespace TT\Modules\MatchPrep\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchPrepPrintRouter (#1031) — isolated print route for a match
 * preparation sheet.
 *
 * URL: ?tt_match_prep_print=1&activity_id=N
 *
 * Same isolation pattern as PdpPrintRouter: hook before the admin /
 * theme shell renders, emit a standalone document, exit. The dashboard
 * shortcode never runs and the active theme's header / footer / nav
 * never load — so no chrome can leak through onto paper.
 *
 * Body layout mirrors the landscape-A4 PDF export (lineup per half,
 * bench, match goals, per-player attention) so the on-screen print
 * matches what the PDF exporter produces — same data shapes, same
 * sections, different output channel (browser print vs DomPDF).
 */
class MatchPrepPrintRouter {

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET['tt_match_prep_print'] ) ) return;
        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
        if ( $activity_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this match prep sheet.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            wp_die( esc_html__( 'You do not have access to print this match prep sheet.', 'talenttrack' ) );
        }

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo self::renderHtml( $activity_id );
        exit;
    }

    public static function renderHtml( int $activity_id ): string {
        ob_start();
        self::emit( $activity_id );
        return (string) ob_get_clean();
    }

    private static function emit( int $activity_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.location, a.team_id, a.activity_type_key,
                    a.opponent, a.home_away, a.kickoff_time, a.formation,
                    t.name AS team_name
                FROM {$p}tt_activities a
                LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                WHERE a.id = %d AND a.club_id = %d
                LIMIT 1",
            $activity_id,
            $club_id
        ) );

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php
        $page_title = $activity
            ? sprintf(
                /* translators: 1: team name, 2: activity title, 3: session date */
                __( 'Match prep — %1$s · %2$s · %3$s', 'talenttrack' ),
                (string) ( $activity->team_name ?? '' ),
                (string) ( $activity->title ?? '' ),
                (string) ( $activity->session_date ?? '' )
            )
            : __( 'Match prep', 'talenttrack' );
        echo esc_html( trim( $page_title, ' ·' ) );
    ?></title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: -apple-system, system-ui, "Segoe UI", Helvetica, Arial, sans-serif; color: #1a1d21; font-size: 11pt; line-height: 1.4; margin: 0; padding: 16px; }
        h1 { font-size: 16pt; margin: 0 0 4px; }
        h2 { font-size: 12pt; margin: 12px 0 4px; }
        p.meta { color: #5b6e75; font-size: 10pt; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin: 4px 0 8px; }
        th, td { border: 1px solid #d6dadd; padding: 4px 6px; text-align: left; font-size: 10pt; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 700; }
        .two-col { display: table; width: 100%; }
        .two-col > div { display: table-cell; width: 50%; padding-right: 12px; vertical-align: top; }
        .two-col > div:last-child { padding-right: 0; padding-left: 12px; }
        .bench { font-size: 9pt; color: #5b6e75; margin: 4px 0 0; }
        .goal-list { margin: 4px 0; padding-left: 18px; }
        .goal-list li { margin-bottom: 4px; }
        .specific { background: #fff5d6; }
        .empty { color: #5b6e75; font-style: italic; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
        .toolbar button, .toolbar a {
            padding: 8px 14px; border: 1px solid #c5c8cc; background: #fff; cursor: pointer;
            border-radius: 4px; font-size: 11pt; color: #1a1d21; text-decoration: none;
            font: inherit;
        }
        .toolbar button.primary { background: #2271b1; border-color: #2271b1; color: #fff; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print();"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
        <?php
        $close_url = add_query_arg(
            [ 'tt_view' => 'match-prep', 'activity_id' => $activity_id ],
            home_url( '/' )
        );
        ?>
        <a href="<?php echo esc_url( $close_url ); ?>"
           onclick="if (window.opener) { window.close(); return false; }">
            <?php esc_html_e( 'Close', 'talenttrack' ); ?>
        </a>
    </div>

    <?php
    if ( ! $activity ) {
        echo '<p class="empty">' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>';
        echo '</body></html>';
        return;
    }

    $repo = new MatchPrepRepository();
    $prep = $repo->findByActivity( $activity_id );
    if ( ! $prep ) {
        echo '<p class="empty">' . esc_html__( 'No match prep exists for this activity yet. Open the wizard from the activity detail page.', 'talenttrack' ) . '</p>';
        echo '</body></html>';
        return;
    }

    $prep_id      = (int) $prep->id;
    $availability = $repo->listAvailability( $prep_id );
    $lineup       = $repo->listLineup( $prep_id );
    $player_goals = $repo->listPlayerGoals( $prep_id );

    $player_ids = [];
    foreach ( $availability as $a ) { $player_ids[] = (int) $a->player_id; }
    foreach ( $player_goals as $g ) { $player_ids[] = (int) $g->player_id; }
    foreach ( $lineup       as $l ) { $player_ids[] = (int) $l->player_id; }
    $player_ids = array_values( array_unique( array_filter( $player_ids ) ) );

    $players_by_id = [];
    if ( $player_ids ) {
        $in  = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        $sql = $wpdb->prepare(
            "SELECT id, first_name, last_name, jersey_number FROM {$p}tt_players WHERE id IN ($in) AND club_id = %d",
            array_merge( $player_ids, [ $club_id ] )
        );
        foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
            $players_by_id[ (int) $row->id ] = $row;
        }
    }

    $lineup_by_half    = [ 1 => [], 2 => [] ];
    $pitch_ids_by_half = [ 1 => [], 2 => [] ];
    foreach ( $lineup as $l ) {
        $h    = (int) $l->half;
        $slot = (int) $l->slot_number;
        $pid  = (int) $l->player_id;
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
    ?>
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
                <?php
                $any_pg = false;
                foreach ( $pgoal_by_pid as $pid => $g ) :
                    $text = trim( (string) ( $g->attention_text ?? '' ) );
                    if ( $text === '' && empty( $g->is_specific_goal ) && empty( $g->analyst_appointed ) ) continue;
                    $any_pg = true;
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
                <?php if ( ! $any_pg ) : ?>
                    <li class="empty"><?php esc_html_e( 'No per-player notes set.', 'talenttrack' ); ?></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</body>
</html><?php
    }
}
