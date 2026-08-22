<?php
namespace TT\Modules\Media\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Shared\Frontend\Components\MediaUploader;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — the files (#2593).
 *
 * The upload commits here, against the target chosen in step 1, through
 * the REST endpoint. See `NewMediaWizard`'s docblock for why it cannot
 * wait until the end: the wizard's form is not multipart and its state is
 * a transient, so a file has nowhere to live between steps.
 *
 * What crosses the step boundary is therefore a list of uuids, not bytes.
 */
final class MediaSourceStep implements WizardStepInterface {

    public const FIELD = 'tt_media_added';

    public function slug(): string { return 'source'; }

    public function label(): string { return __( 'Photos and video', 'talenttrack' ); }

    public function render( array $state ): void {
        $type = (string) ( $state['entity_type'] ?? '' );
        $id   = (int) ( $state['entity_id'] ?? 0 );

        if ( ! MediaEntityType::isValid( $type ) || $id <= 0 ) {
            echo '<p>' . esc_html__( 'Go back and choose who this is for first.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<p>' . esc_html(
            sprintf(
                /* translators: %s is the player, team or training the media is being added to. */
                __( 'Add photos or video for %s.', 'talenttrack' ),
                MediaTargetStep::labelFor( $type, $id )
            )
        ) . '</p>';

        MediaUploader::render( [
            'entity_type' => $type,
            'entity_id'   => $id,
            'allow_link'  => true,
            'state_field' => self::FIELD,
        ] );

        echo '<p class="description">'
            . esc_html__( 'Files are saved as soon as they finish uploading, so you can leave at any point without losing them.', 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        $raw   = isset( $post[ self::FIELD ] ) ? (string) $post[ self::FIELD ] : '';
        $uuids = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

        if ( $uuids === [] ) {
            return new \WP_Error(
                'nothing_added',
                __( 'Add at least one photo, video or link before continuing.', 'talenttrack' )
            );
        }

        // Never trust the field: it is a client-supplied list, so every
        // uuid is re-read from the database and dropped if it does not
        // resolve to a real item in this club.
        $repo  = new MediaRepository();
        $valid = [];
        foreach ( $uuids as $uuid ) {
            $media = $repo->findByUuid( $uuid );
            if ( $media ) $valid[] = (string) $media->uuid;
        }

        if ( $valid === [] ) {
            return new \WP_Error( 'nothing_added', __( 'Those uploads could not be found. Try adding them again.', 'talenttrack' ) );
        }

        return [ 'media_uuids' => $valid ];
    }

    public function nextStep( array $state ): ?string { return 'details'; }

    public function submit( array $state ) { return null; }
}
