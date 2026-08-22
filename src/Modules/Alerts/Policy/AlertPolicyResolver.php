<?php
namespace TT\Modules\Alerts\Policy;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;

/**
 * AlertPolicyResolver (#2632, epic #2629) — the effective answer to "where,
 * if anywhere, may this alert reach this person?"
 *
 * Three layers, in order:
 *
 *   1. **Definition default** — `defaultSurfaces()`, shipped in code.
 *   2. **Club policy** — force on, force off, or leave it to the user.
 *   3. **User preference** — within what the club allows.
 *
 * Pure domain: no view code, no request state, no output. The evaluator
 * calls it when deciding whether to write occurrences at all, and the
 * renderers call it when deciding whether to draw one. Both get the same
 * answer, which is the property that keeps a banner from appearing for an
 * alert the user muted, or a muted alert from silently still being counted.
 *
 * Resolution is per (user, alert). Preferences are loaded once per user and
 * cached on the instance, because the settings screen resolves every
 * definition for one user and the sweep resolves every recipient for one
 * definition — both would otherwise issue a query per pair.
 */
final class AlertPolicyResolver {

    /**
     * Surfaces that reach a person outside the app, and therefore may never
     * be switched on by a shipped default (#2634, epic decision 10).
     *
     * The asymmetry is deliberate and worth stating: the app may nag you
     * in-app the moment an alert ships, but it may not put mail in your
     * inbox or a notification on your lock screen until you have asked for
     * it. In-app noise is recoverable by ignoring a page; unsolicited mail
     * is what gets a sender filtered permanently.
     *
     * @var list<string>
     */
    private const OPT_IN_ONLY_SURFACES = [ Surface::DIGEST, Surface::PUSH ];

    /** @var ClubAlertPolicy */
    private $club;

    /** @var AlertPreferencesRepository */
    private $prefs;

    /** @var array<int,array<string,array{surfaces:list<string>,muted_until:?string}>> */
    private $prefCache = [];

    public function __construct( ?ClubAlertPolicy $club = null, ?AlertPreferencesRepository $prefs = null ) {
        $this->club  = $club ?? new ClubAlertPolicy();
        $this->prefs = $prefs ?? new AlertPreferencesRepository();
    }

    /**
     * Surfaces this alert may use for this user. Empty means "nowhere" —
     * the alert is effectively off for them.
     *
     * @return list<string>
     */
    public function surfacesFor( int $userId, string $alertKey ): array {
        $definition = AlertRegistry::find( $alertKey );
        if ( $definition === null ) return [];
        return $this->surfacesForDefinition( $userId, $definition );
    }

    /**
     * As `surfacesFor()`, but for a definition you already hold.
     *
     * The evaluator uses this rather than the key-based variant, and the
     * distinction matters: `run()` can legitimately be handed a definition
     * that is not in the registry — a test stub, or a caller evaluating one
     * definition directly. Resolving that by key would find nothing, return
     * "no surfaces", and silently drop every occurrence. Failing closed on a
     * registry miss is right for a UI lookup and badly wrong here.
     *
     * @return list<string>
     */
    public function surfacesForDefinition( int $userId, AlertInterface $definition ): array {
        $alertKey = $definition->key();
        $mode     = $this->club->modeFor( $alertKey );

        if ( $mode === ClubAlertPolicy::MODE_FORCE_OFF ) {
            return [];
        }

        // #2634 — surfaces that leave the building are opt-in only (epic
        // decision 10). A definition listing `digest` or `push` among its
        // defaults would otherwise enrol every eligible user the day it
        // ships, which is precisely the unsolicited-mail outcome that
        // decision rules out. They appear only when a user's stored
        // preference asks for them, or when a club force-on names them
        // explicitly — both of which are somebody choosing.
        $surfaces = array_values( array_diff(
            Surface::normalise( $definition->defaultSurfaces() ),
            self::OPT_IN_ONLY_SURFACES
        ) );

        if ( $mode === ClubAlertPolicy::MODE_FORCE_ON ) {
            // The club's chosen surfaces win outright; an empty club set
            // means "on, at whatever the definition ships".
            $forced = $this->club->forcedSurfacesFor( $alertKey );
            $surfaces = ! empty( $forced ) ? $forced : $surfaces;
            return $this->withInterrupt( $alertKey, $surfaces );
        }

        // user_choice: a stored row replaces the default entirely, including
        // the empty set. Absence of a row means the user has never expressed
        // a view, which is different from having chosen "nowhere".
        $pref = $this->preferencesFor( $userId )[ $alertKey ] ?? null;

        if ( $pref !== null ) {
            if ( $this->isMuted( $pref['muted_until'] ) ) return [];
            $surfaces = $pref['surfaces'];
        }

        return $this->withInterrupt( $alertKey, $surfaces );
    }

    /**
     * Whether the alert may reach the user anywhere at all.
     *
     * This is what the evaluator asks before writing an occurrence. Note it
     * is deliberately NOT "is any surface enabled" evaluated per recipient
     * during a sweep — see `isForcedOff()` for the cheap club-level check
     * the sweep uses first.
     */
    public function isEnabledFor( int $userId, string $alertKey ): bool {
        return ! empty( $this->surfacesFor( $userId, $alertKey ) );
    }

