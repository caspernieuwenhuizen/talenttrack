<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\Generators\UserGenerator;
use TT\Modules\DemoData\Generators\TeamGenerator;
use TT\Modules\DemoData\Generators\PlayerGenerator;

/**
 * DemoGenerator — Checkpoint 1 orchestrator.
 *
 * Runs user → team → player generation in order, seeding the MT RNG
 * up front so a given (seed, preset, domain) tuple is reproducible.
 *
 * Checkpoint 2 will extend this with EvaluationGenerator,
 * SessionGenerator, GoalGenerator and the demo-mode scope filter.
 */
class DemoGenerator {

    public const PRESETS = [
        'tiny'   => [ 'teams' => 1,  'players_per_team' => 12, 'weeks' => 4  ],
        'small'  => [ 'teams' => 3,  'players_per_team' => 12, 'weeks' => 8  ],
        'medium' => [ 'teams' => 6,  'players_per_team' => 12, 'weeks' => 16 ],
        'large'  => [ 'teams' => 12, 'players_per_team' => 12, 'weeks' => 36 ],
    ];

    /**
     * @param array{preset:string, domain:string, password:string, seed:int} $opts
     * @return array{
     *   batch_id:string,
     *   users:array<string,int>,
     *   accounts:array<string,array{user_id:int,email:string}>,
     *   teams:object[],
     *   players:object[],
     *   counts:array{users:int,teams:int,players:int}
     * }
     */
    public static function run( array $opts ): array {
        $preset = $opts['preset'] ?? 'small';
        if ( ! isset( self::PRESETS[ $preset ] ) ) {
            $preset = 'small';
        }
        $config = self::PRESETS[ $preset ];
        $seed = (int) ( $opts['seed'] ?? 20260504 );
        mt_srand( $seed );

        $batch_id = self::makeBatchId( $preset, $seed );
        $registry = new DemoBatchRegistry( $batch_id );

        $userGen  = new UserGenerator( $registry, (string) $opts['domain'], (string) $opts['password'] );
        $users    = $userGen->generate();

        $teamGen  = new TeamGenerator( $registry, $users, (int) $config['teams'] );
        $teams    = $teamGen->generate();

        $playerGen = new PlayerGenerator( $registry, $teams, $users, (int) $config['players_per_team'] );
        $players   = $playerGen->generate();

        return [
            'batch_id' => $batch_id,
            'users'    => $users,
            'accounts' => $userGen->accounts(),
            'teams'    => $teams,
            'players'  => $players,
            'counts'   => [
                'users'   => count( $users ),
                'teams'   => count( $teams ),
                'players' => count( $players ),
            ],
        ];
    }

    private static function makeBatchId( string $preset, int $seed ): string {
        return sprintf( '%s-%d-%s', $preset, $seed, gmdate( 'YmdHis' ) );
    }

    /**
     * Aggregate counts across all demo-tagged entities. Used by the
     * admin page to show "current state".
     *
     * @return array<string,int>
     */
    public static function counts(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT entity_type, COUNT(*) AS n
             FROM {$wpdb->prefix}tt_demo_tags
             GROUP BY entity_type"
        );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (string) $r->entity_type ] = (int) $r->n;
        }
        return $out;
    }

    /**
     * @return object[] Distinct batches with created_at and entity totals.
     */
    public static function batches(): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT batch_id,
                    MIN(created_at) AS created_at,
                    COUNT(*)        AS total_entities
             FROM {$wpdb->prefix}tt_demo_tags
             GROUP BY batch_id
             ORDER BY MIN(created_at) DESC"
        );
    }
}
