<?php
namespace TT\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaKind (#2590, epic #2589) — the shapes a media row takes.
 *
 * `image`, `video` and `document` are bytes we hold. `video_link` is a URL
 * to footage that already lives somewhere else — Veo, Hudl, YouTube, Vimeo
 * — which is how a full match gets onto a player's record without a club
 * re-uploading a file they already have hosted.
 *
 * `document` (#2648) arrived for course assignments, where what a coach
 * hands in is a written plan rather than footage. It is bytes in the same
 * private store with the same lifecycle; what separates it is that nothing
 * renders it inline — no thumbnail, no dimensions, no capture date. Every
 * branch in the ingest path already keys on `IMAGE`, so documents fall
 * through them correctly without a new branch.
 *
 * Which kinds a given attachment target accepts is not decided here.
 * `MediaAttachmentPolicy` owns that, because it varies by entity type.
 */
final class MediaKind {

    public const IMAGE      = 'image';
    public const VIDEO      = 'video';
    public const VIDEO_LINK = 'video_link';
    public const DOCUMENT   = 'document';

    /**
     * Content types that land as `document`.
     *
     * A subset of `MediaIngestService::ALLOWED`, which remains the gate on
     * what may be ingested at all; this only sorts what got through.
     */
    public const DOCUMENT_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/plain',
    ];

    /** @return list<string> */
    public static function all(): array {
        return [ self::IMAGE, self::VIDEO, self::VIDEO_LINK, self::DOCUMENT ];
    }

    public static function isValid( string $kind ): bool {
        return in_array( $kind, self::all(), true );
    }

    /** True when the row's bytes live in our own store. */
    public static function isStored( string $kind ): bool {
        return $kind === self::IMAGE || $kind === self::VIDEO || $kind === self::DOCUMENT;
    }

    /**
     * The kind a content type lands as.
     *
     * Documents are matched explicitly rather than inferred from "not
     * video". Keeping `image` as the fallback is what stops an
     * unrecognised type from being waved into the document lane, where
     * nothing would ever look at its pixels again.
     */
    public static function forMime( string $mime ): string {
        if ( strpos( $mime, 'video/' ) === 0 ) return self::VIDEO;
        if ( in_array( $mime, self::DOCUMENT_MIMES, true ) ) return self::DOCUMENT;
        return self::IMAGE;
    }

    public static function label( string $kind ): string {
        switch ( $kind ) {
            case self::VIDEO:
                return __( 'Video', 'talenttrack' );
            case self::VIDEO_LINK:
                return __( 'Video link', 'talenttrack' );
            case self::DOCUMENT:
                return __( 'Document', 'talenttrack' );
            default:
                return __( 'Photo', 'talenttrack' );
        }
    }
}
