<?php
/**
 * Migration 0215 — link exercises to methodology principles by their
 * tactical theme (#2497, epic #2493).
 *
 * ## Why this exists
 *
 * `tt_exercise_principles` is the join the whole Training epic rests on:
 * the generator ranks candidate exercises by how many of a squad's open
 * development targets they touch, and wave 7 computes per-player training
 * exposure by joining run blocks through it to principles.
 *
 * It was empty. Every one of the 123 exercises on a seeded install — the
 * 18 from migration 0090 and the 105 merged from VCT by 0212 — carried no
 * principle link at all, and nothing in the product wrote one. The
 * mechanism was there; the data was not. Left alone, the generator would
 * have scored every candidate zero and silently degraded to a variety
 * heuristic, and the exposure numbers would all have been zero.
 *
 * ## How the mapping is derived
 *
 * Not from the principle codes. That would have been wrong: methodology 1
 * uses `OA-*` for *omschakelen naar aanvallen*, while methodology 2 uses
 * `OA-*` for *omschakelen naar verdedigen* — the opposite phase. The codes
 * are per-methodology labels, not a shared vocabulary.
 *
 * The mapping instead runs through the columns that actually classify a
 * principle by game phase, `team_function_key` and `team_task_key`, which
 * are consistent across both shipped methodologies:
 *
 *   aanvallen / opbouwen                        AO-01..05  AV-01..03
 *   aanvallen / scoren                          AS-01..02  AV-04..06
 *   verdedigen / storen                         VS-01..05  VD-01..05
 *   verdedigen / doelpunten_voorkomen           VV-01..03
 *   omschakelen_naar_aanvallen / balwinst       OA-*(m1)   OV-*(m2)
 *   omschakelen_naar_verdedigen / balverlies    OV-*(m1)   OA-*(m2)
 *
 * The only judgement is which phase each VCT tactical theme trains, and
 * that is the small table below — one line per theme, easy to review and
 * easy to correct.
 *
 * ## What it deliberately does not do
 *
 * - It never touches an exercise that already has a principle link, so
 *   anything an academy tagged by hand always wins.
 * - `1v1_duels`, `set_pieces` and `mixed` map to nothing. They span
 *   phases, and a wrong link is worse than none: it would make the
 *   generator confident about a drill it has misunderstood.
 * - The 60 exercises with no tactical theme get nothing. They are tagged
 *   in the library UI, which is the path an academy uses from here.
 *
 * Idempotent: INSERT IGNORE on the unique key, plus the has-no-links
 * guard. Re-running adds nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /**
     * VCT tactical theme => the game phases it trains, as
     * (team_function_key, team_task_key) pairs. A null task means "any
     * task within that function".
     *
     * @var array<string, list<array{0:string,1:string|null}>>
     */
    private const THEME_PHASES = [
        'build_up'   => [ [ 'aanvallen', 'opbouwen' ] ],
        'possession' => [ [ 'aanvallen', 'opbouwen' ] ],
        'finishing'  => [ [ 'aanvallen', 'scoren' ] ],
        'pressing'   => [ [ 'verdedigen', 'storen' ] ],
        'defending'  => [ [ 'verdedigen', 'storen' ], [ 'verdedigen', 'doelpunten_voorkomen' ] ],
        'counter'    => [ [ 'omschakelen_naar_aanvallen', null ] ],
        'transition' => [ [ 'omschakelen_naar_aanvallen', null ], [ 'omschakelen_naar_verdedigen', null ] ],
        // Deliberately unmapped — these span phases, and a confident
        // wrong link is worse than no link.
        '1v1_duels'  => [],
        'set_pieces' => [],
        'mixed'      => [],
    ];

    public function getName(): string {
        return '0215_seed_exercise_principle_links';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ 'tt_exercises', 'tt_principles', 'tt_exercise_principles' ] as $table ) {
            $full = $p . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                return;
            }
        }

        $principles = $this->principlesByPhase( $p );
        if ( ! $principles ) return;

        // Exercises that carry a theme and have no principle link yet.
        $rows = $wpdb->get_results(
            "SELECT e.id, e.club_id, e.tactical_theme
               FROM {$p}tt_exercises e
              WHERE e.tactical_theme IS NOT NULL
                AND e.tactical_theme <> ''
                AND NOT EXISTS (
                        SELECT 1 FROM {$p}tt_exercise_principles ep
                         WHERE ep.exercise_id = e.id AND ep.club_id = e.club_id
                    )"
        );
        if ( ! is_array( $rows ) || ! $rows ) return;

        $sql = "INSERT IGNORE INTO {$p}tt_exercise_principles
                    (club_id, exercise_id, principle_id, created_at)
                VALUES (%d, %d, %d, %s)";
        $now = current_time( 'mysql' );

        foreach ( $rows as $row ) {
            $phases = self::THEME_PHASES[ (string) $row->tactical_theme ] ?? [];
            if ( ! $phases ) continue;

            foreach ( $phases as [ $function, $task ] ) {
                $key = $function . '|' . ( $task ?? '*' );
                foreach ( $principles[ $key ] ?? [] as $principle_id ) {
                    $this->exec( $wpdb->prepare(
                        $sql,
                        (int) $row->club_id,
                        (int) $row->id,
                        (int) $principle_id,
                        $now
                    ) );
                }
            }
        }
    }

    /**
     * Principle ids grouped by `function|task` and by `function|*`, so a
     * theme can address either a specific task or a whole phase.
     *
     * Both shipped methodologies are included: a club runs one of them,
     * and the coverage scoring only ever counts principles a player
     * actually has a goal on, so links to the other set are inert rather
     * than wrong.
     *
     * @return array<string, list<int>>
     */
    private function principlesByPhase( string $prefix ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, team_function_key, team_task_key
               FROM {$prefix}tt_principles
              WHERE archived_at IS NULL
                AND team_function_key IS NOT NULL"
        );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $function = (string) $row->team_function_key;
            $task     = (string) ( $row->team_task_key ?? '' );

            $out[ $function . '|*' ][] = (int) $row->id;
            if ( $task !== '' ) {
                $out[ $function . '|' . $task ][] = (int) $row->id;
            }
        }

        return $out;
    }
};
