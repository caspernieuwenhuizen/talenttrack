<?php
/**
 * PlayerStatus — typed constants for the lifecycle values stored in
 * `tt_players.status`. Five canonical states:
 *
 *   - `active`    — player is currently on the roster, default for new
 *                   signings + the post-promotion state after a
 *                   successful trial.
 *   - `trial`     — player is on a trial run with the academy; gated UI
 *                   in `FrontendPlayerDetailView`, `SquadStep`, and the
 *                   trials manage view checks against this value before
 *                   surfacing trial-specific affordances.
 *   - `inactive`  — player has left the roster but is preserved as a
 *                   historical record (distinct from `archived_at` which
 *                   is the soft-delete column from migration 0010).
 *   - `released`  — released from the academy mid-season or post-trial;
 *                   transition emits a `released` journey event via
 *                   `JourneyEventSubscriber::emitStatusTransition()`.
 *   - `graduated` — player aged out / progressed beyond the academy;
 *                   transition emits a `graduated` journey event.
 *
 * Lifecycle vs archive. `tt_players.archived_at` is the soft-delete /
 * bulk-archive marker (NULL = present, timestamp = archived). The
 * `status` column is the *roster lifecycle marker*; archived players
 * still carry one of the five values above. Migration 0061 back-filled
 * the legacy `status='deleted'` rows from v3.89.1-and-earlier delete
 * paths back to `'active'` (with `archived_at` populated), so the
 * five-value vocabulary above is the only stored set on every install.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( (string) $player->status === PlayerStatus::TRIAL ) { ... }
 *     [ 'status' => PlayerStatus::ACTIVE ]
 *
 * SQL string literals (`WHERE pl.status='active'` in roster aggregation
 * queries) stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PlayerStatus {

    public const ACTIVE    = 'active';
    public const TRIAL     = 'trial';
    public const INACTIVE  = 'inactive';
    public const RELEASED  = 'released';
    public const GRADUATED = 'graduated';

    /** @var list<string> */
    public const ALL = [
        self::ACTIVE,
        self::TRIAL,
        self::INACTIVE,
        self::RELEASED,
        self::GRADUATED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
