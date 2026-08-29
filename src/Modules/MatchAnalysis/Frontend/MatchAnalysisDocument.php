<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;

/**
 * MatchAnalysisDocument — the finished analysis, as one document.
 *
 * The read-only view, the staff share page and the print sheet are the same
 * artefact seen through three windows, so they render from here. Three
 * copies of "what a finished analysis looks like" is three places for it to
 * drift, and the one people forward to each other is the one that would be
 * left behind.
 *
 * ## The shape, and why
 *
 * A landscape A4 one-pager: match identity across the top, the coach's
 * overall read in a box beneath it, then two columns of phase tiles and a
 * wide players column.
 *
 * The two columns are the two chains of the game rather than an arbitrary
 * split. With the ball: attacking, then the instant we lose it, then our
 * own set pieces. Without it: defending, then the instant we win it, then
 * theirs. A transition only means something read next to the phase it comes
 * out of, which a single flat list of six cannot express.
 *
 * Each tile carries its own points, so the qualification and the specifics
 * sit together. The first version put every phase in a full-width card and
 * the points in a separate list further down, which gave a phase nobody
 * rated the same weight as the one somebody wrote three points about.
 *
 * Tiles size to their content and unrated phases render as a thin dashed
 * placeholder: the page should show what was said, and show at a glance
 * what was not.
 */
final class MatchAnalysisDocument {

    /**
     * @param array<string,mixed> $payload as composed by MatchAnalysisComposer
     * @param array<string,mixed> $opts    ['print' => bool] — the print
     *                                     sheet drops nothing, but stamps a
     *                                     footer the screen has no use for
     */
    public static function render( array $payload, array $opts = [] ): void {
        $print = ! empty( $opts['print'] );

        echo '<article class="tt-mad' . ( $print ? ' tt-mad--print' : '' ) . '">';

        self::renderHead( $payload );
        self::renderSummary( $payload );
        // #2860 — the goals sit above the phase tiles because that is what
        // they are for: a run of three conceded inside ten minutes is the
        // context for the defending-phase rating further down, and it was
        // living on another screen.
        self::renderGoals( (array) ( $payload['goals'] ?? [] ) );

        echo '<div class="tt-mad__body">';
        foreach ( MatchAnalysisEnums::chains() as $chain => $section_keys ) {
            self::renderChain( $chain, $section_keys, (array) $payload['sections'] );
        }
        self::renderPlayers( (array) $payload['players'] );
        echo '</div>';

        self::renderLegacySetPieces( (array) $payload['sections'] );

        if ( $print ) self::renderFoot();

        echo '</article>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function renderHead( array $payload ): void {
        /** @var object $activity */
        $activity = $payload['activity'];
        $result   = (array) $payload['result'];

        $title = trim( (string) ( $activity->title ?? '' ) );
        if ( $title === '' ) $title = __( 'Match analysis', 'talenttrack' );

        echo '<header class="tt-mad__head">';

        echo '<div class="tt-mad__id">';
        echo '<p class="tt-mad__eyebrow">' . esc_html__( 'Match analysis', 'talenttrack' ) . '</p>';
        echo '<h1 class="tt-mad__title">' . esc_html( $title );

        $opponent  = trim( (string) ( $result['opponent'] ?? '' ) );
        $home_away = strtolower( (string) ( $result['home_away'] ?? '' ) );
        if ( $opponent !== '' ) {
            echo ' <span class="tt-mad__vs">' . esc_html(
                $home_away === 'away'
                    /* translators: %s: opponent name */
                    ? sprintf( __( '— away at %s', 'talenttrack' ), $opponent )
                    /* translators: %s: opponent name */
                    : sprintf( __( '— at home against %s', 'talenttrack' ), $opponent )
            ) . '</span>';
        }
        echo '</h1>';
        echo '</div>';

        echo '<div class="tt-mad__facts">';

        $date = (string) ( $activity->session_date ?? '' );
        if ( $date !== '' ) {
            echo '<span>' . esc_html( date_i18n( (string) get_option( 'date_format' ), strtotime( $date ) ) ) . '</span>';
        }

        $played = count( (array) $payload['players'] );
        if ( $played > 0 ) {
            echo '<span>' . esc_html( sprintf(
                /* translators: %d: how many players took part */
                _n( '%d player', '%d players', $played, 'talenttrack' ),
                $played
            ) ) . '</span>';
        }

        if ( ! empty( $result['has_score'] ) ) {
            printf(
                '<span class="tt-mad__score">%d – %d</span>',
                (int) $result['home_score'],
                (int) $result['away_score']
            );
        }

        echo '</div>';
        echo '</header>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function renderSummary( array $payload ): void {
        $summary = trim( (string) $payload['summary'] );
        if ( $summary === '' ) return;

        echo '<div class="tt-mad__summary">';
        echo '<span class="tt-mad__summary-label">'
            . esc_html( MatchAnalysisEnums::sectionLabel( MatchAnalysisEnums::SECTION_GENERAL ) )
            . '</span>';
        echo nl2br( esc_html( $summary ) );
        echo '</div>';
    }

