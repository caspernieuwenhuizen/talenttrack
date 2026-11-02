<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * #4601 — which activity types count as a match is answered once, by
 * `ActivityTypeKey`. The list used to be written out by hand in eighteen
 * places, and three of them compared without `LOWER()`, so a row stored as
 * `Match` counted in the minutes reports and not in the standard minutes
 * report or the match-day start check.
 */
final class MatchTypeClauseTest extends WP_UnitTestCase {

    private const TEAM = 46010;

    /** @var string */
    private $p = '';

    /** @var int */
    private $player = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ) );

        $wpdb->insert( "{$this->p}tt_teams", [ 'id' => self::TEAM, 'club_id' => 1, 'name' => 'JO13-1' ] );
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => 1, 'team_id' => self::TEAM, 'first_name' => 'Matchtype', 'last_name' => 'Speler',
            'jersey_number' => 4, 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_clause_and_the_php_check_ignore_case(): void {
        $this->assertSame( "LOWER(a.activity_type_key) IN ('game','tournament','match')", ActivityTypeKey::matchTypeClause( 'a.activity_type_key' ) );
        $this->assertSame( 'LOWER(activity_type_keyDROP) IN (' . ActivityTypeKey::MATCH_LIKE_SQL . ')', ActivityTypeKey::matchTypeClause( 'activity_type_key; DROP' ), 'only identifier characters survive' );
        $this->assertSame( 'LOWER(activity_type_key) IN (' . ActivityTypeKey::MATCH_LIKE_SQL . ')', ActivityTypeKey::matchTypeClause( ' ; ' ), 'and an empty one falls back to the column' );
        foreach ( [ 'match', 'Match', ' GAME ', 'Tournament' ] as $key ) {
            $this->assertTrue( ActivityTypeKey::isMatchType( $key ), $key );
        }
        foreach ( [ 'training', 'friendly', '' ] as $key ) {
            $this->assertFalse( ActivityTypeKey::isMatchType( $key ), $key );
        }
    }

    /** A completed match stored as `Match` counts in all three reads. */
    public function test_a_match_stored_with_a_capital_counts_everywhere(): void {
        global $wpdb;
        $played = $this->activity( '2026-03-14', 'completed' );
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id' => 1, 'activity_id' => $played, 'player_id' => $this->player, 'is_guest' => 0,
            'record_type' => 'actual', 'status' => 'present', 'minutes_played' => 60,
        ] );

        $report = 0;
        foreach ( ( new MinutesQuery() )->forTeam( self::TEAM, '2026-03-01', '2026-03-31' ) as $row ) {
            if ( (int) $row['player_id'] === $this->player ) $report = (int) $row['total_minutes'];
        }
        $this->assertSame( 60, $report, 'the minutes report' );

        $html = $this->renderReport( 'renderPlayerMinutesPlayed', [ 'player_id' => (string) $this->player, 'from' => '2026-03-01', 'to' => '2026-03-31' ] );
        preg_match_all( '#<td class="num">(\d+)</td>#', $html, $m );
        $this->assertSame( 60, array_sum( array_map( 'intval', $m[1] ) ), 'the standard Player · Minutes played report' );

        $today = $this->activity( current_time( 'Y-m-d' ), 'planned' );
        $wpdb->insert( "{$this->p}tt_match_prep", [
            'uuid' => wp_generate_uuid4(), 'club_id' => 1, 'activity_id' => $today, 'half_length_minutes' => 30,
        ] );
        $startable = ( new MatchExecutionRepository() )->findStartableForTeams( [ self::TEAM ] );
        $this->assertNotNull( $startable, 'the match-day start check' );
        $this->assertSame( $today, (int) $startable->activity_id );
    }

    /**
     * The guard: the three match types written out together in `src/`
     * outside `ActivityTypeKey` fail here. The four-type lists that also
     * name `friendly` are a different set and are left alone.
     */
    public function test_no_copy_of_the_list_is_left_in_src(): void {
        $root  = dirname( __DIR__, 2 ) . '/src';
        $found = [];
        $it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            if ( $file->getFilename() === 'ActivityTypeKey.php' ) continue;
            foreach ( self::copiesIn( (string) file_get_contents( $file->getPathname() ) ) as $line ) {
                $found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . $line;
            }
        }
        $this->assertSame( [], $found, 'use ActivityTypeKey::matchTypeClause() / isMatchType() instead' );
    }

    public function test_the_guard_catches_a_planted_copy(): void {
        $this->assertSame( [ 2 ], self::copiesIn( "<?php\n\$sql = \"AND LOWER(t) IN ( 'match', 'game', 'tournament' )\";\n" ) );
        $this->assertSame( [ 1 ], self::copiesIn( "\$ok = in_array( \$t, [ 'tournament','match','game' ], true );" ) );
        $this->assertSame( [], self::copiesIn( "const T = [ 'game', 'match', 'friendly', 'tournament' ];" ), 'the four-type set is another list' );
    }

    /**
     * Lines holding exactly the three match types as one quoted list, in
     * any order.
     *
     * @return list<int> line numbers
     */
    private static function copiesIn( string $source ): array {
        $out = [];
        foreach ( explode( "\n", $source ) as $i => $line ) {
            if ( ! preg_match_all( "/'(?:match|game|tournament)'(?:\s*,\s*'[a-z_]+')*/i", $line, $lists ) ) continue;
            foreach ( $lists[0] as $list ) {
                preg_match_all( "/'([a-z_]+)'/i", $list, $items );
                $set = array_map( 'strtolower', $items[1] );
                sort( $set );
                if ( $set === [ 'game', 'match', 'tournament' ] ) {
                    $out[] = $i + 1;
                    break;
                }
            }
        }
        return $out;
    }

    private function activity( string $date, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id' => 1, 'team_id' => self::TEAM, 'title' => 'Thuis', 'session_date' => $date,
            'activity_type_key' => 'Match', 'activity_status_key' => $status, 'plan_state' => $status,
            'opponent' => 'VVV',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string, string> $query */
    private function renderReport( string $method, array $query ): string {
        $saved = $_GET;
        $_GET  = $query;
        $m = new \ReflectionMethod( \TT\Shared\Frontend\FrontendStandardReportsView::class, $method );
        $m->setAccessible( true );
        ob_start();
        try {
            $m->invoke( null );
        } finally {
            $html = (string) ob_get_clean();
            $_GET = $saved;
        }
        return $html;
    }
}
