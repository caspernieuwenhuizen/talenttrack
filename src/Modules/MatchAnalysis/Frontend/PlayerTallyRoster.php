<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;

/**
 * PlayerTallyRoster (#2726) — the roster half of a match analysis, rendered
 * once and used by both surfaces.
 *
 * ## Why it looks the way it does
 *
 * The first version gave every player a four-option radio group and a note
 * field. Fourteen of those is a page a coach scrolls past rather than
 * fills in, and the thing they actually want to do — mark the two or three
 * players who stood out — was buried in the ninety-odd controls belonging
 * to everyone else.
 *
 * So the squad is a **grid of names**, and the name IS the control. Tapping
 * one opens a three-way picker; the players you mark then appear below with
 * a note field each. Fourteen players fit on one phone screen and a blank
 * analysis has no text inputs on it at all.
 *
 * The whole squad still renders — that has not changed and must not. The
 * roster is listed so nobody is quietly skipped, which a "pick two players"
 * search box would do to exactly the children nobody thinks to search for.
 *
 * ## The markup is the fallback
 *
 * PHP renders the honest form: one block per player with a real radio group
 * and its note + phase fields. `match-analysis-tally.js` then builds the
 * grid from that markup, hides the radio groups, and hides the blocks of
 * players nobody marked. With no JS the coach gets the plain form — slower,
 * complete, and correct. There is exactly one set of inputs either way, so
 * nothing can post twice or fall out of step.
 */
final class PlayerTallyRoster {

    /**
     * @param list<array<string,mixed>> $players as composed by MatchAnalysisComposer
     * @param string                    $id_prefix so the wizard and the flat
     *                                  surface can render on one page without
     *                                  colliding element ids
     */
    public static function render( array $players, string $id_prefix = 'ma' ): void {
        if ( empty( $players ) ) {
            echo '<p class="tt-ma__hint">'
                . esc_html__( 'Nobody is recorded as having played this match, so there is no roster to mark up. Record attendance or minutes first.', 'talenttrack' )
                . '</p>';
            return;
        }

        $markers = MatchAnalysisEnums::markers();
        $tags    = MatchAnalysisEnums::playerItemTags();

        echo '<div class="tt-ma__roster" data-tt-tally data-prefix="' . esc_attr( $id_prefix ) . '">';

        self::renderLegend();

        echo '<p class="tt-ma__hint tt-ma__roster-intro">'
            . esc_html__( 'Everyone who played is listed. Mark the players worth a word — the rest stay untouched, which is the normal case.', 'talenttrack' )
            . '</p>';

        echo '<ul class="tt-ma__players">';
        foreach ( $players as $player ) {
            self::renderPlayer( $player, $markers, $tags, $id_prefix );
        }
        echo '</ul>';

        echo '</div>';
    }

    /**
     * The glyph key. Rendered server-side rather than by the script so it is
     * present in the fallback too, where the glyphs still label the radio
     * options.
     */
    private static function renderLegend(): void {
        $glyphs = self::glyphs();

        echo '<p class="tt-ma__legend">';
        foreach ( MatchAnalysisEnums::markers() as $key => $label ) {
            printf(
                '<span class="tt-ma__legend-item"><span class="tt-ma__glyph" data-marker="%s" aria-hidden="true">%s</span> %s</span>',
                esc_attr( $key ),
                esc_html( $glyphs[ $key ] ?? '' ),
                esc_html( $label )
            );
        }
        echo '</p>';
    }

