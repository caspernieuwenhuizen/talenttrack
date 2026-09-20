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
 *
 * `$wpdb` is read with `global` inside each method rather than captured
 * as a property. That is the house style, and it is what keeps the
 * level-8 gate happy: a `\wpdb`-typed property loses the `literal-string`
 * narrowing on `$wpdb->prefix`, and every `prepare()` built from it then
 * fails.
 */
class ProspectVisitObservationsRepository {

    private const TABLE = 'tt_prospect_visit_observations';

    /**
     * Record that `$prospect_id` was watched at `$visit_id`.
     *
     * Returns the observation id — the existing one when the pair is
     * already recorded, because linking twice is the same statement made
     * twice and not a second sighting.
     */
    public function link( int $prospect_id, int $visit_id, ?string $observed_at = null, string $notes = '' ): int {
        global $wpdb;
        if ( $prospect_id <= 0 || $visit_id <= 0 ) return 0;

        $existing = $this->findId( $prospect_id, $visit_id );
        if ( $existing > 0 ) return $existing;

        $ok = $wpdb->insert( $wpdb->prefix . self::TABLE, [
            'uuid'              => wp_generate_uuid4(),
            'club_id'           => CurrentClub::id(),
            'prospect_id'       => $prospect_id,
            'scouting_visit_id' => $visit_id,
            'observed_at'       => $observed_at !== null && $observed_at !== '' ? $observed_at : null,
            'notes'             => $notes !== '' ? $notes : null,
            'created_by'        => get_current_user_id(),
        ] );
        if ( $ok ) return (int) $wpdb->insert_id;

        // A concurrent writer won the unique key. Read theirs rather than
        // reporting a failure the user would have to make sense of.
        return $this->findId( $prospect_id, $visit_id );
    }

    /**
     * The id of the observation recording this pair, or 0 when the
     * prospect has not been logged at that visit.
     */
    public function findId( int $prospect_id, int $visit_id ): int {
        global $wpdb; $p = $wpdb->prefix;
        if ( $prospect_id <= 0 || $visit_id <= 0 ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_prospect_visit_observations
              WHERE club_id = %d AND prospect_id = %d AND scouting_visit_id = %d",
            CurrentClub::id(), $prospect_id, $visit_id
        ) );
    }

    public function find( int $prospect_id, int $visit_id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        if ( $prospect_id <= 0 || $visit_id <= 0 ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_prospect_visit_observations
              WHERE club_id = %d AND prospect_id = %d AND scouting_visit_id = %d",
            CurrentClub::id(), $prospect_id, $visit_id
        ) );
        return is_object( $row ) ? $row : null;
    }

    /**
     * One observation by id, as an associative row. An array rather than
     * an object because its caller reads two fields out of it, and a
     * `?object` return has no declared properties to read.
     *
     * @return array<string,mixed>|null
     */
    public function findById( int $id ): ?array {
        global $wpdb; $p = $wpdb->prefix;
        if ( $id <= 0 ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_prospect_visit_observations WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public function delete( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 ) return false;
        return (bool) $wpdb->delete( $wpdb->prefix . self::TABLE, [
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
        global $wpdb; $p = $wpdb->prefix;
        if ( $prospect_id <= 0 ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*, v.visit_date, v.location, v.event_description
               FROM {$p}tt_prospect_visit_observations o
         INNER JOIN {$p}tt_scouting_plan_visits v
                 ON v.id = o.scouting_visit_id AND v.club_id = o.club_id
              WHERE o.club_id = %d AND o.prospect_id = %d
           ORDER BY COALESCE(o.observed_at, v.visit_date) ASC, o.id ASC",
            CurrentClub::id(), $prospect_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** How many sightings this prospect has. */
    public function countForProspect( int $prospect_id ): int {
        global $wpdb; $p = $wpdb->prefix;
        if ( $prospect_id <= 0 ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_prospect_visit_observations
              WHERE club_id = %d AND prospect_id = %d",
            CurrentClub::id(), $prospect_id
        ) );
    }

    /**
     * The prospect ids observed at one visit.
     *
     * @return list<int>
     */
    public function prospectIdsForVisit( int $visit_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        if ( $visit_id <= 0 ) return [];
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT prospect_id FROM {$p}tt_prospect_visit_observations
              WHERE club_id = %d AND scouting_visit_id = %d",
            CurrentClub::id(), $visit_id
        ) );
        return array_map( 'intval', (array) $ids );
    }
}
