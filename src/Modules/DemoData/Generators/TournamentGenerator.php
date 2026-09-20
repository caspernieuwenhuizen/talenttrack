<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\SeedLoader;

/**
 * TournamentGenerator — a tournament per team with its squad, fixtures and
 * per-period player assignments.
 *
 * Tournament matches carry their own schedule and result columns rather than
 * deferring to `tt_match_execution`, so a tournament is self-contained: past
 * fixtures are completed, the rest are still scheduled.
 *
 * Assignments spread minutes deliberately. Target minutes per player are set
 * on the squad row, and the period assignments rotate through the squad so no
 * one sits out entirely — which is the point of a youth tournament planner.
 */
class TournamentGenerator implements DependentGeneratorInterface {

    private const MATCHES_PER_TOURNAMENT = 4;
    private const MATCH_MINUTES          = 20;

    /**
     * The pitch, in the planner's own codes (#3559).
     *
     * Seven-a-side, the shape a youth tournament is played in. The codes
     * used to be `GK` plus `OF1`…`OF6`, which are outside the set
     * `TournamentsRestController` allows and which no other surface knows
     * how to read.
     *
     * @var list<string>
     */
    private const PITCH_POSITIONS = [ 'GK', 'CB', 'LB', 'RB', 'DM', 'AM', 'ST' ];

    /**
     * Position groups a squad player can cover, cycled across the squad.
     *
     * #3559 — was `DF` / `MF` / `FW`, which `normalisePositionsJson()`
     * only accepts as legacy tokens it coerces away. Stored directly by
     * the generator they were simply outside the allowed set, so the
     * auto-planner could not match a demo player to any slot.
     */
    private const ELIGIBLE_POSITIONS = [
        [ 'CB', 'LB' ],
        [ 'DM', 'CM' ],
        [ 'CM', 'AM' ],
        [ 'AM', 'ST' ],
        [ 'RB', 'CB' ],
    ];

