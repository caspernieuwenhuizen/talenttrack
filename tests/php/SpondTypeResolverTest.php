<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Spond\SpondTypeResolver;

/**
 * #3912 — the `game` keyword list carries `kamp` (Norwegian for *match*),
 * which as a bare substring also fires on the Dutch suffix `-kamp`, so
 * "Trainingskamp" imported as a fixture: match roster, minutes grid, a slot
 * in the team's record, none of it undoable by renaming the activity
 * afterwards. These tests pin both sides — the compound words classify as
 * trainings, the Norwegian and Dutch match vocabulary still classifies as
 * games — so a future needle added to `game` cannot reintroduce it.
 */
final class SpondTypeResolverTest extends WP_UnitTestCase {

    /** @return array<string,array{0:string,1:string}> */
    public function compoundWordProvider(): array {
        return [
            'training camp'          => [ 'Trainingskamp', 'training' ],
            'training camp, day two' => [ 'Trainingskamp dag 2', 'training' ],
            'football camp'          => [ 'Voetbalkamp', 'training' ],
            'summer camp'            => [ 'Zomerkamp', 'training' ],
        ];
    }

    /**
     * @dataProvider compoundWordProvider
     */
    public function test_a_compound_ending_in_kamp_is_not_a_game( string $summary, string $expected ): void {
        $this->assertSame(
            $expected,
            SpondTypeResolver::classify( $summary ),
            "\"{$summary}\" must not classify as a fixture"
        );
    }

    public function test_kamp_as_a_whole_word_still_classifies_as_a_game(): void {
        $this->assertSame( 'game', SpondTypeResolver::classify( 'Kamp mot Rosenborg' ) );
    }

    public function test_dutch_match_vocabulary_still_classifies_as_a_game(): void {
        $this->assertSame( 'game', SpondTypeResolver::classify( 'Wedstrijd tegen Ajax' ) );
    }

    public function test_a_friendly_keeps_classifying_as_a_game(): void {
        $this->assertSame(
            'game',
            SpondTypeResolver::classify( 'Trainingswedstrijd tegen PSV' ),
            'a trainingswedstrijd is a friendly, not a training'
        );
        $this->assertSame( 'game', SpondTypeResolver::classify( 'Thuiswedstrijd tegen Feyenoord' ) );
    }

    public function test_uit_only_counts_as_a_whole_word(): void {
        $this->assertSame( 'game', SpondTypeResolver::classify( 'Uit tegen Willem II' ) );
        $this->assertSame(
            'meeting',
            SpondTypeResolver::classify( 'Vooruitblik overleg' ),
            '"vooruit" is not the away marker "uit"'
        );
    }

    public function test_the_other_types_are_untouched(): void {
        $this->assertSame( 'tournament', SpondTypeResolver::classify( 'Paastoernooi' ) );
        $this->assertSame( 'meeting', SpondTypeResolver::classify( 'Oudergesprek' ) );
        $this->assertSame( 'training', SpondTypeResolver::classify( 'Training woensdag' ) );
        $this->assertSame(
            'training',
            SpondTypeResolver::classify( 'Teamdag' ),
            'an unrecognised title falls back to training'
        );
    }
}