    /**
     * @param array<string,mixed> $player
     * @param array<string,string> $markers
     * @param array<string,string> $tags
     */
    private static function renderPlayer( array $player, array $markers, array $tags, string $prefix ): void {
        $pid    = (int) $player['player_id'];
        $marker = (string) $player['marker'];
        $name   = (string) $player['name'];

        printf(
            '<li class="tt-ma__player" data-player-id="%d" data-marker="%s" data-name="%s" data-minutes="%s">',
            $pid,
            esc_attr( $marker ),
            esc_attr( $name ),
            esc_attr( $player['minutes'] !== null ? (string) (int) $player['minutes'] : '' )
        );

        echo '<div class="tt-ma__player-head">';
        echo '<span class="tt-ma__player-name">' . esc_html( $name ) . '</span>';
        if ( $player['minutes'] !== null ) {
            printf(
                '<span class="tt-ma__player-min">%s</span>',
                esc_html( sprintf(
                    /* translators: %d: minutes played */
                    __( "%d'", 'talenttrack' ),
                    (int) $player['minutes']
                ) )
            );
        }
        echo '</div>';

        if ( (string) $player['prep_focus'] !== '' ) {
            echo '<p class="tt-ma__player-plan">'
                . esc_html( sprintf(
                    /* translators: %s: the attention note written on the match plan */
                    __( 'Asked to: %s', 'talenttrack' ),
                    (string) $player['prep_focus']
                ) )
                . '</p>';
        }

        self::renderMarkerRadios( $pid, $marker, $markers, $prefix, $name );

        printf(
            '<input type="text" class="tt-input tt-ma__player-note" name="players[%1$d][note]" value="%2$s" maxlength="240" placeholder="%3$s" aria-label="%4$s" />',
            $pid,
            esc_attr( (string) $player['note'] ),
            esc_attr__( 'What exactly did they do?', 'talenttrack' ),
            esc_attr( sprintf(
                /* translators: %s: player name */
                __( 'Note about %s', 'talenttrack' ),
                $name
            ) )
        );

        printf(
            '<select class="tt-input tt-ma__player-tag" name="players[%1$d][team_function]" aria-label="%2$s">',
            $pid,
            esc_attr( sprintf(
                /* translators: %s: player name */
                __( 'Which part of the game — %s', 'talenttrack' ),
                $name
            ) )
        );
        printf(
            '<option value=""%s>%s</option>',
            selected( (string) ( $player['team_function'] ?? '' ), '', false ),
            esc_html__( 'No particular phase', 'talenttrack' )
        );
        foreach ( $tags as $tag_key => $tag_label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( (string) $tag_key ),
                selected( (string) ( $player['team_function'] ?? '' ), (string) $tag_key, false ),
                esc_html( (string) $tag_label )
            );
        }
        echo '</select>';

        echo '</li>';
    }

    /**
     * The radio group that actually carries the value. Visible in the
     * fallback, hidden (but still submitted) once the script takes over.
     *
     * @param array<string,string> $markers
     */
    private static function renderMarkerRadios( int $pid, string $current, array $markers, string $prefix, string $name ): void {
        $glyphs  = self::glyphs();
        $options = [ '' => __( 'Not mentioned', 'talenttrack' ) ] + $markers;

        printf(
            '<div class="tt-ma__markers" role="radiogroup" aria-label="%s">',
            esc_attr( sprintf(
                /* translators: %s: player name */
                __( 'How did %s do?', 'talenttrack' ),
                $name
            ) )
        );

        foreach ( $options as $value => $label ) {
            $id = 'tt-' . $prefix . '-p' . $pid . '-' . ( $value === '' ? 'none' : sanitize_key( (string) $value ) );
            printf(
                '<input type="radio" class="tt-ma__marker-input" id="%1$s" name="players[%2$d][marker]" value="%3$s"%4$s />'
                . '<label class="tt-ma__marker" for="%1$s" data-marker="%3$s">'
                . '<span class="tt-ma__glyph" aria-hidden="true">%5$s</span> %6$s</label>',
                esc_attr( $id ),
                $pid,
                esc_attr( (string) $value ),
                checked( $current, (string) $value, false ),
                esc_html( $glyphs[ (string) $value ] ?? '' ),
                esc_html( (string) $label )
            );
        }

        echo '</div>';
    }

    /**
     * Glyph per marker. Shape, not only colour: ▲ and ▼ carry the direction
     * for anyone who cannot tell the green fill from the amber one.
     *
     * @return array<string,string>
     */
    public static function glyphs(): array {
        return [
            ''                                    => '—',
            MatchAnalysisEnums::MARKER_STOOD_OUT   => '▲',
            MatchAnalysisEnums::MARKER_AS_EXPECTED => '●',
            MatchAnalysisEnums::MARKER_BELOW_PAR   => '▼',
        ];
    }

    /**
     * Strings the script needs. Localised here so the roster's copy lives
     * with the roster rather than in whichever view happened to enqueue it.
     *
     * @return array<string,string>
     */
    public static function scriptStrings(): array {
        return [
            'chooseFor'  => __( 'How did %s do?', 'talenttrack' ),
            'clear'      => __( 'Clear', 'talenttrack' ),
            'notesTitle' => __( 'Notes', 'talenttrack' ),
            /* translators: 1: how many players were marked, 2: squad size */
            'counted'    => __( '%1$d of %2$d marked', 'talenttrack' ),
            'emptyState' => __( 'Nobody marked yet — that is a valid answer.', 'talenttrack' ),
        ];
    }
}
