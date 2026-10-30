<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\MatchExecution\Services\PitchLayoutService;
use TT\Shared\Util\PlayerShortName;

/**
 * #4372 — two players in one squad must never share a short name. The
 * resolver escalates within the set it is given: first name, then the
 * surname initial, then more surname letters for the ones still
 * colliding, then the shirt number when the surnames are identical. The
 * live pitch resolves over the whole pitch with the same resolver.
 */
final class PlayerShortNameCollisionTest extends WP_UnitTestCase {

    /**
     * @param array<int, array{0:string,1:string,2?:int}> $players id => [first, last, jersey]
     * @return array<int, string>
     */
    private function resolve( array $players ): array {
        $rows = [];
        foreach ( $players as $id => $p ) {
            $rows[] = [
                'id'            => $id,
                'first_name'    => $p[0],
                'last_name'     => $p[1],
                'jersey_number' => $p[2] ?? null,
            ];
        }
        return PlayerShortName::resolve( $rows );
    }

    public function test_a_unique_first_name_stays_alone(): void {
        $names = $this->resolve( [ 1 => [ 'Senna', 'Bakker' ], 2 => [ 'Dex', 'Vries' ] ] );
        $this->assertSame( [ 1 => 'Senna', 2 => 'Dex' ], $names );
    }

    public function test_a_shared_first_name_with_different_initials_takes_the_initial(): void {
        $names = $this->resolve( [ 1 => [ 'Dani', 'Dekker' ], 2 => [ 'Dani', 'Kok' ], 3 => [ 'Senna', 'Bakker' ] ] );
        $this->assertSame( 'Dani D', $names[1] );
        $this->assertSame( 'Dani K', $names[2] );
        $this->assertSame( 'Senna', $names[3] );
    }

    public function test_two_dex_v_players_get_more_surname_letters(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'Vries' ], 2 => [ 'Dex', 'Vaart' ] ] );
        $this->assertSame( 'Dex Vr', $names[1] );
        $this->assertSame( 'Dex Va', $names[2] );
    }

    public function test_only_the_colliding_players_get_a_longer_name(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'Vries' ], 2 => [ 'Dex', 'Vaart' ], 3 => [ 'Dex', 'Bos' ] ] );
        $this->assertSame( [ 1 => 'Dex Vr', 2 => 'Dex Va', 3 => 'Dex B' ], $names );
    }

    public function test_tobias_k_and_tobias_v_keep_the_initial(): void {
        $names = $this->resolve( [ 1 => [ 'Tobias', 'Kramer' ], 2 => [ 'Tobias', 'Visser' ] ] );
        $this->assertSame( [ 1 => 'Tobias K', 2 => 'Tobias V' ], $names );
    }

    public function test_a_surname_that_starts_another_still_differs(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'Vries' ], 2 => [ 'Dex', 'Vriesman' ] ] );
        $this->assertNotSame( $names[1], $names[2] );
    }

    public function test_identical_full_names_differ_by_shirt_number(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'Visser', 7 ], 2 => [ 'Dex', 'Visser', 14 ] ] );
        $this->assertSame( 'Dex #7', $names[1] );
        $this->assertSame( 'Dex #14', $names[2] );
    }

    /** The reported squad: prefix words are skipped, so the labels stay short. */
    public function test_van_der_laan_and_van_wijk_read_by_the_main_surname(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'Van der Laan', 11 ], 2 => [ 'Dex', 'Van Wijk', 23 ] ] );
        $this->assertSame( [ 1 => 'Dex L', 2 => 'Dex W' ], $names );
    }

    public function test_de_jong_and_de_vries_read_by_the_main_surname(): void {
        $names = $this->resolve( [ 1 => [ 'Sem', 'de Jong' ], 2 => [ 'Sem', 'De Vries' ] ] );
        $this->assertSame( [ 1 => 'Sem J', 2 => 'Sem V' ], $names );
    }

    public function test_main_surnames_sharing_an_initial_take_more_capitalised_letters(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'van Wijk' ], 2 => [ 'Dex', 'Van der Wel' ] ] );
        $this->assertSame( [ 1 => 'Dex Wi', 2 => 'Dex We' ], $names );
    }

    public function test_identical_prefixed_names_differ_by_shirt_number_alone(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'Van Wijk', 23 ], 2 => [ 'Dex', 'Van Wijk', 30 ], 3 => [ 'Dex', 'Van der Laan', 11 ] ] );
        $this->assertSame( [ 1 => 'Dex #23', 2 => 'Dex #30', 3 => 'Dex L' ], $names );
    }

    public function test_a_surname_of_prefix_words_only_keeps_its_last_word(): void {
        $names = $this->resolve( [ 1 => [ 'Dex', 'Van' ], 2 => [ 'Dex', 'Bos' ] ] );
        $this->assertSame( [ 1 => 'Dex V', 2 => 'Dex B' ], $names );
    }

    public function test_every_label_in_a_colliding_squad_is_distinct(): void {
        $names = $this->resolve( [
            1 => [ 'Dex', 'Vries', 4 ],
            2 => [ 'Dex', 'Vaart', 9 ],
            3 => [ 'Dani', 'Dekker', 5 ],
            4 => [ 'Dani', 'Kok', 6 ],
            5 => [ 'Tobias', 'Kramer', 8 ],
            6 => [ 'Tobias', 'Visser', 10 ],
            7 => [ 'Dex', 'Visser', 11 ],
            8 => [ 'Dex', 'Visser', 17 ],
        ] );
        $this->assertCount( 8, array_unique( $names ) );
    }

    public function test_the_live_pitch_resolves_over_the_whole_pitch(): void {
        $slot_to_player = [ 1 => 501, 2 => 502, 3 => 503 ];
        $meta = [
            501 => [ 'name' => 'Dex Vries', 'jersey' => 4, 'first_name' => 'Dex', 'last_name' => 'Vries' ],
            502 => [ 'name' => 'Dex Vaart', 'jersey' => 9, 'first_name' => 'Dex', 'last_name' => 'Vaart' ],
            503 => [ 'name' => 'Daan Portzgen', 'jersey' => 1, 'first_name' => 'Daan', 'last_name' => 'Portzgen' ],
        ];

        $slots = ( new PitchLayoutService() )->positionedXi( 0, $slot_to_player, $meta );

        $by_player = array_column( $slots, 'short_name', 'player_id' );
        $this->assertSame( 'Dex Vr', $by_player[501] );
        $this->assertSame( 'Dex Va', $by_player[502] );
        $this->assertSame( 'Daan', $by_player[503] );
    }
}
