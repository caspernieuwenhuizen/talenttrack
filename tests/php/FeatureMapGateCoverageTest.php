<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\FeatureMap;

/**
 * #2922 — the tier map, and the thing that stops it rotting again.
 *
 * The map went stale in exactly one way: the product grew, the map did
 * not, and nothing anywhere failed. Forty-odd surfaces shipped with no
 * tier and therefore landed in Free, and the only way to notice was to
 * read `FeatureMap` next to `config/modules.php` and spot the absence.
 *
 * These tests make the absence loud. A Pro feature must be gated
 * somewhere in `src/`, or be listed in `config/license_gate_pending.php`
 * with a note saying where its gate will go. Neither is optional, so a
 * new paid feature cannot quietly ship ungated and the pending list
 * cannot silently accumulate entries nobody remembers writing.
 */
final class FeatureMapGateCoverageTest extends WP_UnitTestCase {

    /** @var array<string,string>|null */
    private static $pending = null;

    /** @var string|null */
    private static $sources = null;

    public function test_every_pro_feature_is_gated_or_explicitly_pending(): void {
        $pending  = self::pending();
        $sources  = self::sources();
        $ungated  = [];

        foreach ( array_keys( FeatureMap::DEFAULT_MAP[ FeatureMap::TIER_PRO ] ) as $feature ) {
            if ( isset( $pending[ $feature ] ) ) continue;
            if ( self::isGated( $feature, $sources ) ) continue;
            $ungated[] = $feature;
        }

        $this->assertSame(
            [],
            $ungated,
            "These Pro features have no LicenseGate call site and are not listed in "
            . "config/license_gate_pending.php. Add a gate, or add the key with a note "
            . "saying where its gate will go:\n  " . implode( "\n  ", $ungated )
        );
    }

    public function test_the_pending_list_only_names_features_that_exist_and_are_ungated(): void {
        $sources = self::sources();
        $pro     = FeatureMap::DEFAULT_MAP[ FeatureMap::TIER_PRO ];

        foreach ( self::pending() as $feature => $note ) {
            $this->assertArrayHasKey(
                $feature,
                $pro,
                "config/license_gate_pending.php names '{$feature}', which is not a Pro feature. "
                . 'Either it moved tier or the entry is stale.'
            );

            $this->assertNotSame(
                '',
                trim( (string) $note ),
                "The pending entry for '{$feature}' has no note saying where its gate will go."
            );

            $this->assertFalse(
                self::isGated( $feature, $sources ),
                "'{$feature}' now has a LicenseGate call site, so its entry in "
                . 'config/license_gate_pending.php is stale and should be deleted.'
            );
        }
    }

    /**
     * The unentitled tier is not a product, and safeguarding is not a
     * tier. Both are decisions from #2922 that a later edit could undo
     * without anyone noticing, so they are pinned here.
     */
    public function test_safeguarding_features_are_available_at_every_tier(): void {
        $safeguarding = [
            'audit_log',
            'authorization_matrix',
            'mfa',
            'record_deletion',
            'recycle_bin',
            'impersonation_log',
            'media_consent',
            'subject_access',
        ];

        foreach ( $safeguarding as $feature ) {
            foreach ( FeatureMap::tiers() as $tier ) {
                $this->assertTrue(
                    FeatureMap::tierHas( $tier, $feature ),
                    "'{$feature}' is safeguarding-adjacent and must not be gated. "
                    . "Tier '{$tier}' does not have it."
                );
            }
        }
    }

    public function test_an_unentitled_install_can_still_read_and_export_its_own_data(): void {
        foreach ( [ 'core_dashboard', 'core_player_card', 'backup_local', 'exports_basic' ] as $feature ) {
            $this->assertTrue(
                FeatureMap::tierHas( FeatureMap::TIER_FREE, $feature ),
                "A club whose entitlement lapsed must keep '{$feature}'. Holding a club's "
                . 'own player data hostage is not a commercial lever this product uses.'
            );
        }
    }

    public function test_only_standard_and_pro_are_sellable(): void {
        $this->assertSame(
            [ FeatureMap::TIER_STANDARD, FeatureMap::TIER_PRO ],
            FeatureMap::sellableTiers()
        );

        $this->assertNotContains(
            FeatureMap::TIER_FREE,
            FeatureMap::sellableTiers(),
            'Free is the unentitled state under managed hosting, not a plan anyone buys.'
        );
    }

    public function test_the_academy_product_is_reachable_on_standard(): void {
        // Standard is not a crippled tier. If any of these ever moves to
        // Pro, that is a pricing decision that should break this test and
        // be argued for, not a quiet edit to a map.
        $academy = [
            'core_players', 'core_teams', 'core_evaluations', 'core_goals',
            'core_attendance', 'player_journey', 'player_pdp', 'measurements',
            'trial_module', 'reports_standard', 'methodology',
        ];

        foreach ( $academy as $feature ) {
            $this->assertTrue(
                FeatureMap::tierHas( FeatureMap::TIER_STANDARD, $feature ),
                "'{$feature}' is part of the academy product and belongs in Standard."
            );
        }
    }

    // Helpers

    /** @return array<string,string> */
    private static function pending(): array {
        if ( self::$pending === null ) {
            $file = TT_PLUGIN_DIR . 'config/license_gate_pending.php';
            $map  = is_readable( $file ) ? require $file : [];
            self::$pending = is_array( $map ) ? $map : [];
        }
        return self::$pending;
    }

    /**
     * Every PHP source file, concatenated once. Cheaper than walking the
     * tree per feature, and this runs over thirty features.
     */
    private static function sources(): string {
        if ( self::$sources === null ) {
            $buffer   = '';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src', \FilesystemIterator::SKIP_DOTS )
            );

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || $file->getExtension() !== 'php' ) continue;
                // The License module declares the keys; a mention there is
                // not a gate. Excluding it is what makes the assertion mean
                // "somebody checks this" rather than "somebody wrote it down".
                if ( strpos( (string) $file->getPathname(), 'Modules' . DIRECTORY_SEPARATOR . 'License' ) !== false ) continue;
                $buffer .= (string) file_get_contents( (string) $file->getPathname() );
            }

            self::$sources = $buffer;
        }

        return self::$sources;
    }

    private static function isGated( string $feature, string $sources ): bool {
        // #3105 — slice 1 (#3104) added the two enforcement helpers, and a
        // controller that gates through them names the feature there rather
        // than in a bare `allows()`. Without these the detector would call a
        // properly gated feature ungated, which is the failure mode that
        // teaches people to edit the pending list instead of the code.
        // Matched up to the feature literal, not to the closing paren: the
        // enforcement helpers take further arguments (the request, the
        // method), and pinning the whole call would make the detector
        // sensitive to an argument list it has no opinion about.
        foreach ( [ 'allows', 'can', 'enforceFeatureRest', 'enforceWriteRest', 'refusalForMethod', 'planRefusal' ] as $method ) {
            if ( strpos( $sources, "LicenseGate::{$method}( '{$feature}'" ) !== false ) return true;
            if ( strpos( $sources, "LicenseGate::{$method}('{$feature}'" ) !== false ) return true;
        }
        return false;
    }
}
