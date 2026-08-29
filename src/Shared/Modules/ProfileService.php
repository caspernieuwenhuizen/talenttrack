<?php
namespace TT\Shared\Modules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;

/**
 * ProfileService (#3035) — comparing an install against a profile, and
 * applying the difference.
 *
 * The whole point of the class is the split between reading and writing.
 * `diff()` and `divergence()` are pure reads; `apply()` is the only thing
 * here that touches state, and it writes through `ModuleRegistry` and
 * `FeatureRegistry` rather than reimplementing either — both already carry
 * the `updated_by` + timestamp audit trail.
 *
 * **Divergence is computed, never stored.** An operator who toggles a row
 * back into line silently un-diverges, with nothing to invalidate. That is
 * worth more than the read it costs.
 *
 * Profile state lives in `tt_config`, not `wp_options`: `wp_options` is
 * global to the WP install, and the tenancy scaffold this product is being
 * built toward hangs off `tt_config`'s `club_id` (CLAUDE.md §4).
 */
class ProfileService {

    /** `tt_config` key holding the slug of the profile this install is on. */
    public const CONFIG_KEY = 'install_profile';

    /**
     * `tt_config` key holding the confirmation watermark (#3039): the rows
     * the operator was last shown for this profile, and what the profile
     * intended for each at that moment. It is what tells "the profile
     * changed" apart from "the operator changed something", which
     * divergence alone cannot.
     */
    public const SEEN_KEY = 'install_profile_seen';

    /** Row-id prefixes. A diff mixes both kinds, so ids must not collide. */
    private const ID_MODULE  = 'module:';
    private const ID_FEATURE = 'feature:';

    /** @var ConfigService|null */
    private static $config = null;

    /**
     * The profile this install is on, or null for an install that predates
     * profiles or was never put on one. A slug that no longer names a
     * shipped profile reads as null — a removed profile is not a shape.
     */
    public static function current(): ?string {
        $slug = self::config()->get( self::CONFIG_KEY, '' );
        if ( $slug === '' || ! ProfileRegistry::exists( $slug ) ) return null;
        return $slug;
    }

    public static function setCurrent( string $slug ): void {
        self::config()->set( self::CONFIG_KEY, $slug );
    }

    /**
     * What applying the profile would change, and what it could not.
     *
     * Only rows that would actually move are returned: a module or feature
     * already in the profile's shape is not a row. A row that cannot be
     * written carries the reason, so a preview screen can explain the gap
     * instead of silently under-applying.
     *
     * Rows that would disable an always-on module are omitted entirely
     * rather than shown as skipped. `ModuleRegistry::setEnabled()` already
     * refuses them, and showing a row that will never move makes the
     * preview lie about what is going to happen. A profile that names an
     * always-on module `false` fails `tools/check-module-toggles.php`, so
     * the case cannot reach a release.
     *
     * @return list<array{kind:string, id:string, key:string, label:string, from:bool, to:bool, skipped_reason:?string}>
     */
    public static function diff( string $slug ): array {
        $profile = ProfileRegistry::get( $slug );
        if ( $profile === null ) return [];

        $rows     = [];
        $declared = self::declaredModules();

        foreach ( $profile['modules'] as $class => $to ) {
            if ( ! isset( $declared[ $class ] ) ) continue;
            if ( ModuleRegistry::isAlwaysOn( $class ) ) continue;

            $from = ModuleRegistry::isEnabled( $class );
            if ( $from === $to ) continue;

            $meta = ModuleMetadata::for( $class );
            $rows[] = [
                'kind'           => 'module',
                'id'             => self::ID_MODULE . $class,
                'key'            => $class,
                'label'          => (string) $meta['label'],
                'from'           => $from,
                'to'             => $to,
                'skipped_reason' => self::tierBlocks( $class, $to ) ? 'tier' : null,
            ];
        }

        foreach ( $profile['features'] as $key => $to ) {
            $meta = FeatureRegistry::describe( $key );
            if ( $meta === null ) continue;

            $from = FeatureRegistry::configuredState( $key );
            if ( $from === $to ) continue;

            $rows[] = [
                'kind'           => 'feature',
                'id'             => self::ID_FEATURE . $key,
                'key'            => $key,
                'label'          => (string) $meta['label'],
                'from'           => $from,
                'to'             => $to,
                'skipped_reason' => self::tierBlocks( $key, $to ) ? 'tier' : null,
            ];
        }

        return $rows;
    }

