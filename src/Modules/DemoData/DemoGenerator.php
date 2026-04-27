<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\Generators\UserGenerator;
use TT\Modules\DemoData\Generators\PeopleGenerator;
use TT\Modules\DemoData\Generators\TeamGenerator;
use TT\Modules\DemoData\Generators\PlayerGenerator;
use TT\Modules\DemoData\Generators\EvaluationGenerator;
use TT\Modules\DemoData\Generators\ActivityGenerator;
use TT\Modules\DemoData\Generators\GoalGenerator;

/**
 * DemoGenerator — orchestrates all six generators in dependency order.
 *
 * MT RNG is seeded up front so (seed, preset, domain) is reproducible
 * byte-for-byte across runs.
 */
class DemoGenerator {

    public const PRESETS = [
        'tiny'   => [ 'teams' => 1,  'players_per_team' => 12, 'weeks' => 4  ],
        'small'  => [ 'teams' => 3,  'players_per_team' => 12, 'weeks' => 8  ],
        'medium' => [ 'teams' => 6,  'players_per_team' => 12, 'weeks' => 16 ],
        'large'  => [ 'teams' => 12, 'players_per_team' => 12, 'weeks' => 36 ],
    ];

    /**
     * @param array{preset:string, domain:string, password:string, seed:int, club_name?:string, content_language?:string} $opts
     * @return array{
     *   batch_id:string,
     *   users:array<string,int>,
     *   accounts:array<string,array{user_id:int,email:string}>,
     *   teams:object[],
     *   players:object[],
     *   counts:array<string,int>,
     *   user_stats:array{created:int, reused:int}
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

        $peopleGen = new PeopleGenerator( $registry, $users );
        $persons   = $peopleGen->generate();

        $club_name = isset( $opts['club_name'] ) ? (string) $opts['club_name'] : null;
        $teamGen   = new TeamGenerator( $registry, $users, $persons, (int) $config['teams'], $club_name );
        $teams     = $teamGen->generate();

        $playerGen = new PlayerGenerator( $registry, $teams, $users, (int) $config['players_per_team'] );
        $players   = $playerGen->generate();

        $content_language = isset( $opts['content_language'] ) && (string) $opts['content_language'] !== ''
            ? (string) $opts['content_language']
            : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );

        $evalGen    = new EvaluationGenerator( $registry, $players, $teams, (int) $config['weeks'] );
        $eval_count = $evalGen->generate();

        $sessionGen    = new ActivityGenerator( $registry, $teams, $players, (int) $config['weeks'], $content_language );
        $session_count = $sessionGen->generate();

        $goalGen    = new GoalGenerator( $registry, $players, $users, $content_language );
        $goal_count = $goalGen->generate();

        return [
            'batch_id' => $batch_id,
            'users'    => $users,
            'accounts' => $userGen->accounts(),
            'teams'    => $teams,
            'players'  => $players,
            'counts'     => [
                'users'       => count( $users ),
                'persons'     => count( $persons ),
                'teams'       => count( $teams ),
                'players'     => count( $players ),
                'evaluations' => $eval_count,
                'activities'    => $session_count,
                'goals'       => $goal_count,
            ],
            'user_stats' => [
                'created' => $userGen->createdCount(),
                'reused'  => $userGen->reusedCount(),
            ],
        ];
    }

    /**
     * True when any persistent demo user already exists — used by the
     * admin form to switch into "reuse only" messaging instead of the
     * "36 welcome emails" warning.
     */
    public static function persistentUsersExist(): bool {
        return DemoBatchRegistry::persistentEntityIds( 'wp_user' ) !== [];
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
