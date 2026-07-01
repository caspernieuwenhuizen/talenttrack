<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * ProfileCardsConfig — single source of truth for which player-profile
 * "Profile" tab cards an academy has chosen to hide (#2207).
 *
 * The Profile tab anchors a player's identity and academy context. Some
 * academies do not use every card the tab can show (e.g. Discovery, which
 * only makes sense for clubs running the Prospects scouting funnel), so the
 * set of visible cards is club-configurable. Resolving that choice here —
 * rather than re-reading and re-parsing the config at each render site —
 * keeps the profile view a pure composer (SaaS-readiness: business logic
 * out of the view) and gives the REST config surface one shared shape.
 *
 * The hidden-card set lives in `tt_config` under the single JSON key
 * `profile_cards_hidden` (an array of card keys). All reads are
 * club-scoped through ConfigService. Default is an empty set — every
 * currently-shown card stays visible until an operator hides it.
 *
 * The Identity card is deliberately NOT hideable: name / date-of-birth /
 * position anchor the player and every screen where they are the subject
 * (CLAUDE.md § 1). Only the cards listed in HIDEABLE can be toggled off;
 * an unknown key in the stored set is ignored.
 */
class ProfileCardsConfig {

    /** tt_config key holding the JSON array of hidden card keys. */
    public const CONFIG_KEY = 'profile_cards_hidden';

    public const CARD_ACADEMY   = 'academy';
    public const CARD_PARENTS   = 'parents';
    public const CARD_DISCOVERY = 'discovery';

    /**
     * The cards an operator may hide, in display order. Identity is
     * excluded on purpose (always-on anchor). PHV / VCT is excluded too —
     * it is already module-gated, so its visibility is governed by the VCT
     * module toggle, not this config.
     *
     * @return string[]
     */
    public static function hideableKeys(): array {
        return [ self::CARD_ACADEMY, self::CARD_PARENTS, self::CARD_DISCOVERY ];
    }

    /**
     * Human-readable label for each hideable card, for the settings UI.
     *
     * @return array<string,string>
     */
    public static function hideableLabels(): array {
        return [
            self::CARD_ACADEMY   => __( 'Academy', 'talenttrack' ),
            self::CARD_PARENTS   => __( 'Parents · Guardians', 'talenttrack' ),
            self::CARD_DISCOVERY => __( 'Discovery', 'talenttrack' ),
        ];
    }

    /**
     * The set of currently-hidden card keys for the active club, filtered
     * to the known hideable set (an unknown / stale key is dropped).
     *
     * @return string[]
     */
    public static function hidden(): array {
        $raw = QueryHelpers::get_config( self::CONFIG_KEY, '' );
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }
        $allowed = self::hideableKeys();
        $out     = [];
        foreach ( $decoded as $key ) {
            $key = (string) $key;
            if ( in_array( $key, $allowed, true ) && ! in_array( $key, $out, true ) ) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * True when a given card is hidden club-wide. Unknown / non-hideable
     * keys (e.g. `identity`) are never hidden.
     */
    public static function isHidden( string $card ): bool {
        return in_array( $card, self::hidden(), true );
    }

    /**
     * True when a given hideable card is currently visible.
     */
    public static function isVisible( string $card ): bool {
        return ! self::isHidden( $card );
    }

    /**
     * Normalise a raw stored value (as submitted through the config REST
     * surface) into the canonical JSON array of known hidden card keys.
     *
     * Accepts either a JSON array string or a comma-separated list, so the
     * settings sub-form can ship a simple CSV of checked cards without the
     * client having to hand-build JSON. Returns a JSON-encoded array of the
     * subset that maps to a known hideable card.
     */
    public static function normaliseStored( string $raw ): string {
        $raw = trim( $raw );
        $parts = [];
        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $parts = $decoded;
            } else {
                $parts = explode( ',', $raw );
            }
        }
        $allowed = self::hideableKeys();
        $out     = [];
        foreach ( $parts as $key ) {
            $key = trim( (string) $key );
            if ( in_array( $key, $allowed, true ) && ! in_array( $key, $out, true ) ) {
                $out[] = $key;
            }
        }
        return (string) wp_json_encode( $out );
    }
}
