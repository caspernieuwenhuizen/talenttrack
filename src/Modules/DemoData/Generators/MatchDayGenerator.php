<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\FormationLayoutResolver;
use TT\Modules\Teams\FootballFormResolver;

/**
 * MatchDayGenerator — turns a generated fixture into a match.
 *
 * Prep for every fixture (availability, lineup, roles, per-player intent);
 * execution for the ones already played (score, goal events, substitutions,
 * a light tracked-event stream). Future fixtures get prep and no execution,
 * which is what a coach's screen looks like mid-week.
 *
 * Two consistency rules matter more than the volume here:
 *
 *  - **Availability agrees with the injury record.** A player marked
 *    available on a date they were injured is a visible contradiction
 *    between two screens.
 *  - **Minutes reconcile.** Substitutions are drawn against the starting XI
 *    and the bench, so derived minutes-played never exceeds the match length
 *    and the team's outfield total lands on squad size x match length.
 *    Minutes reporting reads these rows; incoherent ones make every minutes
 *    report look broken.
 */
class MatchDayGenerator implements DependentGeneratorInterface {

    /** Used only when a prep comes back without a half length. */
    private const HALF_LENGTH = 35;

    /**
     * #3574 — the half length of the fixture being generated, as
     * `MatchLengthResolver` set it on the prep. Every minute drawn for that
     * match comes from it.
     */
    private int $half_length = self::HALF_LENGTH;

    /** @var array<int,int> team id => the seeded formation template for its football form (0 = none). */
    private array $template_by_team = [];

    /** Roles the prep screen assigns. */
    private const ROLES = [ 'captain', 'penalties', 'corners', 'free_kicks' ];

    /** Tracked actions, used when the club has no configured action list. */
    private const FALLBACK_ACTIONS = [
        'shot_on_target' => 'Shot on target',
        'key_pass'       => 'Key pass',
        'duel_won'       => 'Duel won',
        'turnover'       => 'Turnover',
    ];

    /** @var array<string, array{general:string, attack:string, defend:string, attention:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'general'   => 'Play out from the back, stay compact when we lose it.',
            'attack'    => 'Switch the play early and attack the far post.',
            'defend'    => 'Press as a unit; first defender sets the angle.',
            'attention' => 'Look for the forward pass before playing back.',
        ],
        'nl_NL' => [
            'general'   => 'Van achteruit opbouwen, compact blijven bij balverlies.',
            'attack'    => 'Snel het spel verleggen en de tweede paal aanvallen.',
            'defend'    => 'Als team druk zetten; de eerste verdediger bepaalt de hoek.',
            'attention' => 'Zoek eerst de voorwaartse pass voordat je terugspeelt.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private string $language;

    private ?DemoRoster $roster;

    /** @var array<int,int> player id => starts so far in this batch (#3588) */
    private array $starts = [];

    /** @var array<int,float> player id => standing handicap in starts (#3588) */
    private array $pecking_order = [];

    public static function category(): string {
        return 'match_day';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self(
            $ctx->registry,
            $ctx->historicPlayers(),
            $ctx->teams,
            $ctx->users,
            $ctx->contentLanguage,
            $ctx->roster()
        );
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
        string $language = '',
        ?DemoRoster $roster = null
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
        $this->roster   = $roster;
    }

    /**
     * The squad a team fielded on a date. Without a roster plan — a caller
     * that built this generator by hand — it is the team as it stands.
     *
     * @param array<int, list<int>> $players_by_team
     * @return list<int>
     */
    private function rosterOn( int $team_id, string $date, array $players_by_team ): array {
        if ( $this->roster === null ) {
            return $players_by_team[ $team_id ] ?? [];
        }

        $out = [];
        foreach ( $this->roster->rosterFor( $team_id, $date ) as $player ) {
            $id = (int) ( $player->id ?? 0 );
            if ( $id > 0 ) $out[] = $id;
        }
        return $out;
    }

