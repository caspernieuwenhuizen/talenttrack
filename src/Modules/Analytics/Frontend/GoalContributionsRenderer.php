<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\GoalContributionDrilldown;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\StackTable;

/**
 * GoalContributionsRenderer (#4994) — the Goals and assists report's two
 * bodies, the team table and one player's drill-down, as one markup for
 * screen and print. Composition only: every number and flag arrives in the
 * data it is given.
 */
final class GoalContributionsRenderer {

    /**
     * The team table: one row per player who scored or assisted, each count a
     * link to that player's drill-down.
     *
     * @param list<array{player_id:int, name:string, goals:int, assists:int}> $rows
     * @param callable(int):string                                             $drill_url player id => drill-down URL
     */
    public static function table( array $rows, callable $drill_url ): string {
        if ( $rows === [] ) {
            return '<p class="tt-ga-empty">' . esc_html__( 'No goals or assists recorded in this season.', 'talenttrack' ) . '</p>';
        }

        $player  = __( 'Player', 'talenttrack' );
        $goals   = _x( 'Goals', 'monthly report matches column', 'talenttrack' );
        $assists = _x( 'Assists', 'monthly report matches column', 'talenttrack' );

        $html  = '<table class="tt-table tt-ga-table ' . esc_attr( StackTable::classes( StackTable::TO_480, true ) ) . '"' . StackTable::attrs( 'table' ) . '>';
        $html .= '<thead' . StackTable::attrs( 'rowgroup' ) . '><tr' . StackTable::attrs( 'row' ) . '>';
        $html .= '<th scope="col"' . StackTable::attrs( 'columnheader' ) . '>' . esc_html( $player ) . '</th>';
        $html .= '<th scope="col" class="tt-ga-n"' . StackTable::attrs( 'columnheader' ) . '>' . esc_html( $goals ) . '</th>';
        $html .= '<th scope="col" class="tt-ga-n"' . StackTable::attrs( 'columnheader' ) . '>' . esc_html( $assists ) . '</th>';
        $html .= '</tr></thead><tbody' . StackTable::attrs( 'rowgroup' ) . '>';

        foreach ( $rows as $row ) {
            $url   = $drill_url( (int) $row['player_id'] );
            $name  = $row['name'] !== '' ? $row['name'] : __( 'Unknown player', 'talenttrack' );
            $html .= '<tr' . StackTable::attrs( 'row' ) . '>';
            $html .= '<td' . StackTable::attrs( 'cell', '' ) . '><a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></td>';
            foreach ( [ [ 'goals', $goals ], [ 'assists', $assists ] ] as [ $key, $label ] ) {
                $n     = (int) $row[ $key ];
                $html .= '<td class="tt-ga-n"' . StackTable::attrs( 'cell', $label ) . '>'
                    . ( $n > 0 ? '<a class="tt-ga-count" href="' . esc_url( $url ) . '">' . esc_html( (string) $n ) . '</a>' : '0' )
                    . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * One player's drill-down: the totals, then every match behind them.
     *
     * @param array<string, mixed>   $data        {@see GoalContributionDrilldown::payload()}
     * @param callable(array):string $match_url   match row => link to the match
     * @param callable(array):string $correct_url match row => link to the grid, or '' for a reader who may not edit
     */
    public static function drilldown( array $data, callable $match_url, callable $correct_url ): string {
        $matches = is_array( $data['matches'] ?? null ) ? $data['matches'] : [];

        $html  = '<ul class="tt-ga-totals">';
        foreach ( [
            [ _x( 'Goals', 'monthly report matches column', 'talenttrack' ), (int) ( $data['goals'] ?? 0 ) ],
            [ _x( 'Assists', 'monthly report matches column', 'talenttrack' ), (int) ( $data['assists'] ?? 0 ) ],
            [ _x( 'Matches', 'goals and assists report', 'talenttrack' ), count( $matches ) ],
        ] as [ $label, $n ] ) {
            $html .= '<li class="tt-ga-totals__cell"><span class="tt-ga-totals__n">' . esc_html( (string) $n ) . '</span>'
                . '<span class="tt-ga-totals__l">' . esc_html( $label ) . '</span></li>';
        }
        $html .= '</ul>';

        if ( $matches === [] ) {
            return $html . '<p class="tt-ga-empty">' . esc_html__( 'No goals or assists recorded in this season.', 'talenttrack' ) . '</p>';
        }

        $html .= '<ol class="tt-ga-matches">';
        foreach ( $matches as $m ) {
            $html .= self::match( $m, $match_url( $m ), $correct_url( $m ) );
        }
        return $html . '</ol>';
    }

    /** @param array<string, mixed> $m */
    private static function match( array $m, string $url, string $correct ): string {
        $flags   = is_array( $m['flags'] ?? null ) ? $m['flags'] : [];
        $flagged = $flags !== [];

        $html  = '<li class="tt-ga-match' . ( $flagged ? ' tt-ga-match--flagged' : '' ) . '">';
        $html .= '<div class="tt-ga-match__head">';
        $title = self::matchTitle( $m );
        $html .= '<h3 class="tt-ga-match__title">'
            . ( $url !== '' ? '<a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) )
            . '</h3>';
        $html .= '<p class="tt-ga-match__meta">' . esc_html( self::matchMeta( $m ) ) . '</p>';
        $html .= '<p class="tt-ga-match__mine">' . esc_html( sprintf(
            /* translators: 1: goals by this player in the match, 2: assists by this player in the match */
            __( '%1$d goals, %2$d assists', 'talenttrack' ),
            (int) $m['goals'],
            (int) $m['assists']
        ) ) . '</p>';
        $html .= '</div>';

        foreach ( $flags as $flag ) {
            $html .= '<p class="tt-ga-flag" role="note">' . esc_html( GoalContributionDrilldown::flagLabel( (string) $flag ) ) . '</p>';
        }

        $html .= '<ul class="tt-ga-events">';
        foreach ( (array) ( $m['events'] ?? [] ) as $e ) {
            $html .= self::event( $e );
        }
        $html .= '</ul>';

        if ( $correct !== '' ) {
            $html .= '<p class="tt-ga-correct"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $correct ) . '">'
                . esc_html__( 'Correct in the minutes grid', 'talenttrack' ) . '</a></p>';
        }

        return $html . '</li>';
    }

    /** @param array<string, mixed> $m */
    private static function matchTitle( array $m ): string {
        $opponent = trim( (string) ( $m['opponent'] ?? '' ) );
        if ( $opponent !== '' ) {
            /* translators: %s: the opponent's name */
            return sprintf( __( 'Against %s', 'talenttrack' ), $opponent );
        }
        $title = trim( (string) ( $m['title'] ?? '' ) );
        return $title !== '' ? $title : __( 'Match', 'talenttrack' );
    }

    /** Date, venue and score, each only when known. @param array<string, mixed> $m */
    private static function matchMeta( array $m ): string {
        $parts = [ TTDate::date( (string) ( $m['date'] ?? '' ) ) ];

        $venue = (string) ( $m['home_away'] ?? '' );
        if ( $venue === 'home' ) $parts[] = _x( 'Home', 'match venue', 'talenttrack' );
        if ( $venue === 'away' ) $parts[] = _x( 'Away', 'match venue', 'talenttrack' );
        if ( ! empty( $m['is_tournament'] ) ) $parts[] = __( 'Tournament', 'talenttrack' );

        if ( $m['team_score'] !== null && $m['opp_score'] !== null ) {
            $parts[] = (int) $m['team_score'] . '–' . (int) $m['opp_score'];
        } else {
            $parts[] = __( 'No score recorded', 'talenttrack' );
        }

        return implode( ' · ', array_filter( $parts, static fn( string $p ): bool => $p !== '' ) );
    }

    /** @param array<string, mixed> $e */
    private static function event( array $e ): string {
        $minute = $e['minute'] !== null
            ? sprintf(
                /* translators: 1: minute in the half, 2: the half (1 or 2) */
                __( '%1$d\' (half %2$d)', 'talenttrack' ),
                (int) $e['minute'],
                (int) ( $e['half'] ?? 1 )
            )
            : __( 'no minute', 'talenttrack' );

        $scorer = (string) $e['scorer'] !== '' ? (string) $e['scorer'] : __( 'No scorer recorded', 'talenttrack' );
        if ( ( $e['team'] ?? 'home' ) === 'away' ) {
            $scorer = __( 'Opponent goal', 'talenttrack' );
        } elseif ( ! empty( $e['is_own_goal'] ) ) {
            /* translators: %s: the player who put the ball in their own net */
            $scorer = sprintf( __( 'Own goal (%s)', 'talenttrack' ), $scorer );
        }

        $line = $minute . ' · ' . $scorer;
        if ( (string) $e['assister'] !== '' ) {
            /* translators: %s: the player credited with the assist */
            $line .= ' · ' . sprintf( __( 'assist %s', 'talenttrack' ), (string) $e['assister'] );
        }

        $html  = '<li class="tt-ga-event' . ( ! empty( $e['flags'] ) ? ' tt-ga-event--flagged' : '' ) . '">';
        $html .= '<span class="tt-ga-event__line">' . esc_html( $line ) . '</span>';
        $html .= '<span class="tt-ga-event__source">' . esc_html( self::provenance( $e ) ) . '</span>';
        return $html . '</li>';
    }

    /** Where and when the entry was recorded, and by whom when that is stored. @param array<string, mixed> $e */
    private static function provenance( array $e ): string {
        $parts = [ GoalContributionDrilldown::sourceLabel( (string) $e['source'] ) ];

        $created = (string) ( $e['created_at'] ?? '' );
        if ( $created !== '' ) $parts[] = TTDate::date( $created );

        if ( ! empty( $e['source_derived'] ) ) {
            $parts[] = __( 'recorded before tracking began', 'talenttrack' );
        } elseif ( (string) ( $e['created_by_name'] ?? '' ) !== '' ) {
            /* translators: %s: the account that entered the goal */
            $parts[] = sprintf( __( 'by %s', 'talenttrack' ), (string) $e['created_by_name'] );
        }

        if ( (string) ( $e['updated_at'] ?? '' ) !== '' ) {
            $who     = (string) ( $e['updated_by_name'] ?? '' );
            $parts[] = $who !== ''
                /* translators: 1: date of the change, 2: the account that changed it */
                ? sprintf( __( 'changed %1$s by %2$s', 'talenttrack' ), TTDate::date( (string) $e['updated_at'] ), $who )
                /* translators: %s: date of the change */
                : sprintf( __( 'changed %s', 'talenttrack' ), TTDate::date( (string) $e['updated_at'] ) );
        }

        return implode( ' · ', $parts );
    }
}
