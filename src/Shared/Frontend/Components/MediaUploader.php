<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\Ingest\MediaIngestService;
use TT\Modules\Media\MediaAttachmentPolicy;
use TT\Modules\Media\MediaEntityType;

/**
 * MediaUploader (#2593, epic #2589) — the one upload control.
 *
 * Rendered inside the wizard's source step and, from #2594, inline on a
 * record's media tab. One component rather than two, because two would
 * drift: the wizard's copy would gain a fix the inline one never got, and
 * the progress and error handling would diverge exactly where a coach on
 * mobile data notices.
 *
 * It renders markup and configuration; `frontend-media-uploader.js` does
 * the work. Uploads go to the REST endpoint (#2592), so the browser talks
 * to the same contract any other client would.
 *
 * Mobile-first (CLAUDE.md §2): `capture="environment"` puts the camera one
 * tap away pitch-side, targets clear 48px, and the effective server limit
 * is shown **before** a coach picks a 300MB file rather than after the
 * upload fails.
 *
 * What it offers is narrowed by `MediaAttachmentPolicy` (#2648) rather than
 * by the caller: the `accept` list, the link box, the camera hint and the
 * copy all follow from the target's kinds. A documents-only target such as
 * a course submission therefore drops `capture` — asking a phone for the
 * camera when only a written plan is accepted is a dead end — and says so
 * in the drop zone. The server re-checks; `accept` only steers the picker.
 */
final class MediaUploader {

    /**
     * @param array{
     *   entity_type: string,
     *   entity_id: int,
     *   allow_link?: bool,
     *   state_field?: string,
     *   describe?: bool
     * } $args
     */
    public static function render( array $args ): void {
        $entity_type = (string) ( $args['entity_type'] ?? '' );
        $entity_id   = (int) ( $args['entity_id'] ?? 0 );

        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) return;

        self::enqueue();

        // What this target accepts is the policy's call, not the caller's.
        // A caller may still switch the link box off, but may not switch it
        // on where the policy refuses external links — otherwise the
        // control would offer a field whose every value is rejected.
        $documents_only = MediaAttachmentPolicy::isDocumentsOnly( $entity_type );
        $allow_link     = ! isset( $args['allow_link'] ) || (bool) $args['allow_link'];
        $allow_link     = $allow_link && MediaAttachmentPolicy::allowsExternalLink( $entity_type );

        $state_field = (string) ( $args['state_field'] ?? '' );
        $limit       = MediaIngestService::maxUploadBytes();

        $accept = implode( ',', MediaAttachmentPolicy::acceptMimes(
            $entity_type,
            MediaIngestService::allowedTypes()
        ) );

        echo '<div class="tt-media-uploader"'
            . ' data-entity-type="' . esc_attr( $entity_type ) . '"'
            . ' data-entity-id="' . (int) $entity_id . '"'
            . ' data-max-bytes="' . (int) $limit . '"'
            . ( $state_field !== '' ? ' data-state-field="' . esc_attr( $state_field ) . '"' : '' )
            . '>';

        // The drop zone is a label wrapping the input, so a tap anywhere in
        // it opens the picker without any JS at all — the control still
        // works if the script fails to load.
        echo '<label class="tt-media-dropzone" data-role="dropzone">';
        echo '<span class="tt-media-dropzone__icon" aria-hidden="true">+</span>';
        echo '<span class="tt-media-dropzone__label">'
            . esc_html(
                $documents_only
                    ? __( 'Choose a document', 'talenttrack' )
                    : __( 'Choose photos or video', 'talenttrack' )
            )
            . '</span>';
        echo '<span class="tt-media-dropzone__hint">'
            . esc_html(
                sprintf(
                    /* translators: %s is the largest file this server accepts, e.g. "64 MB". */
                    __( 'Up to %s per file on this server.', 'talenttrack' ),
                    size_format( $limit )
                )
            )
            . '</span>';
        if ( $documents_only ) {
            echo '<span class="tt-media-dropzone__hint">'
                . esc_html__( 'PDF, Word, spreadsheet or plain text. Photos and video are not accepted here.', 'talenttrack' )
                . '</span>';
        }
        // `capture` asks for the camera, which is right for a photograph and
        // wrong for a document — on a phone it would open the lens for a
        // file that can only be a written plan.
        printf(
            '<input type="file" class="tt-media-file-input" data-role="file" accept="%1$s"%2$s multiple />',
            esc_attr( $accept ),
            $documents_only ? '' : ' capture="environment"'
        );
        echo '</label>';

        echo '<ul class="tt-media-queue" data-role="queue"></ul>';

        if ( $allow_link ) {
            echo '<div class="tt-media-linkbox">';
            echo '<label for="tt-media-link-url"><span>'
                . esc_html__( 'Or paste a link to video hosted elsewhere', 'talenttrack' )
                . '</span></label>';
            echo '<div class="tt-media-linkbox__row">';
            printf(
                '<input type="url" id="tt-media-link-url" class="tt-input" data-role="link-url" inputmode="url" autocomplete="off" placeholder="%s" />',
                esc_attr__( 'https://…', 'talenttrack' )
            );
            echo '<button type="button" class="tt-btn tt-btn-secondary" data-role="link-add">'
                . esc_html__( 'Add link', 'talenttrack' ) . '</button>';
            echo '</div>';
            echo '<p class="description">'
                . esc_html__( 'Veo, Hudl, YouTube and Vimeo are recognised automatically. Anything else is saved as a plain link with a title you type.', 'talenttrack' )
                . '</p>';
            echo '</div>';
        }

        if ( $state_field !== '' ) {
            printf( '<input type="hidden" name="%s" data-role="state" value="" />', esc_attr( $state_field ) );
        }

        echo '<p class="tt-media-uploader__status" data-role="status" role="status" aria-live="polite"></p>';
        echo '</div>';
    }

    /**
     * Enqueue once per request, however many uploaders a page renders.
     */
    public static function enqueue(): void {
        if ( wp_script_is( 'tt-media-uploader', 'enqueued' ) ) return;

        wp_enqueue_style(
            'tt-media',
            plugins_url( 'assets/css/frontend-media.css', TT_PLUGIN_FILE ),
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-media-uploader',
            plugins_url( 'assets/js/frontend-media-uploader.js', TT_PLUGIN_FILE ),
            [],
            TT_VERSION,
            true
        );

        wp_localize_script( 'tt-media-uploader', 'TT_Media', [
            'root'  => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [
                'uploading'    => __( 'Uploading…', 'talenttrack' ),
                'done'         => __( 'Added', 'talenttrack' ),
                'cancel'       => __( 'Cancel', 'talenttrack' ),
                'cancelled'    => __( 'Cancelled', 'talenttrack' ),
                'remove'       => __( 'Remove', 'talenttrack' ),
                'failed'       => __( 'Could not be added', 'talenttrack' ),
                'tooLarge'     => __( 'This file is larger than the server accepts.', 'talenttrack' ),
                'badType'      => __( 'That file type cannot be added. Use a JPEG, PNG or WebP image, or an MP4 or MOV video.', 'talenttrack' ),
                'linkNeeded'   => __( 'Paste a web address first.', 'talenttrack' ),
                'networkError' => __( 'The upload failed. Check your connection and try again.', 'talenttrack' ),
                /* translators: %d is how many files were added. */
                'addedCount'   => __( '%d added', 'talenttrack' ),
            ],
        ] );
    }
}