    public function generate(): int {
        global $wpdb;

        $fixtures = $this->demoFixtures();
        if ( ! $fixtures ) return 0;

        $prep_repo = new MatchPrepRepository();
        $exec_repo = new MatchExecutionRepository();
        $copy      = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $author    = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $unavailable = InjuryGenerator::unavailabilityByPlayer();

        $players_by_team = [];
        foreach ( $this->players as $p ) {
            $players_by_team[ (int) ( $p->team_id ?? 0 ) ][] = (int) $p->id;
        }

        $age_by_team = [];
        foreach ( $this->teams as $t ) {
            $age_by_team[ (int) $t->id ] = isset( $t->age_group ) ? (string) $t->age_group : '';
        }

        // #3574 — who can go in goal, from the positions the roster carries.
        $keepers = [];
        foreach ( $this->players as $p ) {
            if ( strpos( (string) ( $p->preferred_positions ?? '' ), '"GK"' ) !== false ) {
                $keepers[ (int) ( $p->id ?? 0 ) ] = true;
            }
        }

        $total = 0;
        foreach ( $fixtures as $fixture ) {
            $activity_id = (int) $fixture->id;
            $team_id     = (int) $fixture->team_id;
            $match_date  = (string) $fixture->session_date;

            // #3402 — who was available for a match is who was in that squad
            // on the day. Falling back to the current roster would put a
            // player in a lineup two seasons before they joined the team.
            $roster = $this->rosterOn( $team_id, $match_date, $players_by_team );
            $squad_size  = self::squadSizeFor( $age_by_team[ $team_id ] ?? '' );
            if ( count( $roster ) < $squad_size ) continue;

            // #3574 — 0 lets `MatchLengthResolver` set the half from the
            // fixture and the age group, the way a coach's prep gets it,
            // rather than every demo match being 2 x 35.
            $prep_id = $prep_repo->ensureForActivity( $activity_id, 0 );
            if ( $prep_id <= 0 ) continue;
            $this->registry->tag( 'match_prep', $prep_id, [ 'activity_id' => $activity_id ] );
            $total++;

            $prep_row          = (array) ( $prep_repo->find( $prep_id ) ?? [] );
            $this->half_length = max( 1, (int) ( $prep_row['half_length_minutes'] ?? self::HALF_LENGTH ) );

            // #3574 — the team's own shape: an 8v8 squad lines up 3-3-1, not
            // on eleven slots with three left empty.
            $template_id = $this->templateForTeam( $team_id );
            $prep_repo->updatePrep( $prep_id, [
                'goals_general'         => $copy['general'],
                'goals_attack'          => $copy['attack'],
                'goals_defend'          => $copy['defend'],
                'created_by'            => $author,
                'formation_template_id' => $template_id > 0 ? $template_id : null,
            ] );

            // Availability first — the injured list decides who can be picked.
            $available = [];
            $avail_rows = [];
            $optional_absences = [];
            foreach ( $roster as $player_id ) {
                if ( $this->isUnavailableOn( $unavailable, $player_id, $match_date ) ) {
                    $avail_rows[ $player_id ] = [ 'status' => 'Injured', 'reason' => null ];
                    continue;
                }
                // A couple of absences that aren't injuries.
                if ( mt_rand( 1, 100 ) <= 6 ) {
                    $avail_rows[ $player_id ] = [ 'status' => 'Absent', 'reason' => null ];
                    $optional_absences[] = $player_id;
                    continue;
                }
                $avail_rows[ $player_id ] = [ 'status' => 'Present', 'reason' => null ];
                $available[] = $player_id;
            }

            // A twelve-player squad can't afford invented absences: a couple
            // of them and there is no side to pick, so the fixture would
            // silently produce no lineup at all. Injuries are real data and
            // stay; the invented absences give way until a team can be
            // fielded.
            while ( count( $available ) < $squad_size && $optional_absences ) {
                $restored = array_shift( $optional_absences );
                $avail_rows[ $restored ] = [ 'status' => 'Present', 'reason' => null ];
                $available[] = $restored;
            }

            $prep_repo->replaceAvailability( $prep_id, $avail_rows );
            $total += count( $avail_rows );
            $this->tagRowsFor( 'match_prep_availability', 'tt_match_prep_availability', 'match_prep_id', $prep_id );

            if ( count( $available ) < $squad_size ) continue;

            // Starting XI + bench, drawn only from available players, on the
            // slots of the shape the prep is bound to (#3574). Where the
            // shape has a goal, a keeper goes in it when the squad has one —
            // it used to be whoever had the lowest id.
            $layout  = FormationLayoutResolver::layoutFor( $template_id, $team_id );
            $gk_slot = 0;
            $nums    = [];
            foreach ( $layout as $layout_slot ) {
                $nums[] = (int) $layout_slot['num'];
                if ( $layout_slot['label'] === 'GK' ) $gk_slot = (int) $layout_slot['num'];
            }
            sort( $nums );

            [ $starting, $bench ] = $this->pickStarting( $available, $squad_size, $gk_slot > 0 ? $keepers : [] );

            $slots   = [];
            $outside = $starting;
            $free    = $nums;
            if ( $gk_slot > 0 && $starting ) {
                $slots[ $gk_slot ] = (int) $starting[0];
                $outside = array_slice( $starting, 1 );
                $free    = array_values( array_diff( $nums, [ $gk_slot ] ) );
            }
            foreach ( $outside as $i => $player_id ) {
                if ( ! isset( $free[ $i ] ) ) break;
                $slots[ $free[ $i ] ] = (int) $player_id;
            }
            $prep_repo->replaceLineupForHalf( $prep_id, 1, $slots );
            $prep_repo->replaceLineupForHalf( $prep_id, 2, $slots );
            $this->tagRowsFor( 'match_prep_lineup', 'tt_match_prep_lineup', 'match_prep_id', $prep_id );
            $total += count( $slots ) * 2;

            foreach ( self::ROLES as $role_key ) {
                $prep_repo->setRole( $prep_id, $role_key, (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ] );
            }
            $this->tagRowsFor( 'match_prep_role', 'tt_match_prep_roles', 'match_prep_id', $prep_id );
            $total += count( self::ROLES );

            // Per-player intent on about half the squad.
            $goal_rows = [];
            foreach ( $starting as $player_id ) {
                if ( mt_rand( 1, 100 ) > 50 ) continue;
                $goal_rows[ $player_id ] = [
                    'attention_text'    => $copy['attention'],
                    'is_specific_goal'  => 1,
                    'analyst_appointed' => 0,
                ];
            }
            if ( $goal_rows ) {
                $prep_repo->replacePlayerGoals( $prep_id, $goal_rows );
                $this->tagRowsFor( 'match_prep_player_goal', 'tt_match_prep_player_goals', 'match_prep_id', $prep_id );
                $total += count( $goal_rows );
            }

            // Future fixtures stop at prep.
            if ( strtotime( $match_date ) > time() ) continue;

            $total += $this->generateExecution( $exec_repo, $activity_id, $prep_id, $match_date, $starting, $bench, $author );
        }

        return $total;
    }

