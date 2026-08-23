<?php
namespace TT\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaAttachmentPolicy (#2648, epic #2589) — which kinds an attachment
 * target accepts.
 *
 * Until course submissions arrived, every entity type accepted every kind
 * and this question had no reason to exist. `course_submission` is the
 * first target that accepts documents and refuses photos and video, so the
 * answer became per-type and needed somewhere to live that is neither the
 * uploader nor the REST controller — both of which now ask it.
 *
 * ## Why submissions are documents only
 *
 * A submission is a coach's own coursework, attached to no player and no
 * team. That is exactly what makes an image risky: a photo taken at a
 * training could depict minors, and because the media item hangs off a
 * submission rather than a player, none of the consent, visibility and
 * retention rules that govern player media would reach it. Written work
 * is what the assignments actually ask for, so the narrow lane costs the
 * feature nothing and keeps a whole category of accident off the table.
 *
 * Revisiting this means deciding how a photo on a submission inherits the
 * player-media rules — not just widening the list here.
 *
 * ## Enforced server-side
 *
 * The uploader reads this to set its `accept` attribute and its copy, but
 * `accept` is a hint to a file picker and nothing more. `create_media`
 * checks the resolved kind against this policy after ingest and before the
 * row is written, which is the check that actually holds.
 */
final class MediaAttachmentPolicy {

    /**
     * Kinds each entity type accepts. A type absent from this map accepts
     * everything, which keeps the three original targets behaving exactly
     * as they did before this class existed.
     *
     * @var array<string, list<string>>
     */
    private const RESTRICTED = [
        MediaEntityType::COURSE_SUBMISSION => [ MediaKind::DOCUMENT ],
    ];

    /**
     * Kinds this entity type accepts, in `MediaKind::all()` order.
     *
     * @return list<string>
     */
    public static function kindsFor( string $entity_type ): array {
        return self::RESTRICTED[ $entity_type ] ?? MediaKind::all();
    }

    public static function allows( string $entity_type, string $kind ): bool {
        return in_array( $kind, self::kindsFor( $entity_type ), true );
    }

    /** True when this target takes documents and nothing else. */
    public static function isDocumentsOnly( string $entity_type ): bool {
        return self::kindsFor( $entity_type ) === [ MediaKind::DOCUMENT ];
    }

    /**
     * Whether a URL to footage hosted elsewhere may be attached here.
     *
     * Separate from `allows()` because the link box is a distinct control
     * in the uploader, and hiding it is clearer than offering a field that
     * rejects everything typed into it.
     */
    public static function allowsExternalLink( string $entity_type ): bool {
        return self::allows( $entity_type, MediaKind::VIDEO_LINK );
    }

    /**
     * Content types to advertise in the file picker's `accept`, filtered to
     * the kinds this target takes.
     *
     * @param  array<string, string> $allowed_types mime => extension
     * @return list<string>
     */
    public static function acceptMimes( string $entity_type, array $allowed_types ): array {
        $out = [];
        foreach ( array_keys( $allowed_types ) as $mime ) {
            if ( self::allows( $entity_type, MediaKind::forMime( (string) $mime ) ) ) {
                $out[] = (string) $mime;
            }
        }
        return $out;
    }

    /**
     * Why an upload was refused, for the message the coach reads.
     *
     * Named rather than assembled at the call site so the REST error and
     * the uploader hint cannot drift into saying different things.
     */
    public static function refusalMessage( string $entity_type ): string {
        if ( self::isDocumentsOnly( $entity_type ) ) {
            return __( 'Only documents can be attached here — PDF, Word, spreadsheet or plain text. Photos and video are not accepted on an assignment.', 'talenttrack' );
        }

        return __( 'That kind of file cannot be attached here.', 'talenttrack' );
    }
}
