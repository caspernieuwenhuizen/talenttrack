<?php
namespace TT\Modules\Tournaments\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Tournaments\PlayerTournamentAccess;
use TT\Modules\Tournaments\Services\PlayerTournamentHistoryQuery;
use TT\Modules\Tournaments\Services\TournamentMinutesCalculator;
use TT\Shared\Dates\TTDate;

/**
 * PlayerTournamentsTab (#3562, epic #3558) — one player's tournament
 * record, on their file.
 *
 * Which player question does this answer? *Where have they come from, and
 * where are they going?* — for the days the academy plays four short
 * fixtures instead of one long one. A tournament day is where a coach
 * rotates hardest, and until this tab the only surface that reported the
 * resulting minutes was the tournament's own planner: you could see how one
 * Saturday had been divided between sixteen children, and nowhere how a
 * season of Saturdays had gone for one of them.
 *
 * ## It composes; it does not decide (CLAUDE.md §4)
 *
 * Every figure comes from `PlayerTournamentHistoryQuery`, which
 * `GET /players/{id}/tournaments` calls too. There is no arithmetic here —
 * if this file ever needs to add two numbers together, the query is missing
 * a field. That is what keeps the tab and the API, and the tab and the
 * coach's minutes ticker, from telling a family three different numbers.
 *
 * ## The comparison is the player's own target
 *
 * Never a squad average. A teammate's minutes are not this player's
 * business, and a child's file is the wrong place to learn how much more
 * somebody else played. `target_minutes` comes from the tournament's own
 * squad row, which is where a coach set it. **Only a shortfall is
 * coloured**, and the figures are spelled out beside the bar, so the state
 * never depends on seeing a colour.
 *
 * ## The source is named on the page
 *
 * Minutes follow the rotation plan of completed fixtures, and the page says
 * so, pointing at the Activities tab for recorded minutes. The two are
 * never added together — a tournament day's attendance is one total for the
 * day — and a reader should not have to work that out.
 */
final class PlayerTournamentsTab {

