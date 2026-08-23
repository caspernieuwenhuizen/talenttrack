<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;

/**
 * AbstractDataQualityAlert (#2636, epic #2629) — shared machinery for the
 * two definitions about records that are simply incomplete.
 *
 * These are the odd ones out in the wave. Every other definition has a
 * coach who owns the thing; a player with no team has no team, so it has no
 * head coach, and a team with no head coach has, by definition, no head
 * coach. There is nobody closer to the problem than whoever looks after the
 * records — so this is the one audience in the wave resolved from a
 * capability rather than from a relationship.
 *
 * ## The custodian set is one query, and it is capped
 *
 * `get_users()` with a capability filter, once per evaluation, not once per
 * row. The cap on how many people it fans to is arithmetic, not taste: the
 * evaluator truncates a definition at 2000 occurrences, and rows ×
 * custodians is what fills that. Twenty leaves room for a hundred incomplete
 * records, which is far more than a healthy academy carries — and an academy
 * that really has more gets a truncation warning in the log rather than a
 * table full of noise.
 *
 * The evaluator re-checks `capRequired()` against every recipient anyway, so
 * a stale row here is filtered before it is written; this query only has to
 * be a good candidate list, not an authoritative one.
 *
 * ## They are quieter than the rest of the catalogue
 *
 * `info` severity, `badge` only. A missing team assignment is worth knowing
 * about; it is not worth a banner across somebody's dashboard every morning
 * until they fix it. Definitions override where the gap is more than
 * administrative — a squad with players and no head coach, for instance.
 */
abstract class AbstractDataQualityAlert implements AlertInterface {

    /** Maximum recipients one data-quality occurrence fans out to. */
    protected const MAX_CUSTODIANS = 20;

    /**
     * Accounts examined while looking for custodians.
     *
     * `user_can()` has to be asked per account (see `custodians()`), so this
     * is what stops a very large install turning one definition into a scan
     * of every user. Generous relative to an academy's staff count: if the
     * first thousand accounts by ID contain no custodian, the install has a
     * configuration problem that an alert cannot fix.
     */
    private const SCAN_CEILING = 1000;

    /** @var ConfigService|null */
    private $config = null;

    public function module(): string {
        return 'dataquality';
    }

    public function defaultSeverity(): string {
        return Severity::INFO;
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ 'badge' ];
    }

    public function isOperational(): bool {
        return false;
    }

    /**
     * The incomplete records. Must be a single set-based query and must
     * return objects carrying at least `subject_id`.
     *
     * @return list<object>
     */
    abstract protected function rows( AlertContext $context ): array;

    abstract public function subjectType(): string;

    abstract protected function titleFor( object $row ): string;

    abstract protected function urlFor( object $row ): string;

    /** The player this rolls up to, when there is one. */
    protected function playerIdFor( object $row ): ?int {
        $id = (int) ( $row->player_id ?? 0 );
        return $id > 0 ? $id : null;
    }

    protected function severityFor( object $row ): string {
        return $this->defaultSeverity();
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [];
    }

    /** @return list<AlertOccurrence> */
    public function evaluate( AlertContext $context ): array {
        $rows = $this->rows( $context );
        if ( empty( $rows ) ) return [];

        $custodians = $this->custodians();
        if ( empty( $custodians ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $subject_id = (int) ( $row->subject_id ?? 0 );
            if ( $subject_id <= 0 ) continue;

            $payload = array_merge( [
                'title' => $this->titleFor( $row ),
                'url'   => $this->urlFor( $row ),
            ], $this->payloadFor( $row ) );

            foreach ( $custodians as $user_id ) {
                $out[] = new AlertOccurrence(
                    $this->key(),
                    $user_id,
                    $this->subjectType(),
                    $subject_id,
                    $this->severityFor( $row ),
                    $payload,
                    $this->playerIdFor( $row )
                );
            }
        }

        return $out;
    }

    /**
     * WP user ids holding this alert's capability, in one query.
     *
     * @return list<int>
     */
    protected function custodians(): array {
        if ( ! function_exists( 'get_users' ) ) return [];

        // NOT `get_users( [ 'capability' => … ] )`, which is what this used
        // to do and why it found nobody.
        //
        // That query inspects the capabilities stored on a user's roles. Most
        // TalentTrack caps are not stored there: they are matrix-derived and
        // bridged at runtime by `Authorization\LegacyCapMapper` through the
        // `user_has_cap` filter — `tt_manage_teams` maps to
        // `team:create_delete`. A meta query never runs that filter, so it
        // returns an empty set on an install where the capability is very
        // much held, and the alert silently reaches nobody.
        //
        // `user_can()` does run the filter, so the shape has to be
        // enumerate-then-ask. Bounded twice over: stop once enough custodians
        // are found, and never scan past SCAN_CEILING accounts, so a large
        // install cannot turn one definition into an unbounded sweep.
        $ids = get_users( [
            'fields'  => 'ID',
            'number'  => self::SCAN_CEILING,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ] );

        $cap = $this->capRequired();
        $out = [];
        foreach ( is_array( $ids ) ? $ids : [] as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) continue;
            if ( ! user_can( $id, $cap ) ) continue;
            $out[] = $id;
            if ( count( $out ) >= static::MAX_CUSTODIANS ) break;
        }

        return $out;
    }

    /**
     * A club-scoped numeric threshold from `tt_config`, floored at 1.
     */
    protected function threshold( string $key, int $default ): int {
        if ( $this->config === null ) {
            $this->config = new ConfigService();
        }
        $value = $this->config->getInt( $key, $default );
        return $value > 0 ? $value : $default;
    }
}
