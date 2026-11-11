<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;
use TT\Modules\Comms\Templates\PdpConversationSignedTemplate;
use TT\Domain\Vocabularies\Enums\PdpConversationTemplate;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PdpConversationSignedSend (#4981, epic #4975) — tells the player and the
 * family that a conversation on a PDP form has been signed off, so they can
 * read it and confirm.
 *
 * Listens on `tt_pdp_conversation_signed_off`, which `PdpFormSignoff` fires
 * once, after the commit of a sign-off that went through. A refused or
 * retried sign-off does not fire it, so nothing is sent twice. It is sent
 * whether or not the player prepared.
 *
 * Recipients follow the age tiers like every message about one player
 * (`RecipientResolver::forPlayer()`, development content), and of the
 * parents in the tier only those the child's `pdp` switch lets read the PDP.
 * The dispatcher sends each in their own language and respects quiet hours
 * and opt-outs.
 */
final class PdpConversationSignedSend {

    public static function init(): void {
        add_action( 'tt_pdp_conversation_signed_off', [ __CLASS__, 'handle' ], 10, 3 );
    }

    /**
     * @param list<int> $goal_ids
     */
    public static function handle( int $conversation_id, int $evaluation_id = 0, array $goal_ids = [] ): void {
        self::send( $conversation_id );
    }

    /**
     * @return CommsResult[]
     */
    public static function send( int $conversation_id ): array {
        $conv = self::loadConversation( $conversation_id );
        if ( $conv === null ) return [];
        $player_id = (int) $conv->player_id;
        if ( $player_id <= 0 ) return [];

        $recipients = self::recipients( $player_id );
        if ( $recipients === [] ) return [];

        // Each reader lands on the talk, open, at its signed form. A parent's
        // link carries the child, so a parent of two lands on the right one.
        $groups = [ 'self' => [], 'parent' => [] ];
        foreach ( $recipients as $r ) {
            $groups[ $r->kind === Recipient::KIND_PARENT ? 'parent' : 'self' ][] = $r;
        }

        $results = [];
        foreach ( $groups as $group => $members ) {
            if ( $members === [] ) continue;
            $results = array_merge( $results, CommsDispatcher::dispatchSync(
                PdpConversationSignedTemplate::KEY,
                [
                    'player_name'       => trim( (string) $conv->first_name . ' ' . (string) $conv->last_name ),
                    'conversation_name' => PdpConversationTemplate::label( (string) $conv->template_key ),
                    'deep_link'         => self::link( $conversation_id, $group === 'parent' ? $player_id : null ),
                ],
                $members,
                [
                    'message_type'      => MessageType::PDP_READY,
                    'sender_user_id'    => 0,
                    'subject_player_id' => $player_id,
                    'subject_type'      => 'pdp_conversation',
                    'subject_id'        => $conversation_id,
                ]
            ) );
        }
        return $results;
    }

    /**
     * My PDP with the talk open at its signed form (#4981, #5040 walk). The
     * view opens the panel `conv` names; the fragment is the read view's
     * heading, which the page's script focuses.
     */
    public static function link( int $conversation_id, ?int $child_id ): string {
        $args = [ 'tt_view' => 'my-pdp', 'conv' => $conversation_id ];
        if ( $child_id !== null && $child_id > 0 ) $args['player_id'] = $child_id;
        return add_query_arg( $args, RecordLink::dashboardUrl() ) . '#tt-pdp-read-' . $conversation_id;
    }

    /**
     * Who is told: the player's tiered audience (#0042), as for every other
     * message about one player, and of its parents only those the child's
     * `pdp` switch lets read the PDP. A parent outside the tier is not told,
     * and still reads and confirms the talk in My PDP.
     *
     * @return list<Recipient>
     */
    public static function recipients( int $player_id ): array {
        $shared = ( new PlayerParentVisibilityRepository() )->isVisible( $player_id, 'pdp' );

        $out = [];
        foreach ( ( new RecipientResolver() )->forPlayer( $player_id, true ) as $r ) {
            if ( $r->kind === Recipient::KIND_PARENT ) {
                // A parent with an account answers the same question as the
                // PDP screen; a contact without one follows the switch.
                $allowed = $r->userId > 0
                    ? AuthorizationService::parentCanViewSection( $r->userId, $player_id, 'pdp' )
                    : $shared;
                if ( ! $allowed ) continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    private static function loadConversation( int $conversation_id ): ?\stdClass {
        if ( $conversation_id <= 0 ) return null;
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.id, c.template_key, f.player_id, pl.first_name, pl.last_name
               FROM {$p}tt_pdp_conversations c
               JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id AND f.club_id = c.club_id
               JOIN {$p}tt_players pl ON pl.id = f.player_id AND pl.club_id = c.club_id
              WHERE c.id = %d AND c.club_id = %d
              LIMIT 1",
            $conversation_id, CurrentClub::id()
        ) );
        return $row instanceof \stdClass ? $row : null;
    }
}
