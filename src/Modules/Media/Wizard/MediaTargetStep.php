<?php
namespace TT\Modules\Media\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — who this is for (#2593).
 *
 * Almost always prefilled: the wizard is reached from a player, team or
 * activity, and that record arrives on the entry URL. When it does, the
 * step confirms the target rather than asking again — a coach who clicked
 * "Add media" on Tom's profile should not have to find Tom in a dropdown.
 *
 * The picker is the fallback for entering the wizard cold, from a tile.
 */
final class MediaTargetStep implements WizardStepInterface {

    public function slug(): string { return 'target'; }

    public function label(): string { return __( 'Who for', 'talenttrack' ); }

    public function render( array $state ): void {
        $type = (string) ( $state['entity_type'] ?? self::paramType() );
        $id   = (int) ( $state['entity_id'] ?? self::paramId() );

        if ( MediaEntityType::isValid( $type ) && $id > 0 ) {
            $this->renderConfirmedTarget( $type, $id );
            return;
        }

        echo '<p>' . esc_html__( 'Who is this photo or video about?', 'talenttrack' ) . '</p>';

        // #3157 — the cold-entry picker used to list every active child in
        // the academy. This step is where a coach decides whose photo is
        // being stored, so it is the last place a name should appear that
        // the coach has no business seeing. Narrowed to the viewer's own
        // teams; global `players` read (scout, HoD, academy admin) still
        // reaches everyone. `validate()` already re-asks
        // `MediaVisibilityService::canAttachTo()`, so this narrows what is
        // offered, not what is allowed.
        $players = QueryHelpers::get_players_in_scope( get_current_user_id() );
        if ( empty( $players ) ) {
            echo '<p class="description">' . esc_html__( 'There are no players to attach media to yet.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<input type="hidden" name="entity_type" value="' . esc_attr( MediaEntityType::PLAYER ) . '" />';
        echo '<label for="tt-media-target"><span>' . esc_html__( 'Player', 'talenttrack' ) . '</span></label>';
        echo '<select id="tt-media-target" name="entity_id" required>';
        echo '<option value="">' . esc_html__( '— choose a player —', 'talenttrack' ) . '</option>';

        foreach ( $players as $player ) {
            printf(
                '<option value="%1$d" %2$s>%3$s</option>',
                (int) $player->id,
                selected( $id, (int) $player->id, false ),
                esc_html( QueryHelpers::player_display_name( $player ) )
            );
        }
        echo '</select>';

        echo '<p class="description">'
            . esc_html__( 'You can attach the same photo to more players, a team or a training afterwards.', 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        $type = isset( $post['entity_type'] ) ? (string) $post['entity_type'] : (string) ( $state['entity_type'] ?? '' );
        $id   = isset( $post['entity_id'] ) ? (int) $post['entity_id'] : (int) ( $state['entity_id'] ?? 0 );

        if ( ! MediaEntityType::isValid( $type ) || $id <= 0 ) {
            return new \WP_Error( 'no_target', __( 'Choose who this is for first.', 'talenttrack' ) );
        }

        // The same check the REST endpoint will run. Failing here means a
        // clear sentence on the step rather than a refused upload three
        // steps later, when the coach has already picked their files.
        if ( ! ( new MediaVisibilityService() )->canAttachTo( get_current_user_id(), $type, $id ) ) {
            return new \WP_Error(
                'not_allowed',
                __( 'You do not have permission to add media to that record.', 'talenttrack' )
            );
        }

        return [ 'entity_type' => $type, 'entity_id' => $id ];
    }

    public function nextStep( array $state ): ?string { return 'source'; }

    public function submit( array $state ) { return null; }

    // Internals

    private function renderConfirmedTarget( string $type, int $id ): void {
        echo '<input type="hidden" name="entity_type" value="' . esc_attr( $type ) . '" />';
        echo '<input type="hidden" name="entity_id" value="' . (int) $id . '" />';

        echo '<p>' . esc_html__( 'This is being added to:', 'talenttrack' ) . '</p>';
        echo '<p class="tt-media-target-name">' . esc_html( self::labelFor( $type, $id ) ) . '</p>';
        echo '<p class="description">'
            . esc_html__( 'You can attach the same photo to more records afterwards.', 'talenttrack' )
            . '</p>';
    }

    /** Human name for the record, falling back to a generic label. */
    public static function labelFor( string $type, int $id ): string {
        if ( $type === MediaEntityType::PLAYER ) {
            $player = QueryHelpers::get_player( $id );
            if ( $player ) return QueryHelpers::player_display_name( $player );
            return __( 'this player', 'talenttrack' );
        }

        if ( $type === MediaEntityType::TEAM ) {
            $team = QueryHelpers::get_team( $id );
            if ( $team && ! empty( $team->name ) ) return (string) $team->name;
            return __( 'this team', 'talenttrack' );
        }

        global $wpdb;
        $title = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT title FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ) );
        return $title !== '' ? $title : __( 'this training', 'talenttrack' );
    }

    private static function paramType(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill of a form field.
        return isset( $_GET['entity_type'] ) ? sanitize_key( wp_unslash( $_GET['entity_type'] ) ) : '';
    }

    private static function paramId(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill of a form field.
        return isset( $_GET['entity_id'] ) ? (int) $_GET['entity_id'] : 0;
    }
}
