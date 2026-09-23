<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\TrialCaseGenerator;
use TT\Modules\Trials\Repositories\TrialCasesRepository;

/**
 * #4022 — a seeded extension moves the case it extends.
 *
 * The generator wrote the extension row and stopped, so a demo case
 * contradicted its own history: `end_date` still on the day the extension
 * had moved past, `extension_count` 0, status `open`, and the decision
 * deadline counting down to a date nobody was working to. Both production
 * paths — the case screen's extend action and `POST
 * trial-cases/{id}/extend` — record the row and update the case as one
 * step.
 */
final class DemoTrialExtensionEndDateTest extends WP_UnitTestCase {

    /** @var object[] */
    private array $roster = [];

    /** @var object[] */
    private array $teams = [];

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

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $users = [ 'hjo' => $admin ];
        foreach ( [ 'hjo2', 'scout', 'staff' ] as $slot ) {
            $users[ $slot ] = self::factory()->user->create( [ 'role' => 'administrator' ] );
        }
        wp_set_current_user( $admin );

        // Only an open case gets an extension, and only on a coin flip, so
        // one run can legitimately produce none. Runs are repeated until
        // there is something to assert on rather than seeding `mt_rand`,
        // whose sequence is not ours to depend on.
        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            ( new TrialCaseGenerator(
                new DemoBatchRegistry( 'test-4022-' . $attempt ),
                $this->roster,
                $this->teams,
                $users,
                'en_US'
            ) )->generate();
            if ( $this->extendedCaseIds() !== [] ) break;
        }
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_generator_seeded_at_least_one_extension(): void {
        $this->assertNotEmpty( $this->extendedCaseIds(), 'no demo trial case was extended' );
    }

    /**
     * The invariant: the case agrees with its extension history.
     *
     * Asserted per case rather than in aggregate, because the failure being
     * fixed showed up on one case at a time — a head of development opening
     * a trialist and reading a deadline that had been moved.
     */
    public function test_every_extended_case_carries_the_extended_end_date(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $cases = $this->extendedCaseIds();
        $this->assertNotEmpty( $cases );

        foreach ( $cases as $case_id ) {
            $case = $wpdb->get_row( $wpdb->prepare(
                "SELECT end_date, extension_count, status, decision FROM {$p}tt_trial_cases WHERE id = %d",
                $case_id
            ), ARRAY_A );
            $this->assertIsArray( $case );

            $latest = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(new_end_date) FROM {$p}tt_trial_extensions WHERE case_id = %d",
                $case_id
            ) );
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_trial_extensions WHERE case_id = %d",
                $case_id
            ) );

            $this->assertSame( $latest, (string) $case['end_date'], "case {$case_id} kept its pre-extension end date" );
            $this->assertSame( $count, (int) $case['extension_count'], "case {$case_id} does not count its extensions" );

            if ( (string) ( $case['decision'] ?? '' ) === '' ) {
                $this->assertSame(
                    TrialCasesRepository::STATUS_EXTENDED,
                    (string) $case['status'],
                    "case {$case_id} was extended but does not say so"
                );
            }
        }
    }

    /** @return int[] */
    private function extendedCaseIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT DISTINCT case_id FROM {$wpdb->prefix}tt_trial_extensions"
        );
        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }
}
