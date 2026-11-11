<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Teams\Services\TeamStaffPrompt;
use TT\Shared\Wizards\WizardStepInterface;
use TT\Shared\Frontend\Components\StackTable;

final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        echo '<p>' . esc_html__( 'Check the team details before creating it.', 'talenttrack' ) . '</p>';
        // #1526 — standard two-column review table (matches Activity/Prospect/VCT).
        // #4910 — on the stack-table primitive, a table from 480px.
        $row = static function ( string $label, string $value ): void {
            echo '<tr' . StackTable::attrs( 'row' ) . '><th scope="row" class="tt-wizard-review-label"' . StackTable::attrs( 'rowheader' ) . '>' . esc_html( $label ) . '</th>'
                . '<td' . StackTable::attrs( 'cell' ) . '>' . esc_html( $value ) . '</td></tr>';
        };
        echo '<div class="tt-table-wrap"><table class="tt-table tt-wizard-review-table ' . esc_attr( StackTable::classes( StackTable::TO_480 ) ) . '"' . StackTable::attrs( 'table' ) . '><tbody' . StackTable::attrs( 'rowgroup' ) . '>';
        $row( __( 'Team name', 'talenttrack' ), (string) ( $state['name'] ?? '' ) );
        $row( __( 'Age group', 'talenttrack' ), (string) ( $state['age_group'] ?? '—' ) );
        foreach ( StaffStep::SLOTS as $key => $label ) {
            $uid = (int) ( $state[ 'staff_' . $key ] ?? 0 );
            $name = '—';
            if ( $uid > 0 ) {
                $u = get_userdata( $uid );
                if ( $u ) $name = (string) $u->display_name;
            }
            $row( __( $label, 'talenttrack' ), $name ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the slot labels are translated where StaffStep::SLOTS is declared
        }
        $spond_group_id = (string) ( $state['spond_group_id'] ?? '' );
        if ( $spond_group_id !== '' ) {
            $row( __( 'Spond group', 'talenttrack' ), $spond_group_id );
        }
        echo '</tbody></table></div>';
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        global $wpdb;

        // v3.85.5 — license cap enforcement. Wizard wpdb->insert
        // bypassed the 1-team free-tier cap that wp-admin TeamsPage
        // already enforces.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && \TT\Modules\License\LicenseGate::capsExceeded( 'teams' )
        ) {
            return new \WP_Error(
                'license_cap_teams',
                __( 'You have reached the free-tier limit of 1 team. Upgrade to Standard to add more.', 'talenttrack' )
            );
        }

        $name = (string) ( $state['name'] ?? '' );
        if ( $name === '' ) return new \WP_Error( 'name_required', __( 'Team name is required.', 'talenttrack' ) );

        // #1315 — `head_coach_id` retired. The staff slot still feeds
        // the tt_team_people insert loop below; it just doesn't get
        // duplicated onto the legacy column anymore.
        $spond_group_id = (string) ( $state['spond_group_id'] ?? '' );

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'        => CurrentClub::id(),
            'name'           => $name,
            'age_group'      => (string) ( $state['age_group'] ?? '' ),
            'notes'          => (string) ( $state['notes'] ?? '' ),
            'spond_group_id' => $spond_group_id !== '' ? $spond_group_id : null,
        ] );
        if ( ! $ok ) return new \WP_Error( 'db_error', __( 'Could not create the team.', 'talenttrack' ) );
        $team_id = (int) $wpdb->insert_id;
        \TT\Infrastructure\Search\SearchText::refresh( 'team', $team_id );

        // Map staff slots to tt_team_people via functional roles.
        $repo   = new FunctionalRolesRepository();
        $people = new PeopleRepository();
        $slot_to_role_key = [
            'head_coach'      => 'head_coach',
            'assistant_coach' => 'assistant_coach',
            'team_manager'    => 'team_manager',
            'physio'          => 'physio',
        ];
        foreach ( $slot_to_role_key as $slot => $role_key ) {
            $uid = (int) ( $state[ 'staff_' . $slot ] ?? 0 );
            if ( $uid <= 0 ) continue;
            $role = $repo->findRoleByKey( $role_key );
            if ( ! $role ) continue;
            // Resolve the person id from the wp user id, or insert a minimal People row.
            $person_id = self::resolvePersonId( $uid );
            if ( $person_id <= 0 ) continue;
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_team_people
                  WHERE team_id = %d AND person_id = %d AND functional_role_id = %d AND club_id = %d LIMIT 1",
                $team_id, $person_id, (int) $role->id, CurrentClub::id()
            ) );
            if ( $exists ) continue;
            // #4729 — through the repository, so the coach gets the team
            // scope row and the authorization caches are flushed; a raw
            // insert left the new team out of every team-scoped check.
            $people->assignToTeam( $team_id, $person_id, (int) $role->id );
        }

        // #0063 — Roster step: bulk-update tt_players.team_id for the
        // ticked players. Existing team_id is overwritten because the
        // wizard step's UI already labels rows that move from another
        // team, so the user has explicit consent.
        $roster = isset( $state['roster_player_ids'] ) && is_array( $state['roster_player_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'intval', $state['roster_player_ids'] ) ) ) )
            : [];
        if ( ! empty( $roster ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $roster ), '%d' ) );
            $params       = array_merge( [ $team_id, CurrentClub::id() ], $roster );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}tt_players
                    SET team_id = %d
                  WHERE club_id = %d AND id IN ({$placeholders})",
                $params
            ) );
        }

        // #4007 — every staff slot here is skippable on purpose, so a team can
        // still finish the wizard with nobody running it. The prompt fires
        // after the assignments above, so it only speaks when the head-coach
        // slot really was left empty, and it lands on the team page this
        // redirect goes to.
        TeamStaffPrompt::afterCreate( $team_id, $name );

        return [ 'redirect_url' => add_query_arg( [ 'tt_view' => 'teams', 'id' => $team_id ], \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl() ) ];
    }

    /**
     * Find or create a tt_people row for a wp user id. Returns the
     * person id, or 0 if neither lookup nor creation works.
     */
    private static function resolvePersonId( int $wp_user_id ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $wp_user_id, CurrentClub::id()
        ) );
        if ( $existing > 0 ) return $existing;

        $user = get_userdata( $wp_user_id );
        if ( ! $user ) return 0;
        $first = (string) $user->first_name ?: (string) $user->display_name;
        $last  = (string) $user->last_name ?: '';
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => CurrentClub::id(),
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string) $user->user_email,
            'wp_user_id' => $wp_user_id,
            'role_type'  => 'staff',
        ] );
        if ( ! $ok ) return 0;
        $person_id = (int) $wpdb->insert_id;
        \TT\Infrastructure\Search\SearchText::refresh( 'person', $person_id );
        // v3.76.2 — auto-tag demo-on rows.
        \TT\Modules\DemoData\DemoMode::tagIfActive( 'person', $person_id );
        return $person_id;
    }
}
