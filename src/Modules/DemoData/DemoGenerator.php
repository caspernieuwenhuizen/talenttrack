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
 * MT RNG is seeded up front so (seed, preset, size, domain) is reproducible
 * byte-for-byte across runs.
 *
 * `$opts['size']` (#3042) optionally overrides the chosen preset's `teams`,
 * `players_per_team` and `weeks`.
 */
class DemoGenerator {

    public const PRESETS = [
        'tiny'   => [ 'teams' => 1,  'players_per_team' => 12, 'weeks' => 4  ],
        'small'  => [ 'teams' => 3,  'players_per_team' => 12, 'weeks' => 8  ],
        'medium' => [ 'teams' => 6,  'players_per_team' => 12, 'weeks' => 16 ],
        'large'  => [ 'teams' => 12, 'players_per_team' => 12, 'weeks' => 36 ],
    ];

    /**
     * @param array<string,mixed> $opts preset, size, domain, password, seed,
     *                                  club_name, content_language, source,
     *                                  excel_path, and the `gen_*` toggles
     * @return array<string,mixed> batch_id, users, accounts, teams, players,
     *                             counts, user_stats
     */
    public static function run( array $opts ): array {
        $state = self::begin( $opts );
        if ( $state->status() === DemoRunState::STATUS_FAILED ) {
            return self::result( $state );
        }

        while ( $state->nextStep() !== null ) {
            self::advance( $state );
            if ( $state->status() === DemoRunState::STATUS_FAILED ) break;
        }

        return self::result( $state );
    }

    /**
     * Start a run: everything that can only happen inside the request that
     * submitted the form (#3041).
     *
     * The WP users need the typed password and the workbook needs the
     * uploaded temp file, and neither may be written down between requests —
     * so both run here, before any state is persisted. What the returned
     * state carries is ids and counts.
     *
     * The remaining steps are then advanceable one request at a time by
     * `advance()`, which is how the large preset stops being a single
     * request the gateway can time out.
     *
     * @param array<string,mixed> $opts see `run()`
     */
    public static function begin( array $opts ): DemoRunState {
        $source      = isset( $opts['source'] ) ? (string) $opts['source'] : 'procedural';
        $excel_path  = isset( $opts['excel_path'] ) ? (string) $opts['excel_path'] : '';
        $preset      = $opts['preset'] ?? 'small';
        if ( ! isset( self::PRESETS[ $preset ] ) ) {
            $preset = 'small';
        }
        $config = self::PRESETS[ $preset ];

        // #3042 — per-run size overrides on top of the preset. The presets
        // stay the one-click path and the default; this is for the operator
        // who needs a different shape, and it is the only way to change the
        // player count at all — `players_per_team` was 12 in every preset,
        // and twelve is not a neutral number in youth football.
        //
        // Only the three keys a preset carries are honoured, each clamped to
        // a range a run can finish. An absent or unusable value leaves the
        // preset's, so an operator who overrides nothing gets exactly the
        // dataset they got before this existed.
        $size = $opts['size'] ?? [];
        foreach ( [ 'teams' => 40, 'players_per_team' => 40, 'weeks' => 104 ] as $key => $max ) {
            if ( ! isset( $size[ $key ] ) ) continue;
            $value = (int) $size[ $key ];
            if ( $value <= 0 ) continue;
            $config[ $key ] = min( $max, $value );
        }

        $seed = (int) ( $opts['seed'] ?? 20260504 );

        // #3041 — every step seeds the RNG from `(seed, step)` rather than
        // the run seeding it once and every generator drawing from one
        // stream. A stream cannot be carried across a request, so a run
        // spread over thirty requests could never have reproduced a run done
        // in one; deriving a stream per step makes the two identical by
        // construction. The `run_order` contract is unaffected — the
        // generators still run in the manifest's order.
        self::seedStep( $seed, DemoRunPlan::STEP_PEOPLE );

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
            self::seedStep( $seed, DemoRunPlan::STEP_EXCEL );
            $excel = $importer->importFile( $excel_path, basename( $excel_path ), $batch_id );
            if ( ! $excel['ok'] ) {
                $failed = DemoRunState::create( $batch_id, [], [
                    'seed'       => $seed,
                    'users'      => $users,
                    'persons'    => $persons,
                    'accounts'   => $userGen ? $userGen->accounts() : [],
                    'user_stats' => $user_stats,
                    'counts'     => array_merge(
                        [ 'users' => count( $users ), 'persons' => count( $persons ) ],
                        $excel['imported']
                    ),
                    'excel_blockers' => $excel['blockers'],
                ] );
                $failed->fail( implode( ' · ', (array) $excel['blockers'] ) );
                return $failed;
            }
            $excel_present_sheets = $excel['present_sheets'];
            $excel_imported       = $excel['imported'];
        }

        $content_language = isset( $opts['content_language'] ) && (string) $opts['content_language'] !== ''
            ? (string) $opts['content_language']
            : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );

        $steps = DemoRunPlan::build( [
            'source'               => $source,
            'gen_people'           => $gen_people,
            'gen_flags'            => $gen_flags,
            'excel_present_sheets' => $excel_present_sheets,
        ] );

        $state = DemoRunState::create( $batch_id, $steps, [
            'seed'                 => $seed,
            'source'               => $source,
            'config'               => $config,
            'content_language'     => $content_language,
            'club_name'            => isset( $opts['club_name'] ) ? (string) $opts['club_name'] : '',
            'gen_teams'            => $gen_teams,
            'gen_players'          => $gen_players,
            'gen_flags'            => $gen_flags,
            'excel_present_sheets' => $excel_present_sheets,
            'users'                => $users,
            'persons'              => $persons,
            'accounts'             => $userGen ? $userGen->accounts() : [],
            'user_stats'           => $user_stats,
            'teams_missing_coach'  => [],
            'counts'               => array_merge(
                [ 'users' => count( $users ), 'persons' => count( $persons ) ],
                $excel_imported
            ),
        ] );

        // The two inline steps have already run — they had to, in this
        // request. Everything after them is advanceable one request at a
        // time.
        foreach ( $steps as $step ) {
            if ( DemoRunPlan::isInline( $step ) ) {
                $state->markDone( $step );
            }
        }
        $state->persist();

        return $state;
    }

    /**
     * Run exactly one pending step of a run, then persist.
     *
     * Master data is re-read from the database rather than carried in the
     * state: `tt_teams` and `tt_players` rows are the run's own output, the
     * batch id identifies them, and a row object is not something to write
     * into an option.
     */
    public static function advance( DemoRunState $state ): void {
        $step = $state->nextStep();
        if ( $step === null ) {
            return;
        }

        $seed     = (int) $state->get( 'seed', 0 );
        $batch_id = $state->batchId();
        $registry = new DemoBatchRegistry( $batch_id );

        self::seedStep( $seed, $step );

        try {
            switch ( $step ) {
                case DemoRunPlan::STEP_TEAMS:
                    self::stepTeams( $state, $registry );
                    break;

                case DemoRunPlan::STEP_PLAYERS:
                    self::stepPlayers( $state, $registry );
                    break;

                case DemoRunPlan::STEP_JOURNEY:
                    // Journey events are written by JourneyEventSubscriber off
                    // the hooks the generators fire, so nothing tags them at
                    // insert time. Sweep them up — an untagged demo row is one
                    // the wipe can never reach.
                    $state->addCounts( [
                        'journey' => self::tagUntaggedJourneyEvents( $registry, self::playersFor( $state ) ),
                    ] );
                    break;

                default:
                    self::stepDependent( $state, $registry, $step );
                    break;
            }
        } catch ( \Throwable $e ) {
            $state->fail( $e->getMessage() );
            $state->persist();
            return;
        }

        $state->markDone( $step );
        $state->persist();
    }

    private static function stepTeams( DemoRunState $state, DemoBatchRegistry $registry ): void {
        if ( (string) $state->get( 'source', 'procedural' ) !== 'procedural' ) {
            // Excel + hybrid: the workbook wrote them; nothing to generate.
            $state->addCounts( [ 'teams' => count( self::teamsFor( $state ) ) ] );
            return;
        }

        if ( ! $state->get( 'gen_teams', true ) ) {
            // Selective mode: the operator set the teams up themselves.
            $teams = self::teamsFor( $state );
            $state->set( 'teams_missing_coach', self::teamsMissingCoach( $teams ) );
            $state->addCounts( [ 'teams' => count( $teams ) ] );
            return;
        }

        /** @var array<string,int> $users */
        $users = (array) $state->get( 'users', [] );
        /** @var array<string,int> $persons */
        $persons   = (array) $state->get( 'persons', [] );
        $config    = (array) $state->get( 'config', [] );
        $club_name = (string) $state->get( 'club_name', '' );

        $teamGen = new TeamGenerator(
            $registry,
            $users,
            $persons,
            (int) ( $config['teams'] ?? 0 ),
            $club_name !== '' ? $club_name : null
        );
        $state->addCounts( [ 'teams' => count( $teamGen->generate() ) ] );
    }

    private static function stepPlayers( DemoRunState $state, DemoBatchRegistry $registry ): void {
        if ( (string) $state->get( 'source', 'procedural' ) !== 'procedural'
            || ! $state->get( 'gen_players', true )
        ) {
            $state->addCounts( [ 'players' => count( self::playersFor( $state ) ) ] );
            return;
        }

        /** @var array<string,int> $users */
        $users  = (array) $state->get( 'users', [] );
        $config = (array) $state->get( 'config', [] );

        $playerGen = new PlayerGenerator(
            $registry,
            self::teamsFor( $state ),
            $users,
            (int) ( $config['players_per_team'] ?? 0 )
        );
        $state->addCounts( [ 'players' => count( $playerGen->generate() ) ] );
    }

    private static function stepDependent( DemoRunState $state, DemoBatchRegistry $registry, string $step ): void {
        $category = DemoRunPlan::categoryOf( $step );
        if ( $category === null ) {
            return;
        }

        $generators = DemoCoverage::dependentGenerators();
        if ( ! isset( $generators[ $category ] ) ) {
            return;
        }
        $generator_class = $generators[ $category ];

        $ctx = self::contextFor( $state, $registry );
        if ( ! self::contextSatisfies( $generator_class, $ctx ) ) {
            $state->addCounts( [ $category => 0 ] );
            return;
        }

        /** @var \TT\Modules\DemoData\Generators\DependentGeneratorInterface $gen */
        $gen = $generator_class::fromContext( $ctx );
        $state->addCounts( [ $category => (int) $gen->generate() ] );
    }

    /**
     * Everything a dependent generator needs, rebuilt from the run state and
     * the database rather than carried between requests.
     */
    private static function contextFor( DemoRunState $state, DemoBatchRegistry $registry ): GeneratorContext {
        /** @var array<string,int> $users */
        $users = (array) $state->get( 'users', [] );
        /** @var array<string,int> $persons */
        $persons = (array) $state->get( 'persons', [] );
        /** @var array{teams:int, players_per_team:int, weeks:int} $config */
        $config = array_merge(
            [ 'teams' => 0, 'players_per_team' => 0, 'weeks' => 0 ],
            (array) $state->get( 'config', [] )
        );

        return new GeneratorContext(
            $registry,
            $users,
            $persons,
            self::teamsFor( $state ),
            self::playersFor( $state ),
            $config,
            (string) $state->get( 'content_language', 'en_US' )
        );
    }

    /**
     * The run's teams. Batch-scoped when the run generated them, club-wide
     * when the operator opted out of generating teams.
     *
     * Both go through `loadTeams()`, which synthesises `head_coach_user_id`
     * — the property `TeamGenerator` puts on the objects it returns and every
     * dependent generator reads. #2503 fixed that for the selective path; the
     * Excel path had the same gap and is fixed here by using one loader.
     *
     * @return object[]
     */
    private static function teamsFor( DemoRunState $state ): array {
        /** @var array<string,int> $users */
        $users    = (array) $state->get( 'users', [] );
        $fallback = (int) ( $users['hjo'] ?? $users['admin'] ?? self::firstAdministratorId() );

        $scoped = (string) $state->get( 'source', 'procedural' ) !== 'procedural'
            || (bool) $state->get( 'gen_teams', true );

        return self::loadTeams( $scoped ? $state->batchId() : null, $fallback );
    }

    /** @return object[] */
    private static function playersFor( DemoRunState $state ): array {
        $scoped = (string) $state->get( 'source', 'procedural' ) !== 'procedural'
            || (bool) $state->get( 'gen_players', true );

        return self::loadPlayers( $scoped ? $state->batchId() : null );
    }

    /**
     * The shape `run()` has always returned, assembled from a finished (or
     * failed) run.
     *
     * @return array<string,mixed>
     */
    public static function result( DemoRunState $state ): array {
        $out = [
            'batch_id'   => $state->batchId(),
            'users'      => (array) $state->get( 'users', [] ),
            'accounts'   => (array) $state->get( 'accounts', [] ),
            'teams'      => self::teamsFor( $state ),
            'players'    => self::playersFor( $state ),
            'counts'     => array_map( 'intval', (array) $state->get( 'counts', [] ) ),
            'user_stats' => (array) $state->get( 'user_stats', [ 'created' => 0, 'reused' => 0 ] ),
            'excel_present_sheets' => (array) $state->get( 'excel_present_sheets', [] ),
            'teams_missing_coach'  => (array) $state->get( 'teams_missing_coach', [] ),
        ];

        $blockers = $state->get( 'excel_blockers', [] );
        if ( is_array( $blockers ) && $blockers ) {
            $out['excel_blockers'] = $blockers;
            $out['teams']          = [];
            $out['players']        = [];
        }

        return $out;
    }

    /**
     * Seed the RNG for one step from `(seed, step)`.
     *
     * Deterministic and independent of how many requests the run is spread
     * over, which is the whole reason the run can be chunked at all (#3041).
     */
    private static function seedStep( int $seed, string $step ): void {
        mt_srand( ( $seed + (int) sprintf( '%u', crc32( $step ) ) ) & 0x7FFFFFFF );
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

    /**
     * The teams a run works with.
     *
     * `$batch_id` narrows to what this run wrote; null means every team in
     * the club, which is what selective generation (`gen_teams=false`) works
     * against.
     *
     * v3.85.0 / #3041. `head_coach_user_id` is not a column on tt_teams —
     * TeamGenerator synthesises it on the objects it returns, and every
     * dependent generator reads it off the team. Resolving it here is what
     * lets a step re-read its teams from the database instead of carrying row
     * objects between requests. Without it the property is simply absent:
     * activities land on coach 0 and the evaluation generator skips every
     * team in silence (#2503).
     *
     * @return object[]
     */
    private static function loadTeams( ?string $batch_id, int $fallback_coach_id = 0 ): array {
        global $wpdb;

        $where  = [ 't.club_id = %d', 't.archived_at IS NULL' ];
        $params = [ CurrentClub::id() ];

        if ( $batch_id !== null ) {
            $where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_demo_tags d
                                   WHERE d.entity_type = 'team'
                                     AND d.entity_id = t.id
                                     AND d.club_id = t.club_id
                                     AND d.batch_id = %s )";
            $params[] = $batch_id;
        }
        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
              WHERE {$where_sql}
              ORDER BY t.id",
            $params
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
     * The active players a run works with. `$batch_id` narrows to what this
     * run wrote; null means every active player in the club, which is what
     * selective generation (`gen_players=false`) works against.
     *
     * @return object[]
     */
    private static function loadPlayers( ?string $batch_id ): array {
        global $wpdb;

        $where  = [ 'p.club_id = %d', "p.status = 'active'" ];
        $params = [ CurrentClub::id() ];

        if ( $batch_id !== null ) {
            $where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_demo_tags d
                                   WHERE d.entity_type = 'player'
                                     AND d.entity_id = p.id
                                     AND d.club_id = p.club_id
                                     AND d.batch_id = %s )";
            $params[] = $batch_id;
        }
        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p WHERE {$where_sql} ORDER BY p.id",
            $params
        ) );
        if ( ! is_array( $rows ) ) return [];

        // Same shape problem as loadTeams(): `archetype` is not a column,
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