    /** As `isEnabledFor()`, for a definition you already hold. */
    public function isEnabledForDefinition( int $userId, AlertInterface $definition ): bool {
        return ! empty( $this->surfacesForDefinition( $userId, $definition ) );
    }

    public function allows( int $userId, string $alertKey, string $surface ): bool {
        return in_array( $surface, $this->surfacesFor( $userId, $alertKey ), true );
    }

    /**
     * Club-level short circuit: true when nobody at this club may receive
     * this alert.
     *
     * The sweep checks this once per definition rather than once per
     * recipient, so a force-off alert costs one config read instead of a
     * resolution per candidate user. It is also the check that makes
     * "force_off stops rows being written" true rather than "written and
     * hidden" — a table of invisible rows is retention cost and privacy
     * surface for no benefit.
     */
    public function isForcedOff( string $alertKey ): bool {
        return $this->club->modeFor( $alertKey ) === ClubAlertPolicy::MODE_FORCE_OFF;
    }

    /** Whether the club has made this alert blocking. */
    public function isInterrupt( int $userId, string $alertKey ): bool {
        return $this->allows( $userId, $alertKey, Surface::INTERRUPT );
    }

    /**
     * Whether the user may change this alert's setting at all, and why not.
     *
     * Returns null when they may. The reason strings exist so the settings
     * screen can render a locked row **with an explanation** rather than
     * hiding it — a preferences screen that quietly omits what you cannot
     * change teaches you the list is complete when it is not.
     */
    public function lockReason( string $alertKey ): ?string {
        $definition = AlertRegistry::find( $alertKey );
        if ( $definition === null ) return null;

        if ( $definition->isOperational() ) {
            return __( 'Always on — this one concerns a child\'s safety.', 'talenttrack' );
        }

        switch ( $this->club->modeFor( $alertKey ) ) {
            case ClubAlertPolicy::MODE_FORCE_ON:
                return __( 'Your academy has set this to always on.', 'talenttrack' );
            case ClubAlertPolicy::MODE_FORCE_OFF:
                return __( 'Your academy has switched this off for everyone.', 'talenttrack' );
            default:
                return null;
        }
    }

    /**
     * Surfaces a user may tick for this alert: what the definition can
     * actually produce, intersected with what a user is allowed to choose.
     *
     * @return list<string>
     */
    public function choosableSurfaces( string $alertKey ): array {
        $definition = AlertRegistry::find( $alertKey );
        if ( $definition === null ) return [];

        $declared = Surface::normalise( $definition->defaultSurfaces() );
        // A definition that ships badge-only should still let a user opt UP
        // to a banner — the default is where it starts, not a ceiling. The
        // ceiling is the user-choosable vocabulary itself.
        $candidates = array_values( array_unique( array_merge( $declared, Surface::userChoosable() ) ) );

        return Surface::normalise(
            array_values( array_intersect( $candidates, Surface::userChoosable() ) )
        );
    }

    /**
     * Every definition's effective state for one user, for the settings
     * screen. Keyed by alert key, grouped by the caller.
     *
     * @return array<string,array{definition:AlertInterface,surfaces:list<string>,locked:?string,choosable:list<string>}>
     */
    public function matrixFor( int $userId ): array {
        $out = [];
        foreach ( AlertRegistry::all() as $key => $definition ) {
            $out[ $key ] = [
                'definition' => $definition,
                'surfaces'   => $this->surfacesFor( $userId, $key ),
                'locked'     => $this->lockReason( $key ),
                'choosable'  => $this->choosableSurfaces( $key ),
            ];
        }
        return $out;
    }

    /** Drop cached preferences. Call after saving. */
    public function flush(): void {
        $this->prefCache = [];
        $this->club->flush();
    }

    /**
     * @return array<string,array{surfaces:list<string>,muted_until:?string}>
     */
    private function preferencesFor( int $userId ): array {
        if ( ! isset( $this->prefCache[ $userId ] ) ) {
            $this->prefCache[ $userId ] = $this->prefs->allForUser( $userId );
        }
        return $this->prefCache[ $userId ];
    }

    private function isMuted( ?string $mutedUntil ): bool {
        if ( $mutedUntil === null || $mutedUntil === '' ) return false;
        return strtotime( $mutedUntil ) > current_time( 'timestamp' );
    }

    /**
     * Add the interrupt surface when the club has assigned it. Applied after
     * every other layer so neither a definition nor a user can introduce or
     * remove it — only the club screen can.
     *
     * @param list<string> $surfaces
     * @return list<string>
     */
    private function withInterrupt( string $alertKey, array $surfaces ): array {
        if ( ! $this->club->interruptEnabled( $alertKey ) ) return $surfaces;
        if ( empty( $surfaces ) ) return $surfaces;  // off is off; do not resurrect it as a modal
        return Surface::normalise( array_merge( $surfaces, [ Surface::INTERRUPT ] ), true );
    }
}
