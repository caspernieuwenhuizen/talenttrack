<?php
namespace TT\Modules\MatchAnalysis\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;

/**
 * MatchAnalysisRepository — CRUD across the three match-analysis tables
 * introduced by migration 0229.
 *
 * One class for the whole aggregate, following `MatchPrepRepository`: the
 * surface is small and the three tables are never read apart from each
 * other, so per-table repositories would only add indirection.
 *
 * Every read and write is club-scoped. That is a no-op on a single-tenant
 * install and load-bearing the day it is not (CLAUDE.md §4).
 */
class MatchAnalysisRepository {

    private \wpdb $wpdb;
    private string $t_analysis;
    private string $t_sections;
    private string $t_players;

    public function __construct() {
        global $wpdb;
        $this->wpdb       = $wpdb;
        $this->t_analysis = $wpdb->prefix . 'tt_match_analyses';
        $this->t_sections = $wpdb->prefix . 'tt_match_analysis_sections';
        $this->t_players  = $wpdb->prefix . 'tt_match_analysis_players';
    }

    public function findByActivity( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_analysis} WHERE activity_id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function find( int $analysis_id ): ?object {
        if ( $analysis_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_analysis} WHERE id = %d AND club_id = %d",
            $analysis_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Resolve a share URL's uuid to the analysis. Deliberately NOT
     * club-scoped: a share link is followed by someone with no session, so
     * there is no current club to scope to. The uuid is the lookup key and
     * the HMAC token is what actually authorises — see
     * `MatchAnalysisShareToken`.
     */
    public function findByUuid( string $uuid ): ?object {
        if ( $uuid === '' ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_analysis} WHERE uuid = %s",
            $uuid
        ) );
        return $row ?: null;
    }

    /**
     * Find-or-create the analysis row for an activity. Returns its id.
     *
     * The prep and execution ids are captured at creation so a printed or
     * shared analysis can still say which plan it was written against after
     * either is archived.
     */
    public function ensureForActivity( int $activity_id, ?int $match_prep_id = null, ?int $match_execution_id = null ): int {
        if ( $activity_id <= 0 ) return 0;

        $existing = $this->findByActivity( $activity_id );
        if ( $existing ) return (int) $existing->id;

        $now = current_time( 'mysql' );
        $this->wpdb->insert( $this->t_analysis, [
            'uuid'               => wp_generate_uuid4(),
            'club_id'            => CurrentClub::id(),
            'activity_id'        => $activity_id,
            'match_prep_id'      => $match_prep_id ?: null,
            'match_execution_id' => $match_execution_id ?: null,
            'status'             => MatchAnalysisEnums::STATUS_DRAFT,
            'created_by'         => get_current_user_id(),
            'created_at'         => $now,
            'updated_at'         => $now,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update( int $analysis_id, array $patch ): bool {
        if ( $analysis_id <= 0 || empty( $patch ) ) return false;

        $allowed = [ 'summary', 'status', 'match_prep_id', 'match_execution_id' ];
        $clean   = [];
        foreach ( $patch as $key => $value ) {
            if ( in_array( $key, $allowed, true ) ) $clean[ $key ] = $value;
        }
        if ( empty( $clean ) ) return false;

        $clean['updated_at'] = current_time( 'mysql' );

        return false !== $this->wpdb->update(
            $this->t_analysis,
            $clean,
            [ 'id' => $analysis_id, 'club_id' => CurrentClub::id() ]
        );
    }

    // -----------------------------------------------------------------
    // Sections
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{rating:?string, notes:string}> section key => row
     */
    public function listSections( int $analysis_id ): array {
        if ( $analysis_id <= 0 ) return [];

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT section_key, rating, notes FROM {$this->t_sections}
              WHERE analysis_id = %d AND club_id = %d",
            $analysis_id, CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row->section_key ] = [
                'rating' => $row->rating !== null && $row->rating !== '' ? (string) $row->rating : null,
                'notes'  => (string) ( $row->notes ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Upsert one section. An empty rating AND empty notes deletes the row:
     * a section a coach cleared should read as "nothing to say here", which
     * is the same state as never having written it, not as a row full of
     * blanks that later aggregation has to filter out.
     */
    public function saveSection( int $analysis_id, string $section_key, ?string $rating, string $notes ): bool {
        if ( $analysis_id <= 0 || ! MatchAnalysisEnums::isSectionKey( $section_key ) ) return false;

        $rating = $rating !== null && MatchAnalysisEnums::isRating( $rating ) ? $rating : null;
        $notes  = trim( $notes );

        if ( $rating === null && $notes === '' ) {
            return false !== $this->wpdb->delete( $this->t_sections, [
                'analysis_id' => $analysis_id,
                'section_key' => $section_key,
                'club_id'     => CurrentClub::id(),
            ] );
        }

        $existing = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$this->t_sections}
              WHERE analysis_id = %d AND section_key = %s AND club_id = %d",
            $analysis_id, $section_key, CurrentClub::id()
        ) );

        $data = [
            'rating'     => $rating,
            'notes'      => $notes,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $existing > 0 ) {
            return false !== $this->wpdb->update( $this->t_sections, $data, [ 'id' => $existing ] );
        }

        return false !== $this->wpdb->insert( $this->t_sections, $data + [
            'club_id'     => CurrentClub::id(),
            'analysis_id' => $analysis_id,
            'section_key' => $section_key,
        ] );
    }

    // -----------------------------------------------------------------
    // Player items
    // -----------------------------------------------------------------

    /**
     * @return array<int, array{marker:string, note:string, team_function:?string, minutes_played:?int}> player id => item
     */
    public function listPlayerItems( int $analysis_id ): array {
        if ( $analysis_id <= 0 ) return [];

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT player_id, marker, note, team_function, minutes_played
               FROM {$this->t_players}
              WHERE analysis_id = %d AND club_id = %d",
            $analysis_id, CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $pid = (int) $row->player_id;
            if ( $pid <= 0 ) continue;
            $out[ $pid ] = [
                'marker'         => (string) ( $row->marker ?? '' ),
                'note'           => (string) ( $row->note ?? '' ),
                'team_function'  => $row->team_function !== null && $row->team_function !== '' ? (string) $row->team_function : null,
                'minutes_played' => $row->minutes_played !== null ? (int) $row->minutes_played : null,
            ];
        }
        return $out;
    }

    /**
     * Upsert one player item. An item with neither a marker nor a note is
     * deleted rather than stored — "not mentioned" is the resting state of
     * every player on the roster, and storing it as a row would make the
     * table grow by squad size per match while saying nothing.
     *
     * Returns the row id on write, 0 on delete / no-op, so the caller can
     * decide whether a journey event should follow.
     */
    public function savePlayerItem(
        int $analysis_id,
        int $player_id,
        string $marker,
        string $note,
        ?string $team_function,
        ?int $minutes_played
    ): int {
        if ( $analysis_id <= 0 || $player_id <= 0 ) return 0;

        $marker        = MatchAnalysisEnums::isMarker( $marker ) ? $marker : '';
        $note          = trim( $note );
        $team_function = $team_function !== null && MatchAnalysisEnums::isPlayerItemTag( $team_function )
            ? $team_function
            : null;

        if ( $marker === '' && $note === '' ) {
            $this->deletePlayerItem( $analysis_id, $player_id );
            return 0;
        }

        $existing = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$this->t_players}
              WHERE analysis_id = %d AND player_id = %d AND club_id = %d",
            $analysis_id, $player_id, CurrentClub::id()
        ) );

        $data = [
            'marker'         => $marker,
            'note'           => $note,
            'team_function'  => $team_function,
            'minutes_played' => $minutes_played,
            'updated_at'     => current_time( 'mysql' ),
        ];

        if ( $existing > 0 ) {
            $this->wpdb->update( $this->t_players, $data, [ 'id' => $existing ] );
            return $existing;
        }

        $this->wpdb->insert( $this->t_players, $data + [
            'club_id'     => CurrentClub::id(),
            'analysis_id' => $analysis_id,
            'player_id'   => $player_id,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function deletePlayerItem( int $analysis_id, int $player_id ): bool {
        if ( $analysis_id <= 0 || $player_id <= 0 ) return false;
        return false !== $this->wpdb->delete( $this->t_players, [
            'analysis_id' => $analysis_id,
            'player_id'   => $player_id,
            'club_id'     => CurrentClub::id(),
        ] );
    }

    /**
     * The row id of one player item, or 0. The journey emitter keys its
     * idempotency on this id, so it needs to be resolvable after a save
     * without re-reading the whole set.
     */
    public function playerItemId( int $analysis_id, int $player_id ): int {
        if ( $analysis_id <= 0 || $player_id <= 0 ) return 0;
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$this->t_players}
              WHERE analysis_id = %d AND player_id = %d AND club_id = %d",
            $analysis_id, $player_id, CurrentClub::id()
        ) );
    }

    // -----------------------------------------------------------------
    // Share-link seed
    // -----------------------------------------------------------------

    /**
     * The analysis's share-token seed, initialising it on first read.
     *
     * Lazily rather than at insert: an analysis nobody has shared should
     * not be carrying a secret. Seeded from the uuid (cryptographically
     * random by construction) exactly as `TeamBlueprintsRepository` does.
     */
    public function ensureShareTokenSeed( int $analysis_id ): string {
        $row = $this->find( $analysis_id );
        if ( ! $row ) return '';

        $seed = (string) ( $row->share_token_seed ?? '' );
        if ( $seed !== '' ) return $seed;

        $seed = (string) $row->uuid;
        $this->wpdb->update(
            $this->t_analysis,
            [ 'share_token_seed' => $seed ],
            [ 'id' => $analysis_id, 'club_id' => CurrentClub::id() ]
        );
        return $seed;
    }

    /**
     * Replace the seed, invalidating every previously issued share URL.
     * Returns the new seed.
     */
    public function rotateShareTokenSeed( int $analysis_id ): string {
        if ( $analysis_id <= 0 ) return '';

        $seed = wp_generate_password( 16, false );
        $this->wpdb->update(
            $this->t_analysis,
            [ 'share_token_seed' => $seed ],
            [ 'id' => $analysis_id, 'club_id' => CurrentClub::id() ]
        );
        return $seed;
    }

    /**
     * Read the seed as stored, without initialising it. The share route
     * uses this: a link cannot be valid for an analysis that has never had
     * a link generated, and lazily creating the seed there would let a
     * guessed uuid mint one.
     */
    public function shareTokenSeed( int $analysis_id ): string {
        if ( $analysis_id <= 0 ) return '';
        return (string) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT share_token_seed FROM {$this->t_analysis} WHERE id = %d",
            $analysis_id
        ) );
    }
}
