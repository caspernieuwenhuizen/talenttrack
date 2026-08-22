<?php
namespace TT\Modules\Alerts\Policy;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Domain\Surface;

/**
 * ClubAlertPolicy (#2632, epic #2629) — what the club allows, per alert.
 *
 * The middle layer of the three-layer precedence: definition default → club
 * policy → user preference. Stored in `tt_config`, which is already keyed by
 * `club_id`, so this is tenant-scoped without a table of its own.
 *
 * Three modes per alert key:
 *
 *   - `user_choice` (default) — the user decides, within the surfaces the
 *     definition declares.
 *   - `force_on` — everyone eligible gets it, at the club's chosen surfaces.
 *     The user's own row is ignored.
 *   - `force_off` — nobody gets it. The evaluator skips the definition
 *     entirely, so no occurrences are written at all rather than written and
 *     hidden; a table full of rows nobody can see is a retention cost and a
 *     privacy surface for no benefit.
 *
 * Plus two per-alert settings the club owns outright:
 *
 *   - `interrupt` — whether this alert may block with an acknowledge modal.
 *     A definition can never declare this itself (epic decision 4).
 *   - `escalate_after_days` — how long an ignored occurrence waits before
 *     becoming a workflow task (epic decision 13). Stored here in wave 2;
 *     wired to actual escalation in #2635. An academy marking attendance
 *     weekly needs a different threshold from one doing it nightly, and a
 *     wrong threshold manufactures tasks nobody asked for.
 *
 * Operational definitions (`isOperational()`) refuse `force_off` — the same
 * rule `Comms\Domain\MessageType::isOperational()` enforces for safeguarding
 * broadcasts. An academy cannot switch off the alerts that exist to protect
 * a child.
 */
final class ClubAlertPolicy {

    public const MODE_USER_CHOICE = 'user_choice';
    public const MODE_FORCE_ON    = 'force_on';
    public const MODE_FORCE_OFF   = 'force_off';

    /** tt_config key holding the whole per-club policy map as JSON. */
    public const CONFIG_KEY = 'tt_alerts_club_policy';

    /** @var ConfigService */
    private $config;

    /** @var array<string,array<string,mixed>>|null */
    private $cache = null;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /** @return list<string> */
    public static function modes(): array {
        return [ self::MODE_USER_CHOICE, self::MODE_FORCE_ON, self::MODE_FORCE_OFF ];
    }

    public static function modeLabel( string $mode ): string {
        switch ( $mode ) {
            case self::MODE_FORCE_ON:
                return __( 'Always on for everyone', 'talenttrack' );
            case self::MODE_FORCE_OFF:
                return __( 'Off for the whole club', 'talenttrack' );
            default:
                return __( 'Each person chooses', 'talenttrack' );
        }
    }

    /**
     * The full policy map, alert key => settings. Alerts with no stored
     * entry are absent; callers use the accessors below rather than reading
     * this directly, so "absent" resolves to the documented default in one
     * place.
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array {
        if ( $this->cache !== null ) return $this->cache;
        $raw = $this->config->getJson( self::CONFIG_KEY, [] );
        $this->cache = is_array( $raw ) ? $raw : [];
        return $this->cache;
    }

    public function modeFor( string $alertKey ): string {
        $entry = $this->all()[ $alertKey ] ?? [];
        $mode  = isset( $entry['mode'] ) ? (string) $entry['mode'] : self::MODE_USER_CHOICE;
        if ( ! in_array( $mode, self::modes(), true ) ) return self::MODE_USER_CHOICE;

        // An operational alert can never be forced off, whatever is stored.
        // Enforced on read as well as on write so a hand-edited config row,
        // or one written before a definition became operational, cannot
        // silence a safeguarding alert.
        if ( $mode === self::MODE_FORCE_OFF && $this->isOperational( $alertKey ) ) {
            return self::MODE_USER_CHOICE;
        }
        return $mode;
    }

    /**
     * Surfaces the club forces when the mode is `force_on`. Empty means
     * "the definition's own defaults", which is the sane reading of
     * "always on" without further instruction.
     *
     * @return list<string>
     */
    public function forcedSurfacesFor( string $alertKey ): array {
        $entry = $this->all()[ $alertKey ] ?? [];
        $raw   = isset( $entry['surfaces'] ) && is_array( $entry['surfaces'] ) ? $entry['surfaces'] : [];
        return Surface::normalise( $raw, true );
    }

    /** Whether this alert may render as a blocking acknowledge modal. */
    public function interruptEnabled( string $alertKey ): bool {
        $entry = $this->all()[ $alertKey ] ?? [];
        return ! empty( $entry['interrupt'] );
    }

    /**
     * Days an occurrence may stay open before escalating to a workflow task,
     * or null when the club has not set one — in which case #2635 falls back
     * to the definition's shipped default.
     */
    public function escalateAfterDays( string $alertKey ): ?int {
        $entry = $this->all()[ $alertKey ] ?? [];
        if ( ! isset( $entry['escalate_after_days'] ) ) return null;
        $days = (int) $entry['escalate_after_days'];
        return $days > 0 ? $days : null;
    }

    /**
     * Persist one alert's policy.
     *
     * Returns an error string when the change is refused, or null on
     * success. Refusal is a message rather than a silent no-op because the
     * only refusable case — forcing off an operational alert — is one an
     * admin needs explained, not swallowed.
     *
     * @param list<string> $forcedSurfaces
     */
    public function set(
        string $alertKey,
        string $mode,
        array $forcedSurfaces = [],
        bool $interrupt = false,
        ?int $escalateAfterDays = null
    ): ?string {
        if ( $alertKey === '' ) return null;

        if ( ! in_array( $mode, self::modes(), true ) ) {
            $mode = self::MODE_USER_CHOICE;
        }

        if ( $mode === self::MODE_FORCE_OFF && $this->isOperational( $alertKey ) ) {
            return sprintf(
                /* translators: %s: the alert's name */
                __( '"%s" protects a child\'s safety and cannot be switched off for the club.', 'talenttrack' ),
                $this->labelFor( $alertKey )
            );
        }

        $map = $this->all();
        $map[ $alertKey ] = [
            'mode'                => $mode,
            'surfaces'            => Surface::normalise( $forcedSurfaces, true ),
            'interrupt'           => $interrupt,
            'escalate_after_days' => $escalateAfterDays !== null && $escalateAfterDays > 0 ? $escalateAfterDays : null,
        ];

        $this->config->setJson( self::CONFIG_KEY, $map );
        $this->cache = $map;
        return null;
    }

    /** Drop the in-instance cache. Used after an external config write. */
    public function flush(): void {
        $this->cache = null;
    }

    private function isOperational( string $alertKey ): bool {
        $definition = AlertRegistry::find( $alertKey );
        return $definition !== null && $definition->isOperational();
    }

    private function labelFor( string $alertKey ): string {
        $definition = AlertRegistry::find( $alertKey );
        return $definition !== null ? $definition->label() : $alertKey;
    }
}
