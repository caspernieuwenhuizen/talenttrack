<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Modules\Tournaments\Wizard\SquadStep;

/**
 * #4170 — every tournament position code reads as a name.
 *
 * The tournament squad step keeps its own code set (GK / CB / LB / RB /
 * DM / CM / AM / LW / RW / ST). DM and AM had no long form in the shared
 * resolver, so the Tournaments tab and the tournament review printed the
 * bare codes. They now map onto the seeded vocabulary's names for the same
 * roles; the codes themselves stay the matching key.
 */
final class TournamentPositionLabelTest extends WP_UnitTestCase {

    public function test_every_tournament_code_has_a_long_form(): void {
        foreach ( array_keys( SquadStep::positionCodes() ) as $code ) {
            $this->assertNotSame(
                $code,
                LabelTranslator::positionLongForm( $code ),
                "tournament position code {$code} has no long form"
            );
            $this->assertNotSame( $code, LabelTranslator::positionLabel( $code ) );
        }
    }

    public function test_dm_and_am_share_the_seeded_names_for_the_same_roles(): void {
        $this->assertSame( 'Defensive midfielder', LabelTranslator::positionLabel( 'DM' ) );
        $this->assertSame( 'Attacking midfielder', LabelTranslator::positionLabel( 'AM' ) );
        $this->assertSame( LabelTranslator::positionLongForm( 'CDM' ), LabelTranslator::positionLongForm( 'DM' ) );
        $this->assertSame( LabelTranslator::positionLongForm( 'CAM' ), LabelTranslator::positionLongForm( 'AM' ) );
    }

    public function test_the_codes_stay_the_matching_key(): void {
        $this->assertSame(
            [ 'GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST' ],
            array_keys( SquadStep::positionCodes() )
        );
    }
}
