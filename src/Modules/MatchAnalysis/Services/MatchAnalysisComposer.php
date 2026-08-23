<?php
namespace TT\Modules\MatchAnalysis\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Util\PlayerShortName;

/**
 * MatchAnalysisComposer — assembles everything a match analysis needs to
 * be rendered, printed, shared or returned over REST.
 *
 * This is where the module's business logic lives, and deliberately not in
 * the view (CLAUDE.md §4): which players count as having played, how a
 * match-prep goal maps onto a methodology section, what the result line
 * says when there is no execution row. Delete every file under
 * `Frontend/` and the REST API still answers all of it.
 *
 * The composer is tolerant by design. An analysis can be written for a
 * match that was never prepped, never run through the sideline tool, or
 * both — the coach who reviews a game they ran off a paper team sheet is
 * the common case at youth level, not the edge case. Everything the plan
 * or the live tool would have contributed simply reads empty.
 */
final class MatchAnalysisComposer {

    private MatchAnalysisRepository $repo;

    public function __construct( ?MatchAnalysisRepository $repo = null ) {
        $this->repo = $repo ?? new MatchAnalysisRepository();
    }

    /**
     * Is this activity one an analysis can be written for?
     *
     * Match-type only. Tournaments are excluded: a tournament day is
     * several games (#2686), and one analysis row per activity cannot say
     * which game it is about. Supporting them needs per-fixture records
     * first — until then the affordance is hidden rather than shown and
     * dead-ended.
     */
    public static function supportsActivity( ?object $activity ): bool {
        if ( ! $activity ) return false;
        $type = strtolower( (string) ( $activity->activity_type_key ?? '' ) );
        return in_array( $type, [ 'match', ActivityTypeKey::GAME ], true );
    }

    /**
     * Has the match been played? An analysis before kick-off is a
     * prediction, so the affordance stays disabled until the date has
     * passed or the sideline tool says the match is over.
     */
    public static function isReviewable( ?object $activity ): bool {
        if ( ! self::supportsActivity( $activity ) ) return false;

        $date = (string) ( $activity->session_date ?? '' );
        if ( $date !== '' && $date <= current_time( 'Y-m-d' ) ) return true;

        $exec = ( new MatchExecutionRepository() )->findByActivity( (int) $activity->id );
        if ( ! $exec ) return false;

        return \TT\Domain\Vocabularies\Enums\MatchExecutionState::isPostLive( (string) ( $exec->state ?? '' ) );
    }