    /**
     * @param list<string>        $section_keys
     * @param array<string,mixed> $sections
     */
    private static function renderChain( string $chain, array $section_keys, array $sections ): void {
        $labels = MatchAnalysisEnums::chainLabels();

        echo '<div class="tt-mad__chain">';
        echo '<p class="tt-mad__chain-head">' . esc_html( $labels[ $chain ] ?? '' ) . '</p>';

        foreach ( $section_keys as $key ) {
            self::renderTile( $key, isset( $sections[ $key ] ) ? (array) $sections[ $key ] : [] );
        }

        echo '</div>';
    }

    /**
     * @param array<string,mixed> $section
     */
    private static function renderTile( string $key, array $section ): void {
        $rating = $section['rating'] ?? null;
        $items  = self::noteItems( $section );
        $label  = (string) ( $section['label'] ?? MatchAnalysisEnums::sectionLabel( $key ) );

        printf( '<section class="tt-mad__tile" data-rating="%s">', esc_attr( (string) $rating ) );

        echo '<div class="tt-mad__tile-head">';
        echo '<h2 class="tt-mad__tile-name">' . esc_html( $label ) . '</h2>';
        echo '<span class="tt-mad__tile-rating">';
        if ( $rating !== null ) {
            echo '<span class="tt-mad__glyph" aria-hidden="true">'
                . esc_html( SectionRatingControl::glyphs()[ $rating ] ?? '' )
                . '</span> '
                . esc_html( MatchAnalysisEnums::ratings()[ $rating ] ?? '' );
        } else {
            echo esc_html__( 'Not rated', 'talenttrack' );
        }
        echo '</span>';
        echo '</div>';

        if ( $items ) {
            echo '<ul class="tt-mad__points">';
            foreach ( $items as $item ) {
                self::renderPoint( $item );
            }
            echo '</ul>';
        }

        echo '</section>';
    }

    /**
     * One bullet, with its mark if it has one (#3091).
     *
     * The sign is a real character in the text rather than a colour on the
     * row: this document is read on a share page, and printed, and the
     * printer is often monochrome. It also carries the word in the
     * accessible name, because "+" read aloud is not a judgement.
     *
     * @param array{valence:string, body:string} $item
     */
    private static function renderPoint( array $item ): void {
        $body    = (string) ( $item['body'] ?? '' );
        $valence = (string) ( $item['valence'] ?? '' );
        if ( $body === '' ) return;

        printf( '<li data-valence="%s">', esc_attr( $valence ) );

        if ( MatchAnalysisEnums::isValence( $valence ) ) {
            printf(
                '<span class="tt-mad__point-mark" data-valence="%s"><span aria-hidden="true">%s</span><span class="tt-mad__sr">%s</span></span> ',
                esc_attr( $valence ),
                esc_html( MatchAnalysisEnums::valenceGlyph( $valence ) ),
                esc_html( MatchAnalysisEnums::valenceLabel( $valence ) )
            );
        }

        echo esc_html( $body );
        echo '</li>';
    }

