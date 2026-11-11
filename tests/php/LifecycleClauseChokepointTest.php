<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #5095 — the report and activity readers spell "not cancelled" through
 * `ActivityLifecycle` and "played" through `PlayedMatch`, never by hand.
 *
 * A hand-written cancel test drifts: one copy checked the status only, so a
 * session the planner cancelled (plan state) still counted; another dropped a
 * NULL. This scan covers `src/Modules/Analytics/` and
 * `src/Modules/Activities/Repositories/`, comments left out, and fails on:
 *
 *  - a comparison against the literal `'cancelled'` in SQL (`<>`, `!=` or
 *    `NOT IN ( … )`);
 *  - a `<> %s` filled with `ActivityStatusKey::CANCELLED`.
 *
 * Played conditions are held to `PlayedMatch` across all of `src` by
 * `PlayedMatchChokepointTest`.
 */
final class LifecycleClauseChokepointTest extends WP_UnitTestCase {

    private const FOLDERS = [
        'src/Modules/Analytics',
        'src/Modules/Activities/Repositories',
    ];

    public function test_no_reader_spells_not_cancelled_by_hand(): void {
        $root  = dirname( __DIR__, 2 );
        $found = [];
        foreach ( self::FOLDERS as $folder ) {
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root . '/' . $folder, \FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $rii as $file ) {
                if ( $file->isDir() || $file->getExtension() !== 'php' ) continue;
                $rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
                if ( self::spellsCancel( (string) file_get_contents( $file->getPathname() ) ) ) $found[] = $rel;
            }
        }

        sort( $found );
        $this->assertSame( [], $found, 'Use ActivityLifecycle::notCancelledClause() / outstandingClause().' );
    }

    public function test_the_guard_catches_a_planted_copy(): void {
        $this->assertTrue( self::spellsCancel( "<?php \$q = \"AND a.activity_status_key <> 'cancelled'\";" ) );
        $this->assertTrue( self::spellsCancel( "<?php \$q = \"AND LOWER(a.plan_state) != 'cancelled'\";" ) );
        $this->assertTrue( self::spellsCancel( "<?php \$q = \"AND a.activity_status_key NOT IN ('completed','cancelled')\";" ) );
        $this->assertTrue( self::spellsCancel( "<?php \$w = \"a.plan_state <> %s\"; \$params[] = ActivityStatusKey::CANCELLED;" ) );

        $this->assertFalse( self::spellsCancel( "<?php \$q = 'AND ' . ActivityLifecycle::notCancelledClause( 'a' );" ) );
        $this->assertFalse( self::spellsCancel( "<?php // a.activity_status_key <> 'cancelled' was the old spelling\n\$x = 1;" ) );
        $this->assertFalse( self::spellsCancel( "<?php if ( \$status === ActivityStatusKey::CANCELLED ) return;" ) );
        $this->assertFalse( self::spellsCancel( "<?php \$allowed = [ 'draft', 'scheduled', 'cancelled' ];" ) );
    }

    private static function spellsCancel( string $source ): bool {
        $code = self::withoutComments( $source );
        if ( preg_match( "/(<>|!=|\bNOT\s+IN\s*\()[^;'\"]{0,40}('[a-z_]+'\s*,\s*)*'cancelled'/i", $code ) ) return true;
        return (bool) preg_match( '/<>\s*%s[^;]*;[^;]*ActivityStatusKey::CANCELLED/s', $code );
    }

    private static function withoutComments( string $source ): string {
        $out = '';
        foreach ( token_get_all( $source ) as $token ) {
            if ( is_array( $token ) ) {
                if ( $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ) continue;
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
