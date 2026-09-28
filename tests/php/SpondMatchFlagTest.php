<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Spond\SpondParser;
use TT\Modules\Spond\SpondSync;
use TT\Modules\Spond\SpondTypeResolver;

/**
 * #4164 — a match in Spond imports as a match.
 *
 * Spond flags every event created as a match with `matchEvent: true` and
 * a `matchInfo` block naming the opponent and home/away. The parser dropped
 * both, and the heading of a Spond match is usually just the two team
 * names ("VCT JO14-1 - Hedel JO14-1"), so the keyword classifier found
 * nothing and every such match landed as a training. The type is kept on
 * re-sync, so those rows never recovered either.
 */
final class SpondMatchFlagTest extends WP_UnitTestCase {

    // ── the parser keeps what Spond says ───────────────────────────────

    public function test_the_parser_keeps_the_match_flag_and_match_info(): void {
        $rows = SpondParser::parse( [ [
            'id'             => 'EV1',
            'heading'        => 'VCT JO14-1 - Hedel JO14-1',
            'startTimestamp' => '2026-10-03T12:00:00.000Z',
            'matchEvent'     => true,
            'matchInfo'      => [ 'type' => 'AWAY', 'teamName' => 'VCT JO14-1', 'opponentName' => 'Hedel JO14-1' ],
        ] ] );

        $this->assertTrue( $rows[0]['is_match'] );
        $this->assertSame( 'Hedel JO14-1', $rows[0]['match_opponent'] );
        $this->assertSame( 'away', $rows[0]['match_home_away'] );
    }

    public function test_an_ordinary_event_parses_as_no_match(): void {
        $rows = SpondParser::parse( [ [ 'id' => 'EV2', 'heading' => 'Training' ] ] );

        $this->assertFalse( $rows[0]['is_match'] );
        $this->assertSame( '', $rows[0]['match_opponent'] );
        $this->assertSame( '', $rows[0]['match_home_away'], 'no home/away is guessed' );
    }

    // ── the resolver: Spond's flag, then the title, then the description ──

    public function test_a_spond_match_with_a_bare_team_names_heading_is_a_game(): void {
        $this->assertSame( 'training', SpondTypeResolver::classify( 'VCT JO14-1 - Hedel JO14-1' ), 'the keyword scan alone cannot see it' );
        $this->assertSame( 'game', SpondTypeResolver::classify( 'VCT JO14-1 - Hedel JO14-1', '', true ) );
    }

    /** Spond's flag wins over a title that happens to say "training". */
    public function test_the_spond_flag_beats_a_training_keyword_in_the_title(): void {
        $this->assertSame( 'game', SpondTypeResolver::classify( 'Oefenen tegen Ajax', 'training vooraf', true ) );
    }

    public function test_a_flagged_tournament_stays_a_tournament(): void {
        $this->assertSame( 'tournament', SpondTypeResolver::classify( 'Paastoernooi Hedel', '', true ) );
    }

    public function test_without_the_flag_nothing_changes(): void {
        $this->assertSame( 'training', SpondTypeResolver::classify( 'Training JO14-1', 'wedstrijd zaterdag', false ) );
        $this->assertSame( 'game', SpondTypeResolver::classify( 'Wedstrijd tegen Ajax' ) );
    }

    // ── re-sync correction of rows the old fallback mis-typed ──────────

    public function test_a_stored_training_that_spond_flags_as_a_match_is_corrected(): void {
        $this->assertSame( 'game', SpondTypeResolver::correctedType( 'training', 'VCT JO14-1 - Hedel JO14-1', true ) );
        $this->assertSame( 'tournament', SpondTypeResolver::correctedType( 'training', 'Toernooi Hedel', true ) );
    }

    /** Any other stored type was a choice. */
    public function test_a_type_a_coach_chose_is_left_alone(): void {
        foreach ( [ 'meeting', 'other', 'tournament', 'game' ] as $stored ) {
            $this->assertNull( SpondTypeResolver::correctedType( $stored, 'VCT JO14-1 - Hedel JO14-1', true ), $stored );
        }
    }

    public function test_a_training_spond_does_not_flag_is_left_alone(): void {
        $this->assertNull( SpondTypeResolver::correctedType( 'training', 'VCT JO14-1 - Hedel JO14-1', false ) );
    }

    // ── the opponent comes from matchInfo first ─────────────────────────

    public function test_match_info_supplies_opponent_and_venue(): void {
        $cols = $this->opponentColumns( 'game', [ 'match_opponent' => 'Hedel JO14-1', 'match_home_away' => 'away' ], 'Zaterdag 14:00' );

        $this->assertSame( [ 'opponent' => 'Hedel JO14-1', 'home_away' => 'away' ], $cols );
    }

    /** Spond's own home/away beats the side of the dash the title puts us on. */
    public function test_match_info_beats_the_title_parse(): void {
        $cols = $this->opponentColumns(
            'game',
            [ 'match_opponent' => 'Hedel JO14-1', 'match_home_away' => 'away' ],
            'VCT JO14-1 - Hedel JO14-1'
        );

        $this->assertSame( 'away', $cols['home_away'] );
    }

    public function test_without_match_info_the_title_parse_still_applies(): void {
        $cols = $this->opponentColumns( 'game', [], 'VCT JO14-1 - Hedel JO14-1' );

        $this->assertSame( 'Hedel JO14-1', $cols['opponent'] );
        $this->assertSame( 'home', $cols['home_away'] );
    }

    public function test_a_tournament_never_gets_a_single_opponent(): void {
        $this->assertSame( [], $this->opponentColumns( 'tournament', [ 'match_opponent' => 'Hedel JO14-1' ], 'Toernooi' ) );
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,string>
     */
    private function opponentColumns( string $type, array $event, string $title ): array {
        $m = new \ReflectionMethod( SpondSync::class, 'matchOpponentColumns' );
        $m->setAccessible( true );
        return $m->invoke( null, $type, $event, $title, 'VCT JO14-1' );
    }
}
