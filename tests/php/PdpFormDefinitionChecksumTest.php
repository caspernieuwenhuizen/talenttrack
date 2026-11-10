<?php
namespace TT\Tests\Php;

use PHPUnit\Framework\TestCase;

/**
 * #4976 — a shipped PDP form version is immutable.
 *
 * Conversations held on a version keep reading it (owner decision 1 on
 * #4975), so an in-place edit would change what an earlier conversation
 * says. Every shipped `config/pdp_forms/<form>/v<N>.php` is pinned here by its
 * SHA-256, line endings normalised. A change to a form is a new file,
 * `v<N+1>.php`, with its own line below; a version is never edited.
 *
 * A new version file without a line here fails too, so a version is pinned
 * the moment it ships.
 */
final class PdpFormDefinitionChecksumTest extends TestCase {

    private const PINNED = [
        'jg4it_elite_outfield/v1.php'   => '4cbfd9c2e803c742af131b23c112426e31de6e4634a71c207d3847d682be0bd6',
        'jg4it_elite_goalkeeper/v1.php' => 'd3ee7d19867a8fb362c3a92431c9f7d163c329f45a8a97aec280ee0b1fc6be66',
    ];

    public function test_every_shipped_version_is_pinned_and_unchanged(): void {
        $dir   = dirname( __DIR__, 2 ) . '/config/pdp_forms';
        $found = [];
        foreach ( (array) glob( $dir . '/*/v*.php' ) as $file ) {
            $found[ basename( dirname( (string) $file ) ) . '/' . basename( (string) $file ) ] = (string) $file;
        }
        ksort( $found );

        $pinned = self::PINNED;
        ksort( $pinned );
        $this->assertSame( array_keys( $pinned ), array_keys( $found ), 'every shipped version has a pinned checksum, and every pin a file' );

        foreach ( $found as $name => $file ) {
            $content = str_replace( "\r\n", "\n", (string) file_get_contents( $file ) );
            $this->assertSame(
                $pinned[ $name ],
                hash( 'sha256', $content ),
                "{$name} was edited after it shipped. Put the change in a new version file instead."
            );
        }
    }
}
