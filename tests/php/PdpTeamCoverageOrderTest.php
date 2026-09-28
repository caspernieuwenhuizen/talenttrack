<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;

/**
 * #4176 — the per-team PDP coverage rows sort worst share of players
 * talked to first, and the "No team" bucket (team_id 0) goes last
 * whatever its figures. Unplaced players are a place to look, not a
 * team to chase, so the bucket must not top the list just because its
 * share is the lowest.
 */
final class PdpTeamCoverageOrderTest extends WP_UnitTestCase {

    /**
     * @return array{ team_id:int, team_name:string, players:int, covered:int, conducted:int, scheduled_soon:int, parent_acked:int }
     */
    private static function row( int $team_id, string $name, int $players, int $conducted ): array {
        return [
            'team_id'        => $team_id,
            'team_name'      => $name,
            'players'        => $players,
            'covered'        => 0,
            'conducted'      => $conducted,
            'scheduled_soon' => 0,
            'parent_acked'   => 0,
        ];
    }

    public function test_no_team_bucket_sorts_last_whatever_its_share(): void {
        $rows = [
            self::row( 0, '', 4, 0 ),
            self::row( 7, 'U15', 10, 8 ),
            self::row( 3, 'U13', 12, 3 ),
            self::row( 5, 'U14', 10, 3 ),
        ];

        usort( $rows, [ PdpFilesRepository::class, 'compareTeamCoverage' ] );

        $this->assertSame( [ 3, 5, 7, 0 ], array_column( $rows, 'team_id' ) );
    }

    public function test_equal_shares_fall_back_to_team_name(): void {
        $rows = [
            self::row( 2, 'Beta', 10, 5 ),
            self::row( 1, 'alpha', 4, 2 ),
        ];

        usort( $rows, [ PdpFilesRepository::class, 'compareTeamCoverage' ] );

        $this->assertSame( [ 1, 2 ], array_column( $rows, 'team_id' ) );
    }
}
