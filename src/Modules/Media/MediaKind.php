<?php
namespace TT\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaKind (#2590, epic #2589) — the three shapes a media row takes.
 *
 * `image` and `video` are bytes we hold. `video_link` is a URL to footage
 * that already lives somewhere else — Veo, Hudl, YouTube, Vimeo — which
 * is how a full match gets onto a player's record without a club
 * re-uploading a file they already have hosted.
 */
final class MediaKind {

    public const IMAGE      = 'image';
    public const VIDEO      = 'video';
    public const VIDEO_LINK = 'video_link';

    /** @return list<string> */
    public static function all(): array {
        return [ self::IMAGE, self::VIDEO, self::VIDEO_LINK ];
    }

    public static function isValid( string $kind ): bool {
        return in_array( $kind, self::all(), true );
    }

    /** True when the row's bytes live in our own store. */
    public static function isStored( string $kind ): bool {
        return $kind === self::IMAGE || $kind === self::VIDEO;
    }

    public static function forMime( string $mime ): string {
        return strpos( $mime, 'video/' ) === 0 ? self::VIDEO : self::IMAGE;
    }

    public static function label( string $kind ): string {
        switch ( $kind ) {
            case self::VIDEO:
                return __( 'Video', 'talenttrack' );
            case self::VIDEO_LINK:
                return __( 'Video link', 'talenttrack' );
            default:
                return __( 'Photo', 'talenttrack' );
        }
    }
}
