<?php
namespace TT\Modules\Threads\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\TeamDevelopment\TeamChemistryAccess;
use TT\Modules\Threads\Domain\ThreadTypeAdapter;

/**
 * BlueprintThreadAdapter (#0068 Phase 3) — third registered thread
 * type after `goal` and `player`. Anchors a discussion thread on
 * one row of `tt_team_blueprints`.
 *
 * Staff-only by design (mirrors `PlayerThreadAdapter`). Authority is the
 * `team_chemistry` matrix entity, resolved through `TeamChemistryAccess`
 * (#1922 / #1939) so the thread gate can't drift from the blueprint
 * surfaces that own the same data:
 *   - Read  = `team_chemistry` READ authority   (the editor surfaces).
 *   - Post  = `team_chemistry` CHANGE authority (the lock / status-change cap).
 *
 * Parents reaching the public share-link in Phase 4 never see the
 * comments — the share-link's read-only render explicitly skips the
 * Comments tab. If parent feedback ever becomes a feature, it ships
 * as its own thread type with its own visibility rules (per the
 * decisions Q1 in `specs/0068-feat-team-blueprint-phases-3-4.md`).
 */
final class BlueprintThreadAdapter implements ThreadTypeAdapter {

    public function findEntity( int $thread_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, team_id FROM {$wpdb->prefix}tt_team_blueprints WHERE id = %d AND club_id = %d",
            $thread_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * No specific @-mention parsing in v1 — broadcast to everyone
     * `canRead` returns true for, same as `PlayerThreadAdapter`. The
     * notification subscriber already scope-filters via `canRead`.
     *
     * @return list<int>
     */
    public function participantUserIds( int $thread_id ): array {
        return [];
    }

    public function canRead( int $user_id, int $thread_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! $this->findEntity( $thread_id ) ) return false;
        return TeamChemistryAccess::canRead( $user_id );
    }

    public function canPost( int $user_id, int $thread_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! $this->findEntity( $thread_id ) ) return false;
        return TeamChemistryAccess::canManage( $user_id );
    }

    public function entityLabel( int $thread_id ): string {
        $bp = $this->findEntity( $thread_id );
        if ( ! $bp ) return '';
        /* translators: %s: blueprint name */
        return sprintf( __( 'Comments — %s', 'talenttrack' ), (string) $bp->name );
    }
}
