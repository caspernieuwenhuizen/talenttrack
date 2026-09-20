<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DemoDataCleaner — deletes demo-tagged content.
 *
 * Two flavours:
 *
 *   wipeData()  — removes every demo-tagged row EXCEPT users marked
 *                 persistent:true (the Rich set of 36). Walks the tag
 *                 table in dependency order so FKs / orphan checks stay
 *                 happy. Also re-binds the 5 player-slot WP users by
 *                 setting tt_players.wp_user_id = NULL before deleting
 *                 the player rows (#1772 — NULL is the canonical
 *                 "no account" value). Users themselves remain, as does
 *                 the player<N> slot tag.
 *
 *   wipeUsers() — removes the persistent demo users too. Three safety
 *                 rails per spec: email domain matches the configured
 *                 demo domain, user is not the current logged-in user,
 *                 user is not the last administrator on the site.
 *
 * Never touches a non-demo record. Every DELETE is gated by an id
 * membership check against tt_demo_tags.
 */
class DemoDataCleaner {

    /**
     * Entity types in dependency-safe delete order, and the table each one
     * lives in — both derived from `DemoCoverage`, so a generator declares
     * its wipe reach in the same place it declares itself. Before #2462
     * these were three hand-maintained constants that had to agree; the
     * Excel importer's `trial_case` and `player_event` tags were missing
     * from them, which left those rows permanently unwipeable.
     *
     * @return string[]
     */
    private static function dataOrder(): array {
        return DemoCoverage::deleteOrder();
    }

    /** @return array<string, array{0:string, 1:string}> entity_type => [table, id_column] */
    private static function tableMap(): array {
        return DemoCoverage::tableMap();
    }

    /**
     * Ids per `DELETE … WHERE id IN (…)` statement.
     *
     * #3813 — the wipe used to put one placeholder per tagged row in a
     * single statement. `tt_eval_ratings` reaches ~25 rows per evaluation,
     * so a medium batch produced a statement of roughly 300,000
     * placeholders, several megabytes long. MySQL refused it,
     * `$wpdb->query()` returned `false`, `(int) false` was recorded as
     * "0 rows deleted", and the tags were dropped anyway — leaving 298k
     * rows alive with nothing left to say they were demo data.
     *
     * 1,000 sits far inside any realistic `max_allowed_packet` while
     * keeping the statement count low enough not to matter.
     */
    private const DELETE_CHUNK = 1000;

    /**
     * Operator-facing categories the wipe form exposes. Kept as a constant
     * for back-compat with callers doing `array_keys( self::CATEGORIES )`;
     * the cascade semantics now live in `DemoCoverage::CATEGORIES`.
     */
    public const CATEGORIES = DemoCoverage::CATEGORIES;

