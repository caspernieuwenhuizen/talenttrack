<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondCli (#0031) — `wp tt spond sync` command.
 *
 *   wp tt spond sync                  # sync every team with a URL
 *   wp tt spond sync --team=<id>      # sync one team
 */
final class SpondCli {

    /**
     * @param array<int,string>     $args
     * @param array<string,string>  $assoc_args
     */
    public static function sync( array $args, array $assoc_args ): void {
        $team_id = isset( $assoc_args['team'] ) ? (int) $assoc_args['team'] : 0;

        if ( $team_id > 0 ) {
            $result = SpondSync::syncTeam( $team_id );
            self::renderResult( $result );
            return;
        }

        $results = SpondSync::syncAll();
        if ( empty( $results ) ) {
            \WP_CLI::log( 'No teams have a Spond URL configured.' );
            return;
        }
        foreach ( $results as $result ) {
            self::renderResult( $result );
        }
    }

    /**
     * @param array{team_id:int,status:string,fetched_count:int,created_count:int,updated_count:int,archived_count:int,last_message:string} $r
     */
    private static function renderResult( array $r ): void {
        \WP_CLI::log( sprintf(
            'Team %d  %s  fetched=%d created=%d updated=%d archived=%d  %s',
            $r['team_id'],
            strtoupper( $r['status'] ),
            $r['fetched_count'],
            $r['created_count'],
            $r['updated_count'],
            $r['archived_count'],
            $r['last_message']
        ) );
    }
}
