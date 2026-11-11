<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4957 — only `PlayedMatch` builds a played condition.
 *
 * Every report figure that says "played" used to wrap the shared test in a
 * set of its own: one surface added an upper date limit, another read a
 * score as played, a third rebuilt the status-or-date test. This scan fails
 * on any copy outside the chokepoint, comments left out:
 *
 *  - the deleted helpers (`playedMatchSql()`, `isPlayed()`, `$played_only`);
 *  - a `CURDATE()` upper limit in a file that reads match types;
 *  - a `'completed'` status test or-ed with a date test;
 *  - a score that means played (`home_score IS NOT NULL` as an `OR` leg, or
 *    a fixture's `outcome` or-ed with its `played`).
 */
final class PlayedMatchChokepointTest extends WP_UnitTestCase {

    private const ALLOWED = [
        'src/Infrastructure/Query/PlayedMatch.php',
    ];

    public function test_only_played_match_builds_a_played_condition(): void {
        $root  = dirname( __DIR__, 2 );
        $found = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $rii as $file ) {
            if ( $file->isDir() || $file->getExtension() !== 'php' ) continue;
            $rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            if ( in_array( $rel, self::ALLOWED, true ) ) continue;
            $why = self::playedCopy( (string) file_get_contents( $file->getPathname() ) );
            if ( $why !== '' ) $found[] = $rel . ': ' . $why;
        }

        sort( $found );
        $this->assertSame( [], $found, 'Read "played" from TT\\Infrastructure\\Query\\PlayedMatch.' );
    }

    public function test_the_guard_catches_a_planted_copy(): void {
        $this->assertNotSame( '', self::playedCopy( '<?php $sql = MinutesQuery::playedMatchSql( "a" );' ) );
        $this->assertNotSame( '', self::playedCopy( '<?php if ( TeamMatchRecord::isPlayed( $f ) ) {}' ) );
        $this->assertNotSame( '', self::playedCopy( '<?php function f( bool $played_only = false ) {}' ) );
        $this->assertNotSame( '', self::playedCopy( '<?php $t = ActivityTypeKey::MATCH_LIKE_SQL; $q = "AND a.session_date <= CURDATE()";' ) );
        $this->assertNotSame( '', self::playedCopy( "<?php \$q = \"( a.activity_status_key = 'completed' OR a.session_date < CURDATE() )\";" ) );
        $this->assertNotSame( '', self::playedCopy( '<?php $q = "a.completed_at IS NULL OR a.home_score IS NOT NULL";' ) );
        $this->assertNotSame( '', self::playedCopy( "<?php \$p = ! empty( \$f['played'] ) || \$f['outcome'] !== '';" ) );

        // Not a played condition: attendance up to today, a comment, the chokepoint's call.
        $this->assertSame( '', self::playedCopy( '<?php $q = "AND a.session_date <= CURDATE()";' ) );
        $this->assertSame( '', self::playedCopy( "<?php // MinutesQuery::playedMatchSql() was the old rule\n\$x = 1;" ) );
        $this->assertSame( '', self::playedCopy( '<?php $t = ActivityTypeKey::GAME; $q = PlayedMatch::playedSql( "a" );' ) );
        $this->assertSame( '', self::playedCopy( "<?php \$q = \"status = 'completed'\"; \$r = \"a.session_date >= CURDATE()\";" ) );
    }

    /** Why the source holds a played condition of its own, or '' when it does not. */
    private static function playedCopy( string $source ): string {
        $code = self::withoutComments( $source );

        if ( preg_match( '/\bplayedMatchSql\b|\bisPlayed\s*\(|\$played_only\b/', $code ) ) {
            return 'a deleted played helper';
        }

        $reads_matches = (bool) preg_match( "/MATCH_LIKE|matchTypeClause|ActivityTypeKey::(GAME|LEGACY_GAME|TOURNAMENT)\b|'tournament'/", $code );
        if ( $reads_matches && preg_match( '/<=?\s*CURDATE\s*\(\s*\)/i', $code ) ) {
            return 'a CURDATE() upper limit on matches';
        }

        if ( preg_match( "/'completed'[^;]{0,200}\bOR\b[^;]{0,120}CURDATE/is", $code ) ) {
            return 'a completed-or-past test';
        }

        if ( preg_match( '/\bOR\b[^;]{0,80}home_score\s+IS\s+NOT\s+NULL/i', $code )
            || preg_match( "/played'?\]?\s*\)?\s*\|\|[^;\n]{0,80}outcome|outcome[^;\n]{0,80}\|\|[^;\n]{0,80}\bplayed\b/", $code ) ) {
            return 'a score that means played';
        }

        return '';
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
