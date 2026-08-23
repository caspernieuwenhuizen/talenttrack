<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\Delivery\MediaDelivery;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Shared\Frontend\Components\MediaUploader;

/**
 * SubmissionAttachments (#2648, epic #2641) — the documents hanging off a
 * handed-in assignment.
 *
 * A thin seam over the media library rather than a store of its own. The
 * files ride `tt_media_links` with `entity_type = 'course_submission'`, so
 * they inherit the private store, the delivery rules, the retention sweep
 * and the visibility model that every other file in the system gets. What
 * lives here is only the two things a submission surface needs: the list,
 * and the control that adds to it.
 *
 * ## Documents, not photographs
 *
 * `MediaAttachmentPolicy` restricts this target to documents. A submission
 * hangs off no player and no team, so an image attached here would sit
 * outside the consent and visibility rules that govern player media — and
 * a photograph taken at a training can hold minors. The assignments ask for
 * written plans, so the narrow lane costs the feature nothing. The uploader
 * reads the same policy, which is why it offers no camera here.
 */
final class SubmissionAttachments {

    /** The documents on one submission. */
    public static function listFor( int $submission_id ): array {
        if ( $submission_id <= 0 ) return [];

        return ( new MediaRepository() )->listForEntity(
            MediaEntityType::COURSE_SUBMISSION,
            $submission_id
        );
    }

    public static function countFor( int $submission_id ): int {
        return count( self::listFor( $submission_id ) );
    }

    /**
     * The attachments as links.
     *
     * Every URL is a delivery URL keyed on the media uuid, never a path
     * under `wp-content/uploads` (CLAUDE.md §4) — the bytes are in a
     * private store and the endpoint is what applies the visibility rules
     * to each request.
     */
    public static function renderList( int $submission_id ): string {
        $items = self::listFor( $submission_id );
        if ( $items === [] ) return '';

        $html  = '<div class="tt-submission-files">';
        $html .= '<h4 class="tt-submission-files__label">'
            . esc_html__( 'Attached documents', 'talenttrack' ) . '</h4>';
        $html .= '<ul class="tt-submission-files__list">';

        foreach ( $items as $item ) {
            $uuid = (string) ( $item->uuid ?? '' );
            if ( $uuid === '' ) continue;

            $title = trim( (string) ( $item->title ?? '' ) );
            if ( $title === '' ) $title = __( 'Document', 'talenttrack' );

            $size = (int) ( $item->file_size ?? 0 );

            $html .= '<li class="tt-submission-files__item">';
            $html .= '<a class="tt-submission-files__link" href="'
                . esc_url( MediaDelivery::url( $uuid ) ) . '">'
                . esc_html( $title ) . '</a>';
            if ( $size > 0 ) {
                $html .= ' <span class="tt-submission-files__meta">'
                    . esc_html( size_format( $size ) ) . '</span>';
            }
            $html .= '</li>';
        }

        $html .= '</ul></div>';

        return $html;
    }

    /**
     * The upload control for a submission that is still the coach's to
     * change.
     *
     * Returns markup rather than echoing, because the caller is a block
     * renderer that builds a string. `MediaUploader::render()` echoes, so
     * it is buffered here — the one place that conversion happens, rather
     * than at each of the two call sites.
     */
    public static function renderUploader( int $submission_id ): string {
        if ( $submission_id <= 0 ) return '';

        ob_start();
        MediaUploader::render( [
            'entity_type' => MediaEntityType::COURSE_SUBMISSION,
            'entity_id'   => $submission_id,
        ] );

        return (string) ob_get_clean();
    }
}
