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
 *
 * #3923 — the same family, one field over: summary and description were
 * concatenated and searched with equal weight, so a training whose
 * description mentioned the weekend's fixture imported as a fixture. The
 * title decides now; the description is read only when the title says
 * nothing about the type. Both halves are pinned below, including that
 * #3912's whole-word rules hold in either field.
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

    /**
     * #3923 — the title decides. A coach writing a sentence about the
     * weekend in the description is doing nothing unusual, and it used to
     * import the training as a fixture.
     */
    public function test_a_description_mentioning_a_match_does_not_make_a_training_a_game(): void {
        $this->assertSame(
            'training',
            SpondTypeResolver::classify( 'Training JO14-1', 'laatste training voor de wedstrijd van zaterdag' ),
            'the summary names a training; the description is context, not the type'
        );
        $this->assertSame(
            'training',
            SpondTypeResolver::classify( 'Training JO14-1', 'we spelen zaterdag uit tegen Ajax' )
        );
    }

    /** #3923 — and a title that names a match is not talked out of it. */
    public function test_a_description_mentioning_training_does_not_make_a_game_a_training(): void {
        $this->assertSame(
            'game',
            SpondTypeResolver::classify( 'Wedstrijd tegen Ajax', 'we trainen eerst in' )
        );
    }

    /** #3923 — an uninformative title still lets the description decide. */
    public function test_a_silent_title_falls_back_to_the_description(): void {
        $this->assertSame( 'game', SpondTypeResolver::classify( 'JO14-1', 'wedstrijd tegen Ajax' ) );
        $this->assertSame( 'tournament', SpondTypeResolver::classify( 'JO14-1', 'Paastoernooi in Hedel' ) );
        $this->assertSame( 'meeting', SpondTypeResolver::classify( 'JO14-1', 'oudergesprek na afloop' ) );
        $this->assertSame(
            'training',
            SpondTypeResolver::classify( 'JO14-1', '' ),
            'and with nothing to read anywhere, the existing default is unchanged'
        );
    }

    /** #3923 — #3912's whole-word rules hold in the description too. */
    public function test_the_whole_word_rules_hold_in_the_description(): void {
        $this->assertSame(
            'training',
            SpondTypeResolver::classify( 'JO14-1', 'trainingskamp in Limburg' ),
            '"kamp" as a Dutch suffix is not a fixture, wherever it is written'
        );
        $this->assertSame(
            'game',
            SpondTypeResolver::classify( 'JO14-1', 'kamp mot Rosenborg' ),
            'and as a whole word it still is'
        );
        $this->assertSame(
            'meeting',
            SpondTypeResolver::classify( 'JO14-1', 'vooruitblik overleg' ),
            '"vooruit" is not the away marker "uit"'
        );
        $this->assertSame(
            'game',
            SpondTypeResolver::classify( 'JO14-1', 'uit tegen Willem II' )
        );
    }

    /**
     * #3923 — `game` is scanned before `training`, in both fields. A
     * reorder would reclassify every friendly as a training.
     */
    public function test_a_friendly_in_the_description_is_still_a_game(): void {
        $this->assertSame(
            'game',
            SpondTypeResolver::classify( 'JO14-1', 'trainingswedstrijd tegen PSV' )
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
