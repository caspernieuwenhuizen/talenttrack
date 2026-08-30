<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlanSummary (#3134) — what plan this install is on, what it is using
 * against the free-tier caps, and which features each plan carries.
 *
 * The Plan tab used to compute all of that inline in
 * `Admin\AccountPage::renderPlanTab()`. Porting it to the frontend would
 * have meant a second copy of the same arithmetic, and the two would have
 * drifted the first time a tier moved. So the answer moves out of the
 * render and into here: the wp-admin tab, the frontend view and
 * `GET /account/plan` all ask this class and get the same numbers
 * (CLAUDE.md §4 — keep business logic out of view files).
 *
 * Nothing here decides *access*. Reading the plan is open to any signed-in
 * user on purpose: a coach who cannot find a feature should be able to see
 * for themselves whether it is missing or merely locked.
 */
final class PlanSummary {

    /**
     * The whole picture in one array, shaped for JSON.
     *
     * `tier` is what the install presents as; `effective_tier` is what
     * gating actually resolves to; `paid_tier` is what it is entitled to
     * with any developer override taken as read. They differ only on an
     * override, and the Plan view needs all three to avoid telling a
     * Free install it is on Standard.
     *
     * @return array{
     *     commercial: bool,
     *     tier: string,
     *     tier_label: string,
     *     effective_tier: string,
     *     paid_tier: string,
     *     entitled: bool,
     *     dev_override: bool,
     *     caps_apply: bool,
     *     caps: list<array{resource:string,label:string,used:int,limit:int,at_cap:bool,near_cap:bool}>,
     *     tiers: list<array{key:string,label:string,current:bool}>,
     *     features: list<array{key:string,label:string,tiers:array<string,bool>}>
     * }
     */
    public static function build(): array {
        $commercial = LicenseMode::isCommercial();
        $tier       = LicenseGate::tier();
        $effective  = LicenseGate::effectiveTier();
        $override   = DevOverride::active();
        $entitled   = Entitlement::tier();

        // What the install is entitled to, ignoring an override's
        // inflation — this is what decides whether an upgrade affordance
        // makes sense. A developer override counts as the underlying tier
        // so testing an upgrade looks like the real thing.
        $paid_tier = $override !== null
            ? FeatureMap::normalizeTier( $override['tier'] )
            : ( $entitled !== null ? FeatureMap::normalizeTier( $entitled ) : FeatureMap::TIER_FREE );

        $caps_apply = ( $effective === FeatureMap::TIER_FREE );

        $caps = [];
        foreach ( [ FreeTierCaps::CAP_TEAMS, FreeTierCaps::CAP_PLAYERS ] as $resource ) {
            $used  = FreeTierCaps::currentCount( $resource );
            $limit = FreeTierCaps::capFor( $resource );
            $caps[] = [
                'resource' => $resource,
                'label'    => $resource === FreeTierCaps::CAP_TEAMS
                    ? __( 'Teams', 'talenttrack' )
                    : __( 'Players', 'talenttrack' ),
                'used'     => $used,
                'limit'    => $limit,
                'at_cap'   => $caps_apply && $used >= $limit,
                'near_cap' => $caps_apply && $used < $limit && $used >= (int) floor( $limit * 0.8 ),
            ];
        }

        $tier_keys = FeatureMap::allTiers();
        $tiers = [];
        foreach ( $tier_keys as $key ) {
            $tiers[] = [
                'key'     => $key,
                'label'   => FeatureMap::tierLabel( $key ),
                'current' => $key === $effective,
            ];
        }

        $features = [];
        foreach ( FeatureMap::featureLabels() as $key => $label ) {
            $per_tier = [];
            foreach ( $tier_keys as $tier_key ) {
                $per_tier[ $tier_key ] = FeatureMap::tierHas( $tier_key, $key );
            }
            $features[] = [ 'key' => $key, 'label' => $label, 'tiers' => $per_tier ];
        }

        return [
            'commercial'     => $commercial,
            'tier'           => $tier,
            'tier_label'     => FeatureMap::tierLabel( $tier ),
            'effective_tier' => $effective,
            'paid_tier'      => $paid_tier,
            'entitled'       => $entitled !== null,
            'dev_override'   => $override !== null,
            'caps_apply'     => $caps_apply,
            'caps'           => $caps,
            'tiers'          => $tiers,
            'features'       => $features,
        ];
    }
}
