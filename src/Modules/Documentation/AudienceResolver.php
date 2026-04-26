<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AudienceResolver — parses the `<!-- audience: ... -->` marker out of
 * a doc file and resolves which audience set a logged-in user can see
 * in the in-product `tt-docs` index (#0029).
 *
 * Markers live at the top of every `docs/*.md` file:
 *
 *   <!-- audience: user -->
 *   <!-- audience: admin -->
 *   <!-- audience: dev -->
 *   <!-- audience: user, admin -->     # cross-cutting
 *
 * Allowed values: user, admin, dev. Direct URL access is not gated;
 * the audience filter only affects what shows up in the sidebar TOC.
 */
final class AudienceResolver {

    public const USER  = 'user';
    public const ADMIN = 'admin';
    public const DEV   = 'dev';

    private const VALID = [ self::USER, self::ADMIN, self::DEV ];

    /**
     * @return array<string, string> machine key => translated label
     */
    public static function labels(): array {
        return [
            self::USER  => __( 'User',  'talenttrack' ),
            self::ADMIN => __( 'Admin', 'talenttrack' ),
            self::DEV   => __( 'Dev',   'talenttrack' ),
        ];
    }

    /**
     * Read a file's audience marker. Returns the list of audiences
     * declared, deduped + lower-cased. An empty list means the marker
     * is missing or malformed; the caller can decide whether that's a
     * lint failure or a "default to user" softening.
     *
     * @return list<string>
     */
    public static function readFromFile( ?string $path ): array {
        if ( $path === null || ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) return [];
        $head = (string) @file_get_contents( $path, false, null, 0, 512 );
        if ( $head === '' ) return [];
        return self::parse( $head );
    }

    /**
     * @return list<string>
     */
    public static function parse( string $head ): array {
        if ( ! preg_match( '/<!--\s*audience\s*:\s*([^>]+?)\s*-->/i', $head, $m ) ) return [];
        $raw   = (string) $m[1];
        $parts = array_map( 'trim', explode( ',', strtolower( $raw ) ) );
        $clean = [];
        foreach ( $parts as $p ) {
            if ( in_array( $p, self::VALID, true ) && ! in_array( $p, $clean, true ) ) {
                $clean[] = $p;
            }
        }
        return $clean;
    }

    /**
     * Audiences the given user is allowed to see in the docs index.
     *
     *   tt_player / tt_readonly_observer / tt_staff / tt_coach → user
     *   tt_head_dev                                            → user + admin
     *   WP administrator                                       → user + admin + dev
     *
     * Multi-role users get the union. Anyone signed in falls back to
     * the user audience as a floor — there's no "no audience" state
     * for a logged-in viewer.
     *
     * @return list<string>
     */
    public static function allowedFor( int $user_id = 0 ): array {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        $user    = $user_id > 0 ? get_userdata( $user_id ) : null;
        $allowed = [ self::USER ];

        if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
            return [ self::USER, self::ADMIN, self::DEV ];
        }
        if ( user_can( $user_id, 'tt_head_dev' ) || user_can( $user_id, 'tt_edit_settings' ) ) {
            $allowed[] = self::ADMIN;
        }
        return array_values( array_unique( $allowed ) );
    }

    /**
     * Decide whether a doc with `$doc_audiences` should appear in the
     * index for a viewer with `$viewer_audiences`. Match if any
     * declared audience overlaps. A doc with no marker (empty list)
     * is hidden from filtered indexes — surfacing those is what the
     * lint catches before merge.
     *
     * @param list<string> $doc_audiences
     * @param list<string> $viewer_audiences
     */
    public static function isVisible( array $doc_audiences, array $viewer_audiences ): bool {
        if ( empty( $doc_audiences ) ) return false;
        return (bool) array_intersect( $doc_audiences, $viewer_audiences );
    }
}
