<?php
namespace TT\Modules\Methodology\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Repositories\PrincipleLinksRepository;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;

/**
 * ActivityPrinciples (#2831) — the principles an activity is linked to,
 * resolved once for every surface that shows them.
 *
 * The activity detail card built this inline: read the pivot, look each
 * principle up, pull the multilingual title, derive the O/A/V bucket from
 * the first letter of the code. Match prep needed exactly the same list
 * and the PDF and print renderers needed it again, which is three more
 * copies of a derivation that is not obvious (the bucket is a code prefix,
 * not a column) and one more place for them to drift.
 *
 * So it lives here, in the domain layer, and the views compose (CLAUDE.md
 * §4). The REST payload reads the same method, which is what lets a
 * non-WordPress front end draw the same sheet.
 *
 * **Read-only, and deliberately.** Principles are attached on the activity
 * form; match prep reports what the match is working on, it does not decide
 * it. One place to answer "which principle is this match about" is the whole
 * point of reading it from the activity rather than picking it again.
 */
final class ActivityPrinciples {

    /** Methodology bucket prefixes: Opbouw / Aanvallen / Verdedigen. */
    private const BUCKETS = [ 'O', 'A', 'V' ];

    /**
     * Is the methodology layer present at all? An academy running without it
     * has no principles to link, which is a different answer from "this match
     * has none yet".
     */
    public static function isAvailable(): bool {
        return class_exists( PrincipleLinksRepository::class )
            && class_exists( PrinciplesRepository::class );
    }

    /**
     * @return list<array{id:int, code:string, title:string, bucket:string, label:string}>
     */
    public static function forActivity( int $activity_id ): array {
        if ( $activity_id <= 0 || ! self::isAvailable() ) return [];

        $ids = ( new PrincipleLinksRepository() )->principlesForActivity( $activity_id );
        if ( empty( $ids ) ) return [];

        $repo = new PrinciplesRepository();
        $out  = [];

        foreach ( $ids as $pid ) {
            $row = $repo->find( (int) $pid );
            if ( ! $row ) continue;

            $code  = (string) ( $row->code ?? '' );
            $title = '';
            if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                // `find()` returns a bare stdClass row, so the column is read
                // defensively: a principle seeded before the multilingual
                // column existed simply has no title to resolve.
                $title_json = $row->title_json ?? null; // @phpstan-ignore-line property.notFound
                $title      = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $title_json );
            }

            $out[] = [
                'id'     => (int) $pid,
                'code'   => $code,
                'title'  => $title,
                'bucket' => self::bucket( $code ),
                'label'  => $code . ( $title !== '' ? ' · ' . $title : '' ),
            ];
        }

        return $out;
    }

    /**
     * The colour bucket, from the first letter of the principle code. Any
     * code outside the three-letter vocabulary falls back to `O` rather than
     * rendering an unstyled pill.
     */
    public static function bucket( string $code ): string {
        $first = $code !== '' ? strtoupper( $code[0] ) : '';

        return in_array( $first, self::BUCKETS, true ) ? $first : 'O';
    }
}
