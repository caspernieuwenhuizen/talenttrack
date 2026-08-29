<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoRunState — one demo-generation run, written down between requests.
 *
 * #3041 — generating the large preset used to be a single `admin-post.php`
 * request. `set_time_limit()` raised PHP's ceiling, but the reverse proxy in
 * front of a hosted install gave up long before PHP did, and the operator was
 * left with a half-written dataset that looked complete.
 *
 * So a run is now a list of steps with a cursor, and the cursor survives the
 * request. That makes three things possible: the overlay can report real
 * progress, an interrupted run is visible instead of silent, and no single
 * request has to outlive a gateway timeout.
 *
 * **Carries no secrets and no uploaded file.** The steps that need those —
 * creating WP users, importing the workbook — run inside the request that
 * submitted the form, before any state is written. What persists is ids and
 * counts.
 *
 * One run at a time, in one option. Two concurrent demo generations against
 * the same club would race on every table they write; the page refuses to
 * start a second while one is unfinished.
 */
final class DemoRunState {

    public const OPTION = 'tt_demo_run';

    /** A run nobody has touched for this long is stale and can be replaced. */
    public const STALE_AFTER = 3600;

    public const STATUS_RUNNING  = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED   = 'failed';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    private function __construct( array $data ) {
        $this->data = $data;
    }

    /**
     * @param list<string>       $steps    every step in the run, in order
     * @param array<string,mixed> $context ids and counts the later steps need
     */
    public static function create( string $batch_id, array $steps, array $context ): self {
        return new self( array_merge( [
            'run_id'     => wp_generate_uuid4(),
            'batch_id'   => $batch_id,
            'status'     => self::STATUS_RUNNING,
            'error'      => '',
            'steps'      => array_values( $steps ),
            'done'       => [],
            'started_at' => time(),
            'updated_at' => time(),
        ], $context ) );
    }

    /** The run currently on file, or null. */
    public static function load(): ?self {
        $raw = get_option( self::OPTION, null );
        if ( ! is_array( $raw ) || empty( $raw['run_id'] ) ) {
            return null;
        }
        return new self( $raw );
    }

    public static function loadById( string $run_id ): ?self {
        $state = self::load();
        return ( $state !== null && $state->runId() === $run_id ) ? $state : null;
    }

    public static function clear(): void {
        delete_option( self::OPTION );
    }

    public function persist(): void {
        $this->data['updated_at'] = time();
        update_option( self::OPTION, $this->data, false );
    }

    public function runId(): string {
        return (string) ( $this->data['run_id'] ?? '' );
    }

    public function batchId(): string {
        return (string) ( $this->data['batch_id'] ?? '' );
    }

    public function status(): string {
        return (string) ( $this->data['status'] ?? self::STATUS_RUNNING );
    }

    public function error(): string {
        return (string) ( $this->data['error'] ?? '' );
    }

    /** @return list<string> */
    public function steps(): array {
        $steps = $this->data['steps'] ?? [];
        return is_array( $steps ) ? array_values( array_map( 'strval', $steps ) ) : [];
    }

    /** @return list<string> */
    public function done(): array {
        $done = $this->data['done'] ?? [];
        return is_array( $done ) ? array_values( array_map( 'strval', $done ) ) : [];
    }

    /** The next step to run, or null when the run has nothing left. */
    public function nextStep(): ?string {
        foreach ( $this->steps() as $step ) {
            if ( ! in_array( $step, $this->done(), true ) ) {
                return $step;
            }
        }
        return null;
    }

    public function markDone( string $step ): void {
        $done = $this->done();
        if ( ! in_array( $step, $done, true ) ) {
            $done[] = $step;
        }
        $this->data['done'] = $done;
        if ( $this->nextStep() === null ) {
            $this->data['status'] = self::STATUS_COMPLETE;
        }
    }

    public function fail( string $message ): void {
        $this->data['status'] = self::STATUS_FAILED;
        $this->data['error']  = $message;
    }

    public function isFinished(): bool {
        return $this->status() === self::STATUS_COMPLETE;
    }

    /**
     * True when nothing has touched this run for an hour. A tab closed
     * mid-run leaves a running state nobody will ever advance; after this it
     * is safe for the page to offer replacing it.
     */
    public function isStale(): bool {
        return $this->status() === self::STATUS_RUNNING
            && ( time() - (int) ( $this->data['updated_at'] ?? 0 ) ) > self::STALE_AFTER;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get( string $key, $default = null ) {
        return $this->data[ $key ] ?? $default;
    }

    /** @param mixed $value */
    public function set( string $key, $value ): void {
        $this->data[ $key ] = $value;
    }

    /**
     * Merge per-category counts as steps complete.
     *
     * @param array<string,int> $counts
     */
    public function addCounts( array $counts ): void {
        $existing = is_array( $this->data['counts'] ?? null ) ? $this->data['counts'] : [];
        $this->data['counts'] = array_merge( $existing, $counts );
    }

    /**
     * What the overlay renders: which step, how many remain, and whether the
     * run is still going.
     *
     * @return array{run_id:string, batch_id:string, status:string, error:string,
     *               total:int, completed:int, next:?string, next_label:string,
     *               counts:array<string,int>}
     */
    public function progress(): array {
        $next = $this->nextStep();

        return [
            'run_id'     => $this->runId(),
            'batch_id'   => $this->batchId(),
            'status'     => $this->status(),
            'error'      => $this->error(),
            'total'      => count( $this->steps() ),
            'completed'  => count( $this->done() ),
            'next'       => $next,
            'next_label' => $next !== null ? DemoRunPlan::label( $next ) : '',
            'counts'     => array_map( 'intval', (array) ( $this->data['counts'] ?? [] ) ),
        ];
    }
}
