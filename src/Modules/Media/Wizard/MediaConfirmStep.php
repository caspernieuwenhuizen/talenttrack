<?php
namespace TT\Modules\Media\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — confirm (#2593).
 *
 * The files already exist by now, so this step is not "create" — it is
 * "apply the details and take me back". It reads as a confirmation
 * because that is what the coach experiences, but the honest description
 * is that it finishes a record that is already saved.
 *
 * Every write re-checks permission on the item. The uuids travelled
 * through a client-supplied field in step 2, and a step boundary is not
 * an authorization boundary.
 */
final class MediaConfirmStep implements WizardStepInterface {

    public function slug(): string { return 'confirm'; }

    public function label(): string { return __( 'Confirm', 'talenttrack' ); }

    public function render( array $state ): void {
        $uuids = (array) ( $state['media_uuids'] ?? [] );
        $type  = (string) ( $state['entity_type'] ?? '' );
        $id    = (int) ( $state['entity_id'] ?? 0 );

        $repo  = new MediaRepository();
        $title = (string) ( $state['media_title'] ?? '' );

        echo '<p>' . esc_html(
            sprintf(
                /* translators: %s is the player, team or training the media was added to. */
                __( 'This will appear on %s.', 'talenttrack' ),
                MediaTargetStep::labelFor( $type, $id )
            )
        ) . '</p>';

        echo '<ul class="tt-media-confirm-list">';
        foreach ( $uuids as $uuid ) {
            $media = $repo->findByUuid( (string) $uuid );
            if ( ! $media ) continue;

            $label = $title !== '' ? $title : (string) $media->title;
            if ( $label === '' ) $label = MediaKind::label( (string) $media->kind );

            echo '<li class="tt-media-confirm-list__item">';
            echo '<span class="tt-media-confirm-list__kind">' . esc_html( MediaKind::label( (string) $media->kind ) ) . '</span>';
            echo '<span class="tt-media-confirm-list__title">' . esc_html( $label ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        $captured = (string) ( $state['media_captured_at'] ?? '' );
        if ( $captured !== '' ) {
            echo '<p class="description">' . esc_html(
                sprintf(
                    /* translators: %s is a date. */
                    __( 'Filed under %s on the timeline.', 'talenttrack' ),
                    $captured
                )
            ) . '</p>';
        }
    }

    public function validate( array $post, array $state ) {
        return [];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $uuids = (array) ( $state['media_uuids'] ?? [] );
        if ( $uuids === [] ) {
            return new \WP_Error( 'nothing_added', __( 'Nothing was added.', 'talenttrack' ) );
        }

        $repo    = new MediaRepository();
        $access  = new MediaVisibilityService();
        $user    = get_current_user_id();
        $applied = 0;

        $fields = [];
        if ( ( $state['media_title'] ?? '' ) !== '' )       $fields['title']       = (string) $state['media_title'];
        if ( ( $state['media_description'] ?? '' ) !== '' ) $fields['description'] = (string) $state['media_description'];
        if ( ( $state['media_captured_at'] ?? '' ) !== '' ) {
            $fields['captured_at'] = (string) $state['media_captured_at'] . ' 12:00:00';
        }

        foreach ( $uuids as $uuid ) {
            $media = $repo->findByUuid( (string) $uuid );
            if ( ! $media ) continue;
            if ( ! $access->canEdit( $user, $media ) ) continue;

            if ( $fields !== [] ) $repo->update( (int) $media->id, $fields );
            $applied++;
        }

        if ( $applied === 0 ) {
            return new \WP_Error(
                'nothing_applied',
                __( 'The uploads could not be updated. They are still saved on the record.', 'talenttrack' )
            );
        }

        return [ 'redirect_url' => self::recordUrl( (string) ( $state['entity_type'] ?? '' ), (int) ( $state['entity_id'] ?? 0 ) ) ];
    }

    /**
     * Back to the record the media was added to — the coach's mental
     * "done" is seeing it on the player, not landing on a media list.
     */
    private static function recordUrl( string $type, int $id ): string {
        $slug = 'players';
        if ( $type === MediaEntityType::TEAM )     $slug = 'teams';
        if ( $type === MediaEntityType::ACTIVITY ) $slug = 'activities-manage';

        return add_query_arg(
            [ 'tt_view' => $slug, 'id' => $id ],
            home_url( '/' )
        );
    }
}