    /**
     * #3588 — the starting side and the bench for one fixture, rotated the
     * way a coach shares out playing time.
     *
     * The side used to be the first `squad_size` available players in roster
     * order, which is id order, so the same lowest ids started nearly every
     * match and every minutes report showed minutes falling with the id.
     *
     * Each player now carries the number of starts they have had in this
     * batch plus a standing "place in the coach's pecking order", drawn once
     * per player (up to three starts' worth). The least-ranked players start;
     * the bench is ranked the same way, so the substitutes who come on first
     * are the ones who have played least. The result is a spread with a few
     * genuinely under-played players rather than a flat line or an id curve.
     *
     * The keeper slot, when the shape has one, goes to the best-placed
     * available keeper by the same ranking. Every draw is `mt_rand`, so a seeded run stays
     * reproducible (#2461).
     *
     * @param list<int>        $available
     * @param array<int,bool>  $keepers player id => can keep goal; empty when the shape has no goal
     * @return array{0:list<int>,1:list<int>} [starting, bench]
     */
    private function pickStarting( array $available, int $squad_size, array $keepers ): array {
        $rank = [];
        foreach ( $available as $player_id ) {
            if ( ! isset( $this->pecking_order[ $player_id ] ) ) {
                $this->pecking_order[ $player_id ] = mt_rand( 0, 300 ) / 100.0;
            }
            $rank[ $player_id ] = ( $this->starts[ $player_id ] ?? 0 )
                + $this->pecking_order[ $player_id ]
                + mt_rand( 0, 99 ) / 1000.0;
        }
        asort( $rank );
        $ordered = array_map( 'intval', array_keys( $rank ) );

        $starting = [];
        foreach ( $ordered as $player_id ) {
            if ( isset( $keepers[ $player_id ] ) ) {
                $starting[] = $player_id;
                break;
            }
        }
        foreach ( $ordered as $player_id ) {
            if ( count( $starting ) >= $squad_size ) break;
            if ( in_array( $player_id, $starting, true ) ) continue;
            $starting[] = $player_id;
        }
        $bench = array_values( array_diff( $ordered, $starting ) );

        foreach ( $starting as $player_id ) {
            $this->starts[ $player_id ] = ( $this->starts[ $player_id ] ?? 0 ) + 1;
        }
        return [ $starting, $bench ];
    }

