<?php
namespace TT\Modules\Development\Notifications;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;

/**
 * AuthorNotifier — emails the original submitter on key status
 * transitions.
 *
 * Hooks `tt_dev_idea_status_changed` and sends a `wp_mail()` for the
 * three transitions that author actually cares about:
 *   - rejected     → "your idea was not accepted, here's why"
 *   - promoted     → "your idea is now #NNNN, here's the commit"
 *   - in-progress  → "we've started building it"
 *
 * Other transitions (refining → ready-for-approval, etc.) are admin
 * housekeeping and do not generate author email noise.
 */
class AuthorNotifier {

    public static function register(): void {
        add_action( 'tt_dev_idea_status_changed', [ self::class, 'onTransition' ], 10, 2 );
    }

    public static function onTransition( int $ideaId, string $status ): void {
        if ( ! in_array( $status, [
            IdeaStatus::REJECTED,
            IdeaStatus::PROMOTED,
            IdeaStatus::IN_PROGRESS,
        ], true ) ) {
            return;
        }

        $repo = new IdeaRepository();
        $idea = $repo->find( $ideaId );
        if ( ! $idea ) return;

        $author = get_userdata( (int) ( $idea->author_user_id ?? 0 ) );
        if ( ! $author || empty( $author->user_email ) ) return;

        $title = (string) $idea->title;
        switch ( $status ) {
            case IdeaStatus::REJECTED:
                $subject = sprintf(
                    /* translators: %s = idea title */
                    __( '[TalentTrack] Your idea "%s" was not accepted', 'talenttrack' ),
                    $title
                );
                $note = (string) ( $idea->rejection_note ?? '' );
                $body = sprintf(
                    /* translators: 1: idea title, 2: rejection note */
                    __( "Hi,\n\nYour submitted idea \"%1\$s\" was reviewed and not accepted.\n\nNote from the reviewer:\n%2\$s\n\nYou can submit a new idea at any time.", 'talenttrack' ),
                    $title,
                    $note !== '' ? $note : __( '(no note provided)', 'talenttrack' )
                );
                break;
            case IdeaStatus::PROMOTED:
                $subject = sprintf(
                    /* translators: %s = idea title */
                    __( '[TalentTrack] Your idea "%s" was accepted', 'talenttrack' ),
                    $title
                );
                $body = sprintf(
                    /* translators: 1: idea title, 2: filename */
                    __( "Hi,\n\nYour submitted idea \"%1\$s\" was accepted and is now in the development queue as %2\$s.\n\nYou'll get another email when work starts on it.", 'talenttrack' ),
                    $title,
                    (string) ( $idea->promoted_filename ?? '' )
                );
                break;
            case IdeaStatus::IN_PROGRESS:
                $subject = sprintf(
                    /* translators: %s = idea title */
                    __( '[TalentTrack] Work has started on "%s"', 'talenttrack' ),
                    $title
                );
                $body = sprintf(
                    /* translators: %s = idea title */
                    __( "Hi,\n\nWork has started on your accepted idea \"%s\". You'll see it move to Done once shipped.", 'talenttrack' ),
                    $title
                );
                break;
            default:
                return;
        }

        wp_mail( $author->user_email, $subject, $body );
    }
}
