<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\SeedLoader;

/**
 * PeopleGenerator — populates tt_people for every demo WP user that
 * represents a staff member (coaches, assistant coaches, head of
 * development, scout, generic staff).
 *
 * Without this layer the People directory stays empty and teams have
 * no Staff Assignments — both broken experiences the demo can't ship
 * with.
 *
 * Approach mirrors UserGenerator: one persistent tt_people record per
 * slot, tagged persistent:true so "Wipe demo data" leaves them alone
 * (only "Wipe demo users too" removes them, after the users, via
 * DemoDataCleaner). On re-runs the existing person row is reused and
 * its names updated to match the refreshed WP user display identity.
 *
 * Names:
 *   - coach<N> + assistant<N> get Dutch first/last names drawn from
 *     the same seeds used by PlayerGenerator, so the demo feels like
 *     a real Dutch academy. The bound WP user's display_name is synced
 *     to match.
 *   - hjo, hjo2, scout, staff keep their operational slot-style labels
 *     ('Demo Head of Development', etc.) — they're easier to recognize
 *     on stage that way.
 */
class PeopleGenerator {

    private const STAFF_SLOTS = [
        'hjo'      => [ 'role_type' => 'other',   'dutch' => false, 'label' => 'Demo Head of Development' ],
        'hjo2'     => [ 'role_type' => 'other',   'dutch' => false, 'label' => 'Demo Deputy Head of Development' ],
        'scout'    => [ 'role_type' => 'scout',   'dutch' => false, 'label' => 'Demo Scout' ],
        'staff'    => [ 'role_type' => 'other',   'dutch' => false, 'label' => 'Demo Staff' ],
    ];

    private DemoBatchRegistry $registry;

    /** @var array<string,int> slot => WP user id */
    private array $users;

    /** @var array<string,int> slot => tt_people.id for this run */
    private array $persons = [];

    /**
     * @param array<string,int> $users slot => user id from UserGenerator
     */
    public function __construct( DemoBatchRegistry $registry, array $users ) {
        $this->registry = $registry;
        $this->users    = $users;
    }

    /**
     * @return array<string,int> slot => tt_people.id
     */
    public function generate(): array {
        $first_names = SeedLoader::firstNames();
        $last_names  = SeedLoader::lastNames();

        foreach ( self::STAFF_SLOTS as $slot => $cfg ) {
            $this->persons[ $slot ] = $this->ensurePerson(
                $slot,
                (string) $cfg['label'],
                '',
                (string) $cfg['role_type']
            );
        }

        for ( $i = 1; $i <= 12; $i++ ) {
            $slot = "coach{$i}";
            [ $first, $last ] = $this->pickDutchName( $first_names, $last_names );
            $this->persons[ $slot ] = $this->ensurePerson( $slot, $first, $last, 'coach' );
            $this->syncUserNamesIfCoach( $slot, $first, $last );

            $aslot = "assistant{$i}";
            [ $first, $last ] = $this->pickDutchName( $first_names, $last_names );
            $this->persons[ $aslot ] = $this->ensurePerson( $aslot, $first, $last, 'coach' );
            $this->syncUserNamesIfCoach( $aslot, $first, $last );
        }

        return $this->persons;
    }

    /**
     * @param string[] $first_names
     * @param string[] $last_names
     * @return array{0:string,1:string}
     */
    private function pickDutchName( array $first_names, array $last_names ): array {
        if ( ! $first_names || ! $last_names ) {
            return [ 'Demo', 'Coach' ];
        }
        return [
            $first_names[ mt_rand( 0, count( $first_names ) - 1 ) ],
            $last_names[ mt_rand( 0, count( $last_names ) - 1 ) ],
        ];
    }

    /**
     * Insert or reuse the tt_people row for a given slot. On reuse,
     * names are refreshed so regenerating with a different seed
     * propagates to the people directory.
     */
    private function ensurePerson( string $slot, string $first, string $last, string $role_type ): int {
        global $wpdb;

        $wp_user_id = (int) ( $this->users[ $slot ] ?? 0 );
        $person_id  = $this->findExistingPersonIdForSlot( $slot, $wp_user_id );

        $email = null;
        if ( $wp_user_id > 0 ) {
            $user = get_userdata( $wp_user_id );
            if ( $user ) {
                $email = (string) $user->user_email ?: null;
            }
        }

        if ( $person_id > 0 ) {
            // Reuse: refresh names + email so the directory reflects the
            // current generation's identity.
            $wpdb->update(
                "{$wpdb->prefix}tt_people",
                [
                    'first_name' => $first,
                    'last_name'  => $last,
                    'email'      => $email,
                    'role_type'  => $role_type,
                    'wp_user_id' => $wp_user_id > 0 ? $wp_user_id : null,
                    'status'     => 'active',
                ],
                [ 'id' => $person_id ]
            );
            return $person_id;
        }

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'role_type'  => $role_type,
            'wp_user_id' => $wp_user_id > 0 ? $wp_user_id : null,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        if ( $person_id > 0 ) {
            $this->registry->tag( 'person', $person_id, [
                'persistent' => true,
                'slot'       => $slot,
                'role_type'  => $role_type,
            ] );
        }
        return $person_id;
    }

    /**
     * Look up a pre-existing slot-tagged person, or fall back to
     * person-with-matching-wp_user_id if tags are missing on a
     * hand-migrated install.
     */
    private function findExistingPersonIdForSlot( string $slot, int $wp_user_id ): int {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_id, extra_json FROM {$wpdb->prefix}tt_demo_tags
             WHERE entity_type = %s",
            'person'
        ) );
        foreach ( (array) $rows as $r ) {
            $extra = $r->extra_json ? json_decode( (string) $r->extra_json, true ) : [];
            if ( is_array( $extra ) && ( $extra['slot'] ?? null ) === $slot ) {
                return (int) $r->entity_id;
            }
        }

        if ( $wp_user_id > 0 ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d LIMIT 1",
                $wp_user_id
            ) );
            if ( $existing > 0 ) {
                $this->registry->tag( 'person', $existing, [
                    'persistent' => true,
                    'slot'       => $slot,
                    'reclaimed'  => true,
                ] );
                return $existing;
            }
        }

        return 0;
    }

    /**
     * For coach and assistant slots the WP user's display_name should
     * match the Dutch person name so anything that renders
     * wp_user->display_name (team coach line, session coach) reads
     * like a real academy. hjo/scout/staff keep their operational
     * labels.
     */
    private function syncUserNamesIfCoach( string $slot, string $first, string $last ): void {
        if ( strpos( $slot, 'coach' ) !== 0 && strpos( $slot, 'assistant' ) !== 0 ) {
            return;
        }
        $wp_user_id = (int) ( $this->users[ $slot ] ?? 0 );
        if ( $wp_user_id <= 0 ) return;
        wp_update_user( [
            'ID'           => $wp_user_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim( $first . ' ' . $last ),
            'nickname'     => $first,
        ] );
    }
}