    /**
     * How far the install has drifted from the profile: the number of
     * rows that would actually be written. Tier-skipped rows do not count
     * — an install cannot close a gap it is not entitled to close, and
     * reporting it as divergence would leave a counter nobody can zero.
     */
    public static function divergence( string $slug ): int {
        $n = 0;
        foreach ( self::diff( $slug ) as $row ) {
            if ( $row['skipped_reason'] === null ) $n++;
        }
        return $n;
    }

    /**
     * Put the install into the profile's shape.
     *
     * @param list<string> $exclusions Row ids (as returned by `diff()`) the
     *                                 operator chose not to apply.
     * @param int|null     $actor      User id credited in the audit trail;
     *                                 defaults to the current user.
     *
     * @return array{profile:string, applied:list<array{kind:string,id:string,label:string,from:bool,to:bool}>, skipped:list<array{kind:string,id:string,label:string,from:bool,to:bool,reason:string}>}
     */
    public static function apply( string $slug, array $exclusions = [], ?int $actor = null ): array {
        $summary = [ 'profile' => $slug, 'applied' => [], 'skipped' => [] ];
        if ( ! ProfileRegistry::exists( $slug ) ) return $summary;

        $excluded = array_flip( array_map( 'strval', $exclusions ) );

        // Captured before anything is written: this is the set of rows the
        // operator was shown, which is what the watermark has to record.
        $shown = self::diff( $slug );

        foreach ( $shown as $row ) {
            $entry = [
                'kind'  => $row['kind'],
                'id'    => $row['id'],
                'label' => $row['label'],
                'from'  => $row['from'],
                'to'    => $row['to'],
            ];

            if ( $row['skipped_reason'] !== null ) {
                $entry['reason'] = $row['skipped_reason'];
                $summary['skipped'][] = $entry;
                continue;
            }
            if ( isset( $excluded[ $row['id'] ] ) ) {
                $entry['reason'] = 'excluded';
                $summary['skipped'][] = $entry;
                continue;
            }

            if ( $row['kind'] === 'module' ) {
                ModuleRegistry::setEnabled( $row['key'], $row['to'], $actor );
            } else {
                FeatureRegistry::setEnabled( $row['key'], $row['to'], $actor );
            }
            $summary['applied'][] = $entry;
        }

        // Recorded even when every row was excluded: the operator chose
        // this shape, and the strip on the Modules page has to be able to
        // say which one, with the divergence alongside it.
        self::setCurrent( $slug );

        // Every row the operator was shown joins the watermark, applied or
        // excluded alike — "I have seen this and decided" is the fact
        // #3039 needs, not "I agreed".
        $seen = [];
        foreach ( $shown as $row ) {
            $seen[ $row['id'] ] = $row['to'];
        }
        self::writeWatermark( $slug, $seen );

        return $summary;
    }

    // ------------------------------------------------------------------
    // Release-time drift (#3039)
    // ------------------------------------------------------------------

