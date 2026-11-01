<?php
namespace TT\Infrastructure\People;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationHelpers;
use TT\Infrastructure\Database\Transaction;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\TableExists;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PersonDeletionCascade — orchestrates the per-table cleanup that must
 * accompany a permanent delete of one or more `tt_people` rows.
 *
 * #1138 (v4.20.0). The pilot ask is explicit: every person must be
 * deletable, no block rules. Two relationship classes:
 *
 *   CASCADE DELETE — the row exists only because the person exists.
 *     tt_team_people, tt_user_role_scopes (rows where person_id = X),
 *     tt_staff_pdp, tt_staff_certifications, tt_staff_evaluations (and
 *     their tt_staff_eval_ratings), tt_staff_goals,
 *     tt_staff_mentorships (mentor or mentee = X),
 *     tt_invitations (target_person_id = X AND status IN ('pending','expired')).
 *
 *   SET NULL — the row means something on its own; nulling preserves it.
 *     tt_user_role_scopes.granted_by_person_id, tt_players.parent_person_id,
 *     tt_invitations.target_person_id where status = 'accepted'.
 *
 * The wp_user account is NOT touched. Audit-log and other tables that
 * reference `wp_users.ID` (coach_id, created_by, etc.) keep working.
 *
 * Every write is limited to the current club's rows.
 *
 * All operations run in a single transaction. On any failure, the whole
 * batch rolls back; partial-state on failure is impossible.
 */
class PersonDeletionCascade {

