<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Activities\Domain\OpponentFromTitle;

/**
 * #3860 — the opponent parser, and above all the answer it is allowed to
 * give when a title says nothing.
 *
 * `tt_activities.opponent` is read by eight surfaces, so a guess written
 * into it is a wrong club name printed on the monthly report, the live
 * scoreboard and the team sheet at once. The parser therefore has to be
 * able to say "no idea", and every caller has to be able to act on that
 * as cheaply as on a confident read. Half of what is asserted here is
 * what the parser must NOT claim.
 */
final class OpponentFromTitleTest extends WP_UnitTestCase {

    private const TEAM = 'Hedel JO12-1';

    public function test_two_sides_with_our_team_first_is_a_home_match(): void {
        $derived = OpponentFromTitle::parse( 'Hedel JO12-1 - Ajax JO12-1', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'Ajax JO12-1', $derived['opponent'] );
        $this->assertSame( 'home', $derived['home_away'] );
        $this->assertSame( 'high', $derived['confidence'] );
    }

    public function test_two_sides_with_our_team_second_is_an_away_match(): void {
        $derived = OpponentFromTitle::parse( 'Ajax JO12-1 - Hedel JO12-1', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'Ajax JO12-1', $derived['opponent'] );
        $this->assertSame( 'away', $derived['home_away'] );
        $this->assertSame( 'high', $derived['confidence'] );
    }

    /** An en dash, a "vs", a "tegen" — the same title in three hands. */
    public function test_the_common_separators_all_read(): void {
        foreach ( [
            'Hedel JO12-1 – Ajax JO12-1',
            'Hedel JO12-1 vs Ajax JO12-1',
            'Hedel JO12-1 tegen Ajax JO12-1',
        ] as $title ) {
            $derived = OpponentFromTitle::parse( $title, self::TEAM );
            $this->assertNotNull( $derived, "{$title} was not read at all" );
            $this->assertSame( 'Ajax JO12-1', $derived['opponent'], $title );
        }
    }

    /** An explicit venue in the title beats the name order. */
    public function test_a_bracketed_venue_wins_over_the_side_order(): void {
        $derived = OpponentFromTitle::parse( 'Hedel JO12-1 - RKSV Driel (uit)', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'RKSV Driel', $derived['opponent'], 'the venue note leaked into the club name' );
        $this->assertSame( 'away', $derived['home_away'] );
    }

    public function test_a_lead_keyword_names_the_opponent_without_naming_us(): void {
        $derived = OpponentFromTitle::parse( 'Wedstrijd tegen DVVC', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'DVVC', $derived['opponent'], 'the word "wedstrijd" is not part of the club' );
        $this->assertSame( '', $derived['home_away'], 'nothing in the title says where it was played' );
        $this->assertSame( 'low', $derived['confidence'] );
    }

    public function test_a_venue_word_before_the_keyword_is_read_as_the_venue(): void {
        $derived = OpponentFromTitle::parse( 'uit tegen DVVC', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'DVVC', $derived['opponent'] );
        $this->assertSame( 'away', $derived['home_away'] );
    }

    /**
     * Two clubs, neither of them us — a fixture somebody typed from the
     * league list. There is a signal, so it is worth proposing, but which
     * side we are is a guess and home/away would be a guess on top of one.
     */
    public function test_two_sides_that_are_neither_of_us_are_low_confidence_with_no_venue(): void {
        $derived = OpponentFromTitle::parse( 'GVV63 JO12-2 vs Nivo Sparta JO12-1', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'low', $derived['confidence'] );
        $this->assertSame( '', $derived['home_away'] );
    }

    /** The whole point: a title with no signal returns nothing. */
    public function test_a_title_with_no_signal_returns_null(): void {
        foreach ( [
            'Training',
            'Zaterdag 14:00',
            'JO12-1',
            '',
            '   ',
            'Hedel JO12-1 - ',
            'Wedstrijd',
        ] as $title ) {
            $this->assertNull(
                OpponentFromTitle::parse( $title, self::TEAM ),
                "\"{$title}\" produced a guess where it should have produced nothing"
            );
        }
    }

    /** Without a team name there is no way to tell which side we are. */
    public function test_without_a_team_name_a_two_sided_title_is_never_confident(): void {
        $derived = OpponentFromTitle::parse( 'Hedel JO12-1 - Ajax JO12-1', '' );

        $this->assertNotNull( $derived );
        $this->assertSame( 'low', $derived['confidence'] );
        $this->assertSame( '', $derived['home_away'] );
    }

    /** A club whose name merely starts with a venue word is not a venue. */
    public function test_a_club_named_like_a_venue_word_is_still_a_club(): void {
        $derived = OpponentFromTitle::parse( 'Hedel JO12-1 - Uitgeest', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'Uitgeest', $derived['opponent'] );
        $this->assertSame( 'home', $derived['home_away'] );
    }

    /** A competition prefix on our own side does not stop us recognising it. */
    public function test_a_competition_prefix_does_not_break_the_team_match(): void {
        $derived = OpponentFromTitle::parse( 'Beker: Hedel JO12-1 - Sparta', self::TEAM );

        $this->assertNotNull( $derived );
        $this->assertSame( 'Sparta', $derived['opponent'] );
        $this->assertSame( 'high', $derived['confidence'] );
    }
}
