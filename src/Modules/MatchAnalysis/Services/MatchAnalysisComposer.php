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
            'goals'       => self::goalsFor( $prep, $exec, $exec_repo ),
            'has_prep'    => (bool) $prep,
            'has_exec'    => (bool) $exec,
        ];
    }

    /**
     * #2860 — the goals that made the result, in the order they happened.
     *
     * The analysis and the match-execution log describe the same game and
     * were not on speaking terms: a coach reading the readback could see
     * which team functions were rated and how the game ended, but not when
     * the goals came or who scored them. A run of three conceded inside ten
     * minutes is context for the defending-phase rating sitting beside it,
     * and without the timeline that context lived on another screen.
     *
     * Read-only, and deliberately so. Goals are edited on the match
     * execution surface; this renders them and nothing more.
     *
     * Minutes are made absolute across both halves so the list reads as one
     * game rather than two restarting clocks. Opponent goals carry no
     * scorer — their squad is not in the system — and our unattributed and
     * own goals keep their own states rather than borrowing a name.
     *
     * @return list<array{
     *     minute:int, half:int, team:string, is_own_goal:bool,
     *     scorer:string, assist:string, has_scorer:bool
     * }>
     */
    public static function goalsFor( ?object $prep, ?object $exec, MatchExecutionRepository $exec_repo ): array {
        if ( ! $exec ) return [];

        $events = $exec_repo->listGoalEvents( (int) $exec->id );
        if ( empty( $events ) ) return [];

        // A wpdb row is a bag of columns, so read it as one: the prep may be
        // absent entirely, and the column may not be set on an old row.
        $prep_columns = $prep ? get_object_vars( $prep ) : [];
        $half_length  = (int) ( $prep_columns['half_length_minutes'] ?? 0 );
        if ( $half_length <= 0 ) $half_length = 35;

        $ids = [];
        foreach ( $events as $ge ) {
            $pid = (int) ( $ge->player_id ?? 0 );
            $aid = (int) ( $ge->assist_player_id ?? 0 );
            if ( $pid > 0 ) $ids[] = $pid;
            if ( $aid > 0 ) $ids[] = $aid;
        }
        $ids = array_values( array_unique( $ids ) );

        $names = [];
        if ( ! empty( $ids ) ) {
            $players = self::playerRows( $ids );
            $short   = PlayerShortName::resolve( $players );
            foreach ( $players as $player ) {
                $pid = (int) $player->id;
                $names[ $pid ] = (string) ( $short[ $pid ] ?? QueryHelpers::player_display_name( $player ) );
            }
        }

        $out = [];
        foreach ( $events as $ge ) {
            $half   = (int) ( $ge->half ?? 1 ) === 2 ? 2 : 1;
            $minute = (int) ( $ge->minute_in_half ?? 0 );
            $pid    = (int) ( $ge->player_id ?? 0 );
            $aid    = (int) ( $ge->assist_player_id ?? 0 );
            $own    = ! empty( $ge->is_own_goal );
            $ours   = ( (string) ( $ge->team ?? 'home' ) === 'home' );

            // A scorer we cannot name is, for a reader, a scorer we do not
            // have: the id is set but resolves to no player row — a record
            // deleted outside the erasure path, or one from another club.
            // `has_scorer` follows the resolved name rather than the id, so
            // the readback says "Scorer not recorded" instead of printing an
            // empty space where a name belongs.
            $scorer_name = $pid > 0 ? (string) ( $names[ $pid ] ?? '' ) : '';
            $assist_name = $aid > 0 ? (string) ( $names[ $aid ] ?? '' ) : '';

            $out[] = [
                'minute'      => ( $half === 2 ? $half_length : 0 ) + $minute,
                'half'        => $half,
                'team'        => $ours ? 'home' : 'away',
                'is_own_goal' => $own,
                'scorer'      => $scorer_name,
                'assist'      => $assist_name,
                'has_scorer'  => $scorer_name !== '',
            ];
        }

        usort( $out, static function ( array $a, array $b ): int {
            return $a['minute'] <=> $b['minute'];
        } );

        return $out;
    }

    /**
     * Every section, each carrying its label, what the coach wrote, and
     * what the plan asked for: the overall read plus the two chains of
     * three.
     *
     * A row still stored under the pre-split `set_pieces` key is appended
     * as well, so an analysis written before the vocabulary changed keeps
     * its words even where migration 0231 could not move it. It is never
     * offered for writing — `ratedSectionKeys()` does not contain it — so
     * only the read-back ever sees it.
     *
     * @return array<string, array{key:string, label:string, rating:?string, notes:string, planned:string, rated:bool}>
     */
    public function sectionsFor( int $analysis_id, ?object $prep ): array {
        $saved  = $analysis_id > 0 ? $this->repo->listSections( $analysis_id ) : [];
        $rated  = MatchAnalysisEnums::ratedSectionKeys();
        $out    = [];

        foreach ( MatchAnalysisEnums::sectionKeys() as $key ) {
            $items = self::noteItems( $saved[ $key ] ?? [] );
            $out[ $key ] = [
                'key'     => $key,
                'label'   => MatchAnalysisEnums::sectionLabel( $key ),
                'rating'  => $saved[ $key ]['rating'] ?? null,
                'notes'   => self::joinBodies( $items ),
                'note_items' => $items,
                'planned' => self::plannedTextFor( $key, $prep ),
                'rated'   => in_array( $key, $rated, true ),
            ];
        }

        $legacy = MatchAnalysisEnums::SECTION_SET_PIECES_LEGACY;
        if ( isset( $saved[ $legacy ] ) ) {
            $items = self::noteItems( $saved[ $legacy ] );
            $out[ $legacy ] = [
                'key'     => $legacy,
                'label'   => MatchAnalysisEnums::sectionLabel( $legacy ),
                'rating'  => $saved[ $legacy ]['rating'] ?? null,
                'notes'   => self::joinBodies( $items ),
                'note_items' => $items,
                'planned' => '',
                'rated'   => false,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $saved
     * @return list<array{valence:string, body:string}>
     */
    private static function noteItems( array $saved ): array {
        $items = $saved['items'] ?? [];
        return is_array( $items ) ? array_values( $items ) : [];
    }

    /**
     * The bullets as one newline-joined string (#3091).
     *
     * Kept in the payload because it is what `notes` has always meant to
     * every reader of it — the print sheet, the share page, an integration.
     * It is now derived rather than stored, so it cannot drift from the
     * rows, and anything that wants the marks reads `note_items` instead.
     *
     * @param list<array{valence:string, body:string}> $items
     */
    private static function joinBodies( array $items ): string {
        $bodies = array_map(
            static fn( array $item ): string => (string) $item['body'],
            $items
        );

        return implode( "\n", array_filter( $bodies, static fn( string $b ): bool => $b !== '' ) );
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
                // `note` stays as the first line so every existing reader
                // keeps working; `note_items` is where the marks are.
                'note'           => self::joinBodies( self::noteItems( (array) ( $item ?? [] ) ) ),
                'note_items'     => self::noteItems( (array) ( $item ?? [] ) ),
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
