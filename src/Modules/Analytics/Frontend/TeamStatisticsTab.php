<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FormChips;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * TeamStatisticsTab (#3522, epic #3519) — what a coach reads when they open
 * their team's statistics.
 *
 * **Composition only.** Every number comes from {@see TeamMatchStatsQuery}
 * (#3520); nothing is aggregated here (CLAUDE.md §4). The test for that is
 * simple: there is no arithmetic in this file beyond deciding how many rows to
 * print.
 *
 * Five blocks, in reading order: the record, recent form, top scorers, top
 * assists, and a slice of appearances. Two of those are deliberately *not*
 * what they could be:
 *
 *   - **The leaderboards list contributors only.** A player who has not scored
 *     is absent rather than present as a zero. A roster-length table of zeros
 *     buries the six names the coach opened the tab to read.
 *   - **Appearances is a top slice, not the full grid.** The complete
 *     per-player table already exists on the team minutes report, with starts,
 *     subs on and off, per-type breakdown and a per-match drill-down.
 *     Rebuilding it here would be two tables that have to agree forever, so
 *     this shows the top of it and links to the rest.
 *
 * Staff-facing. The page that hosts it has already established that the reader
 * may see this team (`tt_view_teams` + `AllTeamsScope::canReadTeam()`); nothing
 * here widens that. `FrontendMyTeamView` is untouched — a player or parent does
 * not get a ranked table of named teammates, and revisiting that is its own
 * decision.
 */
final class TeamStatisticsTab {

    /** Appearances rows shown before the reader is sent to the full report. */
    private const APPEARANCES_TOP_N = 8;

    public static function render( int $team_id ): void {
        self::enqueue();

        [ $from, $to ] = self::requestedWindow();

        $filters = ( $from !== '' && $to !== '' ) ? [ 'from' => $from, 'to' => $to ] : [];
        $stats   = ( new TeamMatchStatsQuery() )->forTeam( $team_id, $filters );

        echo '<div class="tt-ts">';

        self::renderWindow( $team_id, $stats['window'], $from, $to );

        $record = is_array( $stats['record'] ?? null ) ? $stats['record'] : [];
        $played = (int) ( $record['played'] ?? 0 );
        $absent = $played === 0
            && (int) ( $record['without_a_score'] ?? 0 ) === 0
            && (int) ( $record['tournaments_excluded'] ?? 0 ) === 0;

        if ( $absent ) {
            // First of the three empty states: nothing was played. Distinct
            // from "played but not recorded", which is a data-entry problem
            // rather than an empty period.
            echo '<p class="tt-ts-empty">' . esc_html__( 'No matches played in this period.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }

        self::renderRecord( $record );
        self::renderForm( is_array( $stats['form'] ?? null ) ? $stats['form'] : [] );
        self::renderContributions(
            is_array( $stats['scorers'] ?? null ) ? $stats['scorers'] : [],
            is_array( $stats['assists'] ?? null ) ? $stats['assists'] : [],
            $played
        );
        self::renderAppearances(
            is_array( $stats['appearances'] ?? null ) ? $stats['appearances'] : [],
            $team_id,
            $stats['window']
        );

        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Window
     * ------------------------------------------------------------- */

    /**
     * The from/to the URL asks for. Both or neither — half a window would
     * silently answer a different question than the reader typed.
     *
     * @return array{0:string, 1:string}
     */
    private static function requestedWindow(): array {
        $ymd  = static fn ( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
        $from = isset( $_GET['stats_from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['stats_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
        $to   = isset( $_GET['stats_to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['stats_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.

        if ( ! $ymd( $from ) || ! $ymd( $to ) || $from > $to ) return [ '', '' ];

        return [ $from, $to ];
    }

    /**
     * The window, named, with the controls to narrow it.
     *
     * Labelled rather than implied: "12 played" is meaningless without saying
     * *when*, and the query already reports which rule produced the window it
     * used, so the screen can say "this season" rather than printing two dates
     * and leaving the reader to work it out.
     *
     * @param array<string,mixed> $window
     */
    private static function renderWindow( int $team_id, array $window, string $from, string $to ): void {
        $source = (string) ( $window['source'] ?? '' );
        $season = (string) ( $window['season'] ?? '' );

        echo '<div class="tt-ts-window">';

        echo '<p class="tt-ts-window__label">';
        if ( $source === 'season' && $season !== '' ) {
            echo esc_html( sprintf(
                /* translators: %s: the season's name, e.g. "2025/26". */
                __( 'Season %s', 'talenttrack' ),
                $season
            ) );
        } elseif ( $source === 'all_time' ) {
            echo esc_html__( 'All time — no current season is set for this academy.', 'talenttrack' );
        } else {
            echo esc_html( sprintf(
                /* translators: 1: window start date, 2: window end date. */
                __( 'From %1$s to %2$s', 'talenttrack' ),
                TTDate::date( (string) ( $window['from'] ?? '' ) ),
                TTDate::date( (string) ( $window['to'] ?? '' ) )
            ) );
        }
        echo '</p>';

        echo '<form method="get" class="tt-ts-window__form">';
        foreach ( [ 'tt_view' => 'teams', 'id' => (string) $team_id, 'tab' => 'stats' ] as $name => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
        }
        echo '<label class="tt-ts-window__field"><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" class="tt-input" name="stats_from" value="' . esc_attr( $from ) . '"></label>';
        echo '<label class="tt-ts-window__field"><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" class="tt-input" name="stats_to" value="' . esc_attr( $to ) . '"></label>';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';

        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Blocks
     * ------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $record
     */
    private static function renderRecord( array $record ): void {
        // `_x` because the catalogue already translates a bare "Record" as the
        // verb — "Registreer" — which on a results strip would be an
        // instruction rather than a heading.
        self::sectionOpen( _x( 'Record', "a team's win/draw/loss tally", 'talenttrack' ) );

        // The four tally labels reuse the contexts #3516 already shipped for
        // the same four words on the monthly report. The context names that
        // surface rather than this one, which reads oddly — but a gettext
        // context exists to disambiguate a sense, and the sense is identical.
        // A second context would mean the translator writing "Gewonnen" twice
        // and the two copies drifting.
        echo '<ul class="tt-ts-record">';
        foreach ( [
            [ _x( 'Played', 'monthly report match record', 'talenttrack' ), (int) ( $record['played'] ?? 0 ) ],
            [ _x( 'Won', 'monthly report match record', 'talenttrack' ), (int) ( $record['won'] ?? 0 ) ],
            [ _x( 'Drawn', 'monthly report match record', 'talenttrack' ), (int) ( $record['drawn'] ?? 0 ) ],
            [ _x( 'Lost', 'monthly report match record', 'talenttrack' ), (int) ( $record['lost'] ?? 0 ) ],
            [ __( 'Goals for', 'talenttrack' ), (int) ( $record['goals_for'] ?? 0 ) ],
            [ __( 'Goals against', 'talenttrack' ), (int) ( $record['goals_against'] ?? 0 ) ],
            [ __( 'Goal difference', 'talenttrack' ), self::signed( (int) ( $record['goal_difference'] ?? 0 ) ) ],
            [ __( 'Clean sheets', 'talenttrack' ), (int) ( $record['clean_sheets'] ?? 0 ) ],
        ] as [ $label, $value ] ) {
            echo '<li class="tt-ts-record__cell">';
            echo '<span class="tt-ts-record__n">' . esc_html( (string) $value ) . '</span>';
            echo '<span class="tt-ts-record__l">' . esc_html( $label ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        // The second empty state: matches were played, nobody typed the
        // results. Printing zeros here instead would make the whole strip wrong.
        // Both notes reuse the wordings #3516 shipped on the monthly report.
        // They say the same thing about the same two gaps, and two phrasings
        // of one caveat is how a coach ends up wondering whether the tab and
        // the report mean different things.
        $without = (int) ( $record['without_a_score'] ?? 0 );
        if ( $without > 0 ) {
            echo '<p class="tt-ts-note">' . esc_html( sprintf(
                /* translators: %d: number of matches with no score recorded */
                _n(
                    '%d match has no score recorded and is not counted in the record.',
                    '%d matches have no score recorded and are not counted in the record.',
                    $without,
                    'talenttrack'
                ),
                $without
            ) ) . '</p>';
        }

        $tournaments = (int) ( $record['tournaments_excluded'] ?? 0 );
        if ( $tournaments > 0 ) {
            echo '<p class="tt-ts-note">' . esc_html( sprintf(
                /* translators: %d: number of tournaments in the period */
                _n(
                    '%d tournament this period is not included — a tournament is a multi-game day.',
                    '%d tournaments this period are not included — a tournament is a multi-game day.',
                    $tournaments,
                    'talenttrack'
                ),
                $tournaments
            ) ) . '</p>';
        }

        self::sectionClose();
    }

    /**
     * @param list<array<string,mixed>> $form
     */
    private static function renderForm( array $form ): void {
        if ( $form === [] ) return;

        self::sectionOpen( __( 'Recent form', 'talenttrack' ) );
        FormChips::render( array_map(
            static fn( array $row ): array => [
                'outcome'    => (string) ( $row['outcome'] ?? '' ),
                'team_score' => (int) ( $row['team_score'] ?? 0 ),
                'opp_score'  => (int) ( $row['opp_score'] ?? 0 ),
                'opponent'   => (string) ( $row['opponent'] ?? '' ),
            ],
            $form
        ) );
        self::sectionClose();
    }

    /**
     * Scorers and assists, side by side from tablet up.
     *
     * @param list<array<string,mixed>> $scorers
     * @param list<array<string,mixed>> $assists
     */
    private static function renderContributions( array $scorers, array $assists, int $played ): void {
        if ( $scorers === [] && $assists === [] ) {
            // The third empty state, and the one most likely to be reported as
            // a bug if it were left blank: matches were played and scored, but
            // nobody has said who scored them. "Nobody scored" is almost never
            // what that means.
            if ( $played > 0 ) {
                self::sectionOpen( __( 'Scorers and assists', 'talenttrack' ) );
                echo '<p class="tt-ts-empty">'
                    . esc_html__( 'No goals have been attributed to a player yet. Goals are attributed on the match page.', 'talenttrack' )
                    . '</p>';
                self::sectionClose();
            }
            return;
        }

        echo '<div class="tt-ts-pair">';
        // `Goals` bare is already translated as "Doelen" — objectives, the PDP
        // sense. On a scorers table that is the wrong word entirely, so both
        // columns take the context #3516 shipped for exactly these two.
        self::renderLeaderboard( __( 'Top scorers', 'talenttrack' ), $scorers, 'goals', _x( 'Goals', 'monthly report matches column', 'talenttrack' ) );
        self::renderLeaderboard( __( 'Top assists', 'talenttrack' ), $assists, 'assists', _x( 'Assists', 'monthly report matches column', 'talenttrack' ) );
        echo '</div>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function renderLeaderboard( string $title, array $rows, string $key, string $column ): void {
        if ( $rows === [] ) return;

        self::sectionOpen( $title );
        echo '<div class="tt-table-wrap"><table class="tt-table tt-ts-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="tt-ts-table__n">' . esc_html( $column ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<th scope="row">' . self::playerLink( $row ) . '</th>';
            echo '<td class="tt-ts-table__n">' . esc_html( (string) (int) ( $row[ $key ] ?? 0 ) ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        self::sectionClose();
    }

    /**
     * A slice of appearances, and the way to the whole table.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $window
     */
    private static function renderAppearances( array $rows, int $team_id, array $window ): void {
        if ( $rows === [] ) return;

        self::sectionOpen( __( 'Appearances and minutes', 'talenttrack' ) );

        echo '<div class="tt-table-wrap"><table class="tt-table tt-ts-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="tt-ts-table__n">' . esc_html_x( 'Apps', 'column: matches appeared in', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="tt-ts-table__n">' . esc_html__( 'Starts', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="tt-ts-table__n">' . esc_html__( 'Minutes', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( array_slice( $rows, 0, self::APPEARANCES_TOP_N ) as $row ) {
            echo '<tr>';
            echo '<th scope="row">' . self::playerLink( $row ) . '</th>';
            echo '<td class="tt-ts-table__n">' . esc_html( (string) (int) ( $row['matches'] ?? 0 ) ) . '</td>';
            echo '<td class="tt-ts-table__n">' . esc_html( (string) (int) ( $row['starts'] ?? 0 ) ) . '</td>';
            echo '<td class="tt-ts-table__n">' . esc_html( (string) (int) ( $row['minutes'] ?? 0 ) ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // The rest of the squad lives on the minutes report, built the way the
        // monthly report builds the same link: through `RecordLink` and gated
        // by `CrossViewLink`, so a reader who cannot open that report is not
        // offered it.
        $url = BackLink::appendTo( add_query_arg( [
            'tt_view' => 'standard-report', /* tt-xview-ok */
            'slug'    => 'minutes-share',
            'team_id' => $team_id,
            'from'    => (string) ( $window['from'] ?? '' ),
            'to'      => (string) ( $window['to'] ?? '' ),
        ], RecordLink::dashboardUrl() ) );

        $remaining = count( $rows ) - self::APPEARANCES_TOP_N;
        $label     = $remaining > 0
            ? sprintf(
                /* translators: %d: how many players are not shown in the slice. */
                _n(
                    '%d more player — see the full minutes report',
                    '%d more players — see the full minutes report',
                    $remaining,
                    'talenttrack'
                ),
                $remaining
            )
            : __( 'See the full minutes report', 'talenttrack' );

        echo '<p class="tt-ts-note">';
        if ( CrossViewLink::allows( 'standard-report' ) ) {
            echo '<a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        } else {
            echo esc_html( $label );
        }
        echo '</p>';

        self::sectionClose();
    }

    /* ---------------------------------------------------------------
     * Pieces
     * ------------------------------------------------------------- */

    /**
     * A player's name, linked with a back-hint so their profile can offer the
     * way back here (CLAUDE.md §5a).
     *
     * @param array<string,mixed> $row
     */
    private static function playerLink( array $row ): string {
        $player_id = (int) ( $row['player_id'] ?? 0 );
        $name      = (string) ( $row['name'] ?? '' );
        if ( $name === '' ) $name = __( 'Unknown player', 'talenttrack' );

        if ( $player_id <= 0 ) return esc_html( $name );

        $url = RecordLink::detailUrlForWithBack( 'player', $player_id );
        if ( $url === '' ) return esc_html( $name );

        return '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
    }

    private static function sectionOpen( string $title ): void {
        echo '<section class="tt-ts-section">';
        echo '<h3 class="tt-ts-section__title">' . esc_html( $title ) . '</h3>';
    }

    private static function sectionClose(): void {
        echo '</section>';
    }

    /** A goal difference reads as a movement, so it always carries its sign. */
    private static function signed( int $value ): string {
        return ( $value > 0 ? '+' : '' ) . number_format_i18n( $value );
    }

    private static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-team-stats',
            TT_PLUGIN_URL . 'assets/css/frontend-team-stats.css',
            [],
            TT_VERSION
        );
    }
}
