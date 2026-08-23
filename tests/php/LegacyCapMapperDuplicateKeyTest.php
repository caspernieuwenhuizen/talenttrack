<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Authorization\LegacyCapMapper;

/**
 * A capability must appear once in the cap→matrix mapping.
 *
 * PHP keeps the last of a duplicated array key and says nothing. That is how
 * `tt_manage_authorization` came to be declared twice — #2654 added a
 * documented `change` mapping, eighteen lines above an existing
 * `create_delete` one, and the older line silently won. The cap then resolved
 * to an activity academy_admin is not seeded, so the matrix editor refused the
 * persona it was built for, and four tests failed with no hint of the cause.
 *
 * The collapsed constant cannot reveal this — by the time any code reads
 * `MAPPING` the duplicate is gone. So this reads the source, which is the only
 * place the second declaration still exists.
 */
final class LegacyCapMapperDuplicateKeyTest extends WP_UnitTestCase {

    public function test_no_capability_is_mapped_twice(): void {
        $file = ( new \ReflectionClass( LegacyCapMapper::class ) )->getFileName();
        $this->assertIsString( $file, 'could not locate the mapper source' );

        $source = file_get_contents( (string) $file );
        $this->assertNotFalse( $source, 'could not read the mapper source' );

        // Every mapping line looks like:  'tt_some_cap' => [ 'entity', 'activity' ],
        preg_match_all( "/^\s*'(tt_[a-z0-9_]+)'\s*=>\s*\[/m", (string) $source, $m );
        $caps = $m[1] ?? [];

        $this->assertNotEmpty( $caps, 'parsed no capabilities — the mapping format changed, fix this test' );

        $seen = [];
        $dupes = [];
        foreach ( $caps as $cap ) {
            if ( isset( $seen[ $cap ] ) ) {
                $dupes[ $cap ] = ( $dupes[ $cap ] ?? 1 ) + 1;
            }
            $seen[ $cap ] = true;
        }

        $this->assertSame(
            [],
            $dupes,
            'these capabilities are declared more than once; PHP keeps the last silently: '
            . implode( ', ', array_keys( $dupes ) )
        );
    }

    /**
     * The specific regression: editing the grid is `change`, which is what
     * academy_admin is seeded. Resetting it is a different privilege and is
     * gated elsewhere, on `manage_options`.
     */
    public function test_managing_the_matrix_bridges_to_change(): void {
        $this->assertSame(
            [ 'authorization_matrix', 'change' ],
            LegacyCapMapper::tupleFor( 'tt_manage_authorization' )
        );
    }
}