    /**
     * Score, goal events, substitutions and a light tracked-event stream.
     *
     * @param int[] $starting
     * @param int[] $bench
     */
    private function generateExecution(
        MatchExecutionRepository $exec_repo,
        int $activity_id,
        int $prep_id,
        string $match_date,
        array $starting,
        array $bench,
        int $author
    ): int {
        global $wpdb;

        $execution_id = $exec_repo->ensureForActivity( $activity_id, $prep_id );
        if ( $execution_id <= 0 ) return 0;
        $this->registry->tag( 'match_execution', $execution_id, [ 'activity_id' => $activity_id ] );
        $total = 1;

        $kickoff = strtotime( $match_date . ' 10:30:00' ) ?: time();
        $half_seconds = $this->half_length * MINUTE_IN_SECONDS;

        // Realistic youth scorelines: mostly 0–4 a side.
        $home_goals = $this->drawGoals();
        $away_goals = $this->drawGoals();

        // #3664 — a played past match is a reviewed one. The `'finished'`
        // literal is the state #1033 retired: no list, filter or pill
        // recognises it, so every generated match read "Not started".
        $exec_repo->update( $execution_id, [
            'state'                   => MatchExecutionState::FINALIZED,
            'first_half_started_at'   => gmdate( 'Y-m-d H:i:s', $kickoff ),
            'first_half_ended_at'     => gmdate( 'Y-m-d H:i:s', $kickoff + $half_seconds ),
            'second_half_started_at'  => gmdate( 'Y-m-d H:i:s', $kickoff + $half_seconds + ( 15 * MINUTE_IN_SECONDS ) ),
            'second_half_ended_at'    => gmdate( 'Y-m-d H:i:s', $kickoff + ( 2 * $half_seconds ) + ( 15 * MINUTE_IN_SECONDS ) ),
            'first_half_pause_seconds'  => 0,
            'second_half_pause_seconds' => 0,
            'home_score'              => $home_goals,
            'away_score'              => $away_goals,
            'created_by'              => $author,
        ] );

        // #3579 — the result lives on the activity, not the execution. The
        // team record, the form line and the match result card all read
        // `tt_activities.home_score` / `away_score`, which the live product
        // copies across when a match is finished (`route_finish()`). Without
        // this every demo team showed 0 played and three matches "without a
        // score" beside a scorers list. `home_score` is our goals, per
        // `MatchResultQuery`. Past fixtures are already `completed` from
        // ActivityGenerator, so only the scoreline is written here.
        $wpdb->update(
            "{$wpdb->prefix}tt_activities",
            [ 'home_score' => $home_goals, 'away_score' => $away_goals ],
            [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ]
        );

        // #3094 — one match in four has its output filled in afterwards
        // rather than logged live. The goals are the same goals and the
        // scoreline is the same scoreline; what they lack is a minute,
        // because the coach typing them up on Sunday evening does not know
        // it. Without this the demo academy would only ever show the live
        // path, and the surface built for every other club would look empty.
        $filled_in_later = mt_rand( 1, 4 ) === 1;

        // Our goals get a scorer from the XI; the opponent's don't.
        // #2856 — roughly half carry an assist and one in eight has no
        // scorer at all, so the demo academy shows the states a real match
        // produces: an attributed goal, a goal nobody could attribute, and
        // the "needs a scorer" prompt the review raises for the latter.
        for ( $i = 0; $i < ( $filled_in_later ? 0 : $home_goals ); $i++ ) {
            $unattributed = mt_rand( 1, 8 ) === 1;
            $scorer = $unattributed ? 0 : (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];

            $assist = null;
            if ( ! $unattributed && count( $starting ) > 1 && mt_rand( 0, 1 ) === 1 ) {
                do {
                    $candidate = (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];
                } while ( $candidate === $scorer );
                $assist = $candidate;
            }

            $exec_repo->logGoalEvent(
                $execution_id,
                self::uuid(),
                $scorer,
                mt_rand( 1, 2 ),
                mt_rand( 1, $this->half_length ),
                'home',
                $assist,
                false
            );
        }
        for ( $i = 0; $i < $away_goals; $i++ ) {
            $exec_repo->logGoalEvent( $execution_id, self::uuid(), 0, mt_rand( 1, 2 ), mt_rand( 1, $this->half_length ), 'away' );
        }

        // The other three matches in four logged their goals live above; this
        // one records the same goals as counts, which is what a coach doing
        // the admin afterwards actually enters. Same scoreline, no minutes.
        if ( $filled_in_later ) {
            $this->recordContributionsAfterTheFact( $exec_repo, $activity_id, $starting, $home_goals );
        }

        // Tagged by activity rather than execution since #3094: a manually
        // recorded goal has no execution_id, and an untagged demo row is a
        // permanent orphan on an operator's install.
        $this->tagRowsFor( 'match_goal_event', 'tt_match_execution_goal_events', 'activity_id', $activity_id );
        $total += $home_goals + $away_goals;

        // Substitutions: each bench player replaces a distinct starter, so a
        // player is never on the pitch twice and minutes stay coherent.
        $subs = min( count( $bench ), mt_rand( 2, 5 ) );
        $off_pool = $starting;
        shuffle( $off_pool );

        // #3029 — remember when each swap happened so minutes can be derived
        // from it below. player_id => absolute minute of the change.
        $off_at = [];
        $on_at  = [];

        for ( $i = 0; $i < $subs; $i++ ) {
            $player_off = (int) $off_pool[ $i ];
            $player_on  = (int) $bench[ $i ];
            $minute_in_half = mt_rand( 5, max( 6, $this->half_length - 2 ) );
            $exec_repo->logSubstitution(
                $execution_id,
                self::uuid(),
                2,                                    // youth subs cluster in the second half
                $minute_in_half,
                $player_off,
                $player_on
            );

            // Second half, so the absolute minute is one full half plus the
            // minute within it.
            $absolute = $this->half_length + $minute_in_half;
            $off_at[ $player_off ] = $absolute;
            $on_at[ $player_on ]   = $absolute;
        }
        $this->tagRowsFor( 'match_substitution', 'tt_match_execution_substitutions', 'execution_id', $execution_id );
        $total += $subs;

        $this->writeMinutes( $activity_id, $starting, $off_at, $on_at );

        // A light tracked-event stream — enough to populate the feed without
        // pretending a youth match was fully scouted.
        $actions = $this->trackedActions();
        $events  = mt_rand( 6, 14 );
        for ( $i = 0; $i < $events; $i++ ) {
            $player_id = (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];
            $key       = (string) array_rand( $actions );
            $wpdb->insert( "{$wpdb->prefix}tt_match_execution_tracked_events", [
                'event_uuid'     => self::uuid(),
                'club_id'        => CurrentClub::id(),
                'execution_id'   => $execution_id,
                'player_id'      => $player_id,
                'half'           => mt_rand( 1, 2 ),
                'minute_in_half' => mt_rand( 1, $this->half_length ),
                'action_key'     => $key,
                'action_label'   => (string) $actions[ $key ],
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'match_tracked_event', $id );
                $total++;
            }
        }

        return $total;
    }