    /**
     * Build the per-person impact summary that the impact-preview dialog
     * renders before the operator confirms. Read-only; no writes.
     *
     * @param int[] $person_ids
     * @return array{
     *   persons: list<array{
     *     id:int,
     *     display_name:string,
     *     removals: list<array<string,mixed>>,
     *     nullifications: list<array<string,mixed>>,
     *   }>,
     *   batch_summary: array{total_persons:int, total_affected_rows:int}
     * }
     */
    public function preview( array $person_ids ): array {
        $ids = $this->cleanIds( $person_ids );
        if ( empty( $ids ) ) {
            return [ 'persons' => [], 'batch_summary' => [ 'total_persons' => 0, 'total_affected_rows' => 0 ] ];
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $club = CurrentClub::id();

        $rows = $this->fetchPersons( $ids );
        $total_affected = 0;
        $out = [];

        foreach ( $rows as $person ) {
            $pid = (int) $person->id;
            $removals = [];
            $nulls    = [];

            // tt_team_people — team-role removals (human-readable team + role)
            $team_roles = $wpdb->get_results( $wpdb->prepare(
                "SELECT tp.role_in_team, tp.functional_role_id, t.name AS team_name, fr.label AS role_label, fr.role_key AS role_key
                 FROM {$p}tt_team_people tp
                 LEFT JOIN {$p}tt_teams t ON t.id = tp.team_id
                 LEFT JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id
                 WHERE tp.person_id = %d AND tp.club_id = %d",
                $pid, $club
            ) );
            foreach ( (array) $team_roles as $tr ) {
                $removals[] = [
                    'kind'  => 'team_role',
                    // #4523 — the translated label first, then the stored one.
                    'role'  => (string) ( \TT\Infrastructure\Query\LabelTranslator::functionalRoleLabel( (string) ( $tr->role_key ?? '' ), $tr->functional_role_id ? (int) $tr->functional_role_id : null ) ?: ( $tr->role_label ?: $tr->role_in_team ?: __( 'staff', 'talenttrack' ) ) ),
                    'team'  => (string) ( $tr->team_name ?: __( '(unknown team)', 'talenttrack' ) ),
                ];
                $total_affected++;
            }

            // tt_user_role_scopes — target person (cascade) + grantor (set-null)
            $scope_target_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_user_role_scopes WHERE person_id = %d AND club_id = %d",
                $pid, $club
            ) );
            if ( $scope_target_count > 0 ) {
                $removals[] = [ 'kind' => 'role_scope_target', 'count' => $scope_target_count ];
                $total_affected += $scope_target_count;
            }
            $scope_grantor_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_user_role_scopes WHERE granted_by_person_id = %d AND club_id = %d",
                $pid, $club
            ) );
            if ( $scope_grantor_count > 0 ) {
                $nulls[] = [
                    'kind'  => 'scope_grantor',
                    'count' => $scope_grantor_count,
                    'note'  => __( 'grants stay; "granted by" becomes unknown', 'talenttrack' ),
                ];
                $total_affected += $scope_grantor_count;
            }

            // tt_staff_* — per-table cascade counts
            foreach ( [
                'staff_development'  => 'tt_staff_pdp',
                'staff_certifications' => 'tt_staff_certifications',
                'staff_evaluations'  => 'tt_staff_evaluations',
                'staff_goals'        => 'tt_staff_goals',
            ] as $kind => $table ) {
                if ( ! TableExists::has( $table ) ) continue;
                $n = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}{$table} WHERE person_id = %d AND club_id = %d",
                    $pid, $club
                ) );
                if ( $n > 0 ) {
                    $removals[] = [ 'kind' => $kind, 'count' => $n ];
                    $total_affected += $n;
                }
            }

            // tt_staff_eval_ratings — through the person's staff evaluations
            if ( TableExists::has( 'tt_staff_eval_ratings' ) && TableExists::has( 'tt_staff_evaluations' ) ) {
                $ratings_n = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}tt_staff_eval_ratings
                     WHERE evaluation_id IN (
                         SELECT id FROM {$p}tt_staff_evaluations
                         WHERE person_id = %d AND club_id = %d
                     )",
                    $pid, $club
                ) );
                if ( $ratings_n > 0 ) {
                    $removals[] = [ 'kind' => 'staff_eval_ratings', 'count' => $ratings_n ];
                    $total_affected += $ratings_n;
                }
            }

            // tt_staff_mentorships — mentor OR mentee
            $mentor_n = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_staff_mentorships WHERE ( mentor_person_id = %d OR mentee_person_id = %d ) AND club_id = %d",
                $pid, $pid, $club
            ) );
            if ( $mentor_n > 0 ) {
                $removals[] = [ 'kind' => 'mentorship', 'count' => $mentor_n ];
                $total_affected += $mentor_n;
            }

            // tt_invitations — pending/expired (cascade) + accepted (null)
            $invite_cascade = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_invitations WHERE target_person_id = %d AND status IN ('pending','expired') AND club_id = %d",
                $pid, $club
            ) );
            if ( $invite_cascade > 0 ) {
                $removals[] = [ 'kind' => 'invitation_pending', 'count' => $invite_cascade ];
                $total_affected += $invite_cascade;
            }
            $invite_null = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_invitations WHERE target_person_id = %d AND status = 'accepted' AND club_id = %d",
                $pid, $club
            ) );
            if ( $invite_null > 0 ) {
                $nulls[] = [
                    'kind'  => 'invitation_accepted',
                    'count' => $invite_null,
                    'note'  => __( 'historical record stays; target reference cleared', 'talenttrack' ),
                ];
                $total_affected += $invite_null;
            }

            // #3572 — `tt_players.parent_person_id` is retired and read by
            // nothing, so it is no longer listed as a consequence here: a
            // player's parent is the `tt_player_parents` link, which a
            // person deletion does not touch. The set-null below stays, so
            // the column holds no dangling id until it is dropped.

            $out[] = [
                'id'             => $pid,
                'display_name'   => $this->displayNameFor( $person ),
                'removals'       => $removals,
                'nullifications' => $nulls,
            ];
        }

        return [
            'persons'       => $out,
            'batch_summary' => [
                'total_persons'       => count( $out ),
                'total_affected_rows' => $total_affected,
            ],
        ];
    }

    /**
     * Run the cascade for N person ids inside a single transaction.
     * Each step + the final DELETE FROM tt_people are guarded; any
     * failure rolls back the whole batch.
     *
     * @param int[] $person_ids
     * @return array{deleted:int, per_table:array<string,int>, nulled:array<string,int>}
     */
    public function cascade( array $person_ids ): array {
        $ids = $this->cleanIds( $person_ids );
        if ( empty( $ids ) ) {
            return [ 'deleted' => 0, 'per_table' => [], 'nulled' => [] ];
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $club = CurrentClub::id();
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $per_table = [];
        $nulled    = [];

        Transaction::begin();
        try {
            // Ratings hang off the evaluation, not the person, so they go
            // first, while the evaluations they point at still exist.
            if ( TableExists::has( 'tt_staff_eval_ratings' ) && TableExists::has( 'tt_staff_evaluations' ) ) {
                $sql_r = "DELETE FROM {$p}tt_staff_eval_ratings
                          WHERE evaluation_id IN (
                              SELECT id FROM {$p}tt_staff_evaluations
                              WHERE person_id IN ({$ph}) AND club_id = %d
                          )";
                $n_r = $wpdb->query( $wpdb->prepare( $sql_r, ...array_merge( $ids, [ $club ] ) ) );
                if ( $n_r === false ) {
                    throw new \RuntimeException( 'Cascade delete failed on tt_staff_eval_ratings: ' . $wpdb->last_error );
                }
                $per_table['tt_staff_eval_ratings'] = (int) $n_r;
            }

            // CASCADE — target_person_id-based deletes
            $cascade_targets = [
                'tt_team_people'         => 'person_id',
                'tt_user_role_scopes'    => 'person_id',
                // #4589 — the staff-development root table; this read
                // `tt_staff_development`, which no migration creates, so
                // every permanent person delete failed and rolled back.
                'tt_staff_pdp'           => 'person_id',
                'tt_staff_certifications' => 'person_id',
                'tt_staff_evaluations'   => 'person_id',
                'tt_staff_goals'         => 'person_id',
            ];
            foreach ( $cascade_targets as $table => $col ) {
                // A table an install does not have holds nothing to remove.
                if ( ! TableExists::has( $table ) ) continue;
                $sql = "DELETE FROM {$p}{$table} WHERE {$col} IN ({$ph}) AND club_id = %d";
                $n = $wpdb->query( $wpdb->prepare( $sql, ...array_merge( $ids, [ $club ] ) ) );
                if ( $n === false ) {
                    throw new \RuntimeException( 'Cascade delete failed on ' . $table . ': ' . $wpdb->last_error );
                }
                $per_table[ $table ] = (int) $n;
            }

            // tt_staff_mentorships — OR semantics on two columns
            if ( TableExists::has( 'tt_staff_mentorships' ) ) {
                $sql_m = "DELETE FROM {$p}tt_staff_mentorships
                          WHERE ( mentor_person_id IN ({$ph}) OR mentee_person_id IN ({$ph}) ) AND club_id = %d";
                $n_m = $wpdb->query( $wpdb->prepare( $sql_m, ...array_merge( $ids, $ids, [ $club ] ) ) );
                if ( $n_m === false ) {
                    throw new \RuntimeException( 'Cascade delete failed on tt_staff_mentorships: ' . $wpdb->last_error );
                }
                $per_table['tt_staff_mentorships'] = (int) $n_m;
            }

            // tt_invitations — only pending/expired cascade
            if ( TableExists::has( 'tt_invitations' ) ) {
                $sql_i = "DELETE FROM {$p}tt_invitations
                          WHERE target_person_id IN ({$ph}) AND status IN ('pending','expired') AND club_id = %d";
                $n_i = $wpdb->query( $wpdb->prepare( $sql_i, ...array_merge( $ids, [ $club ] ) ) );
                if ( $n_i === false ) {
                    throw new \RuntimeException( 'Cascade delete failed on tt_invitations (pending/expired): ' . $wpdb->last_error );
                }
                $per_table['tt_invitations'] = (int) $n_i;
            }

            // SET NULL
            $sql_grantor = "UPDATE {$p}tt_user_role_scopes
                            SET granted_by_person_id = NULL
                            WHERE granted_by_person_id IN ({$ph}) AND club_id = %d";
            $n_g = $wpdb->query( $wpdb->prepare( $sql_grantor, ...array_merge( $ids, [ $club ] ) ) );
            if ( $n_g === false ) {
                throw new \RuntimeException( 'Set-null failed on tt_user_role_scopes.granted_by_person_id: ' . $wpdb->last_error );
            }
            $nulled['tt_user_role_scopes.granted_by_person_id'] = (int) $n_g;

            $sql_inv_acc = "UPDATE {$p}tt_invitations
                            SET target_person_id = NULL
                            WHERE target_person_id IN ({$ph}) AND status = 'accepted' AND club_id = %d";
            $n_ia = $wpdb->query( $wpdb->prepare( $sql_inv_acc, ...array_merge( $ids, [ $club ] ) ) );
            if ( $n_ia === false ) {
                throw new \RuntimeException( 'Set-null failed on tt_invitations.target_person_id (accepted): ' . $wpdb->last_error );
            }
            $nulled['tt_invitations.target_person_id'] = (int) $n_ia;

            // The column is retired (#3572); once it is dropped there is
            // nothing to clear, and the delete must not fail on it.
            if ( MigrationHelpers::columnExists( $p . 'tt_players', 'parent_person_id' ) ) {
                $sql_parent = "UPDATE {$p}tt_players
                               SET parent_person_id = NULL
                               WHERE parent_person_id IN ({$ph}) AND club_id = %d";
                $n_pp = $wpdb->query( $wpdb->prepare( $sql_parent, ...array_merge( $ids, [ $club ] ) ) );
                if ( $n_pp === false ) {
                    throw new \RuntimeException( 'Set-null failed on tt_players.parent_person_id: ' . $wpdb->last_error );
                }
                $nulled['tt_players.parent_person_id'] = (int) $n_pp;
            }

            // Final delete — guarded by club_id
            $sql_final = "DELETE FROM {$p}tt_people WHERE id IN ({$ph}) AND club_id = %d";
            $deleted = $wpdb->query( $wpdb->prepare( $sql_final, ...array_merge( $ids, [ $club ] ) ) );
            if ( $deleted === false ) {
                throw new \RuntimeException( 'Final delete failed on tt_people: ' . $wpdb->last_error );
            }
        } catch ( \Throwable $e ) {
            Transaction::rollback();
            Logger::error( 'person.cascade.failed', [
                'person_ids' => $ids,
                'club_id'    => $club,
                'error'      => $e->getMessage(),
            ] );
            throw $e;
        }
        Transaction::commit();

        Logger::info( 'person.deleted_with_cascade', [
            'person_ids' => $ids,
            'club_id'    => $club,
            'deleted'    => (int) $deleted,
            'per_table'  => $per_table,
            'nulled'     => $nulled,
            'by_user'    => get_current_user_id(),
        ] );

        return [
            'deleted'   => (int) $deleted,
            'per_table' => $per_table,
            'nulled'    => $nulled,
        ];
    }

    /**
     * @param int[] $ids
     * @return list<object>
     */
    private function fetchPersons( array $ids ): array {
        global $wpdb;
        $p  = $wpdb->prefix;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT id, first_name, last_name, email FROM {$p}tt_people
                WHERE id IN ({$ph}) AND club_id = %d";
        $args = array_merge( $ids, [ CurrentClub::id() ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
        return is_array( $rows ) ? $rows : [];
    }

    private function displayNameFor( object $row ): string {
        $name = trim( ( (string) ( $row->first_name ?? '' ) ) . ' ' . ( (string) ( $row->last_name ?? '' ) ) );
        if ( $name !== '' ) return $name;
        return ( (string) ( $row->email ?? '' ) ) !== '' ? (string) $row->email : '#' . (int) $row->id;
    }

    /**
     * Positive, unique ids of people in the current club. An id from
     * another club never reaches the child deletes.
     *
     * @param int[] $raw
     * @return int[]
     */
    private function cleanIds( array $raw ): array {
        $out = [];
        foreach ( $raw as $v ) {
            $i = (int) $v;
            if ( $i > 0 ) $out[ $i ] = true;
        }
        if ( empty( $out ) ) return [];

        global $wpdb;
        $ids = array_keys( $out );
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT id FROM {$wpdb->prefix}tt_people
                WHERE id IN ({$ph}) AND club_id = %d";
        $rows = $wpdb->get_col( $wpdb->prepare( $sql, ...array_merge( $ids, [ CurrentClub::id() ] ) ) );
        return array_values( array_map( 'intval', (array) $rows ) );
    }
}