    public static function render( int $player_id, int $user_id ): void {
        // The tab is gated where the strip is built, but a tab panel is
        // reachable by URL, so the guard is repeated here rather than
        // assumed. A tab hidden from the strip is not a tab that is
        // protected.
        if ( ! PlayerTournamentAccess::canRead( $user_id, $player_id ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to see this player\'s tournament record.', 'talenttrack' )
                . '</p>';
            return;
        }

        // #1867 — a parent reads only what the child has left visible. The
        // REST route applies the same check, so the screen and the API
        // cannot disagree about what a family may see.
        if ( ! AuthorizationService::parentCanViewSection( $user_id, $player_id, 'tournaments' ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'This player has chosen not to share their tournament history.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueue();

        $history = ( new PlayerTournamentHistoryQuery() )->forPlayer( $player_id );

        if ( empty( $history['tournaments'] ) ) {
            // "Never selected" is a finding, not an empty state to hide: a
            // coach checking fair-share play needs to see it.
            echo '<p class="tt-muted">'
                . esc_html__( 'This player has not been in a tournament squad yet.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::renderUpcoming( $history['upcoming'] );
        self::renderFacts( (array) $history['totals'] );
        self::renderTournaments( (array) $history['tournaments'] );
        self::renderSource();
    }

    private static function enqueue(): void {
        // The fact cards are the training-exposure ones, reused rather than
        // restyled: two player-file tabs showing "here are the headline
        // numbers" should look like the same product.
        wp_enqueue_style(
            'tt-frontend-training-exposure',
            TT_PLUGIN_URL . 'assets/css/frontend-training-exposure.css',
            [],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-frontend-player-tournaments',
            TT_PLUGIN_URL . 'assets/css/frontend-player-tournaments.css',
            [ 'tt-frontend-training-exposure' ],
            TT_VERSION
        );
    }

    /** @param array<string,mixed>|null $upcoming */
    private static function renderUpcoming( ?array $upcoming ): void {
        if ( ! $upcoming ) return;

        $date = (string) ( $upcoming['start_date'] ?? '' );

        echo '<div class="tt-ptour__upcoming" role="status">';
        echo '<strong>' . esc_html( trim(
            ( $date !== '' ? TTDate::dateWithDay( $date ) . ' · ' : '' ) . (string) ( $upcoming['name'] ?? '' )
        ) ) . '</strong>';
        echo '<span>' . esc_html( sprintf(
            /* translators: 1: minutes scheduled, 2: number of fixtures, 3: number of those the player starts. */
            __( '%1$d min planned across %2$d fixtures · %3$d starting', 'talenttrack' ),
            (int) ( $upcoming['scheduled_minutes'] ?? 0 ),
            (int) ( $upcoming['fixture_count'] ?? 0 ),
            (int) ( $upcoming['starts'] ?? 0 )
        ) ) . '</span>';
        echo '</div>';
    }

    /** @param array<string,mixed> $totals */
    private static function renderFacts( array $totals ): void {
        $minutes  = (int) ( $totals['minutes'] ?? 0 );
        $stronger = (int) ( $totals['minutes_vs_stronger'] ?? 0 );

        echo '<div class="tt-exposure__facts">';

        self::fact(
            (string) $minutes,
            __( 'Minutes', 'talenttrack' ),
            sprintf(
                /* translators: 1: number of fixtures, 2: number of tournaments. */
                __( '%1$d fixtures, %2$d tournaments', 'talenttrack' ),
                (int) ( $totals['fixtures'] ?? 0 ),
                (int) ( $totals['tournaments'] ?? 0 )
            )
        );

        self::fact(
            sprintf( '%d / %d', (int) ( $totals['starts'] ?? 0 ), (int) ( $totals['fixtures'] ?? 0 ) ),
            __( 'Starts', 'talenttrack' ),
            ''
        );

        self::fact(
            (string) (int) ( $totals['full_matches'] ?? 0 ),
            // The minutes ticker's own label, msgctxt and all: the same
            // number about the same fixtures, so it reads the same word.
            _x( 'Full matches', 'tournament minutes ticker: matches played end to end', 'talenttrack' ),
            ''
        );

        self::fact(
            (string) $stronger,
            __( 'Against stronger sides', 'talenttrack' ),
            // The share is a reading aid, not a second fact — a bare 95 says
            // nothing without the 215 it came out of. The percentage is
            // formatted, not computed: both numbers are given.
            $minutes > 0
                ? sprintf(
                    /* translators: %s: percentage of the player's minutes, already formatted. */
                    __( '%s of their minutes', 'talenttrack' ),
                    number_format_i18n( round( $stronger / $minutes * 100 ) ) . '%'
                )
                : ''
        );

        if ( (int) ( $totals['targets_set'] ?? 0 ) > 0 ) {
            self::fact(
                sprintf( '%d / %d', (int) ( $totals['targets_met'] ?? 0 ), (int) $totals['targets_set'] ),
                __( 'Targets met', 'talenttrack' ),
                __( 'tournaments with a minutes target', 'talenttrack' )
            );
        }

        echo '</div>';
    }

    private static function fact( string $value, string $label, string $sub ): void {
        echo '<div class="tt-exposure__fact">';
        echo '<span class="tt-exposure__fact-value">' . esc_html( $value ) . '</span>';
        echo '<span class="tt-exposure__fact-label">' . esc_html( $label ) . '</span>';
        if ( $sub !== '' ) {
            echo '<span class="tt-exposure__fact-sub">' . esc_html( $sub ) . '</span>';
        }
        echo '</div>';
    }

    /** @param list<array<string,mixed>> $tournaments */
    private static function renderTournaments( array $tournaments ): void {
        echo '<h3 class="tt-ptour__title">' . esc_html__( 'Per tournament', 'talenttrack' ) . '</h3>';
        echo '<div class="tt-ptour__list">';

        $first = true;
        foreach ( $tournaments as $tournament ) {
            self::renderTournament( (array) $tournament, $first );
            $first = false;
        }

        echo '</div>';
    }

    /** @param array<string,mixed> $tournament */
    private static function renderTournament( array $tournament, bool $open ): void {
        $date   = (string) ( $tournament['start_date'] ?? '' );
        $target = $tournament['target_minutes'] !== null ? (int) $tournament['target_minutes'] : null;
        $played = (int) ( $tournament['played_minutes'] ?? 0 );

        echo '<details class="tt-ptour__tournament"' . ( $open ? ' open' : '' ) . '>';
        echo '<summary class="tt-ptour__summary">';

        echo '<span class="tt-ptour__summary-head">';
        echo '<span class="tt-ptour__name">' . esc_html( (string) ( $tournament['name'] ?? '' ) ) . '</span>';
        if ( $date !== '' ) {
            echo '<span class="tt-ptour__date">' . esc_html( TTDate::dateWithDay( $date ) ) . '</span>';
        }
        echo '</span>';

        if ( $target !== null && $target > 0 ) {
            // The one computed style on this surface: the bar's width is a
            // share of a number only the server knows.
            $share = min( 100, (int) round( $played / $target * 100 ) );
            $short = $played < $target;

            echo '<span class="tt-ptour__target' . ( $short ? ' is-short' : '' ) . '">';
            echo '<span class="tt-ptour__bar"><i style="width:' . (int) $share . '%"></i></span>'; /* tt-inline-ok */
            echo '<span class="tt-ptour__target-label">' . esc_html( sprintf(
                /* translators: 1: minutes played, 2: the player's own minutes target for this tournament. */
                __( '%1$d of %2$d min target', 'talenttrack' ),
                $played,
                $target
            ) ) . '</span>';
            echo '</span>';
        }

        echo '<span class="tt-ptour__meta">' . esc_html( self::metaLine( $tournament, $target ) ) . '</span>';
        echo '</summary>';

        echo '<ul class="tt-ptour__fixtures">';
        foreach ( (array) ( $tournament['fixtures'] ?? [] ) as $fixture ) {
            self::renderFixture( (array) $fixture );
        }
        echo '</ul>';

        echo '</details>';
    }

    /** @param array<string,mixed> $tournament */
    private static function metaLine( array $tournament, ?int $target ): string {
        $parts = [];

        $team = (string) ( $tournament['team_name'] ?? '' );
        if ( $team !== '' ) $parts[] = $team;

        $parts[] = sprintf(
            /* translators: %d: number of fixtures in this tournament the player was down for. */
            _n( '%d fixture', '%d fixtures', (int) ( $tournament['fixture_count'] ?? 0 ), 'talenttrack' ),
            (int) ( $tournament['fixture_count'] ?? 0 )
        );

        $parts[] = sprintf(
            /* translators: %d: number of fixtures the player started. */
            _n( '%d start', '%d starts', (int) ( $tournament['starts'] ?? 0 ), 'talenttrack' ),
            (int) ( $tournament['starts'] ?? 0 )
        );

        if ( $target === null ) {
            $parts[] = __( 'no minutes target', 'talenttrack' );
        }

        return implode( ' · ', $parts );
    }

    /** @param array<string,mixed> $fixture */
    private static function renderFixture( array $fixture ): void {
        $completed = ! empty( $fixture['completed'] );
        $role      = (string) ( $fixture['role'] ?? '' );
        $level     = is_array( $fixture['opponent_level'] ?? null ) ? $fixture['opponent_level'] : [];

        $classes = [ 'tt-ptour__fixture' ];
        if ( $role === TournamentMinutesCalculator::ROLE_BENCH ) $classes[] = 'is-bench';
        if ( ! $completed ) $classes[] = 'is-upcoming';

        echo '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';

        echo '<span class="tt-ptour__opp">';
        $opponent = (string) ( $fixture['opponent_name'] ?? '' );
        echo esc_html( $opponent !== '' ? $opponent : (string) ( $fixture['label'] ?? '' ) );
        if ( ( $level['key'] ?? '' ) !== '' ) {
            // The lookup's own colour, resolved server-side by
            // `LookupPill::describe()` so an operator who recolours the
            // vocabulary recolours this pill too.
            echo ' <span class="tt-ptour__level" style="background:' . esc_attr( (string) $level['color'] ) . ';">' /* tt-inline-ok */
                . esc_html( (string) $level['label'] ) . '</span>';
        }
        echo '</span>';

        // Null, not 0-0. A goalless draw and a fixture nobody typed in are
        // different facts, and this is a child's record.
        $ours   = $fixture['our_score'];
        $theirs = $fixture['their_score'];
        if ( $ours === null || $theirs === null ) {
            echo '<span class="tt-ptour__score is-none">' . esc_html__( 'no result', 'talenttrack' ) . '</span>';
        } else {
            echo '<span class="tt-ptour__score">' . esc_html( sprintf( '%d–%d', (int) $ours, (int) $theirs ) ) . '</span>';
        }

        echo '<span class="tt-ptour__line">';
        echo '<span class="tt-ptour__role">' . esc_html( self::roleLabel( $role ) ) . '</span>';
        echo '<span>' . esc_html( sprintf(
            /* translators: 1: minutes the player was on the pitch, 2: the fixture's full length. */
            __( '%1$d of %2$d min', 'talenttrack' ),
            (int) ( $fixture['minutes'] ?? 0 ),
            (int) ( $fixture['duration_min'] ?? 0 )
        ) ) . '</span>';
        $positions = (array) ( $fixture['positions'] ?? [] );
        echo '<span>' . esc_html( $positions !== [] ? implode( ', ', array_map( 'strval', $positions ) ) : '—' ) . '</span>';
        echo '</span>';

        echo '</li>';
    }

    /**
     * One word each, through `_x()`: a bare "Start" takes the wrong Dutch
     * sense — the verb rather than the place in the line-up.
     */
    private static function roleLabel( string $role ): string {
        switch ( $role ) {
            case TournamentMinutesCalculator::ROLE_START:
                return _x( 'Start', 'the player was in the opening line-up', 'talenttrack' );
            case TournamentMinutesCalculator::ROLE_SUB:
                return _x( 'Sub', 'the player came on during the fixture', 'talenttrack' );
            case TournamentMinutesCalculator::ROLE_BENCH:
                return _x( 'Bench', 'the player was in the squad and did not play', 'talenttrack' );
            default:
                return '';
        }
    }

    /**
     * Where the numbers come from, said on the page rather than left for a
     * reader to assume. The two sources are never added together.
     */
    private static function renderSource(): void {
        echo '<p class="tt-ptour__source">'
            . esc_html__( 'Minutes follow the rotation plan of completed fixtures. Minutes entered afterwards in the minutes overview are on the Activities tab, and the two are never added together.', 'talenttrack' )
            . '</p>';
    }
}