    /**
     * Players on the pitch for this age group.
     *
     * #3044 — reads the product's own resolver rather than a private copy of
     * the ladder. The demo data now agrees with what the academy configured,
     * which is the point of demo data.
     */
    /**
     * #3574 — the first seeded formation template for the team's football
     * form (an 8v8 team's is "Small-sided 3-3-1"), or 0 when the install has
     * none for that form. Resolved once per team.
     */
    private function templateForTeam( int $team_id ): int {
        if ( $team_id <= 0 ) return 0;
        if ( isset( $this->template_by_team[ $team_id ] ) ) return $this->template_by_team[ $team_id ];

        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formation_templates
              WHERE football_form = %s AND is_seeded = 1 AND archived_at IS NULL
              ORDER BY id ASC LIMIT 1",
            FootballFormResolver::forTeam( $team_id )
        ) );

        $this->template_by_team[ $team_id ] = $id;
        return $id;
    }

    private static function squadSizeFor( string $age_group ): int {
        return \TT\Modules\Teams\FootballFormResolver::squadSizeForAgeGroup( $age_group );
    }

    /** Youth scorelines skew low; blowouts are rare but not impossible. */
    private function drawGoals(): int {
        $roll = mt_rand( 1, 100 );
        if ( $roll <= 18 ) return 0;
        if ( $roll <= 42 ) return 1;
        if ( $roll <= 66 ) return 2;
        if ( $roll <= 84 ) return 3;
        if ( $roll <= 95 ) return 4;
        return mt_rand( 5, 7 );
    }

    /**
     * True when the player was injured on the fixture date, per the injury
     * rows this batch generated.
     *
     * @param array<int, array<int, array{0:string, 1:?string}>> $unavailable
     */
    private function isUnavailableOn( array $unavailable, int $player_id, string $date ): bool {
        foreach ( $unavailable[ $player_id ] ?? [] as [ $start, $end ] ) {
            if ( $date < $start ) continue;
            if ( $end === null || $date <= $end ) return true;
        }
        return false;
    }

    /** @return array<string,string> action key => label */
    private function trackedActions(): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( 'football_action' ) as $item ) {
            $out[ (string) $item->name ] = (string) $item->name;
        }
        return $out ?: self::FALLBACK_ACTIONS;
    }

    /**
     * Derive minutes played and write them onto the attendance rows (#3029).
     *
     * The docblock at the top of this class has always claimed that "derived
     * minutes-played never exceeds the match length and the team's outfield
     * total lands on squad size × match length". That was true of the
     * substitution stream, but nobody ever derived the number back onto
     * `tt_attendance.minutes_played` — so every minutes surface was empty on
     * the dataset the product is demonstrated with, because `MinutesQuery`
     * reads only persisted minutes and never estimates at report time
     * (#2193).
     *
     * The arithmetic, from the stream this method is handed:
     *
     *   starter, never replaced   → the full match
     *   starter off at minute m   → m
     *   substitute on at minute m → match length − m
     *   unused bench              → left alone
     *
     * Each swap therefore contributes exactly one match's worth of minutes
     * across the pair, which is what makes the team total reconcile.
     *
     * An unused bench player keeps `NULL` rather than being written to 0.
     * "Did not feature" and "played nothing" are different facts, and a
     * demo dataset that blurs them would teach the wrong thing about a
     * surface whose whole job is minutes distribution.
     *
     * @param int[]           $starting Starting XI player ids.
     * @param array<int,int>  $off_at   player id => absolute minute taken off.
     * @param array<int,int>  $on_at    player id => absolute minute brought on.
     */
    private function writeMinutes( int $activity_id, array $starting, array $off_at, array $on_at ): void {
        $full = $this->half_length * 2;

        $minutes = [];
        foreach ( $starting as $player_id ) {
            $player_id = (int) $player_id;
            $minutes[ $player_id ] = isset( $off_at[ $player_id ] )
                ? (int) $off_at[ $player_id ]
                : $full;
        }
        foreach ( $on_at as $player_id => $minute ) {
            $minutes[ (int) $player_id ] = $full - (int) $minute;
        }

        // Minutes are the record of what happened, so they go on the
        // recorded row — the one `ActivityGenerator` wrote. #3451 moved the
        // scope from a key in the WHERE map to the writer's method names.
        $writer = new \TT\Modules\Activities\Repositories\AttendanceWriter();
        foreach ( $minutes as $player_id => $played ) {
            if ( $played <= 0 ) continue;

            $row_id = $writer->actualRowId( $activity_id, (int) $player_id );
            if ( $row_id <= 0 ) continue;

            $writer->updateRow( $row_id, [ 'minutes_played' => $played ] );
        }
    }

    /**
     * Tag every untagged row a repository just wrote for one parent, so rows
     * the domain layer inserted are still reachable by the wipe.
     */
    /**
     * #3094 — a match whose output was typed up afterwards.
     *
     * Distributes the same number of goals across the XI as counts and
     * writes them through the reconciliation path a coach's Save uses, so
     * the demo exercises the real writer rather than a fixture that only
     * looks like its output. Roughly half the goals carry an assist, as on
     * the live path.
     *
     * The result reconciles: attributed goals equal `home_score`, so the
     * grid's footer reads `3/3` rather than flagging a mismatch the demo
     * never intended to show.
     *
     * @param list<int> $starting
     */
    private function recordContributionsAfterTheFact(
        \TT\Modules\MatchExecution\Repositories\MatchExecutionRepository $exec_repo,
        int $activity_id,
        array $starting,
        int $home_goals
    ): void {
        if ( $activity_id <= 0 || $home_goals <= 0 || count( $starting ) < 2 ) return;

        $goals   = [];
        $assists = [];

        for ( $i = 0; $i < $home_goals; $i++ ) {
            $scorer = (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];
            $goals[ $scorer ] = ( $goals[ $scorer ] ?? 0 ) + 1;

            if ( mt_rand( 0, 1 ) !== 1 ) continue;

            do {
                $candidate = (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];
            } while ( $candidate === $scorer );

            $assists[ $candidate ] = ( $assists[ $candidate ] ?? 0 ) + 1;
        }

        foreach ( array_unique( array_merge( array_keys( $goals ), array_keys( $assists ) ) ) as $player_id ) {
            $exec_repo->setContributions(
                $activity_id,
                (int) $player_id,
                (int) ( $goals[ $player_id ] ?? 0 ),
                (int) ( $assists[ $player_id ] ?? 0 )
            );
        }
    }

    private function tagRowsFor( string $entity_type, string $table, string $parent_column, int $parent_id ): void {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT t.id FROM {$wpdb->prefix}{$table} t
               LEFT JOIN {$wpdb->prefix}tt_demo_tags d
                      ON d.entity_type = %s AND d.entity_id = t.id AND d.club_id = %d
              WHERE t.{$parent_column} = %d AND t.club_id = %d AND d.id IS NULL",
            $entity_type, CurrentClub::id(), $parent_id, CurrentClub::id()
        ) );
        foreach ( (array) $ids as $id ) {
            $this->registry->tag( $entity_type, (int) $id );
        }
    }

    /**
     * Generated fixtures — game-type activities from this batch.
     *
     * @return object[]
     */
    private function demoFixtures(): array {
        global $wpdb;

        $ids = $this->registry->entityIds( 'activity' );
        if ( ! $ids ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, team_id, session_date FROM {$wpdb->prefix}tt_activities
              WHERE id IN ({$placeholders}) AND club_id = %d AND activity_type_key = 'game'
              ORDER BY session_date",
            ...array_merge( $ids, [ CurrentClub::id() ] )
        ) );
        return is_array( $rows ) ? $rows : [];
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
