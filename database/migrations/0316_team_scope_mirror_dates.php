<?php
/**
 * Migration 0316 — the team-scope mirror rows carry their assignments' dates
 * (#4721).
 *
 * `PeopleRepository::syncTeamScopeRow()` mirrors a person's assignments to a
 * team (`tt_team_people`) into one team-scope row in `tt_user_role_scopes`,
 * `role_id = 0`. It wrote that row with open dates, and 0215 backfilled rows
 * of the same shape, so every team-scope reader, which filters on the row's
 * dates, counted an assignment that had ended or had not started as current.
 * The sync now writes the envelope of the pair's assignments; this migration
 * brings the existing mirror rows in line:
 *
 *   - start: open when any assignment of the pair has no start, otherwise
 *     the earliest start;
 *   - end: open when any has no end, otherwise the latest end.
 *
 * Only mirror rows (`role_id = 0`, `scope_type = 'team'`) are touched. A
 * scope row an operator wrote and dated keeps its dates. A mirror row whose
 * pair has no assignment left is not touched either; the sync removes it
 * on the next write for that pair.
 *
 * Data only, no schema change. A second run changes nothing: the update
 * only writes a row whose dates differ from its envelope. Run-alone (no
 * other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0316_team_scope_mirror_dates';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $scopes      = "{$p}tt_user_role_scopes";
        $team_people = "{$p}tt_team_people";
        foreach ( [ $scopes, $team_people ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                $this->report( 0, "{$table} is missing" );
                return;
            }
        }

        $written = $this->exec(
            "UPDATE {$scopes} urs
               JOIN (
                    SELECT club_id, person_id, team_id,
                           CASE WHEN SUM( CASE WHEN start_date IS NULL THEN 1 ELSE 0 END ) > 0 THEN NULL ELSE MIN( start_date ) END AS env_start,
                           CASE WHEN SUM( CASE WHEN end_date   IS NULL THEN 1 ELSE 0 END ) > 0 THEN NULL ELSE MAX( end_date )   END AS env_end
                      FROM {$team_people}
                     GROUP BY club_id, person_id, team_id
                    ) env
                 ON env.club_id   = urs.club_id
                AND env.person_id = urs.person_id
                AND env.team_id   = urs.scope_id
                SET urs.start_date = env.env_start,
                    urs.end_date   = env.env_end
              WHERE urs.role_id    = 0
                AND urs.scope_type = 'team'
                AND NOT ( urs.start_date <=> env.env_start AND urs.end_date <=> env.env_end )"
        );

        $this->report(
            $written,
            $written > 0
                ? sprintf( 'dated %d team-scope mirror row(s) from their assignments', $written )
                : 'every team-scope mirror row already carries its assignments\' dates'
        );
    }
};
