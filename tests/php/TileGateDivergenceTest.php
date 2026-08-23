<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Tiles\TileRegistry;
use TT\Modules\Authorization\LegacyCapMapper;

/**
 * #2008 — a tile a persona can see but cannot open.
 *
 * The dispatcher admits a user on `read` of the tile's entity
 * (`matrixDispatchAllows()`); the destination view gates entry on the tile's
 * declared `cap`, which may bridge to a stronger activity. Where a persona is
 * seeded read-but-not-write, the tile renders and the view refuses — a 403 the
 * user cannot act on and the operator cannot see coming.
 *
 * It has recurred: #1143, #1105/#1106, #1175, and #2005 which prompted this.
 * Auditing by hand produces a list that is stale the next time a tile is
 * registered, so the audit lives here instead and runs on every push.
 *
 * ## The baseline, and why it is not zero
 *
 * Four divergences exist today. They are recorded in KNOWN so this suite pins
 * the current state rather than failing red on arrival — the point is that the
 * *next* one fails, not that these four block every PR until someone redesigns
 * four surfaces.
 *
 * Fixing one means deciding, per surface, whether entry should drop to `read`
 * with the mutating controls gated on the finer activity (the #2005 shape), or
 * whether the surface is management-only and the persona should not be seeded
 * read on its entity at all. That is a product decision per surface, not a
 * mechanical edit, which is exactly why they are listed rather than "fixed"
 * here. Remove an entry from KNOWN as it is resolved; the assertion then holds
 * it resolved.
 */
final class TileGateDivergenceTest extends WP_UnitTestCase {

    /**
     * Divergences that exist today, as `view_slug => [personas]`.
     * Every one is a persona that sees the tile and is refused by the view.
     */
    private const KNOWN = [
        'workflow-config'     => [ 'head_of_development' ],
        'pdp-planning'        => [ 'team_manager' ],
        'holidays'            => [ 'assistant_coach', 'head_coach' ],
        'invitations-config'  => [ 'head_of_development' ],
    ];

    private const RANK = [ 'read' => 0, 'change' => 1, 'create_delete' => 2 ];

    /** @return array<string, array<string, array<string, bool>>> persona => entity => activity => true */
    private function seedGrants(): array {
        $seed = require TT_PLUGIN_DIR . 'config/authorization_seed.php';
        $out  = [];
        foreach ( (array) $seed as $row ) {
            $persona  = (string) ( $row['persona'] ?? '' );
            $entity   = (string) ( $row['entity'] ?? '' );
            $activity = (string) ( $row['activity'] ?? '' );
            if ( $persona === '' || $entity === '' ) continue;
            $out[ $persona ][ $entity ][ $activity ] = true;
        }
        return $out;
    }

    /**
     * @return array<string, list<string>> view_slug => personas that would 403
     */
    private function divergences(): array {
        $grants = $this->seedGrants();
        $found  = [];

        foreach ( TileRegistry::allRegistered() as $tile ) {
            $slug   = (string) ( $tile['view_slug'] ?? '' );
            $entity = (string) ( $tile['entity'] ?? '' );
            $cap    = (string) ( $tile['cap'] ?? '' );
            if ( $slug === '' || $entity === '' || $cap === '' ) continue;

            $tuple = LegacyCapMapper::tupleFor( $cap );
            if ( $tuple === null ) continue;

            [ $cap_entity, $cap_activity ] = $tuple;
            if ( ( self::RANK[ $cap_activity ] ?? 0 ) === 0 ) continue;

            $affected = [];
            foreach ( $grants as $persona => $entities ) {
                $sees_tile     = isset( $entities[ $entity ]['read'] );
                $cannot_enter  = ! isset( $entities[ $cap_entity ][ $cap_activity ] );
                if ( $sees_tile && $cannot_enter ) $affected[] = $persona;
            }

            if ( $affected ) {
                sort( $affected );
                $found[ $slug ] = $affected;
            }
        }

        ksort( $found );
        return $found;
    }

    public function test_no_new_tile_shows_a_persona_a_surface_it_cannot_open(): void {
        $found = $this->divergences();

        $expected = self::KNOWN;
        foreach ( $expected as $slug => $personas ) {
            sort( $personas );
            $expected[ $slug ] = $personas;
        }
        ksort( $expected );

        $new = array_diff_key( $found, $expected );
        $this->assertSame(
            [],
            $new,
            "new tile-gate divergence — these personas see a tile they cannot open:\n"
            . $this->describe( $new )
            . "\nEither drop the tile's entry to `read` and gate the mutating controls on the finer "
            . "activity (#2005's shape), or stop seeding the persona read on the tile's entity."
        );
    }

    /**
     * The other direction: a divergence that has been fixed must not linger in
     * KNOWN, or the list rots into a place where real ones can hide.
     */
    public function test_the_known_list_holds_no_resolved_entries(): void {
        $found   = $this->divergences();
        $stale   = array_diff_key( self::KNOWN, $found );

        $this->assertSame(
            [],
            $stale,
            'these are no longer divergent and should be removed from KNOWN: '
            . implode( ', ', array_keys( $stale ) )
        );
    }

    /** @param array<string, list<string>> $set */
    private function describe( array $set ): string {
        $lines = [];
        foreach ( $set as $slug => $personas ) {
            $lines[] = "  {$slug}: " . implode( ', ', $personas );
        }
        return implode( "\n", $lines );
    }
}
