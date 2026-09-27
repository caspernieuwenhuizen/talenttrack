<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\PotentialStaleAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\I18n\TranslationsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;
use TT\Modules\Players\Services\PotentialRecorder;
use TT\Modules\Players\Services\PotentialTrajectory;

/**
 * #3981 — potential bands place a player against their age-group pathway.
 *
 * Pinned here:
 *
 *   - the five pathway keys, best first, with the shipped labels;
 *   - a band can be recorded at any age, and the stale alert covers every
 *     age (the #3265 floor went with the adult-ceiling bands);
 *   - a retired key sent by an old client is stored as its successor;
 *   - the label is the lookup's, so a rename shows everywhere while the
 *     rank stays put;
 *   - the migration maps every stored entry rank for rank, idempotently,
 *     and never overwrites a label an academy chose.
 */
final class PotentialPathwayBandsTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        FeatureRegistry::setEnabled( 'potential_rating', true );

        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'   => $this->club,
            'name'      => 'Pathway Test',
            'age_group' => 'U9',
        ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        FeatureRegistry::setEnabled( 'potential_rating', true );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the vocabulary ─────────────────────────────────────────────────

    public function test_the_bands_are_the_pathway_five_best_first(): void {
        $this->assertSame(
            [ 'exceptional', 'ahead', 'on_track', 'needs_time', 'below_level' ],
            PotentialBand::ALL
        );
        $this->assertSame( PotentialBand::ALL, array_keys( PotentialTrajectory::labels() ) );
    }

    public function test_each_retired_key_maps_to_the_band_of_the_same_rank(): void {
        $old = [ 'first_team', 'professional_elsewhere', 'semi_pro', 'top_amateur', 'recreational' ];
        foreach ( $old as $rank => $key ) {
            $this->assertSame( PotentialBand::ALL[ $rank ], PotentialBand::normalise( $key ) );
        }
        $this->assertSame( 'on_track', PotentialBand::normalise( 'on_track' ) );
        $this->assertSame( 'nonsense', PotentialBand::normalise( 'nonsense' ) );
    }

    // ── no age floor ───────────────────────────────────────────────────

    public function test_a_band_is_recorded_for_a_seven_year_old(): void {
        $outcome = ( new PotentialRecorder() )->record( $this->playerAged( 7 ), PotentialBand::ON_TRACK );

        $this->assertSame( PotentialRecorder::RECORDED, $outcome['result'] );
    }

    public function test_the_stale_alert_covers_a_young_player(): void {
        global $wpdb;
        $young = $this->playerAged( 7 );
        $wpdb->update(
            "{$this->p}tt_players",
            [ 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 years' ) ) ],
            [ 'id' => $young ]
        );

        $this->assertContains( $young, $this->alertSubjects() );
    }

    // ── the deprecation window ─────────────────────────────────────────

    public function test_a_retired_key_is_stored_as_its_successor(): void {
        $player  = $this->playerAged( 15 );
        $outcome = ( new PotentialRecorder() )->record( $player, 'first_team' );

        $this->assertSame( PotentialRecorder::RECORDED, $outcome['result'] );
        $this->assertSame( PotentialBand::EXCEPTIONAL, $outcome['band'] );

        $latest = ( new PlayerPotentialRepository() )->latestFor( $player );
        $this->assertNotNull( $latest );
        $this->assertSame( PotentialBand::EXCEPTIONAL, (string) $latest->potential_band );
    }

    // ── one label source ───────────────────────────────────────────────

    public function test_renaming_a_band_in_the_lookup_changes_its_label_not_its_rank(): void {
        global $wpdb;
        $row_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_lookups WHERE lookup_type = 'potential_band' AND name = %s AND club_id = %d",
            PotentialBand::AHEAD,
            $this->club
        ) );
        $this->assertGreaterThan( 0, $row_id, 'The migration seeded the pathway rows.' );

        ( new TranslationsRepository() )->upsert( 'lookup', $row_id, 'name', 'en_US', 'Front runner', 1 );

        $this->assertSame( 'Front runner', PotentialTrajectory::labelFor( PotentialBand::AHEAD ) );
        $this->assertSame( 1, PotentialTrajectory::rank( PotentialBand::AHEAD ) );
    }

    // ── the migration ──────────────────────────────────────────────────

    public function test_the_migration_maps_entries_by_rank_and_is_idempotent(): void {
        global $wpdb;
        $player = $this->playerAged( 14 );

        $old = [ 'recreational', 'top_amateur', 'semi_pro', 'professional_elsewhere', 'first_team' ];
        foreach ( $old as $i => $key ) {
            $wpdb->insert( "{$this->p}tt_player_potential", [
                'club_id'        => $this->club,
                'player_id'      => $player,
                'set_at'         => gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( 50 - $i ) . ' days' ) ),
                'set_by'         => 1,
                'potential_band' => $key,
                'notes'          => 'note ' . $i,
            ] );
        }
        // A retired lookup row, as an install that never ran this has.
        $wpdb->insert( "{$this->p}tt_lookups", [
            'club_id'     => $this->club,
            'lookup_type' => 'potential_band',
            'name'        => 'semi_pro',
            'description' => 'Semi-professional',
            'meta'        => '{"is_locked":1}',
            'sort_order'  => 30,
        ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0292_potential_bands_pathway.php';
        $migration->up();

        $bands = $wpdb->get_col( $wpdb->prepare(
            "SELECT potential_band FROM {$this->p}tt_player_potential WHERE player_id = %d ORDER BY set_at ASC",
            $player
        ) );
        $this->assertSame( [ 'below_level', 'needs_time', 'on_track', 'ahead', 'exceptional' ], $bands );

        $notes = $wpdb->get_col( $wpdb->prepare(
            "SELECT notes FROM {$this->p}tt_player_potential WHERE player_id = %d ORDER BY set_at ASC",
            $player
        ) );
        $this->assertSame( [ 'note 0', 'note 1', 'note 2', 'note 3', 'note 4' ], $notes, 'Notes are untouched.' );

        $retired = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->p}tt_lookups WHERE lookup_type = 'potential_band' AND name = 'semi_pro'"
        );
        $this->assertSame( 0, $retired, 'The retired lookup row is gone.' );

        // An academy renames a band; a re-run must neither rewrite entries
        // nor overwrite the label.
        $row_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_lookups WHERE lookup_type = 'potential_band' AND name = %s AND club_id = %d",
            PotentialBand::ON_TRACK,
            $this->club
        ) );
        ( new TranslationsRepository() )->upsert( 'lookup', $row_id, 'name', 'nl_NL', 'Op schema', 1 );

        $migration->up();

        $this->assertSame( $bands, $wpdb->get_col( $wpdb->prepare(
            "SELECT potential_band FROM {$this->p}tt_player_potential WHERE player_id = %d ORDER BY set_at ASC",
            $player
        ) ) );
        $this->assertSame( 'Op schema', (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT value FROM {$this->p}tt_translations
              WHERE entity_type = 'lookup' AND entity_id = %d AND field = 'name' AND locale = 'nl_NL'",
            $row_id
        ) ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function playerAged( int $years ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team_id,
            'first_name'    => 'Age',
            'last_name'     => (string) $years,
            'status'        => 'active',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( "-{$years} years -1 month" ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return list<int> player ids the alert's own query returns */
    private function alertSubjects(): array {
        $alert = new PotentialStaleAlert();

        $rows = new \ReflectionMethod( PotentialStaleAlert::class, 'rows' );
        $rows->setAccessible( true );

        $out = [];
        foreach ( (array) $rows->invoke( $alert, new AlertContext() ) as $row ) {
            $out[] = (int) ( $row->player_id ?? 0 );
        }
        return $out;
    }
}
