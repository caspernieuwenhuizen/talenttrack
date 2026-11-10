<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepShareView;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\MatchPrepShareLink;

/**
 * #4992 — the logged-out share views read as the shared record's club, and
 * they pin that club through `ClubIteration::withClub()`, the one place
 * that also drops the per-club authorization caches and takes the pin off
 * again when the render throws. A view that sets the club filter itself
 * gets neither.
 */
final class ShareViewClubPinTest extends WP_UnitTestCase {

    private const OWNER_DIR = 'src/Infrastructure/Tenancy/';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
    }

    public function tear_down(): void {
        unset( $_GET['id'], $_GET['token'] );
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_no_file_outside_tenancy_sets_the_club_filter(): void {
        $root  = dirname( __DIR__, 2 );
        $found = [];
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            $path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            if ( strpos( $path, self::OWNER_DIR ) === 0 ) continue;
            foreach ( self::directPins( (string) file_get_contents( $file->getPathname() ) ) as $line ) {
                $found[] = $path . ':' . $line;
            }
        }
        $this->assertSame( [], $found, 'Pin a club with ClubIteration::withClub(), not with the tt_current_club_id filter directly.' );
    }

    public function test_the_guard_finds_a_planted_pin(): void {
        $planted = "<?php\nadd_filter( 'tt_current_club_id', \$f );\n"
            . "add_filter(\"tt_current_club_id\", static fn() => 3, 50);\n"
            . "\$club = apply_filters( 'tt_current_club_id', 1 );\n"
            . "ClubIteration::withClub( 3, \$fn );\n";
        $this->assertSame( [ 2, 3 ], self::directPins( $planted ), 'both quote styles are found, and reading the club is not' );
    }

    public function test_the_share_view_renders_as_the_preps_club_and_restores_everything(): void {
        $this->shareAPrepInClub( 7 );
        $this->primeCaches();

        // After the pin, so it sees the club every read inside the render
        // is answered with.
        $seen  = [];
        $probe = static function ( $club ) use ( &$seen ) {
            $seen[] = (int) $club;
            return $club;
        };
        add_filter( 'tt_current_club_id', $probe, 10000 );
        try {
            ob_start();
            FrontendMatchPrepShareView::render();
            $out = (string) ob_get_clean();
        } finally {
            remove_filter( 'tt_current_club_id', $probe, 10000 );
        }

        $this->assertContains( 7, $seen, 'the render reads as the prep\'s club' );
        $this->assertStringContainsString( 'tt-mp--shared', $out, 'the prep is found in its own club and shown' );
        $this->assertSame( 1, CurrentClub::id(), 'the request is back on its own club' );
        $this->assertCachesDropped();
    }

    public function test_a_render_that_throws_still_restores_the_club_and_the_caches(): void {
        $this->shareAPrepInClub( 7 );
        $this->primeCaches();

        $boom = static function ( $club ) {
            if ( (int) $club === 7 ) {
                throw new \RuntimeException( 'render failed' );
            }
            return $club;
        };
        add_filter( 'tt_current_club_id', $boom, 10000 );
        $thrown = false;
        ob_start();
        try {
            FrontendMatchPrepShareView::render();
        } catch ( \RuntimeException $e ) {
            $thrown = true;
        } finally {
            ob_end_clean();
            remove_filter( 'tt_current_club_id', $boom, 10000 );
        }

        $this->assertTrue( $thrown, 'the render reached the pinned club and failed there' );
        $this->assertSame( 1, CurrentClub::id(), 'the pin is off again' );
        $this->assertCachesDropped();
    }

    public function test_flushCache_clears_the_player_visibility_answer(): void {
        $this->setStatic( PlayerVisibility::class, 'cache', [ 4242 => true ] );

        AuthorizationService::flushCache();

        $this->assertSame( [], $this->getStatic( PlayerVisibility::class, 'cache' ) );
    }

    /**
     * Line numbers of every `add_filter` on the club filter.
     *
     * @return list<int>
     */
    private static function directPins( string $source ): array {
        $lines = [];
        foreach ( preg_split( '/\R/', $source ) ?: [] as $i => $line ) {
            if ( preg_match( '/add_filter\s*\(\s*[\'"]tt_current_club_id[\'"]/', (string) $line ) ) {
                $lines[] = $i + 1;
            }
        }
        return $lines;
    }

    /** A shared prep whose row belongs to `$club_id`, its link in `$_GET`. */
    private function shareAPrepInClub( int $club_id ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => (int) CurrentClub::id(),
            'name'    => 'JO14-1',
        ] );
        $team_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => (int) CurrentClub::id(),
            'team_id'           => $team_id,
            'title'             => 'Hedel - Tilburg',
            'session_date'      => '2026-09-05',
            'activity_type_key' => 'match',
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $prep_id = ( new MatchPrepRepository() )->ensureForActivity( $activity_id );
        $url     = MatchPrepShareLink::urlFor( $prep_id );
        $this->assertNotSame( '', $url );

        // Minted in club 1, then moved: the prep, its activity and its team
        // now belong to another club, which this request is not on.
        $wpdb->update( $wpdb->prefix . 'tt_match_prep', [ 'club_id' => $club_id ], [ 'id' => $prep_id ] );
        $wpdb->update( $wpdb->prefix . 'tt_activities', [ 'club_id' => $club_id ], [ 'id' => $activity_id ] );
        $wpdb->update( $wpdb->prefix . 'tt_teams', [ 'club_id' => $club_id ], [ 'id' => $team_id ] );

        $query = [];
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
        $_GET['id']    = (string) ( $query['id'] ?? '' );
        $_GET['token'] = (string) ( $query['token'] ?? '' );
    }

    /** Answers the request held before the render, which must not survive it. */
    private function primeCaches(): void {
        $this->setStatic( PlayerVisibility::class, 'cache', [ 4242 => true ] );
        $this->setStatic( AuthorizationService::class, 'cache_person', [ 4242 => null ] );
    }

    private function assertCachesDropped(): void {
        $this->assertSame( [], $this->getStatic( PlayerVisibility::class, 'cache' ), 'the player visibility answer is dropped' );
        $this->assertSame( [], $this->getStatic( AuthorizationService::class, 'cache_person' ), 'the authorization answers are dropped' );
    }

    /** @param mixed $value */
    private function setStatic( string $class, string $property, $value ): void {
        $p = new \ReflectionProperty( $class, $property );
        $p->setAccessible( true );
        $p->setValue( null, $value );
    }

    /** @return mixed */
    private function getStatic( string $class, string $property ) {
        $p = new \ReflectionProperty( $class, $property );
        $p->setAccessible( true );
        return $p->getValue();
    }
}
