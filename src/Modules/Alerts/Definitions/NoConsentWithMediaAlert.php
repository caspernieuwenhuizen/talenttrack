<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * NoConsentWithMediaAlert (#3805) — there are pictures of this child on
 * file and nobody recorded that the family agreed.
 *
 * Which player question does this answer? *What does this player need
 * next?* — from the part of their file that is not football. A photograph
 * of a minor sitting in an academy's system with no answer to "may we use
 * this" is the club's problem to fix, and until now nothing said so:
 * `media_consent` was written by one form and read by nothing that could
 * raise its hand. The gap was found by opening two players' records by
 * hand.
 *
 * ## The condition
 *
 * An active player with at least one non-archived media item linked to
 * them, and no `media_consent` on record. It resolves itself the moment
 * consent is recorded, or the last item is archived.
 *
 * ## A notification, never a gate
 *
 * #3804 locked that consent is recorded and never enforced, and this alert
 * respects that completely. It hides nothing, blurs nothing and blocks
 * nothing — a coach who cannot see a picture cannot judge whether they may
 * use it. It tells a human to go and ask the family, which is the only
 * thing that actually resolves the question.
 *
 * ## Boundary with `people.no_guardian_contact`
 *
 * The two overlap on the same child often, and deliberately are not merged.
 * One says the club cannot reach anybody about this player at all; this one
 * says there is a specific thing to ask about. A player can have a perfectly
 * reachable parent and no consent on record, which is exactly the case the
 * other alert cannot see.
 */
final class NoConsentWithMediaAlert extends AbstractPlayerAlert {

    public function key(): string {
        return 'media.no_consent_with_media';
    }

    public function module(): string {
        return 'media';
    }

    public function label(): string {
        return __( 'Pictures on file with no consent', 'talenttrack' );
    }

    public function description(): string {
        return __( 'There are photos or videos of this player on file and no record that the family agreed to the club using them. Nothing is hidden — this is a prompt to ask.', 'talenttrack' );
    }

    /** The fix is recording consent on the player's own record. */
    public function capRequired(): string {
        return 'tt_edit_players';
    }

    protected function titleFor( object $row ): string {
        $count = (int) ( $row->media_count ?? 0 );

        return sprintf(
            /* translators: 1: number of photos or videos on file, 2: player name */
            _n(
                '%1$d picture of %2$s is on file with no consent recorded.',
                '%1$d pictures of %2$s are on file with no consent recorded.',
                $count,
                'talenttrack'
            ),
            $count,
            $this->playerName( $row )
        );
    }

    /** Straight to the form with the consent tick on it. */
    protected function urlFor( object $row ): string {
        return add_query_arg(
            [ 'tt_view' => 'players', 'action' => 'edit', 'id' => $this->playerIdFor( $row ) ],
            RecordLink::dashboardUrl()
        );
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // One item per link, matching `GET /players`'s `media_count`
        // (#3804): a squad photo linked to eleven children is one item each,
        // because it is one conversation with each of eleven families.
        $sql = "SELECT p.id AS player_id, p.first_name, p.last_name, p.team_id,
                       ( SELECT COUNT(*)
                           FROM {$p}tt_media_links ml
                           JOIN {$p}tt_media m ON m.id = ml.media_id
                          WHERE ml.entity_type = 'player'
                            AND ml.entity_id = p.id
                            AND ml.club_id = p.club_id
                            AND m.archived_at IS NULL ) AS media_count
                  FROM {$p}tt_players p
                 WHERE " . QueryHelpers::clubScopeWhere( 'p' ) . "
                   AND p.archived_at IS NULL
                   AND p.trashed_at IS NULL
                   AND p.status = 'active'
                   AND ( p.media_consent IS NULL OR p.media_consent = 0 )
                   AND EXISTS (
                        SELECT 1
                          FROM {$p}tt_media_links ml2
                          JOIN {$p}tt_media m2 ON m2.id = ml2.media_id
                         WHERE ml2.entity_type = 'player'
                           AND ml2.entity_id = p.id
                           AND ml2.club_id = p.club_id
                           AND m2.archived_at IS NULL
                   )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
                 ORDER BY p.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : [];
    }
}
