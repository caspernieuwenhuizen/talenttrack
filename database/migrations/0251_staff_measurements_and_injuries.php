<?php
/**
 * Migration: 0251_staff_measurements_and_injuries
 *
 * #3232 — give the `staff` persona the two entities the seat exists for.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. So without this migration the rows exist in
 * `config/authorization_seed.php` and nowhere that matters, and a physio on
 * an already-migrated install still cannot record a measurement or an
 * injury.
 *
 * Same shape as `0249_authorization_seed_topup_observer_and_staff`, and
 * scoped the same way — to the two new tuples only, so it can never re-add
 * a row an operator deliberately removed for somebody else.
 *
 * WHAT IT GRANTS, AND THE DECISION BEHIND IT
 *
 *   staff → measurements     [read, change] @ team
 *   staff → player_injuries  [read, change] @ team
 *
 * `measurements` is uncontroversial: height, weight, sprint times, and
 * `team_manager` already holds the read half at team scope.
 *
 * `player_injuries` is a decision taken on 2026-08-30 with its tradeoff
 * stated rather than buried. A physio is the obviously correct holder of an
 * injury record. But `tt_staff` is one undifferentiated role covering
 * physio and kit manager, so this reaches medical data about minors for
 * every Staff account. The mitigations are that the grant is `team` and
 * never `global`, that `create_delete` stays with HoD / academy admin, and
 * that `docs/access-control.md` now says plainly what the role reaches — so
 * an academy handing it to a kit manager does so knowingly.
 *
 * NOT granted: `create_delete` on either. Deleting a minor's medical record
 * is not a touchline decision.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves operator-edited rows alone
 * and only adds the missing tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** Exactly what this migration is allowed to write. */
    private const ROWS = [
        [ 'staff', 'measurements',    'read',   'team' ],
        [ 'staff', 'measurements',    'change', 'team' ],
        [ 'staff', 'player_injuries', 'read',   'team' ],
        [ 'staff', 'player_injuries', 'change', 'team' ],
    ];

    public function getName(): string {
        return '0251_staff_measurements_and_injuries';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_authorization_matrix";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Resolve the module class the same way the seed does, so a row
        // written here is indistinguishable from a seeded one. Falling back
        // to the authorization module keeps the row valid on an install
        // where the owning module is absent.
        $measurements = class_exists( '\TT\Modules\Measurements\MeasurementsModule' )
            ? \TT\Modules\Measurements\MeasurementsModule::class
            : \TT\Modules\Authorization\AuthorizationModule::class;

        $journey = class_exists( '\TT\Modules\Journey\JourneyModule' )
            ? \TT\Modules\Journey\JourneyModule::class
            : \TT\Modules\Authorization\AuthorizationModule::class;

        $module_for = [
            'measurements'    => $measurements,
            'player_injuries' => $journey,
        ];

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( self::ROWS as [ $persona, $entity, $activity, $scope ] ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare(
                $sql,
                $persona,
                $entity,
                $activity,
                $scope,
                (string) $module_for[ $entity ]
            ) );
        }

        if ( class_exists( '\TT\Modules\Authorization\Matrix\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