    /**
     * @param string[]|null $categories Operator-picked categories from
     *   `CATEGORIES` keys (`['teams','activities',…]`). `null` falls back
     *   to all categories — the v3.85.0 "wipe everything" behaviour, kept
     *   for back-compat callers. Each category expands to its dependency
     *   cascade; the union is then deleted in dependency-safe order.
     * @param string|null $batch_id #0080 Wave B2 — optional batch
     *   filter. When set, the wipe is scoped to entities tagged with
     *   that `batch_id` only; the matching `tt_demo_tags` rows for
     *   that batch are also dropped. Other batches' demo rows survive.
     *   `null` / empty preserves the all-batches behaviour.
     * @return array<string,int|false> Rows deleted per entity type, or
     *   `false` for a type whose delete failed. #3813 — `0` and `false`
     *   are different answers: `0` means the batch held no rows of that
     *   type, `false` means the statement was refused and the rows are
     *   still there. A `false` also means the type's `tt_demo_tags` rows
     *   were deliberately left in place, so a second wipe can still find
     *   the rows; callers must report it rather than summing it away.
     */
    public static function wipeData( ?array $categories = null, ?string $batch_id = null ): array {
        global $wpdb;
        $deleted = [];
        $batch_id = ( $batch_id !== null && $batch_id !== '' ) ? $batch_id : null;

        $types_to_wipe = self::resolveTypes( $categories );

        // Unbind any player<N> WP users that currently point at demo
        // players, so the persistent users remain in a clean state.
        // Only fires when player rows are actually being wiped.
        if ( in_array( 'player', $types_to_wipe, true ) ) {
            $player_ids = DemoBatchRegistry::allEntityIds( 'player', $batch_id );
            // #1772 — unlink via NULL, not 0 (UNIQUE on
            // (club_id, wp_user_id) now rejects duplicate 0s).
            // #3813 — chunked like every other id-set statement here.
            foreach ( array_chunk( $player_ids, self::DELETE_CHUNK ) as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}tt_players SET wp_user_id = NULL WHERE id IN ({$placeholders}) AND club_id = %d",
                    ...array_merge( $chunk, [ CurrentClub::id() ] )
                ) );
            }
        }

        $table_map = self::tableMap();

        foreach ( self::dataOrder() as $type ) {
            if ( ! in_array( $type, $types_to_wipe, true ) ) continue;
            if ( ! isset( $table_map[ $type ] ) ) continue;
            $ids = DemoBatchRegistry::allEntityIds( $type, $batch_id );
            if ( ! $ids ) {
                $deleted[ $type ] = 0;
                continue;
            }
            [ $table, $id_col ] = $table_map[ $type ];
            $club_clause = DemoCoverage::isClubScoped( $table ) ? ' AND club_id = %d' : '';
            $club_args   = DemoCoverage::isClubScoped( $table ) ? [ CurrentClub::id() ] : [];

            // A pivot table with no surrogate id (tt_player_parents) can't be
            // addressed by its own tagged ids — delete by the parent entity's
            // wiped id set instead.
            $delete_by = DemoCoverage::deleteBy( $table );
            if ( $delete_by !== null ) {
                $parent_ids = DemoBatchRegistry::allEntityIds( (string) $delete_by['entity_type'], $batch_id );
                if ( ! $parent_ids ) {
                    $deleted[ $type ] = 0;
                    continue;
                }
                $column = (string) $delete_by['column'];
                $n = self::deleteInChunks( $table, $column, $parent_ids, $club_clause, $club_args );
                $deleted[ $type ] = $n;
                if ( $n !== false ) {
                    self::dropTags( $type, $batch_id );
                }
                continue;
            }

            $n = self::deleteInChunks( $table, $id_col, $ids, $club_clause, $club_args );
            $deleted[ $type ] = $n;

            // #3813 — the tags are the only record that these rows are
            // demo data. Dropping them after a failed delete orphans the
            // rows permanently: a second wipe can no longer find them.
            if ( $n !== false ) {
                self::dropTags( $type, $batch_id );
            }
        }
        return $deleted;
    }

    /**
     * Delete rows whose `$column` is in `$ids`, one bounded statement per
     * `DELETE_CHUNK` ids.
     *
     * @param int[]   $ids        Already-validated integer ids.
     * @param string  $club_clause Either '' or ' AND club_id = %d'.
     * @param int[]   $club_args   Args matching `$club_clause`.
     * @return int|false Total affected rows, or `false` as soon as one
     *   statement fails — a partial delete is reported as a failure so the
     *   caller keeps the tags and can try again.
     */
    private static function deleteInChunks( string $table, string $column, array $ids, string $club_clause, array $club_args ) {
        global $wpdb;
        $total = 0;
        foreach ( array_chunk( $ids, self::DELETE_CHUNK ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$table} WHERE {$column} IN ({$placeholders}){$club_clause}",
                ...array_merge( $chunk, $club_args )
            ) );
            if ( $n === false ) {
                return false;
            }
            $total += (int) $n;
        }
        return $total;
    }

    /**
     * The entity types whose delete failed in a `wipeData()` result.
     *
     * @param array<string,int|false> $result
     * @return string[]
     */
    public static function failedTypes( array $result ): array {
        $failed = [];
        foreach ( $result as $type => $n ) {
            if ( $n === false ) $failed[] = (string) $type;
        }
        return $failed;
    }

    /**
     * Rows actually deleted across a `wipeData()` result, ignoring the
     * types that failed. `array_sum()` on the raw result would fold a
     * `false` into 0 and read as success.
     *
     * @param array<string,int|false> $result
     */
    public static function deletedTotal( array $result ): int {
        $total = 0;
        foreach ( $result as $n ) {
            if ( $n !== false ) $total += $n;
        }
        return $total;
    }

    /**
     * Drop `tt_demo_tags` rows for one entity type. Scoped to the batch when
     * one was passed, so other batches' tags survive.
     */
    private static function dropTags( string $type, ?string $batch_id ): void {
        global $wpdb;
        if ( $batch_id !== null ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tt_demo_tags WHERE entity_type = %s AND club_id = %d AND batch_id = %s",
                $type, CurrentClub::id(), $batch_id
            ) );
            return;
        }
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tt_demo_tags WHERE entity_type = %s AND club_id = %d",
            $type, CurrentClub::id()
        ) );
    }

    /**
     * Expand the operator-picked categories to the underlying entity-type
     * set, deduplicated. Null falls back to every entity type except
     * `person`, which `wipeUsers()` owns.
     *
     * @param string[]|null $categories
     * @return string[] entity types
     */
    private static function resolveTypes( ?array $categories ): array {
        if ( $categories === null ) {
            return array_values( array_filter(
                self::dataOrder(),
                static function ( string $t ): bool { return $t !== 'person'; }
            ) );
        }
        $types = [];
        foreach ( $categories as $cat ) {
            foreach ( DemoCoverage::cascade( (string) $cat ) as $t ) {
                if ( ! in_array( $t, $types, true ) ) $types[] = $t;
            }
        }
        return $types;
    }

    /**
     * Live count per category given the current `tt_demo_tags` state.
     * Counts the tagged-row total once per type then sums across the
     * category's cascade. Used by the wipe-form preview so the operator
     * sees what each checkbox will actually delete.
     *
     * @return array<string,int> category key => total tagged rows that
     *   would be deleted if that category alone were checked
     */
    public static function categoryCounts( ?string $batch_id = null ): array {
        $per_type = [];
        foreach ( array_keys( self::tableMap() ) as $type ) {
            $per_type[ $type ] = count( DemoBatchRegistry::allEntityIds( $type, $batch_id ) );
        }
        $out = [];
        foreach ( DemoCoverage::categoryKeys() as $cat ) {
            $total = 0;
            foreach ( DemoCoverage::cascade( $cat ) as $t ) {
                $total += (int) ( $per_type[ $t ] ?? 0 );
            }
            $out[ $cat ] = $total;
        }
        return $out;
    }

    /**
     * Remove the persistent demo users. Requires:
     *   - every user's email domain matches $expected_domain
     *   - no user is the currently logged-in user
     *   - no user is the only remaining administrator
     *
     * @return array{deleted:int, refused:array<int,string>}
     */
    public static function wipeUsers( string $expected_domain ): array {
        $expected_domain = strtolower( ltrim( $expected_domain, '@' ) );
        $refused = [];
        $deleted = 0;

        $persistent_ids = DemoBatchRegistry::persistentEntityIds( 'wp_user' );
        if ( ! $persistent_ids ) {
            return [ 'deleted' => 0, 'refused' => [] ];
        }

        // Persistent persons are tied to the user set — remove them
        // before deleting the users so we don't leave orphan rows with
        // wp_user_id pointing at nonexistent accounts.
        global $wpdb;
        $person_ids = DemoBatchRegistry::persistentEntityIds( 'person' );
        if ( $person_ids ) {
            // #3813 — chunked, and the tag delete only runs when every
            // chunk landed.
            $n = self::deleteInChunks( 'tt_people', 'id', $person_ids, ' AND club_id = %d', [ CurrentClub::id() ] );
            if ( $n !== false ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}tt_demo_tags WHERE entity_type = 'person' AND club_id = %d",
                    CurrentClub::id()
                ) );
            }
        }

        $current_user_id = (int) get_current_user_id();
        $admin_count = self::countAdministrators();

        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        foreach ( $persistent_ids as $user_id ) {
            $user = get_user_by( 'id', $user_id );
            if ( ! $user ) {
                // Orphan tag — safe to drop.
                $wpdb->delete( $wpdb->prefix . 'tt_demo_tags', [
                    'entity_type' => 'wp_user',
                    'entity_id'   => $user_id,
                    'club_id'     => CurrentClub::id(),
                ] );
                continue;
            }

            $email_domain = strtolower( (string) substr( strrchr( $user->user_email, '@' ) ?: '@', 1 ) );
            if ( $email_domain !== $expected_domain ) {
                $refused[ $user_id ] = 'domain-mismatch';
                continue;
            }
            if ( $user_id === $current_user_id ) {
                $refused[ $user_id ] = 'is-current-user';
                continue;
            }
            $is_admin_user = \TT\Infrastructure\Security\RoleResolver::userHasRole( $user_id, 'administrator' );
            if ( $is_admin_user && $admin_count <= 1 ) {
                $refused[ $user_id ] = 'last-admin';
                continue;
            }

            $ok = wp_delete_user( $user_id );
            if ( $ok ) {
                $wpdb->delete( $wpdb->prefix . 'tt_demo_tags', [
                    'entity_type' => 'wp_user',
                    'entity_id'   => $user_id,
                    'club_id'     => CurrentClub::id(),
                ] );
                $deleted++;
                if ( $is_admin_user ) {
                    $admin_count--;
                }
            } else {
                $refused[ $user_id ] = 'wp_delete_user-failed';
            }
        }

        return [ 'deleted' => $deleted, 'refused' => $refused ];
    }

    private static function countAdministrators(): int {
        $users = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        return count( $users );
    }
}
