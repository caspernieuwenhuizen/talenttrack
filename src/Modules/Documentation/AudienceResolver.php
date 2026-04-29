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
 *   <!-- audience: player -->
 *   <!-- audience: parent -->
 *   <!-- audience: user, admin -->     # cross-cutting
 *
 * Allowed values: user, admin, dev, player, parent. The `player` /
 * `parent` audiences (#0042) carry the install-on-iOS / install-on-
 * Android / notifications-setup / parent-handles-everything KB; they
 * are subsets of `user` so a viewer with only `user` doesn't see them
 * unless they also resolve as the matching persona.
 *
 * Direct URL access is not gated; the audience filter only affects
 * what shows up in the sidebar TOC.
 */
final class AudienceResolver {

    public const USER   = 'user';
    public const ADMIN  = 'admin';
    public const DEV    = 'dev';
    public const PLAYER = 'player';
    public const PARENT = 'parent';

    private const VALID = [ self::USER, self::ADMIN, self::DEV, self::PLAYER, self::PARENT ];

    /**
     * @return array<string, string> machine key => translated label
     */
    public static function labels(): array {
        return [
            self::USER   => __( 'User',   'talenttrack' ),
            self::ADMIN  => __( 'Admin',  'talenttrack' ),
            self::DEV    => __( 'Dev',    'talenttrack' ),
            self::PLAYER => __( 'Player', 'talenttrack' ),
            self::PARENT => __( 'Parent', 'talenttrack' ),
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
     *   tt_player                                              → user + player
     *   tt_parent                                              → user + parent
     *   tt_head_dev                                            → user + admin
     *   WP administrator                                       → user + admin + dev + player + parent
     *
     * Multi-role users get the union. Anyone signed in falls back to
     * the user audience as a floor — there's no "no audience" state
     * for a logged-in viewer. Admins see player/parent audiences too
     * so they can preview the KB their members will read.
     *
     * @return list<string>
     */
    public static function allowedFor( int $user_id = 0 ): array {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        $allowed = [ self::USER ];

        // Admin docs include the dev audience by exception. The role
        // check goes through RoleResolver per #0052 PR-B so any future
        // SaaS auth backend re-implements this in one place.
        if ( $user_id > 0 && \TT\Infrastructure\Security\RoleResolver::userHasRole( $user_id, 'administrator' ) ) {
            return [ self::USER, self::ADMIN, self::DEV, self::PLAYER, self::PARENT ];
        }
        if ( user_can( $user_id, 'tt_head_dev' ) || user_can( $user_id, 'tt_edit_settings' ) ) {
            $allowed[] = self::ADMIN;
        }
        if ( $user_id > 0 ) {
            if ( \TT\Infrastructure\Security\RoleResolver::userHasRole( $user_id, 'tt_player' ) ) {
                $allowed[] = self::PLAYER;
            }
            if ( \TT\Infrastructure\Security\RoleResolver::userHasRole( $user_id, 'tt_parent' ) ) {
                $allowed[] = self::PARENT;
            }
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
