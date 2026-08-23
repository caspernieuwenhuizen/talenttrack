<?php
namespace TT\Modules\Authorization\Matrix;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatrixEditService (#2654) — the one place a matrix edit is applied.
 *
 * ## Why this exists
 *
 * The diff-and-write loop lived inside `MatrixPage::handleSave`, reading
 * `$_POST` directly. That was fine while wp-admin was the only editor.
 * The moment a second consumer appears — the frontend view, and REST
 * behind it — an inlined loop means two implementations of "what a save
 * does", and the one that drifts is the one nobody is looking at.
 *
 * So: wp-admin, the frontend view and the REST controller all call
 * `applyGrid()`. The audit rows they write are identical because there is
 * only one writer.
 *
 * ## What is submitted, and what that means
 *
 * `$scopes` is the coverage declaration. The form renders one
 * `scope[persona|entity]` select per cell it is allowed to edit, so the
 * keys present in `$scopes` say exactly which persona/entity pairs this
 * submission speaks for. Pairs absent from `$scopes` are left untouched —
 * which is what makes a partial REST payload safe, and what makes a
 * disabled (protected) cell genuinely unwritable rather than merely
 * unclickable.
 *
 * Within a covered pair, a missing or falsy `cell[persona|entity|activity]`
 * means *revoked*, because an unchecked checkbox submits nothing at all.
 * That asymmetry is the HTML form contract, not a choice.
 *
 * ## The escalation guardrail
 *
 * A club admin may edit the matrix, but not the parts of it that decide
 * who may edit the matrix. Their own persona is off limits (no
 * self-escalation) and so is a small set of entities that govern the
 * permission model, the schema and the backups. A WordPress
 * administrator has no such restriction — they are the recovery path.
 *
 * The guardrail is enforced HERE rather than in markup. A disabled input
 * is a courtesy to the person using the screen; it is not a control. A
 * hand-crafted POST or a direct REST call reaches this method, and this
 * method rejects the cell without writing a row or an audit entry.
 */
final class MatrixEditService {

    /** @var list<string> */
    public const ACTIVITIES = [ 'read', 'change', 'create_delete' ];

    /** @var list<string> */
    public const SCOPE_KINDS = [ 'global', 'team', 'player', 'self' ];

    /**
     * Entities a non-administrator may never edit, whatever their persona.
     *
     * Each one either governs the permission model itself, or is the way
     * back from a mistake in it. A club admin who could grant their own
     * persona `create_delete` on `authorization_matrix` would have granted
     * themselves everything, one save later.
     *
     * @var list<string>
     */
    public const PROTECTED_ENTITIES = [
        'authorization_matrix',
        'authorization_changelog',
        'settings',
        'migrations',
        'backup',
        'module_management',
        'feature_toggles',
        'functional_role_definitions',
    ];

    /**
     * Persona rows a non-administrator may never edit.
     *
     * `academy_admin` is the persona a club admin resolves to, so editing
     * that row is editing their own rights. Everything a club admin
     * legitimately needs to fix — a coach who sees too much, a parent who
     * sees too little — lives in the other rows.
     *
     * @var list<string>
     */
    public const PROTECTED_PERSONAS = [ 'academy_admin' ];

    /**
     * What this user may edit.
     *
     * One source of truth for the view (which cells to disable), REST
     * (what to advertise) and the writer (what to reject), so the three
     * cannot disagree about the same user.
     *
     * @return array{unrestricted:bool, protected_entities:list<string>, protected_personas:list<string>}
     */
    public static function editableFor( int $user_id ): array {
        $unrestricted = self::isUnrestricted( $user_id );

        return [
            'unrestricted'       => $unrestricted,
            'protected_entities' => $unrestricted ? [] : self::PROTECTED_ENTITIES,
            'protected_personas' => $unrestricted ? [] : self::PROTECTED_PERSONAS,
        ];
    }

    /** May this user change the cells at (persona, entity)? */
    public static function canEditCell( int $user_id, string $persona, string $entity ): bool {
        if ( self::isUnrestricted( $user_id ) ) return true;
        if ( in_array( $persona, self::PROTECTED_PERSONAS, true ) ) return false;

        return ! in_array( $entity, self::PROTECTED_ENTITIES, true );
    }

    /**
     * A WordPress administrator, and nobody else.
     *
     * `manage_options` rather than a role-name compare (CLAUDE.md §4):
     * the capability is portable to a front end that has no WordPress
     * roles behind it, and it is the capability that actually
     * distinguishes an administrator on this install.
     */
    private static function isUnrestricted( int $user_id ): bool {
        return $user_id > 0 && user_can( $user_id, 'manage_options' );
    }

    /**
     * Apply a submitted grid and write the audit trail.
     *
     * @param array<string, string> $cells  `persona|entity|activity` => a truthy value means granted.
     * @param array<string, string> $scopes `persona|entity` => scope kind. The keys declare coverage.
     * @return array{grants:int, revokes:int, scope_changes:int, rejected:int}
     */
    public function applyGrid( array $cells, array $scopes, int $actor_id ): array {
        $repo     = new MatrixRepository();
        $entities = $repo->entities();
        $grid     = $repo->asGrid();
        $personas = $repo->personas();

        $entity_module = [];
        foreach ( $entities as $e ) {
            $entity_module[ (string) $e['entity'] ] = (string) $e['module_class'];
        }
        $persona_set = array_flip( $personas );

        $summary = [ 'grants' => 0, 'revokes' => 0, 'scope_changes' => 0, 'rejected' => 0 ];

        global $wpdb;
        $changelog = $wpdb->prefix . 'tt_authorization_changelog';
        $now       = current_time( 'mysql' );

        foreach ( $scopes as $pair_key => $submitted_scope ) {
            $parts = explode( '|', (string) $pair_key );
            if ( count( $parts ) !== 2 ) continue;

            [ $persona, $entity ] = $parts;

            // An unknown persona or entity is not an error worth failing the
            // whole save for — it is a stale form, or a client sending a
            // typo. Skip it; the matrix only ever holds tuples it knows.
            if ( ! isset( $persona_set[ $persona ] ) || ! isset( $entity_module[ $entity ] ) ) continue;

            if ( ! self::canEditCell( $actor_id, $persona, $entity ) ) {
                $summary['rejected']++;
                continue;
            }

            $scope_kind = in_array( $submitted_scope, self::SCOPE_KINDS, true )
                ? (string) $submitted_scope
                : 'global';
            $module = $entity_module[ $entity ];

            foreach ( self::ACTIVITIES as $activity ) {
                // `! empty` rather than `isset`: an HTML checkbox submits
                // "1" or nothing at all, but a JSON client sends `false` to
                // revoke, and reading that as "the key is there, so it is
                // granted" would turn every revoke into a grant.
                $now_set     = ! empty( $cells[ $persona . '|' . $entity . '|' . $activity ] );
                $was_details = $grid[ $persona ][ $entity ][ $activity ] ?? null;
                $was_set     = (bool) $was_details;
                $was_scope   = (string) ( $was_details['scope_kind'] ?? 'global' );

                if ( $now_set === $was_set && $was_scope === $scope_kind ) continue;

                if ( $now_set ) {
                    // The unique key is (persona, entity, activity, scope_kind),
                    // so a scope change is a remove followed by an insert
                    // rather than an update.
                    if ( $was_set && $was_scope !== $scope_kind ) {
                        $repo->removeRow( $persona, $entity, $activity, $was_scope );
                    }
                    $repo->setRow( $persona, $entity, $activity, $scope_kind, $module );
                    $wpdb->insert( $changelog, [
                        'persona'       => $persona,
                        'entity'        => $entity,
                        'activity'      => $activity,
                        'scope_kind'    => $scope_kind,
                        'change_type'   => $was_set ? 'scope_change' : 'grant',
                        'before_value'  => $was_set ? 1 : 0,
                        'after_value'   => 1,
                        'actor_user_id' => $actor_id,
                        'note'          => $was_set ? "scope: {$was_scope} → {$scope_kind}" : null,
                        'created_at'    => $now,
                    ] );
                    if ( $was_set ) {
                        $summary['scope_changes']++;
                    } else {
                        $summary['grants']++;
                    }
                } elseif ( $was_set ) {
                    $repo->removeRow( $persona, $entity, $activity, $was_scope );
                    $wpdb->insert( $changelog, [
                        'persona'       => $persona,
                        'entity'        => $entity,
                        'activity'      => $activity,
                        'scope_kind'    => $was_scope,
                        'change_type'   => 'revoke',
                        'before_value'  => 1,
                        'after_value'   => 0,
                        'actor_user_id' => $actor_id,
                        'note'          => null,
                        'created_at'    => $now,
                    ] );
                    $summary['revokes']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Reseed from the shipped defaults, and say so in the changelog.
     *
     * Administrator-only at every caller: a reset discards every edit
     * anybody made, including the ones a club admin cannot make.
     */
    public function resetToDefaults( int $actor_id ): void {
        ( new MatrixRepository() )->reseed();

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_authorization_changelog', [
            'persona'       => '*',
            'entity'        => '*',
            'activity'      => '*',
            'scope_kind'    => '*',
            'change_type'   => 'reset',
            'actor_user_id' => $actor_id,
            'note'          => 'matrix reset to seed defaults',
            'created_at'    => current_time( 'mysql' ),
        ] );
    }

    /**
     * Persona display names.
     *
     * Here rather than on either surface so the wp-admin column header and
     * the frontend column header cannot end up calling the same persona
     * two different things.
     *
     * @return array<string, string>
     */
    public static function personaLabels(): array {
        return [
            'player'              => __( 'Player', 'talenttrack' ),
            'parent'              => __( 'Parent', 'talenttrack' ),
            'assistant_coach'     => __( 'Assistant Coach', 'talenttrack' ),
            'head_coach'          => __( 'Head Coach', 'talenttrack' ),
            'head_of_development' => __( 'Head of Dev', 'talenttrack' ),
            'scout'               => __( 'Scout', 'talenttrack' ),
            'team_manager'        => __( 'Team Manager', 'talenttrack' ),
            'academy_admin'       => __( 'Academy Admin', 'talenttrack' ),
        ];
    }

    public static function personaLabel( string $persona ): string {
        $map = self::personaLabels();

        return $map[ $persona ] ?? $persona;
    }
}