    /** @var array<string, array{name:string, notes:string, label:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'name'  => 'Spring tournament',
            'notes' => 'Four short matches across one morning. Everyone plays.',
            'label' => 'Match',
        ],
        'nl_NL' => [
            'name'  => 'Voorjaarstoernooi',
            'notes' => 'Vier korte wedstrijden op één ochtend. Iedereen speelt.',
            'label' => 'Wedstrijd',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'tournaments';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->teams, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
    }

    /**
     * @param object[] $players
     * @param object[] $teams
     * @param array<string,int> $users
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        array $users,
        int $weeks,
        string $language = ''
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $copy      = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $author    = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $opponents = SeedLoader::opponents();
        $levels    = $this->lookupNames( 'tournament_opponent_level' ) ?: [ 'equal' ];
        $formations = $this->lookupNames( 'tournament_formation' ) ?: [ '1-3-4-3' ];

        $players_by_team = [];
        foreach ( $this->players as $p ) {
            $players_by_team[ (int) ( $p->team_id ?? 0 ) ][] = (int) $p->id;
        }

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        $total = 0;
        foreach ( $this->teams as $index => $team ) {
            $team_id = (int) $team->id;
            $squad   = $players_by_team[ $team_id ] ?? [];
            if ( count( $squad ) < 6 ) continue;

            // First team's tournament sits in the past, the rest ahead, so
            // both a played and an upcoming tournament are on screen.
            $start_ts = $index === 0
                ? $window_start + (int) ( $this->weeks * 0.5 * WEEK_IN_SECONDS )
                : time() + ( mt_rand( 10, 40 ) * DAY_IN_SECONDS );

            $wpdb->insert( "{$wpdb->prefix}tt_tournaments", [
                'club_id'           => CurrentClub::id(),
                'uuid'              => self::uuid(),
                'name'              => $copy['name'] . ' — ' . (string) ( $team->name ?? '' ),
                'start_date'        => gmdate( 'Y-m-d', $start_ts ),
                'end_date'          => gmdate( 'Y-m-d', $start_ts ),
                'default_formation' => (string) $formations[ mt_rand( 0, count( $formations ) - 1 ) ],
                'team_id'           => $team_id,
                'notes'             => $copy['notes'],
                'created_by'        => $author,
            ] );
            $tournament_id = (int) $wpdb->insert_id;
            if ( ! $tournament_id ) continue;
            $this->registry->tag( 'tournament', $tournament_id, [ 'team_id' => $team_id ] );
            $total++;

            // Squad with target minutes — the planner's whole purpose is
            // spreading these fairly, so they're set rather than left null.
            $per_player = (int) floor(
                ( self::MATCHES_PER_TOURNAMENT * self::MATCH_MINUTES * 7 ) / max( 1, count( $squad ) )
            );
            foreach ( $squad as $slot => $player_id ) {
                // eligible_positions and substitution_windows carry a JSON
                // validity constraint — NULL is rejected, so both always get
                // a real value rather than being left empty.
                $eligible = (string) wp_json_encode(
                    $slot === 0 ? [ 'GK', 'CB' ] : self::ELIGIBLE_POSITIONS[ $slot % count( self::ELIGIBLE_POSITIONS ) ]
                );

                $ok = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}tt_tournament_squad
                        (tournament_id, player_id, club_id, target_minutes, eligible_positions)
                     VALUES (%d, %d, %d, %d, %s)",
                    $tournament_id, (int) $player_id, CurrentClub::id(), $per_player, $eligible
                ) );
                if ( $ok ) {
                    // No surrogate id on this table — the wipe reaches it via
                    // the tagged tournament (DemoCoverage::TABLE_QUIRKS).
                    $total++;
                }
            }
            $this->registry->tag( 'tournament_squad', $tournament_id, [ 'players' => count( $squad ) ] );

            // One substitution window, halfway. The planner reads this as
            // the period count: windows + 1 (#3559).
            $windows      = [ (int) floor( self::MATCH_MINUTES / 2 ) ];
            $period_count = count( $windows ) + 1;

            $cursor = 0;
            for ( $m = 1; $m <= self::MATCHES_PER_TOURNAMENT; $m++ ) {
                $kick_off = $start_ts + ( ( $m - 1 ) * 45 * MINUTE_IN_SECONDS );
                $played   = $kick_off < time();

                $wpdb->insert( "{$wpdb->prefix}tt_tournament_matches", [
                    'club_id'        => CurrentClub::id(),
                    'tournament_id'  => $tournament_id,
                    'sequence'       => $m,
                    'label'          => $copy['label'] . ' ' . $m,
                    'opponent_name'  => $opponents ? (string) $opponents[ ( $index + $m ) % count( $opponents ) ] : 'Opponent ' . $m,
                    'opponent_level' => (string) $levels[ mt_rand( 0, count( $levels ) - 1 ) ],
                    'formation'      => (string) $formations[ mt_rand( 0, count( $formations ) - 1 ) ],
                    'duration_min'   => self::MATCH_MINUTES,
                    // JSON validity constraint — see the squad insert above.
                    'substitution_windows' => (string) wp_json_encode( $windows ),
                    'scheduled_at'   => gmdate( 'Y-m-d H:i:s', $kick_off ),
                    'kicked_off_at'  => $played ? gmdate( 'Y-m-d H:i:s', $kick_off ) : null,
                    'completed_at'   => $played ? gmdate( 'Y-m-d H:i:s', $kick_off + ( self::MATCH_MINUTES * MINUTE_IN_SECONDS ) ) : null,
                ] );
                $match_id = (int) $wpdb->insert_id;
                if ( ! $match_id ) continue;
                $this->registry->tag( 'tournament_match', $match_id, [ 'tournament_id' => $tournament_id ] );
                $total++;

                // Rotate through the squad so minutes spread rather than the
                // same seven playing every period.
                //
                // #3559 — periods are 0-based, because period 0 is the
                // opening lineup and is what `computeTotals()` counts as a
                // start. Generating 1 and 2 gave every demo player zero
                // starts and put an assignment on a period the fixture does
                // not have.
                $slots = array_slice(
                    self::PITCH_POSITIONS,
                    0,
                    min( count( self::PITCH_POSITIONS ), count( $squad ) )
                );

                for ( $period = 0; $period < $period_count; $period++ ) {
                    $on_pitch = [];

                    foreach ( $slots as $code ) {
                        // Walk on until an unused squad member turns up: a
                        // squad smaller than the pitch would otherwise put
                        // the same child in two positions at once.
                        $player_id = 0;
                        for ( $step = 0; $step < count( $squad ); $step++ ) {
                            $candidate = (int) $squad[ $cursor % count( $squad ) ];
                            $cursor++;
                            if ( ! in_array( $candidate, $on_pitch, true ) ) {
                                $player_id = $candidate;
                                break;
                            }
                        }
                        if ( $player_id <= 0 ) continue;
                        $on_pitch[] = $player_id;

                        $total += $this->assign( $match_id, $period, $player_id, (string) $code );
                    }

                    // #3559 — and everyone else is on the bench for it.
                    // The planner writes a BENCH row per squad member per
                    // period; without them a player's tournament history
                    // cannot tell "did not play" from "was never picked".
                    foreach ( $squad as $squad_player_id ) {
                        $squad_player_id = (int) $squad_player_id;
                        if ( in_array( $squad_player_id, $on_pitch, true ) ) continue;
                        $total += $this->assign( $match_id, $period, $squad_player_id, 'BENCH' );
                    }
                }
            }
        }
        return $total;
    }

    /**
     * Write one assignment row and tag it.
     *
     * @return int 1 when a row landed, 0 otherwise — so the caller can add
     *   it straight to the run's total.
     */
    private function assign( int $match_id, int $period, int $player_id, string $position_code ): int {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_tournament_assignments", [
            'club_id'       => CurrentClub::id(),
            'match_id'      => $match_id,
            'period_index'  => $period,
            'player_id'     => $player_id,
            'position_code' => $position_code,
        ] );

        $assignment_id = (int) $wpdb->insert_id;
        if ( $assignment_id <= 0 ) return 0;

        $this->registry->tag( 'tournament_assignment', $assignment_id );
        return 1;
    }

    /** @return string[] */
    private function lookupNames( string $type ): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( $type ) as $item ) {
            $out[] = (string) $item->name;
        }
        return $out;
    }

    /**
     * #3102 — outside the seeded stream, so a second run into the same
     * install does not re-mint the uuid the first one already stored. See
     * \TT\Modules\DemoData\DemoUuid.
     */
    private static function uuid(): string {
        return \TT\Modules\DemoData\DemoUuid::mint();
    }

    private static function resolveLanguage( string $locale ): string {
        if ( isset( self::COPY_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::COPY_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
