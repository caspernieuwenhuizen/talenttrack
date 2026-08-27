<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Import\Excel\ExcelImporter;
use TT\Modules\Import\ImportTagSink;
use TT\Modules\DemoData\Generators\UserGenerator;
use TT\Modules\DemoData\Generators\PeopleGenerator;
use TT\Modules\DemoData\Generators\TeamGenerator;
use TT\Modules\DemoData\Generators\PlayerGenerator;
use TT\Modules\DemoData\Generators\EvaluationGenerator;
use TT\Modules\DemoData\Generators\ActivityGenerator;
use TT\Modules\DemoData\Generators\GeneratorContext;
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

        // v3.85.0 / v3.90.1 — selective generation: when running procedurally,
        // the operator can opt out of any of the six demo-data categories so
        // the generator only fills the rest on top of the existing club data.
        // Defaults preserve the v3.0 behaviour (everything generated). Excel
        // + hybrid paths ignore master-data flags — the workbook drives those
        // — but still honour dependent-entity flags so the operator can e.g.
        // upload a teams/players workbook and skip procedural goals on top.
        $gen_people      = ! isset( $opts['gen_people'] )      || (bool) $opts['gen_people'];
        $gen_teams       = ! isset( $opts['gen_teams'] )       || (bool) $opts['gen_teams'];
        $gen_players     = ! isset( $opts['gen_players'] )     || (bool) $opts['gen_players'];

        $gen_flags = [];
        foreach ( array_keys( DemoCoverage::dependentGenerators() ) as $category ) {
            $key = 'gen_' . $category;
            $gen_flags[ $category ] = ! isset( $opts[ $key ] ) || (bool) $opts[ $key ];
        }

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
            $importer = new ExcelImporter(
                static fn( string $id ): ImportTagSink => new DemoBatchRegistry( $id )
            );
            $excel = $importer->importFile( $excel_path, basename( $excel_path ), $batch_id );
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
        $teams_missing_coach = [];
        if ( $source === 'procedural' ) {
            if ( $gen_teams ) {
                $club_name = isset( $opts['club_name'] ) ? (string) $opts['club_name'] : null;
                $teamGen   = new TeamGenerator( $registry, $users, $persons, (int) $config['teams'], $club_name );
                $teams     = $teamGen->generate();
            } else {
                // Selective mode: use whatever teams already exist in the
                // current club. Activities + evaluations + goals attach to
                // these directly and read `head_coach_user_id` off the team,
                // so loadAllTeams() resolves it from the roster and falls
                // back to the run's author for a team with no head coach.
                $teams = self::loadAllTeams(
                    (int) ( $users['hjo'] ?? $users['admin'] ?? self::firstAdministratorId() )
                );
                $teams_missing_coach = self::teamsMissingCoach( $teams );
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

        // Dependent generators are resolved from `DemoCoverage` rather than
        // hardcoded here, so a new wave's generator only has to declare
        // itself in the manifest. Master data (users, people, teams,
        // players) stays explicit above: each one produces the entity set
        // the next needs, and only those three carry the "use the club's
        // existing rows instead" opt-out.
        $ctx = new GeneratorContext(
            $registry,
            $users,
            $persons,
            $teams,
            $players,
            $config,
            $content_language
        );

        // Per-category opt-out (`$gen_flags`, built above). Excel-sourced runs
        // also skip procedural fill for any sheet the workbook covered; a
        // pure-Excel run skips all of it.
        $dependent_counts = [];
        foreach ( DemoCoverage::dependentGenerators() as $category => $generator_class ) {
            $dependent_counts[ $category ] = 0;

            if ( $source === 'excel' ) continue;
            if ( ! ( $gen_flags[ $category ] ?? true ) ) continue;

            $sheet = DemoCoverage::excelSheetFor( $category );
            if ( $sheet !== null && in_array( $sheet, $excel_present_sheets, true ) ) continue;

            if ( ! self::contextSatisfies( $generator_class, $ctx ) ) continue;

            /** @var \TT\Modules\DemoData\Generators\DependentGeneratorInterface $gen */
            $gen = $generator_class::fromContext( $ctx );
            $dependent_counts[ $category ] = (int) $gen->generate();
        }

        // Journey events are written by JourneyEventSubscriber off the hooks
        // the generators fire, so nothing tags them at insert time. Sweep
        // them up here — an untagged demo row is one the wipe can never
        // reach.
        $journey_count = self::tagUntaggedJourneyEvents( $registry, $players );

        return [
            'batch_id' => $batch_id,
            'users'    => $users,
            'accounts' => $userGen ? $userGen->accounts() : [],
            'teams'    => $teams,
            'players'  => $players,
            'counts'   => array_merge( [
                'users'   => count( $users ),
                'persons' => count( $persons ),
                'teams'   => count( $teams ),
                'players' => count( $players ),
                'journey' => $journey_count,
            ], $dependent_counts, $excel_imported ),
            'user_stats' => $user_stats,
            'excel_present_sheets' => $excel_present_sheets,
            'teams_missing_coach'  => $teams_missing_coach,
        ];
    }

    /**
     * Master data a dependent generator needs before it can write anything.
     * Running one against an empty roster is a no-op at best and a division
     * by zero at worst, so skip rather than call.
     */
    private static function contextSatisfies( string $generator_class, GeneratorContext $ctx ): bool {
        if ( empty( $ctx->players ) ) return false;
        if ( $generator_class === GoalGenerator::class ) return true;
        return ! empty( $ctx->teams );
    }

    /**
     * Tag `tt_player_events` rows for this batch's players that carry no
     * demo tag yet, so the journey cascade can wipe them.
     *
     * @param object[] $players
     * @return int rows tagged
     */
    private static function tagUntaggedJourneyEvents( DemoBatchRegistry $registry, array $players ): int {
        global $wpdb;
        if ( ! $players ) return 0;

        $player_ids = [];
        foreach ( $players as $p ) {
            $id = (int) ( $p->id ?? 0 );
            if ( $id > 0 ) $player_ids[] = $id;
        }
        if ( ! $player_ids ) return 0;

        $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT e.id
               FROM {$wpdb->prefix}tt_player_events e
          LEFT JOIN {$wpdb->prefix}tt_demo_tags d
                 ON d.entity_type = 'player_event' AND d.entity_id = e.id AND d.club_id = %d
              WHERE e.player_id IN ({$placeholders})
                AND e.club_id = %d
                AND d.id IS NULL",
            ...array_merge( [ CurrentClub::id() ], $player_ids, [ CurrentClub::id() ] )
        ) );

        $tagged = 0;
        foreach ( (array) $rows as $event_id ) {
            $registry->tag( 'player_event', (int) $event_id );
            $tagged++;
        }
        return $tagged;
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
    private static function loadAllTeams( int $fallback_coach_id = 0 ): array {
        global $wpdb;

        // `head_coach_user_id` is not a column on tt_teams — TeamGenerator
        // synthesises it on the objects it returns, and every dependent
        // generator reads it off the team. Resolve it from the roster so the
        // selective path hands back the same shape. Without this the property
        // is simply absent: activities land on coach 0 and the evaluation
        // generator skips every team in silence (#2503).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*,
                    COALESCE( (
                        SELECT p.wp_user_id
                          FROM {$wpdb->prefix}tt_team_people tp
                          JOIN {$wpdb->prefix}tt_people p ON p.id = tp.person_id
                         WHERE tp.team_id = t.id
                           AND tp.club_id = t.club_id
                           AND tp.is_head_coach = 1
                           AND tp.end_date IS NULL
                         ORDER BY tp.id
                         LIMIT 1
                    ), 0 ) AS head_coach_user_id
               FROM {$wpdb->prefix}tt_teams t
              WHERE t.club_id = %d AND t.archived_at IS NULL",
            CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];

        // A club can legitimately have a team with nobody marked head coach.
        // Attribute that team's generated work to the operator running the
        // job rather than to user 0, which no screen can resolve.
        foreach ( $rows as $row ) {
            $row->head_coach_user_id = (int) ( $row->head_coach_user_id ?? 0 );
            if ( $row->head_coach_user_id <= 0 ) {
                $row->head_coach_user_id = $fallback_coach_id;
                $row->coach_was_missing  = true;
            }
        }
        return $rows;
    }

    /**
     * Teams from the last selective load that had no head coach on the
     * roster, so the caller can tell the operator which ones were guessed at.
     *
     * @param object[] $teams
     * @return string[] team names
     */
    private static function teamsMissingCoach( array $teams ): array {
        $out = [];
        foreach ( $teams as $team ) {
            if ( ! empty( $team->coach_was_missing ) ) {
                $out[] = (string) ( $team->name ?? ( '#' . (int) ( $team->id ?? 0 ) ) );
            }
        }
        return $out;
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
        if ( ! is_array( $rows ) ) return [];

        // Same shape problem as loadAllTeams(): `archetype` is not a column,
        // it is written to tt_demo_tags.extra_json by PlayerGenerator and read
        // back off the player object by EvaluationGenerator. Recover it for
        // players a previous batch generated; anything else keeps the neutral
        // default rather than a flat line for the whole squad (#2503).
        $archetypes = [];
        $tagged = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_id, extra_json FROM {$wpdb->prefix}tt_demo_tags
              WHERE entity_type = 'player' AND club_id = %d",
            CurrentClub::id()
        ) );
        foreach ( (array) $tagged as $tag ) {
            $extra = $tag->extra_json ? json_decode( (string) $tag->extra_json, true ) : [];
            if ( is_array( $extra ) && ! empty( $extra['archetype'] ) ) {
                $archetypes[ (int) $tag->entity_id ] = (string) $extra['archetype'];
            }
        }

        $pool = [ 'rising_star', 'steady_solid', 'late_bloomer', 'inconsistent', 'in_a_slump' ];
        foreach ( $rows as $row ) {
            $id = (int) ( $row->id ?? 0 );
            $row->archetype = $archetypes[ $id ] ?? $pool[ $id % count( $pool ) ];
        }
        return $rows;
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
