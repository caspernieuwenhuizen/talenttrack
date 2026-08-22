<?php
namespace TT\Modules\Alerts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertEvaluator (#2631, epic #2629) — runs a definition and reconciles.
 *
 * This is the load-bearing class of the whole engine. Everything else is
 * plumbing around the three-way reconcile it performs:
 *
 *   present now, present before  → bump `last_seen_at` (and severity)
 *   present now, absent before   → insert, `first_seen_at` = now
 *   absent now,  open before     → stamp `resolved_at`
 *
 * That third line is what makes an alert an alert rather than a task. The
 * coach fixes the activity in the activities list; nobody tells the alerts
 * engine anything; the next sweep simply does not see the condition and
 * resolves it. No completion step, no dangling row to reconcile by hand.
 *
 * It also means `evaluate()` must return the FULL truth for its scope. A
 * definition returning a delta tells this method that everything it omitted
 * has been fixed. That contract is documented on `AlertInterface` and is
 * the first thing to check when a backlog mysteriously empties.
 */
final class AlertEvaluator {

    /**
     * Ceiling on occurrences accepted from a single definition in one run.
     *
     * A definition returning more than this is misspecified — almost always
     * a missing scope filter — and the honest failure is to log which one
     * and truncate. Writing all of them would inflate the table and bury
     * the cause; writing none would look like the definition works.
     */
    public const OCCURRENCE_CEILING = 2000;

    /** @var AlertOccurrencesRepository */
    private $repo;

    public function __construct( ?AlertOccurrencesRepository $repo = null ) {
        $this->repo = $repo ?? new AlertOccurrencesRepository();
    }

    /**
     * Evaluate one definition and reconcile its results.
     *
     * @return array{created:int,bumped:int,resolved:int,skipped:int,truncated:bool}
     */
    public function run( AlertInterface $alert, AlertContext $context ): array {
        $now  = current_time( 'mysql' );
        $stat = [ 'created' => 0, 'bumped' => 0, 'resolved' => 0, 'skipped' => 0, 'truncated' => false ];

        $occurrences = $alert->evaluate( $context );
        if ( ! is_array( $occurrences ) ) $occurrences = [];

        if ( count( $occurrences ) > self::OCCURRENCE_CEILING ) {
            $stat['truncated'] = true;
            error_log( sprintf(
                '[TalentTrack alerts] definition "%s" returned %d occurrences, ceiling is %d — truncating. This usually means a missing scope filter.',
                $alert->key(),
                count( $occurrences ),
                self::OCCURRENCE_CEILING
            ) );
            $occurrences = array_slice( $occurrences, 0, self::OCCURRENCE_CEILING );
        }

        $seen = [];
        foreach ( $occurrences as $occ ) {
            if ( ! $occ instanceof AlertOccurrence ) continue;

            // Capability gate, applied on every run rather than trusted from
            // the definition's audience resolution. Roles change between
            // sweeps; a coach who moved teams last week must stop receiving
            // this at the next tick, not at the next release.
            if ( ! $this->recipientMayReceive( $occ->recipientUserId, $alert->capRequired() ) ) {
                $stat['skipped']++;
                continue;
            }

            // The definition names the alert it belongs to; trusting a
            // mismatched key would let one definition write into another's
            // dedupe namespace and resolve its rows.
            $occ->alertKey = $alert->key();

            $created = $this->repo->upsert( $occ, $now );
            $created ? $stat['created']++ : $stat['bumped']++;
            $seen[] = $occ->dedupeKey();
        }

        // A truncated run must not resolve. It saw only part of the truth,
        // so everything it did not reach would be marked fixed — turning a
        // misspecified definition into silent data loss.
        if ( ! $stat['truncated'] ) {
            $stat['resolved'] = $this->repo->resolveMissing(
                $alert->key(),
                $seen,
                $now,
                $context->isFullSweep() ? [] : $context->subjectIds
            );
        }

        return $stat;
    }

    /**
     * Run every registered definition for the current club.
     *
     * One definition throwing must not take the sweep down — the others
     * are unrelated conditions and a coach should not lose their attendance
     * alerts because an evaluations query broke.
     *
     * @return array<string,array{created:int,bumped:int,resolved:int,skipped:int,truncated:bool,ms:int}>
     */
    public function runAll( AlertContext $context ): array {
        $out = [];
        foreach ( AlertRegistry::all() as $key => $alert ) {
            $started = microtime( true );
            try {
                $stat = $this->run( $alert, $context );
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[TalentTrack alerts] definition "%s" failed: %s',
                    $key,
                    $e->getMessage()
                ) );
                continue;
            }
            $stat['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            $out[ $key ] = $stat;
        }
        return $out;
    }

    /**
     * Whether a resolved recipient may actually receive this alert.
     *
     * `user_can()` rather than `current_user_can()`: the sweep runs on a
     * cron tick with no logged-in user, so the current-user variant would
     * evaluate against nobody and deny everything.
     */
    private function recipientMayReceive( int $userId, string $cap ): bool {
        if ( $userId <= 0 ) return false;
        if ( $cap === '' ) return true;
        return user_can( $userId, $cap );
    }
}
