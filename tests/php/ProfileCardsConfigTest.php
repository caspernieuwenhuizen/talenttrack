<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Players\Services\ProfileCardsConfig;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * #2207 — club-configurable player-profile cards.
 *
 * ProfileCardsConfig is the single source of truth for which Profile-tab
 * cards an academy has hidden. These tests lock: the settings-form value
 * normalises to canonical JSON of known keys only; a stored hidden set is
 * read back and gates isHidden()/isVisible(); and the Identity card is never
 * hideable.
 */
final class ProfileCardsConfigTest extends WP_UnitTestCase {

    public function test_normalise_accepts_json_array_of_known_keys(): void {
        $this->assertSame( '["discovery"]', ProfileCardsConfig::normaliseStored( '["discovery"]' ) );
        $this->assertSame(
            '["academy","parents","discovery"]',
            ProfileCardsConfig::normaliseStored( '["academy","parents","discovery"]' )
        );
    }

    public function test_normalise_accepts_comma_separated_list(): void {
        $this->assertSame( '["academy","discovery"]', ProfileCardsConfig::normaliseStored( 'academy,discovery' ) );
    }

    public function test_normalise_drops_unknown_and_always_on_keys(): void {
        // `identity` is the always-on anchor and must never be hideable;
        // `bogus` is not a real card. Both are dropped.
        $this->assertSame( '["academy"]', ProfileCardsConfig::normaliseStored( 'identity,academy,bogus' ) );
        $this->assertSame( '[]', ProfileCardsConfig::normaliseStored( 'identity' ) );
    }

    public function test_normalise_dedupes_and_empties(): void {
        $this->assertSame( '["parents"]', ProfileCardsConfig::normaliseStored( 'parents,parents' ) );
        $this->assertSame( '[]', ProfileCardsConfig::normaliseStored( '' ) );
        $this->assertSame( '[]', ProfileCardsConfig::normaliseStored( 'not-valid-json{' ) );
    }

    public function test_hidden_reads_and_filters_stored_config(): void {
        QueryHelpers::set_config( ProfileCardsConfig::CONFIG_KEY, '["discovery","identity","bogus"]' );

        // identity + bogus are filtered out; only the known hideable key
        // survives.
        $this->assertSame( [ ProfileCardsConfig::CARD_DISCOVERY ], ProfileCardsConfig::hidden() );
        $this->assertTrue( ProfileCardsConfig::isHidden( ProfileCardsConfig::CARD_DISCOVERY ) );
        $this->assertFalse( ProfileCardsConfig::isVisible( ProfileCardsConfig::CARD_DISCOVERY ) );

        // A card not in the hidden set stays visible.
        $this->assertTrue( ProfileCardsConfig::isVisible( ProfileCardsConfig::CARD_ACADEMY ) );
        $this->assertFalse( ProfileCardsConfig::isHidden( ProfileCardsConfig::CARD_ACADEMY ) );

        // Identity is never reported hidden even if it leaks into storage.
        $this->assertFalse( ProfileCardsConfig::isHidden( 'identity' ) );
    }

    public function test_default_is_everything_visible(): void {
        QueryHelpers::set_config( ProfileCardsConfig::CONFIG_KEY, '' );

        $this->assertSame( [], ProfileCardsConfig::hidden() );
        foreach ( ProfileCardsConfig::hideableKeys() as $key ) {
            $this->assertTrue( ProfileCardsConfig::isVisible( $key ) );
        }
    }
}
