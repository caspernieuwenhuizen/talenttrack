<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\TrialCaseGenerator;

/**
 * #4034 — the panel on a seeded trial case says three different things.
 *
 * Every staff input carried the same sentence, word for word, across
 * panellists and across cases, while the ratings differed. On a screen whose
 * whole job is to show three independent views of a child before a decision
 * about them, that reads as a bulk write that overwrote three individual
 * assessments — a data-corruption alarm on a product that had written
 * nothing of the kind.
 */
final class DemoTrialInputNotesTest extends WP_UnitTestCase {

    /** @var object[] */
    private array $roster = [];

    /** @var object[] */
    private array $teams = [];

    /** @var array<string,int> */
    private array $users = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'demo-' . uniqid(), 'name' => 'Standard' ] );

        foreach ( [ 'U11', 'U13' ] as $age ) {
            $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo ' . $age, 'age_group' => $age ] );
            $team_id       = (int) $wpdb->insert_id;
            $this->teams[] = (object) [ 'id' => $team_id, 'age_group' => $age ];

            for ( $i = 1; $i <= 6; $i++ ) {
                $wpdb->insert( "{$p}tt_players", [
                    'club_id' => $club, 'team_id' => $team_id, 'first_name' => 'Vaste', 'last_name' => "Speler {$i}",
                    'status' => 'active', 'jersey_number' => $i, 'date_joined' => '2024-01-03',
                ] );
                $this->roster[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $team_id, 'date_joined' => '2024-01-03' ];
            }
        }

        // A panel of four, so cases carry two or three assessments each and
        // there is something for two panellists to collide on.
        $admin       = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->users = [ 'hjo' => $admin ];
        foreach ( [ 'hjo2', 'scout', 'staff' ] as $slot ) {
            $this->users[ $slot ] = self::factory()->user->create( [ 'role' => 'administrator' ] );
        }
        wp_set_current_user( $admin );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_no_english_case_has_two_panellists_saying_the_same_thing(): void {
        $this->generate( 'en_US' );
        $this->assertEveryCasesNotesAreDistinct();
    }

    /**
     * The Dutch pool is a pool too.
     *
     * Worth its own run rather than trusting the constant: the copy table
     * holds one entry per language, and a language left as a single sentence
     * would pass the English assertion and ship the bug to the install the
     * pilot actually runs on, which is Dutch.
     */
    public function test_no_dutch_case_has_two_panellists_saying_the_same_thing(): void {
        $this->generate( 'nl_NL' );
        $this->assertEveryCasesNotesAreDistinct();
    }

    private function generate( string $language ): void {
        ( new TrialCaseGenerator(
            new DemoBatchRegistry( 'test-4034-' . $language ),
            $this->roster,
            $this->teams,
            $this->users,
            $language
        ) )->generate();
    }

    /**
     * Every case with more than one assessment has as many distinct notes as
     * it has assessments, and none of them is empty.
     */
    private function assertEveryCasesNotesAreDistinct(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_trial_case_staff_inputs';

        $rows = $wpdb->get_results(
            "SELECT case_id, COUNT(*) AS inputs, COUNT(DISTINCT free_text_notes) AS notes
               FROM {$table}
              GROUP BY case_id",
            ARRAY_A
        );
        $rows = is_array( $rows ) ? $rows : [];
        $this->assertNotEmpty( $rows, 'the generator seeded no staff inputs' );

        $with_a_panel = 0;
        foreach ( $rows as $row ) {
            $data   = (array) $row;
            $inputs = (int) ( $data['inputs'] ?? 0 );
            if ( $inputs < 2 ) continue;
            $with_a_panel++;
            $this->assertSame(
                $inputs,
                (int) ( $data['notes'] ?? 0 ),
                'two assessments on case ' . (string) ( $data['case_id'] ?? 0 ) . ' carry the same note'
            );
        }
        $this->assertGreaterThan( 0, $with_a_panel, 'no seeded case carried more than one assessment' );

        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE free_text_notes IS NULL OR free_text_notes = ''" ),
            'a seeded assessment carries no note at all'
        );
    }
}
