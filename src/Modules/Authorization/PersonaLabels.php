<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PersonaLabels (#4504) — the one map from a persona key to the name a
 * person reads for it.
 *
 * Every screen that names a persona reads it here: the shell nav and the
 * top-bar chip, the persona switcher, the persona landing, the persona
 * dashboard editor, the authorization matrix, the MFA setting and the
 * dashboard configuration. It sits next to `PersonaResolver`, which decides
 * which persona a user holds, so the admin and REST code that labels a
 * matrix column does not depend on a Frontend class.
 *
 * Sentence case (docs/ui-copy.md). The multi-word labels carry the
 * `persona label` context so they do not share a msgid with the same words
 * used elsewhere.
 */
final class PersonaLabels {

    /**
     * Persona key → label, for every persona `PersonaResolver` can return.
     *
     * @return array<string,string>
     */
    public static function all(): array {
        return [
            'player'              => __( 'Player', 'talenttrack' ),
            'parent'              => __( 'Parent', 'talenttrack' ),
            'assistant_coach'     => _x( 'Assistant coach', 'persona label', 'talenttrack' ),
            'head_coach'          => _x( 'Head coach', 'persona label', 'talenttrack' ),
            'head_of_development' => _x( 'Head of development', 'persona label', 'talenttrack' ),
            'scout'               => __( 'Scout', 'talenttrack' ),
            'team_manager'        => _x( 'Team manager', 'persona label', 'talenttrack' ),
            'academy_admin'       => _x( 'Academy admin', 'persona label', 'talenttrack' ),
            'readonly_observer'   => __( 'Observer', 'talenttrack' ),
            // #4490 — physios and other staff on `tt_staff`.
            'staff'               => _x( 'Staff', 'persona label', 'talenttrack' ),
        ];
    }

    /** The label for a persona key, or '' when the key is unknown. */
    public static function label( string $persona ): string {
        return self::all()[ $persona ] ?? '';
    }

    /**
     * The labels for the given keys, in the given order. An unknown key is
     * left out, so a caller's own list of selectable personas stays the
     * list of keys it names.
     *
     * @param list<string> $personas
     * @return array<string,string>
     */
    public static function forKeys( array $personas ): array {
        $all = self::all();
        $out = [];
        foreach ( $personas as $persona ) {
            if ( isset( $all[ $persona ] ) ) $out[ $persona ] = $all[ $persona ];
        }
        return $out;
    }
}
