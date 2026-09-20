<?php
namespace TT\Modules\Prospects\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ProspectVisitObservationsRepository — `tt_prospect_visit_observations`.
 *
 * #3711. Before this table the link between a prospect and a scouting
 * visit was the single nullable `tt_prospects.scouting_visit_id`, so a
 * player watched twice could be recorded once. A scout who saw somebody
 * again either created a duplicate prospect or left the second sighting
 * unrecorded, and the journey question the prospects module exists to
 * answer — *where did this player come from, and when did we keep
 * looking?* — had no data behind it.
 *
 * The discovery column stays as the **first** sighting. This table is
 * every sighting, the first one included: the migration backfills it, so
 * a prospect that has only ever been seen once reads the same through
 * both.
 *
 * Every query filters on `club_id` (CLAUDE.md §4), and the unique key
 * `(club_id, prospect_id, scouting_visit_id)` is what makes `link()`
 * idempotent rather than duplicating a row on a double tap.
 */
class ProspectVisitObservationsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_prospect_visit_observations';
    }

    /**
     * Record that `$prospect_id` was watched at `$visit_id`.
     *
     * Returns the observation id — the existing one when the pair is
     * already recorded, because linking twice is the same statement made
     * twice and not a second sighting.
     */
    public function link( int $prospect_id, int $visit_id, ?string $observed_at = null, string $notes = '' ): int {
        if ( $prospect_id <= 0 || $visit_id <= 0 ) return 0;

        $existing = $this->find( $prospect_id, $visit_id );
        if ( $existing !== null ) return (int) $existing->id;

        $ok = $this->wpdb->insert( $this->table, [
            'uuid'              => wp_generate_uuid4(),
            'club_id'           => CurrentClub::id(),
            'prospect_id'       => $prospect_id,
            'scouting_visit_id' => $visit_id,
            'observed_at'       => $observed_at !== null && $observed_at !== '' ? $observed_at : null,
            'notes'             => $notes !== '' ? $notes : null,
            'created_by'        => get_current_user_id(),
        ] );
        if ( $ok ) return (int) $this->wpdb->insert_id;

        // A concurrent writer won the unique key. Read theirs rather than
        // reporting a failure the user would have to make sense of.
        $row = $this->find( $prospect_id, $visit_id );
        return $row !== null ? (int) $row->id : 0;
    }

    public function find( int $prospect_id, int $visit_id ): ?object {
        if ( $prospect_id <= 0 || $visit_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d AND prospect_id = %d AND scouting_visit_id = %d",
            CurrentClub::id(), $prospect_id, $visit_id
        ) );
        return $row ?: null;
    }

    public function findById( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;
        return (bool) $this->wpdb->delete( $this->table, [
            'id'      => $id,
            'club_id' => CurrentClub::id(),
        ] );
    }

    /**
     * Every sighting of one prospect, earliest first — the player's
     * scouting timeline, which is the shape §1 asks for.
     *
     * @return object[]
     */
    public function forProspect( int $prospect_id ): array {
        if ( $prospect_id <= 0 ) return [];
        $visits = $this->wpdb->prefix . 'tt_scouting_plan_visits';

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT o.*, v.visit_date, v.location, v.event_description
               FROM {$this->table} o
         INNER JOIN {$visits} v ON v.id = o.scouting_visit_id AND v.club_id = o.club_id
              WHERE o.club_id = %d AND o.prospect_id = %d
           ORDER BY COALESCE(o.observed_at, v.visit_date) ASC, o.id ASC",
            CurrentClub::id(), $prospect_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** How many sightings this prospect has. */
    public function countForProspect( int $prospect_id ): int {
        if ( $prospect_id <= 0 ) return 0;
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE club_id = %d AND prospect_id = %d",
            CurrentClub::id(), $prospect_id
        ) );
    }

    /**
     * The prospect ids observed at one visit.
     *
     * @return list<int>
     */
    public function prospectIdsForVisit( int $visit_id ): array {
        if ( $visit_id <= 0 ) return [];
        $ids = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT prospect_id FROM {$this->table} WHERE club_id = %d AND scouting_visit_id = %d",
            CurrentClub::id(), $visit_id
        ) );
        return array_values( array_map( 'intval', (array) $ids ) );
    }
}