    /**
     * Changes that came from the profile definition moving, not from an
     * operator moving a switch.
     *
     * Divergence alone cannot tell the two apart: both look like "the
     * install does not match the profile". The watermark is what
     * separates them — it records, for every row the operator was last
     * shown, the *intent* the profile had at that moment. So:
     *
     *   - a row absent from the watermark is new since the operator last
     *     looked, which is a profile change (or a module that did not
     *     exist yet);
     *   - a row present with a **different** intent is the profile having
     *     changed its mind about it — also a profile change;
     *   - a row present with the **same** intent is one the operator has
     *     already seen and left alone. Their divergence, not the
     *     profile's.
     *
     * Returns nothing at all when the install is on no profile, or when
     * the watermark was written for a different profile than the one the
     * install is on now. A comparison against a watermark that does not
     * describe this shape cannot be interpreted, and raising a notice out
     * of data we cannot read is worse than staying quiet.
     *
     * @return list<array{kind:string, id:string, key:string, label:string, from:bool, to:bool, skipped_reason:?string}>
     */
    public static function pending(): array {
        $slug = self::current();
        if ( $slug === null ) return [];

        $mark = self::watermark();
        if ( $mark === null || $mark['profile'] !== $slug ) return [];

        $out = [];
        foreach ( self::diff( $slug ) as $row ) {
            // A row the install cannot apply is not a change to review.
            if ( $row['skipped_reason'] !== null ) continue;
            if ( array_key_exists( $row['id'], $mark['rows'] )
                && $mark['rows'][ $row['id'] ] === $row['to'] ) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Mark pending rows as seen without applying them.
     *
     * Dismissing is a decision, and re-raising it on the next unrelated
     * release would make the notice something an operator learns to
     * ignore. The row joins the watermark carrying the profile's *current*
     * intent, so a later release that changes its mind about the same row
     * raises it again — which is the one case where nagging is right.
     *
     * @param list<string> $ids
     */
    public static function dismiss( array $ids ): void {
        $slug = self::current();
        if ( $slug === null || $ids === [] ) return;

        $mark = self::watermark();
        $rows = ( $mark !== null && $mark['profile'] === $slug ) ? $mark['rows'] : [];

        $wanted = array_flip( array_map( 'strval', $ids ) );
        foreach ( self::diff( $slug ) as $row ) {
            if ( ! isset( $wanted[ $row['id'] ] ) ) continue;
            $rows[ $row['id'] ] = $row['to'];
        }

        self::writeWatermark( $slug, $rows );
    }

    /**
     * @return array{profile:string, rows:array<string,bool>}|null
     */
    private static function watermark(): ?array {
        $raw = self::config()->get( self::SEEN_KEY, '' );
        if ( $raw === '' ) return null;

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return null;

        $profile = (string) ( $decoded['profile'] ?? '' );
        if ( $profile === '' ) return null;

        $rows = [];
        foreach ( (array) ( $decoded['rows'] ?? [] ) as $id => $to ) {
            $rows[ (string) $id ] = (bool) $to;
        }
        return [ 'profile' => $profile, 'rows' => $rows ];
    }

    /** @param array<string,bool> $rows */
    private static function writeWatermark( string $slug, array $rows ): void {
        $json = wp_json_encode( [ 'profile' => $slug, 'rows' => $rows ] );
        self::config()->set( self::SEEN_KEY, is_string( $json ) ? $json : '' );
    }

    /**
     * Has anybody already shaped this install by hand?
     *
     * True as soon as one module or feature row carries an `updated_by` —
     * somebody has been to the Modules page, or a profile has already been
     * applied. The Setup wizard (#3038) uses it to decide whether choosing
     * a profile can be applied on the spot: on a fresh install the diff is
     * uncontroversial, and on a configured one it is not, so re-running
     * Setup routes through the preview screen instead of quietly undoing
     * an operator's decisions.
     *
     * A missing state table reads as untouched — an install mid-migration
     * has certainly not been configured.
     */
    public static function hasOperatorChanges(): bool {
        global $wpdb;
        foreach ( [ 'tt_module_state', 'tt_feature_state' ] as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) continue;
            $n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE updated_by IS NOT NULL AND updated_by > 0" );
            if ( $n > 0 ) return true;
        }
        return false;
    }

    /**
     * Is this row above the install's entitlement?
     *
     * Switchability and plan are two independent axes and neither derives
     * from the other (`docs/modules.md` § "Switchability and plan are two
     * different axes"). They line up only where `FeatureMap` happens to
     * name the same thing — `analytics_explorer`, `comms_sms_channel`,
     * `knowledge_courses` and friends. So the ceiling is checked exactly
     * where it is defined: a key `FeatureMap` does not know carries no
     * entitlement, and inventing one from the module name would be the
     * false link the doc warns against.
     *
     * Only ever blocks switching something **on**. Turning a surface off
     * is always allowed, whatever the plan says.
     */
    private static function tierBlocks( string $key, bool $to ): bool {
        if ( ! $to ) return false;
        if ( ! class_exists( FeatureMap::class ) ) return false;
        if ( FeatureMap::tierFor( $key ) === null ) return false;
        return ! LicenseGate::allows( $key );
    }

    /**
     * Is the class declared in `config/modules.php`? A profile naming a
     * module that has since been removed must not produce a row for it.
     *
     * @return array<string,bool>
     */
    private static function declaredModules(): array {
        $out = [];
        foreach ( ModuleRegistry::allWithState() as $row ) {
            $out[ ltrim( $row['class'], '\\' ) ] = true;
        }
        return $out;
    }

    private static function config(): ConfigService {
        if ( self::$config === null ) self::$config = new ConfigService();
        return self::$config;
    }

    /** Test seam — drops the memoised ConfigService and its per-key cache. */
    public static function resetConfigCache(): void {
        self::$config = null;
    }
}
