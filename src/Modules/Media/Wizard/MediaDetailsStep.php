<?php
namespace TT\Modules\Media\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\MediaTagRoster;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Shared\Frontend\Components\MediaPlayerTagField;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — what it is (#2593).
 *
 * Title, description and the date it was taken. One set of answers for
 * everything added in step 2: a coach who uploads eight photos from one
 * training should describe the training once, not eight times.
 *
 * The capture date is prefilled from the first upload's EXIF, because
 * that is the date the player's timeline sorts on. When the photo carried
 * one, it is almost always right and the coach just confirms it.
 */
final class MediaDetailsStep implements WizardStepInterface {

    public function slug(): string { return 'details'; }

    public function label(): string { return __( 'Details', 'talenttrack' ); }

    public function render( array $state ): void {
        $uuids = (array) ( $state['media_uuids'] ?? [] );
        $count = count( $uuids );

        $title       = (string) ( $state['media_title'] ?? '' );
        $description = (string) ( $state['media_description'] ?? '' );
        $captured    = (string) ( $state['media_captured_at'] ?? self::guessCaptureDate( $uuids ) );

        if ( $count > 1 ) {
            echo '<p>' . esc_html(
                sprintf(
                    /* translators: %d is how many items were added. */
                    _n( 'These details apply to the %d item you added.', 'These details apply to all %d items you added.', $count, 'talenttrack' ),
                    $count
                )
            ) . '</p>';
        }

        echo '<label for="tt-media-title"><span>' . esc_html__( 'Title', 'talenttrack' ) . '</span></label>';
        printf(
            '<input type="text" id="tt-media-title" class="tt-input" name="media_title" value="%s" maxlength="255" autocomplete="off" />',
            esc_attr( $title )
        );

        echo '<label for="tt-media-description"><span>' . esc_html__( 'Description', 'talenttrack' ) . '</span></label>';
        printf(
            '<textarea id="tt-media-description" class="tt-input" name="media_description" rows="3">%s</textarea>',
            esc_textarea( $description )
        );

        echo '<label for="tt-media-captured"><span>' . esc_html__( 'When was this taken?', 'talenttrack' ) . '</span></label>';
        printf(
            '<input type="date" id="tt-media-captured" class="tt-input" name="media_captured_at" value="%s" />',
            esc_attr( $captured )
        );

        echo '<p class="description">'
            . esc_html__( 'The date decides where this sits on the player\'s timeline — so it is the day of the training or match, not the day you uploaded it.', 'talenttrack' )
            . '</p>';

        self::renderTagField( $state );
    }

    /**
     * Who is in it (#3093).
     *
     * Asked here rather than only afterwards on each tile, because the
     * coach knows who is in the photo at the moment they add it and is
     * gone by the time the grid renders. Absent where nothing can be
     * tagged — a photo on a player is already about that player, and a
     * team's roster is a list of everyone.
     *
     * @param array<string, mixed> $state
     */
    private static function renderTagField( array $state ): void {
        $players = MediaTagRoster::for(
            (string) ( $state['entity_type'] ?? '' ),
            (int) ( $state['entity_id'] ?? 0 )
        );

        if ( $players === [] ) return;

        $selected = [];
        foreach ( (array) ( $state['media_tag_player_ids'] ?? [] ) as $player_id ) {
            if ( isset( $players[ (int) $player_id ] ) ) $selected[ (int) $player_id ] = 0;
        }

        MediaPlayerTagField::render( [
            'mode'       => 'wizard',
            'players'    => $players,
            'selected'   => $selected,
            'field_name' => 'media_tag_player_ids',
            'mentions'   => '#tt-media-description',
            'label'      => __( 'Tagged players', 'talenttrack' ),
        ] );

        echo '<p class="description">'
            . esc_html__( 'Type a name, or type @ in the description. Everyone you tag gets this on their own record.', 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        $captured = isset( $post['media_captured_at'] ) ? trim( (string) $post['media_captured_at'] ) : '';

        if ( $captured !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $captured ) ) {
            return new \WP_Error( 'bad_date', __( 'That date could not be read. Use the date picker.', 'talenttrack' ) );
        }

        return [
            'media_title'          => isset( $post['media_title'] ) ? sanitize_text_field( (string) $post['media_title'] ) : '',
            'media_description'    => isset( $post['media_description'] ) ? sanitize_textarea_field( (string) $post['media_description'] ) : '',
            'media_captured_at'    => $captured,
            'media_tag_player_ids' => self::taggedIds( $post, $state ),
        ];
    }

    public function nextStep( array $state ): ?string { return 'confirm'; }

    public function submit( array $state ) { return null; }

    /**
     * The posted tags, kept to the roster this target actually offers.
     *
     * The field is client-supplied, so the ids are checked against the
     * roster rather than trusted — a step boundary is not an
     * authorization boundary, and the confirm step re-checks per item.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $state
     * @return array<int, int>
     */
    private static function taggedIds( array $post, array $state ): array {
        $raw = isset( $post['media_tag_player_ids'] ) ? (string) $post['media_tag_player_ids'] : '';
        if ( trim( $raw ) === '' ) return [];

        $roster = MediaTagRoster::for(
            (string) ( $state['entity_type'] ?? '' ),
            (int) ( $state['entity_id'] ?? 0 )
        );

        $out = [];
        foreach ( explode( ',', $raw ) as $candidate ) {
            $player_id = (int) trim( $candidate );
            if ( $player_id > 0 && isset( $roster[ $player_id ] ) ) $out[ $player_id ] = $player_id;
        }

        return array_values( $out );
    }

    /**
     * Capture date of the first item that has one, as `Y-m-d`.
     *
     * Falls back to empty rather than to today: a wrong date silently
     * accepted is worse on a timeline than a blank one the coach fills in.
     */
    private static function guessCaptureDate( array $uuids ): string {
        $repo = new MediaRepository();

        foreach ( $uuids as $uuid ) {
            $media = $repo->findByUuid( (string) $uuid );
            if ( $media && ! empty( $media->captured_at ) ) {
                return substr( (string) $media->captured_at, 0, 10 );
            }
        }

        return '';
    }
}
