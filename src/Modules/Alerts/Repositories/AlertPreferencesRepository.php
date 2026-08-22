<?php
namespace TT\Modules\Alerts\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Domain\Surface;

/**
 * AlertPreferencesRepository (#2632, epic #2629) — `tt_alert_preferences`.
 *
 * Stores **only deviations** from the shipped defaults. A user who has never
 * touched the settings screen has no rows, and a definition with no row for
 * this user resolves to its own `defaultSurfaces()`. See migration 0226 for
 * why absence-means-default rather than absence-means-off.
 *
 * Nothing here resolves precedence — that is `AlertPolicyResolver`'s job.
 * This class only reads and writes what the user asked for.
 */
final class AlertPreferencesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_alert_preferences';
    }

    /**
     * Every stored preference for a user, keyed by alert key.
     *
     * Loaded in one query and handed to the resolver, because the settings
     * screen and the evaluator both need the whole set at once; a
     * per-alert-key lookup would be a query per definition per user on a
     * sweep that already runs for every club.
     *
     * @return array<string,array{surfaces:list<string>,muted_until:?string}>
     */
    public function allForUser( int $userId ): array {
        if ( $userId <= 0 || ! $this->tableExists() ) return [];

        global $wpdb;
        $table = $this->table();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT alert_key, surfaces_json, muted_until
               FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND user_id = %d",
            $userId
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $decoded = json_decode( (string) $row->surfaces_json, true );
            $out[ (string) $row->alert_key ] = [
                'surfaces'    => Surface::normalise( is_array( $decoded ) ? $decoded : [] ),
                'muted_until' => $row->muted_until !== null ? (string) $row->muted_until : null,
            ];
        }
        return $out;
    }

    /**
     * Store one user's choice for one alert.
     *
     * `$surfaces` is the complete set the user wants, not a delta. An empty
     * array is a legitimate value meaning "nowhere" — distinct from having
     * no row at all, which means "wherever the definition says". Those two
     * states look similar and behave oppositely, which is why `save()` always
     * writes a row rather than deleting one when the set empties.
     *
     * `INTERRUPT` is stripped: a user can neither opt into being interrupted
     * nor out of a club-assigned one (epic decision 4).
     *
     * @param list<string> $surfaces
     */
    public function save( int $userId, string $alertKey, array $surfaces, ?string $mutedUntil = null ): void {
        if ( $userId <= 0 || $alertKey === '' || ! $this->tableExists() ) return;

        global $wpdb;
        $table = $this->table();
        $json  = (string) wp_json_encode( Surface::normalise( $surfaces ) );
        $now   = current_time( 'mysql' );

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND user_id = %d AND alert_key = %s
              LIMIT 1",
            $userId,
            $alertKey
        ) );

        if ( $existing_id > 0 ) {
            $wpdb->update(
                $table,
                [ 'surfaces_json' => $json, 'muted_until' => $mutedUntil, 'updated_at' => $now ],
                [ 'id' => $existing_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return;
        }

        $wpdb->insert(
            $table,
            array_merge(
                [
                    'user_id'       => $userId,
                    'alert_key'     => $alertKey,
                    'surfaces_json' => $json,
                    'muted_until'   => $mutedUntil,
                    'updated_at'    => $now,
                ],
                QueryHelpers::clubScopeInsertColumn()
            ),
            [ '%d', '%s', '%s', '%s', '%s', '%d' ]
        );
    }

    /**
     * Drop a user's stored choice for one alert, returning it to the shipped
     * default. This is "reset", not "turn off" — see `save()`.
     */
    public function reset( int $userId, string $alertKey ): void {
        if ( $userId <= 0 || $alertKey === '' || ! $this->tableExists() ) return;

        global $wpdb;
        $wpdb->delete(
            $this->table(),
            [
                'club_id'   => CurrentClub::id(),
                'user_id'   => $userId,
                'alert_key' => $alertKey,
            ],
            [ '%d', '%d', '%s' ]
        );
    }

    /** @var bool|null per-request cache */
    private static $tableExists = null;

    public function tableExists(): bool {
        if ( self::$tableExists !== null ) return self::$tableExists;
        global $wpdb;
        $table = $this->table();
        self::$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        return self::$tableExists;
    }

    /** Drop the per-request table cache. Tests use this. */
    public static function flushTableCache(): void {
        self::$tableExists = null;
    }
}
