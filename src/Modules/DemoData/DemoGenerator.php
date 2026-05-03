<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\Excel\ExcelImporter;
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
        $source      = isset( $opts['source'] ) ? (string) $opts['source'] : 'procedural';
        $excel_path  = isset( $opts['excel_path'] ) ? (string) $opts['excel_path'] : '';
        $preset      = $opts['preset'] ?? 'small';
        if ( ! isset( self::PRESETS[ $preset ] ) ) {
            $preset = 'small';
        }
        $config = self::PRESETS[ $preset ];
        $seed   = (int) ( $opts['seed'] ?? 20260504 );
        mt_srand( $seed );

        // v3.85.0 — selective generation: when running procedurally, the
        // operator can opt out of generating master data (teams / people /
        // players) so the generator only fills dependent entities on top
        // of the existing club data. Defaults preserve the v3.0 behaviour
        // (everything generated). Excel + hybrid paths ignore these — the
        // workbook drives those.
        $gen_people  = ! isset( $opts['gen_people'] )  || (bool) $opts['gen_people'];
        $gen_teams   = ! isset( $opts['gen_teams'] )   || (bool) $opts['gen_teams'];
        $gen_players = ! isset( $opts['gen_players'] ) || (bool) $opts['gen_players'];

        $batch_id = self::makeBatchId( $preset, $seed );
        $registry = new DemoBatchRegistry( $batch_id );

        $users   = [];
        $persons = [];
        $user_stats = [ 'created' => 0, 'reused' => 0 ];
        $userGen = null;

        if ( $source !== 'procedural' || $gen_people ) {
            $userGen = new UserGenerator( $registry, (string) $opts['domain'], (string) $opts['password'] );
            $users   = $userGen->generate();
            $user_stats = [ 'created' => $userGen->createdCount(), 'reused' => $userGen->reusedCount() ];

            $peopleGen = new PeopleGenerator( $registry, $users );
            $persons   = $peopleGen->generate();
        } else {
            // Selective mode skipped UserGenerator + PeopleGenerator. The
            // downstream generators only consult `$users['hjo']` /
            // `$users['admin']` for the `created_by` field on goals; fall
            // back to the first WP administrator so goals still get a
            // valid author.
            $admin_id = self::firstAdministratorId();
            $users    = [ 'admin' => $admin_id, 'hjo' => $admin_id ];
        }

        // #0059 — pure-Excel path: run the importer, return its counts +
        // the user/staff numbers from the procedural generators above.
        // Hybrid path falls through to the chain below but skips
        // entities the Excel sheet covered.
        $excel_present_sheets = [];
        $excel_imported       = [];
        if ( $source === 'excel' || $source === 'hybrid' ) {
            $excel = ( new ExcelImporter() )->importFile( $excel_path, basename( $excel_path ), $batch_id );
            if ( ! $excel['ok'] ) {
                return [
                    'batch_id'   => $batch_id,
                    'users'      => $users,
                    'accounts'   => $userGen->accounts(),
                    'teams'      => [],
                    'players'    => [],
                    'counts'     => array_merge( [ 'users' => count( $users ), 'persons' => count( $persons ) ], $excel['imported'] ),
                    'user_stats' => [
                        'created' => $userGen->createdCount(),
                        'reused'  => $userGen->reusedCount(),
                    ],
                    'excel_blockers' => $excel['blockers'],
                ];
            }
            $excel_present_sheets = $excel['present_sheets'];
            $excel_imported       = $excel['imported'];
        }

        $teams   = [];
        $players = [];
        if ( $source === 'procedural' ) {
            if ( $gen_teams ) {
                $club_name = isset( $opts['club_name'] ) ? (string) $opts['club_name'] : null;
                $teamGen   = new TeamGenerator( $registry, $users, $persons, (int) $config['teams'], $club_name );
                $teams     = $teamGen->generate();
            } else {
                // Selective mode: use whatever teams already exist in the
                // current club. Activities + evaluations + goals attach
                // to these directly, so head_coach_id has to be set on
                // each row for the downstream generators to assign a
                // coach. Existing rows that lack head_coach_id silently
                // produce zero downstream rows for that team.
                $teams = self::loadAllTeams();
            }

            if ( $gen_players ) {
                $playerGen = new PlayerGenerator( $registry, $teams, $users, (int) $config['players_per_team'] );
                $players   = $playerGen->generate();
            } else {
                $players = self::loadAllPlayers();
            }
        } else {
            // For excel + hybrid: load whatever the Excel importer just
            // inserted as native objects so the downstream generators can
            // write related entities.
            $teams   = self::loadDemoTaggedTeams( $batch_id );
            $players = self::loadDemoTaggedPlayers( $batch_id );
        }

        $content_language = isset( $opts['content_language'] ) && (string) $opts['content_language'] !== ''
            ? (string) $opts['content_language']
            : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );

        $eval_count    = 0;
        $session_count = 0;
        $goal_count    = 0;

        // Hybrid mode: run procedural generators only for sheets the
        // Excel didn't cover. Pure Excel skips them entirely.
        $skip_eval     = $source === 'excel' || in_array( 'evaluations',  $excel_present_sheets, true );
        $skip_activity = $source === 'excel' || in_array( 'sessions',     $excel_present_sheets, true );
        $skip_goal     = $source === 'excel' || in_array( 'goals',        $excel_present_sheets, true );

        if ( ! $skip_eval && ! empty( $players ) && ! empty( $teams ) ) {
            $evalGen    = new EvaluationGenerator( $registry, $players, $teams, (int) $config['weeks'] );
            $eval_count = $evalGen->generate();
        }
        if ( ! $skip_activity && ! empty( $teams ) && ! empty( $players ) ) {
            $sessionGen    = new ActivityGenerator( $registry, $teams, $players, (int) $config['weeks'], $content_language );
            $session_count = $sessionGen->generate();
        }
        if ( ! $skip_goal && ! empty( $players ) ) {
            $goalGen    = new GoalGenerator( $registry, $players, $users, $content_language );
            $goal_count = $goalGen->generate();
        }

        return [
            'batch_id' => $batch_id,
            'users'    => $users,
            'accounts' => $userGen ? $userGen->accounts() : [],
            'teams'    => $teams,
            'players'  => $players,
            'counts'   => array_merge( [
                'users'       => count( $users ),
                'persons'     => count( $persons ),
                'teams'       => count( $teams ),
                'players'     => count( $players ),
                'evaluations' => $eval_count,
                'activities'  => $session_count,
                'goals'       => $goal_count,
            ], $excel_imported ),
            'user_stats' => $user_stats,
            'excel_present_sheets' => $excel_present_sheets,
        ];
    }

    /** Load the teams that this batch's Excel importer just inserted. */
    private static function loadDemoTaggedTeams( string $batch_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$wpdb->prefix}tt_teams t
              JOIN {$wpdb->prefix}tt_demo_tags d
                ON d.entity_type = 'team' AND d.entity_id = t.id
             WHERE d.batch_id = %s AND d.club_id = %d",
            $batch_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * v3.85.0 — load every team in the current club, regardless of demo
     * tag. Used by selective generation when `gen_teams=false` (the
     * operator has set up teams themselves and wants the dependent
     * entities generated on top).
     */
    private static function loadAllTeams(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$wpdb->prefix}tt_teams t
              WHERE t.club_id = %d AND t.archived_at IS NULL",
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * v3.85.0 — load every active player in the current club, regardless
     * of demo tag. Used by selective generation when `gen_players=false`.
     */
    private static function loadAllPlayers(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p
              WHERE p.club_id = %d AND p.status = 'active'",
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * v3.85.0 — first WP administrator id, used as the `created_by`
     * fallback for goals when selective generation skips
     * UserGenerator + PeopleGenerator. The current request's user_id
     * is preferred when available so the audit trail attributes the
     * run to the operator who clicked Generate.
     */
    private static function firstAdministratorId(): int {
        $current = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $current > 0 ) return $current;
        $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ] );
        return is_array( $admins ) && $admins ? (int) $admins[0] : 0;
    }

    /** Load the players that this batch's Excel importer just inserted. */
    private static function loadDemoTaggedPlayers( string $batch_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p
              JOIN {$wpdb->prefix}tt_demo_tags d
                ON d.entity_type = 'player' AND d.entity_id = p.id
             WHERE d.batch_id = %s AND d.club_id = %d",
            $batch_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
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
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_type, COUNT(*) AS n
             FROM {$wpdb->prefix}tt_demo_tags
             WHERE club_id = %d
             GROUP BY entity_type",
            CurrentClub::id()
        ) );
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
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT batch_id,
                    MIN(created_at) AS created_at,
                    COUNT(*)        AS total_entities
             FROM {$wpdb->prefix}tt_demo_tags
             WHERE club_id = %d
             GROUP BY batch_id
             ORDER BY MIN(created_at) DESC",
            CurrentClub::id()
        ) );
    }
}