    /**
     * @param array<string,mixed> $carrier a composed section or player
     * @return list<array{valence:string, body:string}>
     */
    private static function noteItems( array $carrier ): array {
        $items = $carrier['note_items'] ?? null;
        if ( ! is_array( $items ) ) return [];

        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $body = trim( (string) ( $item['body'] ?? '' ) );
            if ( $body === '' ) continue;
            $out[] = [
                'valence' => (string) ( $item['valence'] ?? '' ),
                'body'    => $body,
            ];
        }
        return $out;
    }

    /**
     * Anything still stored under the pre-split `set_pieces` key, so an
     * analysis written before the vocabulary changed keeps its words even
     * if the migration could not move it (an analysis that already had an
     * attacking set-piece section has nowhere to put a merged one).
     *
     * @param array<string,mixed> $sections
     */
    private static function renderLegacySetPieces( array $sections ): void {
        $legacy = $sections[ MatchAnalysisEnums::SECTION_SET_PIECES_LEGACY ] ?? null;
        if ( ! is_array( $legacy ) ) return;
        if ( ( $legacy['rating'] ?? null ) === null && self::noteItems( $legacy ) === [] ) return;

        echo '<div class="tt-mad__legacy">';
        self::renderTile( MatchAnalysisEnums::SECTION_SET_PIECES_LEGACY, $legacy );
        echo '</div>';
    }

    /**
     * @param list<array<string,mixed>> $players
     */
    private static function renderPlayers( array $players ): void {
        $mentioned = array_values( array_filter(
            $players,
            static fn( array $p ): bool => (string) $p['marker'] !== '' || self::noteItems( $p ) !== []
        ) );

        echo '<div class="tt-mad__players">';
        printf(
            '<p class="tt-mad__chain-head">%s</p>',
            esc_html( sprintf(
                /* translators: 1: how many players were marked, 2: how many played */
                __( 'Players · %1$d of %2$d mentioned', 'talenttrack' ),
                count( $mentioned ),
                count( $players )
            ) )
        );

        if ( empty( $mentioned ) ) {
            echo '<p class="tt-mad__none">' . esc_html__( 'Nobody was singled out in this match.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }

        // Grouped by marker so the column reads stood out → as expected →
        // below par, which is the order a coach says it in.
        $order = array_keys( MatchAnalysisEnums::markers() );
        usort(
            $mentioned,
            static function ( array $a, array $b ) use ( $order ): int {
                $ai = array_search( (string) $a['marker'], $order, true );
                $bi = array_search( (string) $b['marker'], $order, true );
                return ( $ai === false ? 99 : $ai ) <=> ( $bi === false ? 99 : $bi );
            }
        );

        foreach ( $mentioned as $player ) {
            $marker = (string) $player['marker'];

            printf( '<div class="tt-mad__player" data-marker="%s">', esc_attr( $marker ) );

            echo '<div class="tt-mad__player-line">';
            echo '<span class="tt-mad__player-name">' . esc_html( (string) $player['name'] ) . '</span>';
            if ( $marker !== '' ) {
                echo '<span class="tt-mad__player-mark">'
                    . '<span class="tt-mad__glyph" aria-hidden="true">'
                    . esc_html( PlayerTallyRoster::glyphs()[ $marker ] ?? '' )
                    . '</span> '
                    . esc_html( MatchAnalysisEnums::markerLabel( $marker ) )
                    . '</span>';
            }
            if ( $player['minutes'] !== null ) {
                echo '<span class="tt-mad__player-min">' . esc_html( sprintf(
                    /* translators: %d: minutes played */
                    __( "%d'", 'talenttrack' ),
                    (int) $player['minutes']
                ) ) . '</span>';
            }
            echo '</div>';

            // #3091 — a player can hold a plus and a minus in the same
            // match, so this is a list rather than a paragraph.
            $notes = self::noteItems( $player );
            if ( $notes ) {
                echo '<ul class="tt-mad__player-notes">';
                foreach ( $notes as $note ) {
                    self::renderPoint( $note );
                }
                echo '</ul>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * #2860 — the goal timeline, chronological across both halves.
     *
     * Renders nothing at all when the match has no logged goals. An empty
     * frame would say "no goals were scored" where the truth is usually
     * "this match was never run through the live screen", and a document
     * that reads as staff evidence should not assert the first.
     *
     * @param list<array<string,mixed>> $goals
     */
    private static function renderGoals( array $goals ): void {
        if ( empty( $goals ) ) return;

        echo '<section class="tt-mad__goals" aria-label="' . esc_attr__( 'Goals', 'talenttrack' ) . '">';
        echo '<h2 class="tt-mad__goals-title">' . esc_html__( 'Goals', 'talenttrack' ) . '</h2>';
        echo '<ol class="tt-mad__goal-list">';

        foreach ( $goals as $goal ) {
            $ours = ( (string) ( $goal['team'] ?? 'home' ) === 'home' );
            $own  = ! empty( $goal['is_own_goal'] );

            // Three states our goals can be in, kept apart. "Own goal" and
            // "scorer not recorded" are different facts, and collapsing them
            // into one label is what the review surface stopped doing.
            if ( ! $ours ) {
                $who = $own
                    ? _x( 'Own goal', 'goal put in by the side it counts against', 'talenttrack' )
                    : __( 'Opponent goal', 'talenttrack' );
            } elseif ( $own ) {
                $who = _x( 'Own goal', 'goal put in by the side it counts against', 'talenttrack' );
            } elseif ( ! empty( $goal['has_scorer'] ) ) {
                $who = (string) $goal['scorer'];
            } else {
                $who = __( 'Scorer not recorded', 'talenttrack' );
            }

            echo '<li class="tt-mad__goal tt-mad__goal--' . ( $ours ? 'for' : 'against' ) . '">';
            echo '<span class="tt-mad__goal-min">' . esc_html( sprintf( "%d'", (int) $goal['minute'] ) ) . '</span>';
            echo '<span class="tt-mad__goal-who">' . esc_html( $who ) . '</span>';

            $assist = (string) ( $goal['assist'] ?? '' );
            if ( $assist !== '' ) {
                echo '<span class="tt-mad__goal-assist">' . esc_html( sprintf(
                    /* translators: %s: the assisting player's name */
                    __( 'assist %s', 'talenttrack' ),
                    $assist
                ) ) . '</span>';
            }

            echo '</li>';
        }

        echo '</ol>';
        echo '</section>';
    }

    private static function renderFoot(): void {
        echo '<footer class="tt-mad__foot">';
        echo '<span>' . esc_html__( 'Staff document — please do not forward it outside the staff.', 'talenttrack' ) . '</span>';
        echo '<span>' . esc_html( sprintf(
            /* translators: %s: the date the sheet was printed */
            __( 'TalentTrack · printed %s', 'talenttrack' ),
            date_i18n( (string) get_option( 'date_format' ) )
        ) ) . '</span>';
        echo '</footer>';
    }

}
