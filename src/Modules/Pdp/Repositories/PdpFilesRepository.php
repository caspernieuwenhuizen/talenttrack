<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PdpStatus;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PdpFilesRepository — one row per (player, season).
 *
 * Files own conversations + at-most-one verdict. cycle_size is the
 * effective count of conversations, derived from the team override
 * → club default at create time.
 *
 * Every read scopes to `CurrentClub::id()`; every write tags the row
 * with the active club. Today the value is always 1 (single-tenant);
 * the pattern is enforced now so a future SaaS migration is one
 * filter-callback away. See `docs/architecture.md` § SaaS-readiness.
 */
class PdpFilesRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_pdp_files';
    }

    public function find( int $id, bool $include_archived = false ): ?object {
        if ( $id <= 0 ) return null;
        $archived_clause = $include_archived ? '' : 'AND archived_at IS NULL';
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d {$archived_clause}",
            $id, CurrentClub::id()
        ) );
        if ( ! $row ) return null;
        self::hydrate( $row );
        return $row;
    }

    /** @return object|null */
    public function findByPlayerSeason( int $player_id, int $season_id, bool $include_archived = false ): ?object {
        if ( $player_id <= 0 || $season_id <= 0 ) return null;
        $archived_clause = $include_archived ? '' : 'AND archived_at IS NULL';
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE player_id = %d AND season_id = %d AND club_id = %d {$archived_clause}",
            $player_id, $season_id, CurrentClub::id()
        ) );
        if ( ! $row ) return null;
        self::hydrate( $row );
        return $row;
    }

    /**
     * #1358 — recent PDP files for the player-profile PDP tab:
     * newest-first, archived rows INCLUDED (the tab treats the most
     * recent file as the active cycle and everything older as
     * history — an archived cycle is still history).
     *
     * @return object[] rows: id, status, season_id, created_at.
     */
    public function listRecentForPlayer( int $player_id, int $limit = 10 ): array {
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 50, $limit ) );
        $rows  = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT id, status, season_id, created_at FROM {$this->table}
              WHERE player_id = %d AND club_id = %d
              ORDER BY created_at DESC LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return object[] */
    public function listForCoach( int $coach_user_id, int $season_id, bool $include_archived = false ): array {
        if ( $coach_user_id <= 0 || $season_id <= 0 ) return [];
        $archived_clause = $include_archived ? '' : 'AND archived_at IS NULL';
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE owner_coach_id = %d AND season_id = %d AND club_id = %d {$archived_clause}
              ORDER BY updated_at DESC",
            $coach_user_id, $season_id, CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];
        foreach ( $rows as $row ) self::hydrate( $row );
        return $rows;
    }

    /** @return object[] */
    public function listForSeason( int $season_id, bool $include_archived = false ): array {
        if ( $season_id <= 0 ) return [];
        $archived_clause = $include_archived ? '' : 'AND archived_at IS NULL';
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE season_id = %d AND club_id = %d {$archived_clause}
              ORDER BY updated_at DESC",
            $season_id, CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];
        foreach ( $rows as $row ) self::hydrate( $row );
        return $rows;
    }

    /**
     * #1274 PR1 — soft archive a PDP file. Updates `archived_at = NOW()`
     * iff the row exists, belongs to the current club, and is not
     * already archived. Returns true only when a row was actually
     * touched — a stale double-click on an already-archived file
     * returns false so the caller can flash a "already archived" hint
     * instead of a false-positive success.
     */
    public function archive( int $id ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id(), 'archived_at' => null ]
        );
        return is_int( $ok ) && $ok > 0;
    }

    /**
     * #1274 PR1 — restore a soft-archived PDP file. Symmetric inverse
     * of archive(). Gated on the new `tt_unarchive_pdp` cap at the
     * REST + UI layer; the repo method itself is just the write.
     */
    public function restore( int $id ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'archived_at' => null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * #1274 PR2 — count active (non-archived) PDP files for a player.
     * Used by the player-archive cascade to populate the confirm
     * modal's "Archiving this player will also archive N PDPs" line.
     */
    public function countActiveForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE player_id = %d AND club_id = %d AND archived_at IS NULL",
            $player_id, CurrentClub::id()
        ) );
    }

    /**
     * #1385 — count of PDP conversations that have actually been
     * conducted (`conducted_at` set) across a player's non-archived PDP
     * files. Powers the `MyPdpConversationsDone` player KPI.
     */
    public function countConductedConversationsForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;
        $conv = $this->wpdb->prefix . 'tt_pdp_conversations';
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$conv} c
               JOIN {$this->table} f ON f.id = c.pdp_file_id
              WHERE f.player_id = %d AND f.club_id = %d AND f.archived_at IS NULL
                AND c.conducted_at IS NOT NULL",
            $player_id, CurrentClub::id()
        ) );
    }

    /**
     * #1274 PR2 — bulk-archive every active PDP attached to a player.
     * Returns the count of rows touched. Caller wraps in a transaction
     * with the player-archive write.
     */
    public function archiveAllForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;
        $n = $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$this->table}
                SET archived_at = %s
              WHERE player_id = %d AND club_id = %d AND archived_at IS NULL",
            current_time( 'mysql', true ),
            $player_id,
            CurrentClub::id()
        ) );
        return is_int( $n ) ? $n : 0;
    }

    /**
     * #1080 — decorate a row in place with `status_localised` from
     * the `pdp_status` lookup. Raw `status` stays for back-compat
     * (filter dropdowns + the upsert gate against `PdpStatus::ALL`).
     * Same pattern as `PdpVerdictsRepository::label()` resolved
     * through `LookupTranslator::byTypeAndName` with the canonical
     * English fallback. Closes the #806 / #1080 module-by-module
     * slice for Pdp.
     */
    private static function hydrate( object $row ): void {
        $raw = (string) ( $row->status ?? '' );
        if ( $raw === '' ) {
            $row->status_localised = '';
            return;
        }
        $label = LookupTranslator::byTypeAndName( 'pdp_status', $raw );
        if ( ! is_string( $label ) || $label === '' || $label === $raw ) {
            // Canonical English fallback when the lookup row isn't
            // seeded yet (fresh install pre-migration 0112).
            switch ( $raw ) {
                case PdpStatus::OPEN:      $label = __( 'Open',      'talenttrack' ); break;
                case PdpStatus::COMPLETED: $label = __( 'Completed', 'talenttrack' ); break;
                case PdpStatus::ARCHIVED:  $label = __( 'Archived',  'talenttrack' ); break;
                default: $label = $raw;
            }
        }
        $row->status_localised = $label;
    }

    /**
     * @param array{
     *   player_id:int, season_id:int,
     *   owner_coach_id?:int|null,
     *   cycle_size?:int|null,
     *   notes?:string,
     * } $data
     * @return int Inserted ID, or 0 on failure or duplicate.
     */
    public function create( array $data ): int {
        $player_id = (int) ( $data['player_id'] ?? 0 );
        $season_id = (int) ( $data['season_id'] ?? 0 );
        if ( $player_id <= 0 || $season_id <= 0 ) return 0;

        if ( $this->findByPlayerSeason( $player_id, $season_id ) ) return 0;

        $ok = $this->wpdb->insert( $this->table, [
            'club_id'        => CurrentClub::id(),
            'player_id'      => $player_id,
            'season_id'      => $season_id,
            'owner_coach_id' => isset( $data['owner_coach_id'] ) ? (int) $data['owner_coach_id'] : null,
            'cycle_size'     => isset( $data['cycle_size'] ) ? (int) $data['cycle_size'] : null,
            'status'         => PdpStatus::OPEN,
            'notes'          => isset( $data['notes'] ) ? (string) $data['notes'] : null,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * #1617 — player-centric PDP coverage for a season.
     *
     * Player-first (CLAUDE.md §1): the row spine is the PLAYER, not
     * the file. Every active player in the requested scope is returned
     * exactly once, LEFT JOINed against this season's PDP file so the
     * caller can see at a glance who HAS a PDP and who does NOT. The
     * `pdp_file_id` is null for players with no file this season; that
     * null is the "Not started" signal.
     *
     * Progress (`conv_total` / `conv_conducted`) lets the caller show
     * "1 / 3 conversations" on covered rows without a second query per
     * player. Archived files are excluded — an archived cycle is not
     * "covered" for the purpose of "who still needs a PDP".
     *
     * Scope is the caller's responsibility: pass `player_ids` already
     * narrowed to the coach's roster (admins pass null = every active
     * player). This keeps the coach-vs-admin authorization decision in
     * one place (the REST controller / view) rather than re-deriving
     * teams here.
     *
     * #3810 — two more counts ride along, because the head of development's
     * question is not "does this player have a file" but "is this round
     * actually happening": `conv_scheduled_soon` (planned in the next four
     * weeks and not yet held) and `conv_parent_acked`. Both are correlated
     * subqueries over the same joined file, like the two that were already
     * here; a coverage list is one roster, so this stays one query.
     *
     * `conducted_none` is the "who has not had their talk" filter. It reads
     * as a `NOT EXISTS` against the conversations rather than a comparison
     * on the projected count, so it narrows the same rows the count
     * describes without a HAVING clause the paging would then have to
     * respect.
     *
     * @param int        $season_id  current season id
     * @param array{
     *   player_ids?: int[]|null,
     *   team_id?: int,
     *   search?: string,
     *   only_missing?: bool,
     *   conducted_none?: bool,
     *   archived_view?: string,
     * } $filters
     * @return object[] one row per player: player_id, first_name,
     *   last_name, team_id, team_name, pdp_file_id, file_status,
     *   conv_total, conv_conducted, conv_scheduled_soon, conv_parent_acked.
     */
    public function coverageForSeason( int $season_id, array $filters = [] ): array {
        if ( $season_id <= 0 ) return [];
        $conv = $this->wpdb->prefix . 'tt_pdp_conversations';
        $players = $this->wpdb->prefix . 'tt_players';
        $teams   = $this->wpdb->prefix . 'tt_teams';

        $where  = [ "pl.status = 'active'", 'pl.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        // Coach scope — restrict to a roster the caller already
        // resolved. An empty array means "no players in scope" → no
        // rows (a coach with no teams sees nothing, which is correct).
        $player_ids = $filters['player_ids'] ?? null;
        if ( is_array( $player_ids ) ) {
            $ids = array_values( array_filter( array_map( 'intval', $player_ids ), static fn( $i ) => $i > 0 ) );
            if ( empty( $ids ) ) return [];
            $place    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $where[]  = "pl.id IN ({$place})";
            $params   = array_merge( $params, $ids );
        }

        if ( ! empty( $filters['team_id'] ) ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = (int) $filters['team_id'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $like     = '%' . $this->wpdb->esc_like( (string) $filters['search'] ) . '%';
            $where[]  = '(pl.first_name LIKE %s OR pl.last_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // #2040 — Active / Archived record-state view. 'active' (the
        // default) joins the non-archived file, so a player with only an
        // archived cycle reads as "Not started"; 'archived' joins the
        // archived file instead and keeps only the players who actually
        // have one (managed for Restore / permanent-delete).
        $archived_view = ( ( $filters['archived_view'] ?? 'active' ) === 'archived' ) ? 'archived' : 'active';
        $file_archive_clause = $archived_view === 'archived'
            ? 'AND f.archived_at IS NOT NULL'
            : 'AND f.archived_at IS NULL';

        $sql = "SELECT pl.id AS player_id, pl.first_name, pl.last_name,
                       pl.team_id, t.name AS team_name,
                       f.id AS pdp_file_id, f.status AS file_status, f.archived_at,
                       (SELECT COUNT(*) FROM {$conv} c WHERE c.pdp_file_id = f.id) AS conv_total,
                       (SELECT COUNT(*) FROM {$conv} c WHERE c.pdp_file_id = f.id AND c.conducted_at IS NOT NULL) AS conv_conducted,
                       (SELECT COUNT(*) FROM {$conv} c
                         WHERE c.pdp_file_id = f.id
                           AND c.conducted_at IS NULL
                           AND c.scheduled_at IS NOT NULL
                           AND c.scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 28 DAY)) AS conv_scheduled_soon,
                       (SELECT COUNT(*) FROM {$conv} c WHERE c.pdp_file_id = f.id AND c.parent_ack_at IS NOT NULL) AS conv_parent_acked
                  FROM {$players} pl
                  LEFT JOIN {$teams} t ON t.id = pl.team_id
                  LEFT JOIN {$this->table} f
                         ON f.player_id = pl.id
                        AND f.season_id = %d
                        AND f.club_id = %d
                        {$file_archive_clause}
                 WHERE {$where_sql}";

        if ( $archived_view === 'archived' ) {
            // Archived view lists only players with an archived file.
            $sql .= ' AND f.id IS NOT NULL';
        } else {
            // Both narrowing toggles are meaningless in the archived view,
            // so they live inside the `else` rather than each re-testing
            // the same thing.
            if ( ! empty( $filters['only_missing'] ) ) {
                // only_missing filters AFTER the join so it reads off the
                // joined file row.
                $sql .= ' AND f.id IS NULL';
            }
            // #3810 — "who has not had their talk", in one call. A player
            // with no file at all has had no talk either, so they stay in:
            // the question is about the player, not about the paperwork,
            // and dropping them would hide the worst cases from the list
            // built to find them.
            if ( ! empty( $filters['conducted_none'] ) ) {
                $sql .= " AND NOT EXISTS (
                            SELECT 1 FROM {$conv} c
                             WHERE c.pdp_file_id = f.id
                               AND c.conducted_at IS NOT NULL )";
            }
        }

        $sql .= ' ORDER BY pl.last_name ASC, pl.first_name ASC';

        // season_id + club_id for the join precede the WHERE params.
        $all = array_merge( [ $season_id, CurrentClub::id() ], $params );
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$all ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1617 — coverage counts for the summary line
     * ("14 of 18 players have a PDP this season"). Returns
     * `[ 'total' => N, 'covered' => M ]` for the same scope/filters
     * `coverageForSeason()` uses (minus `only_missing`, which would
     * make the ratio meaningless).
     *
     * @param array{ player_ids?: int[]|null, team_id?: int, search?: string,
     *               only_missing?: bool, conducted_none?: bool, archived_view?: string } $filters
     * @return array{ total:int, covered:int }
     */
    public function coverageSummaryForSeason( int $season_id, array $filters = [] ): array {
        unset( $filters['only_missing'], $filters['conducted_none'] );
        $rows = $this->coverageForSeason( $season_id, $filters );
        $total   = count( $rows );
        $covered = 0;
        foreach ( $rows as $r ) {
            if ( ! empty( $r->pdp_file_id ) ) $covered++;
        }
        return [ 'total' => $total, 'covered' => $covered ];
    }

    /**
     * #3810 — the same coverage, broken down by team.
     *
     * Seven weeks into a season, one talk of sixty-four had been held and
     * the head of development had no way to see which teams those
     * sixty-three sat in: the screen shows one team at a time, and the
     * ratio above it is a single number for whatever is on screen. Four
     * coaches got chased by text instead.
     *
     * Computed from the same unpaged row set `coverageSummaryForSeason()`
     * reads, so the breakdown and the headline cannot disagree and a total
     * never changes when you turn the page.
     *
     * `only_missing` and `conducted_none` are dropped for the same reason
     * the headline drops them: they are ways of narrowing a list to look
     * at, and "1 of 1 players have had their talk" is not a coverage
     * figure. The scope filters — team, search, the caller's roster —
     * stay, so a coach still sees only their own players.
     *
     * Ordered worst first. The team that needs chasing is the one this
     * list exists to surface, and making the reader sort to find it is how
     * a report becomes something nobody opens.
     *
     * @param array{ player_ids?: int[]|null, team_id?: int, search?: string,
     *               only_missing?: bool, conducted_none?: bool, archived_view?: string } $filters
     * @return list<array{ team_id:int, team_name:string, players:int, covered:int,
     *                     conducted:int, scheduled_soon:int, parent_acked:int }>
     */
    public function coverageByTeamForSeason( int $season_id, array $filters = [] ): array {
        unset( $filters['only_missing'], $filters['conducted_none'] );

        /** @var array<int, array{ team_id:int, team_name:string, players:int, covered:int, conducted:int, scheduled_soon:int, parent_acked:int }> $by_team */
        $by_team = [];

        foreach ( $this->coverageForSeason( $season_id, $filters ) as $row ) {
            $team_id = (int) ( $row->team_id ?? 0 );
            if ( ! isset( $by_team[ $team_id ] ) ) {
                $by_team[ $team_id ] = [
                    'team_id'        => $team_id,
                    'team_name'      => (string) ( $row->team_name ?? '' ),
                    'players'        => 0,
                    'covered'        => 0,
                    'conducted'      => 0,
                    'scheduled_soon' => 0,
                    'parent_acked'   => 0,
                ];
            }

            $by_team[ $team_id ]['players']++;
            if ( ! empty( $row->pdp_file_id ) ) $by_team[ $team_id ]['covered']++;
            // A player counts once towards "has had a talk", however many
            // talks their cycle holds. The question is about people.
            if ( (int) ( $row->conv_conducted ?? 0 ) > 0 )      $by_team[ $team_id ]['conducted']++;
            if ( (int) ( $row->conv_scheduled_soon ?? 0 ) > 0 ) $by_team[ $team_id ]['scheduled_soon']++;
            if ( (int) ( $row->conv_parent_acked ?? 0 ) > 0 )   $by_team[ $team_id ]['parent_acked']++;
        }

        $out = array_values( $by_team );
        usort( $out, static function ( array $a, array $b ): int {
            $a_share = $a['players'] > 0 ? $a['conducted'] / $a['players'] : 0.0;
            $b_share = $b['players'] > 0 ? $b['conducted'] / $b['players'] : 0.0;
            if ( $a_share !== $b_share ) return $a_share <=> $b_share;
            return strcasecmp( $a['team_name'], $b['team_name'] );
        } );

        return $out;
    }

    public function setStatus( int $file_id, string $status ): bool {
        if ( $file_id <= 0 || ! PdpStatus::isValid( $status ) ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'status' => $status ],
            [ 'id' => $file_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    public function setOwner( int $file_id, ?int $coach_user_id ): bool {
        if ( $file_id <= 0 ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'owner_coach_id' => $coach_user_id !== null ? (int) $coach_user_id : null ],
            [ 'id' => $file_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }
}
