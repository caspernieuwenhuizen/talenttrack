<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaConsentStatement (#3804) — what the academy may say, in words, about
 * one child's photo and video consent.
 *
 * `tt_players` has carried `media_consent`, `media_consent_at` and
 * `media_consent_by` since migration 0232, and until this issue exactly one
 * surface read them: the edit form that wrote them. Everywhere a picture of
 * a minor was actually shown — the media tab, the players list, the PDP
 * file handed to a parent — showed it with no indication of whether anyone
 * had ever asked permission to take it.
 *
 * Two things this class deliberately does not do.
 *
 * It does not hide anything. The locked decision on #3804 is mark, never
 * hide, everywhere, including the printed hand-out. A coach who cannot see
 * a photo cannot judge whether they may use it, and a photo that silently
 * disappears from a file reads as a bug, not as a safeguard. The safeguard
 * is that the file says, next to the picture, that nobody has consented.
 *
 * It does not decide *whose* consent is being reported. That is the
 * viewing player's, always, decided by the caller passing that player's
 * row. A squad photo depicting eleven children is marked by the consent of
 * the one whose file you are reading, which is the only question a reader
 * of that file can act on.
 */
final class MediaConsentStatement {

    /**
     * The short form, for a label/value row on an identity card.
     *
     * Always says something, including when the answer is no: a blank
     * value reads as "not asked yet", which is the one ambiguity a consent
     * record must never carry.
     */
    public static function summary( ?object $player ): string {
        if ( ! $player ) return __( 'Not recorded', 'talenttrack' );

        if ( empty( $player->media_consent ) ) {
            return __( 'Not recorded', 'talenttrack' );
        }

        $when = self::when( $player );
        $who  = self::who( $player );

        if ( $when !== '' && $who !== '' ) {
            return sprintf(
                /* translators: 1: date consent was recorded, 2: the staff member who recorded it. */
                __( 'On record, %1$s by %2$s', 'talenttrack' ),
                $when,
                $who
            );
        }
        if ( $when !== '' ) {
            return sprintf(
                /* translators: %s: the date consent was recorded. */
                __( 'On record, %s', 'talenttrack' ),
                $when
            );
        }
        return __( 'On record', 'talenttrack' );
    }

    /**
     * The full sentence, for the line above a set of images.
     *
     * The negative case carries the instruction, because the person
     * reading it is looking at the pictures right then and the whole
     * point is that they may still use them — carefully.
     */
    public static function sentence( ?object $player ): string {
        if ( ! $player || empty( $player->media_consent ) ) {
            return __( 'Photo and video consent: not recorded. Anything here may still be shown — ask before using it outside the club.', 'talenttrack' );
        }

        $when = self::when( $player );
        $who  = self::who( $player );

        if ( $when !== '' && $who !== '' ) {
            return sprintf(
                /* translators: 1: date consent was recorded, 2: the staff member who recorded it. */
                __( 'Photo and video consent: on record, %1$s by %2$s.', 'talenttrack' ),
                $when,
                $who
            );
        }
        if ( $when !== '' ) {
            return sprintf(
                /* translators: %s: the date consent was recorded. */
                __( 'Photo and video consent: on record, %s.', 'talenttrack' ),
                $when
            );
        }
        return __( 'Photo and video consent: on record.', 'talenttrack' );
    }

    /** True when consent is recorded for this player. */
    public static function isRecorded( ?object $player ): bool {
        return $player !== null && ! empty( $player->media_consent );
    }

    private static function when( object $player ): string {
        $at = isset( $player->media_consent_at ) ? (string) $player->media_consent_at : '';
        return $at !== '' ? \TT\Shared\Dates\TTDate::date( $at ) : '';
    }

    private static function who( object $player ): string {
        $by = (int) ( $player->media_consent_by ?? 0 );
        if ( $by <= 0 ) return '';
        $user = get_userdata( $by );
        return $user ? (string) $user->display_name : '';
    }
}