    public static function activity( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * The whole payload for one activity's analysis. Returns null when the
     * activity does not exist or is not a match.
     *
     * `$create` writes the analysis row when it is missing — the read paths
     * (REST GET, share page, print) pass false so opening a page never
     * creates a record; the write paths pass true.
     *
     * @return array<string,mixed>|null
     */
    public function forActivity( int $activity_id, bool $create = false ): ?array {
        $activity = self::activity( $activity_id );
        if ( ! self::supportsActivity( $activity ) ) return null;

        $prep_repo = new MatchPrepRepository();
        $exec_repo = new MatchExecutionRepository();

        $prep = $prep_repo->findByActivity( $activity_id );
        $exec = $exec_repo->findByActivity( $activity_id );

        $analysis = $this->repo->findByActivity( $activity_id );
        if ( ! $analysis && $create ) {
            $this->repo->ensureForActivity(
                $activity_id,
                $prep ? (int) $prep->id : null,
                $exec ? (int) $exec->id : null
            );
            $analysis = $this->repo->findByActivity( $activity_id );
        }

        $analysis_id = $analysis ? (int) $analysis->id : 0;

        return [
            'activity'    => $activity,
            'analysis'    => $analysis,
            'analysis_id' => $analysis_id,
            'status'      => (string) ( $analysis->status ?? MatchAnalysisEnums::STATUS_DRAFT ),
            'summary'     => (string) ( $analysis->summary ?? '' ),
            'result'      => self::resultFor( $activity, $exec ),
            'sections'    => $this->sectionsFor( $analysis_id, $prep ),
            'players'     => $this->playersFor( $activity, $analysis_id, $prep, $prep_repo, $exec_repo ),
            'has_prep'    => (bool) $prep,
            'has_exec'    => (bool) $exec,
        ];
    }

    /**
     * The six sections, each carrying its label, what the coach wrote, and
     * what the plan asked for.
     *
     * Both match-prep set-piece boxes fold into the one Set pieces section,
     * joined rather than picked: a coach who planned "second ball on our
     * corners" and "no short corners against" planned both, and showing one
     * would quietly drop half the plan.
     *
     * @return array<string, array{key:string, label:string, rating:?string, notes:string, planned:string, rated:bool}>
     */
    public function sectionsFor( int $analysis_id, ?object $prep ): array {
        $saved  = $analysis_id > 0 ? $this->repo->listSections( $analysis_id ) : [];
        $rated  = MatchAnalysisEnums::ratedSectionKeys();
        $out    = [];

        foreach ( MatchAnalysisEnums::sectionKeys() as $key ) {
            $out[ $key ] = [
                'key'     => $key,
                'label'   => MatchAnalysisEnums::sectionLabel( $key ),
                'rating'  => $saved[ $key ]['rating'] ?? null,
                'notes'   => (string) ( $saved[ $key ]['notes'] ?? '' ),
                'planned' => self::plannedTextFor( $key, $prep ),
                'rated'   => in_array( $key, $rated, true ),
            ];
        }

        return $out;
    }

    /**
     * The plan text for one section, or '' when the plan never covered it.
     */
    public static function plannedTextFor( string $section_key, ?object $prep ): string {
        if ( ! $prep ) return '';

        // Since the set-piece split each section maps onto at most one goal
        // box, so there is nothing left to merge — the plan's own attacking
        // and defending set-piece lines land beside the matching phase.
        $column = MatchAnalysisEnums::prepGoalColumnFor( $section_key );
        if ( $column === null ) return '';

        return trim( (string) ( $prep->{$column} ?? '' ) );
    }

    /**
     * The result line. Prefers the activity's own score columns (which the
     * sideline tool writes back on finalize) and falls back to the
     * execution row, so a match whose score was typed on the activity form
     * still reads correctly.
     *
     * @return array{has_score:bool, home_score:?int, away_score:?int, home_away:string, opponent:string, state:string}
     */
    public static function resultFor( object $activity, ?object $exec ): array {
        $home = $activity->home_score ?? ( $exec->home_score ?? null );
        $away = $activity->away_score ?? ( $exec->away_score ?? null );

        return [
            'has_score'  => $home !== null && $away !== null,
            'home_score' => $home !== null ? (int) $home : null,
            'away_score' => $away !== null ? (int) $away : null,
            'home_away'  => (string) ( $activity->home_away ?? '' ),
            'opponent'   => (string) ( $activity->opponent ?? '' ),
            'state'      => (string) ( $exec->state ?? '' ),
        ];
    }

    /**
     * Every player who played, with their minutes, whatever the coach has
     * already written about them, and what the plan asked them to do.
     *
     * "Played" is resolved in four steps, each falling back to the next:
     * recorded minutes, then actual attendance, then the prep's
     * availability, then the team roster. The last is what makes an
     * analysis possible for a match nobody prepped or ran — the coach
     * still remembers who was there, and the surface should not be empty
     * because the paperwork was.
     *
     * @return list<array<string,mixed>>
     */
    public function playersFor(
        object $activity,
        int $analysis_id,
        ?object $prep,
        MatchPrepRepository $prep_repo,
        MatchExecutionRepository $exec_repo
    ): array {
        $activity_id = (int) $activity->id;

        $minutes = $exec_repo->loggedMinutesByActivity( $activity_id );
        $ids     = array_keys( $minutes );

        if ( empty( $ids ) ) $ids = self::attendedPlayerIds( $activity_id );
        if ( empty( $ids ) && $prep ) $ids = self::availablePlayerIds( $prep_repo, (int) $prep->id );
        if ( empty( $ids ) ) $ids = self::rosterPlayerIds( (int) ( $activity->team_id ?? 0 ) );
        if ( empty( $ids ) ) return [];

        $players = self::playerRows( $ids );
        if ( empty( $players ) ) return [];

        $short   = PlayerShortName::resolve( $players );
        $saved   = $analysis_id > 0 ? $this->repo->listPlayerItems( $analysis_id ) : [];
        $tracked = $prep ? $prep_repo->listTrackedPlayers( (int) $prep->id ) : [];

        $out = [];
        foreach ( $players as $player ) {
            $pid  = (int) $player->id;
            $item = $saved[ $pid ] ?? null;

            $out[] = [
                'player_id'      => $pid,
                'name'           => (string) ( $short[ $pid ] ?? QueryHelpers::player_display_name( $player ) ),
                'full_name'      => (string) QueryHelpers::player_display_name( $player ),
                'minutes'        => $minutes[ $pid ] ?? ( $item['minutes_played'] ?? null ),
                'marker'         => (string) ( $item['marker'] ?? '' ),
                'note'           => (string) ( $item['note'] ?? '' ),
                'team_function'  => $item['team_function'] ?? null,
                'prep_focus'     => (string) ( $tracked[ $pid ]['attention_text'] ?? '' ),
                'prep_specific'  => (bool) ( $tracked[ $pid ]['is_specific_goal'] ?? false ),
                'prep_analyst'   => (bool) ( $tracked[ $pid ]['analyst_appointed'] ?? false ),
            ];
        }

        return $out;
    }

    /** @return list<int> */
    private static function attendedPlayerIds( int $activity_id ): array {
        global $wpdb;

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT player_id
               FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND club_id = %d
                AND is_guest = 0
                AND record_type = 'actual'
                AND status IN ( 'present', 'late' )",
            $activity_id, CurrentClub::id()
        ) );

        return array_values( array_filter( array_map( 'intval', (array) $rows ) ) );
    }

    /** @return list<int> */
    private static function availablePlayerIds( MatchPrepRepository $prep_repo, int $prep_id ): array {
        $out = [];
        foreach ( $prep_repo->listAvailability( $prep_id ) as $row ) {
            if ( strcasecmp( (string) ( $row->status ?? '' ), 'present' ) !== 0 ) continue;
            $pid = (int) ( $row->player_id ?? 0 );
            if ( $pid > 0 ) $out[] = $pid;
        }
        return $out;
    }

    /** @return list<int> */
    private static function rosterPlayerIds( int $team_id ): array {
        if ( $team_id <= 0 ) return [];

        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL",
            $team_id, CurrentClub::id()
        ) );

        return array_values( array_filter( array_map( 'intval', (array) $rows ) ) );
    }

    /**
     * @param list<int> $ids
     * @return list<object>
     */
    private static function playerRows( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( empty( $ids ) ) return [];

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params       = array_merge( $ids, [ CurrentClub::id() ] );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players
              WHERE id IN ({$placeholders}) AND club_id = %d
           ORDER BY last_name ASC, first_name ASC",
            ...$params
        ) );

        return is_array( $rows ) ? $rows : [];
    }
}
