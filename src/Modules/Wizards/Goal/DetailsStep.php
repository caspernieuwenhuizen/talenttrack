<?php
namespace TT\Modules\Wizards\Goal;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\GoalOrigin;
use TT\Domain\Vocabularies\Lookups\GoalPriority;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

final class DetailsStep implements WizardStepInterface {

    public function slug(): string { return 'details'; }
    public function label(): string { return __( 'Details', 'talenttrack' ); }

    public function render( array $state ): void {
        echo '<label><span>' . esc_html__( 'Goal title', 'talenttrack' ) . ' *</span><input type="text" name="title" required value="' . esc_attr( (string) ( $state['title'] ?? '' ) ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Description', 'talenttrack' ) . '</span><textarea name="description" rows="3">' . esc_textarea( (string) ( $state['description'] ?? '' ) ) . '</textarea></label>';

        echo '<label><span>' . esc_html__( 'Priority', 'talenttrack' ) . '</span><select name="priority">';
        $priority = (string) ( $state['priority'] ?? 'medium' );
        foreach ( [ 'low' => __( 'Low', 'talenttrack' ), 'medium' => __( 'Medium', 'talenttrack' ), 'high' => __( 'High', 'talenttrack' ) ] as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '" ' . selected( $priority, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Due date', 'talenttrack' ) . '</span><input type="date" name="due_date" value="' . esc_attr( (string) ( $state['due_date'] ?? '' ) ) . '"></label>';
    }

    public function validate( array $post, array $state ) {
        $title = isset( $post['title'] ) ? sanitize_text_field( wp_unslash( (string) $post['title'] ) ) : '';
        if ( $title === '' ) return new \WP_Error( 'no_title', __( 'Goal title is required.', 'talenttrack' ) );
        return [
            'title'       => $title,
            'description' => isset( $post['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $post['description'] ) ) : '',
            'priority'    => isset( $post['priority'] ) ? sanitize_key( (string) $post['priority'] ) : 'medium',
            'due_date'    => isset( $post['due_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['due_date'] ) ) : null,
        ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        global $wpdb;
        $player_id = (int) ( $state['player_id'] ?? 0 );
        if ( $player_id <= 0 ) return new \WP_Error( 'no_player', __( 'Player is required.', 'talenttrack' ) );

        // #3131 — through the repository, which is where the row, the
        // demo tag and the `tt_goal_saved` announcement live. This step
        // used to write `tt_goals` itself and announce nothing, so a goal
        // set here never reached the player's journey while the identical
        // goal set over REST did — on the path CLAUDE.md §3 makes the
        // primary one.
        $goal_id = ( new GoalsRepository() )->create( [
            'player_id'   => $player_id,
            'title'       => (string) ( $state['title'] ?? '' ),
            'description' => (string) ( $state['description'] ?? '' ),
            'priority'    => in_array( (string) ( $state['priority'] ?? '' ), GoalPriority::ALL, true ) ? (string) $state['priority'] : GoalPriority::MEDIUM,
            'due_date'    => $state['due_date'] ?: null,
            'status'      => GoalStatus::PENDING,
            'created_by'  => get_current_user_id(),
        ], [ 'origin' => GoalOrigin::SET ] );
        if ( $goal_id <= 0 ) return new \WP_Error( 'db_error', __( 'Could not create the goal.', 'talenttrack' ) );

        // Optional methodology link.
        $link_type = (string) ( $state['link_type'] ?? '' );
        $link_id   = (int) ( $state['link_id'] ?? 0 );
        if ( $link_type !== '' && $link_id > 0 ) {
            $wpdb->insert( $wpdb->prefix . 'tt_goal_links', [
                'club_id'   => CurrentClub::id(),
                'goal_id'   => $goal_id,
                'link_type' => $link_type,
                'link_id'   => $link_id,
            ] );
        }

        // v3.110.182 (#782) — currentDashboardUrl over dashboardBaseUrl
        // to avoid the post-submit 404 on installs where the dashboard-
        // page-id config has drifted from the actual shortcode-hosting
        // page. See WizardEntryPoint::currentDashboardUrl() docblock.
        return [ 'redirect_url' => add_query_arg( [ 'tt_view' => 'goals', 'id' => $goal_id ], \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl() ) ];
    }
}
