<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * UserGenerator — creates the Rich set of 36 persistent demo WP users
 * on first run, reuses them on every subsequent generate.
 *
 * Slot inventory (36 total):
 *   Fixed (7):    admin, hjo, hjo2, scout, staff, observer, parent
 *   Coaches (12): coach1 .. coach12
 *   Assistants (12): assistant1 .. assistant12
 *   Players (5):  player1 .. player5
 *
 * All users share a catch-all domain the demo-giver controls (e.g.
 * demo.talenttrack.dev). Emails are <slot>@<domain>. Users are tagged
 * in tt_demo_tags with extra_json = {"persistent": true, "slot": "..."}
 * so they survive "Wipe demo data" (only "Wipe demo users too" removes
 * them, gated behind three safety rails).
 *
 * On re-run: looked up by slot tag first, falling back to email so an
 * older install without tags still gets reused cleanly.
 */
class UserGenerator {

    private const FIXED_SLOTS = [
        'admin'    => 'administrator',
        'hjo'      => 'tt_head_dev',
        'hjo2'     => 'tt_head_dev',
        'scout'    => 'tt_scout',
        'staff'    => 'tt_staff',
        'observer' => 'tt_readonly_observer',
        'parent'   => 'tt_parent',
    ];

    private DemoBatchRegistry $registry;
    private string $domain;
    private string $password;

    /**
     * @var array<string, int> Map of slot name -> WP user id for the current run.
     */
    private array $users = [];

    public function __construct( DemoBatchRegistry $registry, string $domain, string $password ) {
        $this->registry = $registry;
        $this->domain   = ltrim( $domain, '@' );
        $this->password = $password;
    }

    /**
     * Create or reuse all 36 accounts. Idempotent: every slot existing
     * before the call is left untouched; every slot missing is created
     * and tagged.
     *
     * @return array<string, int> slot name => user id
     */
    public function generate(): array {
        foreach ( self::FIXED_SLOTS as $slot => $role ) {
            $this->users[ $slot ] = $this->ensureUser( $slot, $role );
        }
        for ( $i = 1; $i <= 12; $i++ ) {
            $this->users[ "coach{$i}" ]     = $this->ensureUser( "coach{$i}", 'tt_coach' );
            $this->users[ "assistant{$i}" ] = $this->ensureUser( "assistant{$i}", 'tt_coach' );
        }
        for ( $i = 1; $i <= 5; $i++ ) {
            $this->users[ "player{$i}" ] = $this->ensureUser( "player{$i}", 'tt_player' );
        }
        return $this->users;
    }

    /**
     * All 36 accounts keyed by slot, with ids and display emails.
     * Used by the success screen on first-run to show credentials.
     *
     * @return array<string, array{user_id:int, email:string}>
     */
    public function accounts(): array {
        $out = [];
        foreach ( $this->users as $slot => $user_id ) {
            $out[ $slot ] = [ 'user_id' => $user_id, 'email' => $this->emailFor( $slot ) ];
        }
        return $out;
    }

    private function emailFor( string $slot ): string {
        return $slot . '@' . $this->domain;
    }

    private function ensureUser( string $slot, string $role ): int {
        $existing = $this->findExistingUserForSlot( $slot );
        if ( $existing !== null ) {
            return $existing;
        }

        $email = $this->emailFor( $slot );
        $login = 'tt_demo_' . $slot;
        $user_id = wp_insert_user( [
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $this->password,
            'display_name' => $this->displayNameFor( $slot ),
            'first_name'   => ucfirst( $slot ),
            'role'         => $role,
        ] );

        if ( is_wp_error( $user_id ) ) {
            throw new \RuntimeException(
                sprintf( 'Failed to create demo user %s: %s', $slot, $user_id->get_error_message() )
            );
        }

        $uid = (int) $user_id;
        $this->registry->tag( 'wp_user', $uid, [
            'persistent' => true,
            'slot'       => $slot,
            'role'       => $role,
        ] );
        return $uid;
    }

    /**
     * Look up an existing slot-tagged user, or fall back to a user
     * matching the slot's email. Second path means pre-tag installs
     * still get reused without duplicate creation.
     */
    private function findExistingUserForSlot( string $slot ): ?int {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_id, extra_json FROM {$wpdb->prefix}tt_demo_tags
             WHERE entity_type = %s",
            'wp_user'
        ) );
        foreach ( (array) $rows as $r ) {
            $extra = $r->extra_json ? json_decode( (string) $r->extra_json, true ) : [];
            if ( is_array( $extra ) && ( $extra['slot'] ?? null ) === $slot ) {
                return (int) $r->entity_id;
            }
        }

        $user = get_user_by( 'email', $this->emailFor( $slot ) );
        if ( $user ) {
            $this->registry->tag( 'wp_user', (int) $user->ID, [
                'persistent' => true,
                'slot'       => $slot,
                'role'       => implode( ',', (array) $user->roles ),
                'reclaimed'  => true,
            ] );
            return (int) $user->ID;
        }

        return null;
    }

    private function displayNameFor( string $slot ): string {
        $labels = [
            'admin'    => 'Demo Admin',
            'hjo'      => 'Demo Head of Development',
            'hjo2'     => 'Demo Deputy Head of Development',
            'scout'    => 'Demo Scout',
            'staff'    => 'Demo Staff',
            'observer' => 'Demo Observer',
            'parent'   => 'Demo Parent',
        ];
        if ( isset( $labels[ $slot ] ) ) return $labels[ $slot ];
        if ( strpos( $slot, 'coach' ) === 0 )     return 'Demo Coach ' . substr( $slot, 5 );
        if ( strpos( $slot, 'assistant' ) === 0 ) return 'Demo Assistant Coach ' . substr( $slot, 9 );
        if ( strpos( $slot, 'player' ) === 0 )    return 'Demo Player ' . substr( $slot, 6 );
        return 'Demo ' . $slot;
    }
}
