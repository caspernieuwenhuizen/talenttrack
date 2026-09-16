<?php
namespace TT\Modules\License\Cli;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\CachedEntitlement;
use TT\Modules\License\DevOverride;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;

/**
 * EntitlementCliCommand (#3466) — `wp tt entitlement` group.
 *
 * The write path for `tt_entitlement`, which nothing else had. The
 * control plane owns what a club is entitled to; this is how that
 * answer reaches an install:
 *
 *   wp tt entitlement show              — what this install resolves to, and why
 *   wp tt entitlement set --tier=<t>    — record what the control plane says
 *   wp tt entitlement clear             — forget it (the unentitled state)
 *
 * Provisioning calls `set` when it stands an install up. The
 * phone-home entitlement response will call the same `store()` when
 * that channel exists — this is the bottom of the stack with a CLI
 * surface on it, not a parallel mechanism.
 *
 * **It stays out of a club admin's reach.** wp-cli needs shell access;
 * there is still no setter, no filter, no settings field and no REST
 * route that writes this option. A club that could promote itself to
 * Pro by toggling something is exactly what `CachedEntitlement` exists
 * to prevent, and this command must not become the hole in that.
 *
 * Registered behind `class_exists( 'WP_CLI' )` in LicenseModule — a
 * no-op on web requests.
 */
final class EntitlementCliCommand {

    /**
     * Show what this install is entitled to, and what it resolves to.
     *
     * Reports the stored record, its age against the TTL and grace
     * window, and the tier `LicenseGate` actually returns — which can
     * differ from the stored one when commercial mode is off (always
     * Pro) or a developer override is live.
     *
     * ## EXAMPLES
     *
     *     wp tt entitlement show
     *
     * @param array<int,string>    $args
     * @param array<string,mixed>  $assoc_args
     */
    public function show( $args, $assoc_args ): void {
        $commercial = LicenseMode::isCommercial();
        \WP_CLI::line( 'Commercial mode (TT_COMMERCIAL_MODE): ' . ( $commercial ? 'ON — licence gates enforced' : 'OFF — every feature unlocked, gates bypassed' ) );

        $record = CachedEntitlement::read();
        if ( $record === null ) {
            \WP_CLI::line( 'Stored entitlement: (none recorded)' );
        } else {
            $age     = time() - $record['fetched_at'];
            $stale   = CachedEntitlement::isStale();
            $expired = $age > CachedEntitlement::TTL_SECONDS + CachedEntitlement::GRACE_SECONDS;

            \WP_CLI::line( 'Stored entitlement: ' . $record['tier'] );
            \WP_CLI::line( '  fetched_at:       ' . gmdate( 'Y-m-d H:i:s', $record['fetched_at'] ) . ' UTC (' . human_time_diff( $record['fetched_at'] ) . ' ago)' );
            \WP_CLI::line( '  past TTL:         ' . ( $stale ? 'yes — wants refreshing' : 'no' ) );
            \WP_CLI::line( '  still honoured:   ' . ( $expired ? 'no — past TTL + grace, reads as unentitled' : 'yes' ) );
        }

        $override = DevOverride::active();
        \WP_CLI::line( 'Developer override: ' . ( $override === null ? 'not active' : $override['tier'] . ' (expires ' . human_time_diff( time(), $override['set_at'] + DevOverride::TRANSIENT_TTL ) . ' from now)' ) );

        \WP_CLI::line( 'Effective tier:     ' . LicenseGate::tier() );

        if ( $commercial && $record === null && $override === null ) {
            \WP_CLI::warning( __( 'Commercial mode is on with no entitlement recorded, so this install resolves to the unentitled state and free-tier caps apply. Record one with: wp tt entitlement set --tier=standard', 'talenttrack' ) );
        }
    }

    /**
     * Record what the control plane says this install is entitled to.
     *
     * ## OPTIONS
     *
     * --tier=<tier>
     * : The entitled tier. One of: standard, pro. Use `free` only to
     *   reproduce the unentitled state deliberately.
     *
     * [--fetched-at=<timestamp>]
     * : Unix timestamp the control plane answered. Defaults to now.
     *   Backdating past the TTL + grace window is how you test expiry.
     *
     * ## EXAMPLES
     *
     *     wp tt entitlement set --tier=standard
     *     wp tt entitlement set --tier=pro
     *
     * @param array<int,string>    $args
     * @param array<string,mixed>  $assoc_args
     */
    public function set( $args, $assoc_args ): void {
        $tier = isset( $assoc_args['tier'] ) ? strtolower( trim( (string) $assoc_args['tier'] ) ) : '';

        // Validate before storing. `FeatureMap::normalizeTier()` answers
        // `free` for anything it doesn't know, so handing it a typo would
        // silently write the unentitled state — which on a paying club's
        // install looks exactly like a licensing bug.
        if ( ! in_array( $tier, FeatureMap::tiers(), true ) ) {
            \WP_CLI::error( sprintf(
                /* translators: 1: the value the operator typed, 2: comma-separated list of valid tiers */
                __( 'Unknown tier "%1$s". Expected one of: %2$s.', 'talenttrack' ),
                $tier,
                implode( ', ', FeatureMap::tiers() )
            ) );
        }

        $fetched_at = null;
        if ( isset( $assoc_args['fetched-at'] ) ) {
            $raw = (string) $assoc_args['fetched-at'];
            if ( ! ctype_digit( $raw ) ) {
                \WP_CLI::error( __( '--fetched-at must be a Unix timestamp.', 'talenttrack' ) );
            }
            $fetched_at = (int) $raw;
        }

        CachedEntitlement::store( $tier, $fetched_at );

        \WP_CLI::success( sprintf(
            /* translators: 1: tier slug, 2: the tier LicenseGate now returns */
            __( 'Entitlement recorded as "%1$s". This install now resolves to "%2$s".', 'talenttrack' ),
            $tier,
            LicenseGate::tier()
        ) );

        if ( ! LicenseMode::isCommercial() ) {
            \WP_CLI::warning( __( 'Commercial mode is off on this install, so the stored entitlement is ignored at runtime and every feature stays unlocked. Add TT_COMMERCIAL_MODE to wp-config.php to enforce it.', 'talenttrack' ) );
        }
    }

    /**
     * Forget the stored entitlement — the unentitled state.
     *
     * In commercial mode the install then reads as unactivated and
     * free-tier caps apply, which is what a lapsed subscription looks
     * like once the grace window runs out.
     *
     * ## EXAMPLES
     *
     *     wp tt entitlement clear
     *
     * @param array<int,string>    $args
     * @param array<string,mixed>  $assoc_args
     */
    public function clear( $args, $assoc_args ): void {
        if ( CachedEntitlement::read() === null ) {
            \WP_CLI::warning( __( 'No entitlement was recorded — nothing to clear.', 'talenttrack' ) );
            return;
        }

        CachedEntitlement::clear();

        \WP_CLI::success( sprintf(
            /* translators: %s: the tier LicenseGate now returns */
            __( 'Entitlement cleared. This install now resolves to "%s".', 'talenttrack' ),
            LicenseGate::tier()
        ) );
    }
}
