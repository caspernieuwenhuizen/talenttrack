<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\KpiSnapshotXlsxExporter;
use TT\Modules\Export\ExportValueFormatter;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * #3414 — the KPI snapshot carries the potential band.
 *
 * The band is the product's only recorded answer to *where is this player
 * going*, and the snapshot is the one artefact an academy reviews about
 * every player at once. It was absent from all twenty exporters (#3385).
 *
 * Both directions are asserted throughout: a player with a band shows it,
 * and a player without one shows an empty cell rather than a default. A
 * guessed default would read as a judgement nobody made, which is worse
 * than a gap, and only an assertion on the blank catches it.
 */
final class KpiSnapshotPotentialTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$this->p}tt_player_potential" );
        $wpdb->query( "DELETE FROM {$this->p}tt_players" );
    }

    public function test_the_snapshot_has_a_potential_sheet(): void {
        $payload = $this->collect();

        $this->assertArrayHasKey( 'sheets', $payload );
        $names = array_keys( (array) $payload['sheets'] );
        $this->assertCount( 2, $names, 'The KPI sheet keeps its place; potential is added beside it.' );
    }

    public function test_a_player_with_a_band_carries_it_translated(): void {
        $player = $this->player( 'Jelle', 'de Vries' );
        $this->band( $player, PotentialBand::FIRST_TEAM, '2026-03-01 10:00:00' );

        $row = $this->potentialRowFor( 'Jelle de Vries' );

        $this->assertNotNull( $row );
        $this->assertNotSame( '', $row[2], 'The band cell is populated.' );
        // Through the lookup, so an academy that renamed its bands sees its
        // own wording. Asserted against the formatter rather than a literal:
        // the label is editable data, and pinning today's English string
        // would make this test fail on a rename it should not care about.
        $this->assertSame(
            ExportValueFormatter::potentialBand( PotentialBand::FIRST_TEAM ),
            $row[2]
        );
        $this->assertSame( '2026-03-01', $row[3] );
    }

    public function test_a_player_without_a_band_gets_an_empty_cell(): void {
        $this->player( 'Sanne', 'Bakker' );

        $row = $this->potentialRowFor( 'Sanne Bakker' );

        $this->assertNotNull( $row, 'The player is listed even with nothing recorded.' );
        $this->assertSame( '', $row[2] );
        $this->assertSame( '', $row[3] );
    }

    /** The band shown is the current one — the same row the status dot uses. */
    public function test_the_most_recent_band_wins(): void {
        $player = $this->player( 'Tim', 'Jansen' );
        $this->band( $player, PotentialBand::RECREATIONAL, '2025-09-01 10:00:00' );
        $this->band( $player, PotentialBand::SEMI_PRO, '2026-02-01 10:00:00' );

        $row    = $this->potentialRowFor( 'Tim Jansen' );
        $latest = ( new PlayerPotentialRepository() )->latestFor( $player );

        $this->assertNotNull( $row );
        $this->assertNotNull( $latest );
        $this->assertSame( PotentialBand::SEMI_PRO, (string) $latest->potential_band );
        $this->assertSame( '2026-02-01', $row[3] );
    }

    /** A released player's band is history, not a snapshot of the academy. */
    public function test_an_inactive_player_is_not_listed(): void {
        $player = $this->player( 'Oud', 'Speler', 'released' );
        $this->band( $player, PotentialBand::TOP_AMATEUR, '2026-01-01 10:00:00' );

        $this->assertNull( $this->potentialRowFor( 'Oud Speler' ) );
    }

    /** The headline metrics count both sides, so a gap is visible at a glance. */
    public function test_the_kpi_sheet_counts_recorded_and_unrecorded_bands(): void {
        $with = $this->player( 'Met', 'Band' );
        $this->band( $with, PotentialBand::SEMI_PRO, '2026-03-01 10:00:00' );
        $this->player( 'Zonder', 'Band' );
        $this->player( 'Ook Zonder', 'Band' );

        $metrics = $this->kpiMetrics();

        $this->assertSame( 1, $metrics[ __( 'Potential — band recorded', 'talenttrack' ) ] );
        $this->assertSame( 2, $metrics[ __( 'Potential — no band recorded', 'talenttrack' ) ] );
    }

    // -- helpers ---------------------------------------------------------

    /** @return array<string,mixed> */
    private function collect(): array {
        return ( new KpiSnapshotXlsxExporter() )->collect( new ExportRequest(
            'kpi_snapshot',
            'xlsx',
            $this->club,
            0,
            null,
            [ 'date_from' => '2026-01-01', 'date_to' => '2026-12-31' ]
        ) );
    }

    /** @return list<string>|null */
    private function potentialRowFor( string $name ): ?array {
        $payload = $this->collect();
        $sheets  = (array) ( $payload['sheets'] ?? [] );
        $sheet   = $sheets[ KpiSnapshotXlsxExporter::potentialSheetName() ] ?? null;
        if ( ! is_array( $sheet ) ) return null;

        foreach ( (array) $sheet[1] as $row ) {
            if ( (string) $row[0] === $name ) return $row;
        }
        return null;
    }

    /** Metric label => value, from the first sheet. @return array<string,mixed> */
    private function kpiMetrics(): array {
        $payload = $this->collect();
        $sheets  = (array) ( $payload['sheets'] ?? [] );
        $sheet   = (array) ( $sheets[ __( 'KPI snapshot', 'talenttrack' ) ] ?? [] );

        $out = [];
        foreach ( (array) ( $sheet[1] ?? [] ) as $row ) {
            $out[ (string) $row[0] ] = $row[1];
        }
        return $out;
    }

    private function player( string $first, string $last, string $status = 'active' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'first_name'  => $first,
            'last_name'   => $last,
            'status'      => $status,
            'date_joined' => '2025-08-01',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function band( int $player_id, string $band, string $set_at ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_potential", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'set_at'         => $set_at,
            'set_by'         => 0,
            'potential_band' => $band,
        ] );
    }
}
