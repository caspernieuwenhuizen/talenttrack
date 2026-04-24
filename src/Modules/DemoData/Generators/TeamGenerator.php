<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\SeedLoader;

/**
 * TeamGenerator — creates the academy's teams, one per age group.
 *
 * Head coach is drawn from coach<N>@ slot pool. Assistant coach
 * assignment (via Functional Roles module) is Checkpoint 2 — Checkpoint
 * 1 just leaves the tt_team_people entry empty, which the plugin
 * tolerates.
 *
 * Team name shape: "{Academy} {JOxx}" (e.g. "Academy JO11"). Academy
 * is pulled from tt_config.academy_name, defaulting to "Demo Academy"
 * if unset.
 */
class TeamGenerator {

    private DemoBatchRegistry $registry;

    /** @var array<string,int> slot => user id */
    private array $users;

    private int $count;

    public function __construct( DemoBatchRegistry $registry, array $users, int $count ) {
        $this->registry = $registry;
        $this->users    = $users;
        $this->count    = $count;
    }

    /**
     * @return object[] Inserted team rows (id, name, age_group, head_coach_id).
     */
    public function generate(): array {
        global $wpdb;

        $age_groups = SeedLoader::ageGroups();
        if ( ! $age_groups ) {
            throw new \RuntimeException( 'Demo seed team_age_groups.txt is missing or empty.' );
        }

        $academy = $this->academyName();
        $teams   = [];
        $count   = min( $this->count, count( $age_groups ), 12 ); // cap at coach pool size

        for ( $i = 0; $i < $count; $i++ ) {
            $age_group = $age_groups[ $i ];
            $coach_slot = 'coach' . ( $i + 1 );
            $head_coach_id = (int) ( $this->users[ $coach_slot ] ?? 0 );

            $name = trim( $academy . ' ' . $age_group );
            $wpdb->insert( "{$wpdb->prefix}tt_teams", [
                'name'          => $name,
                'age_group'     => $age_group,
                'head_coach_id' => $head_coach_id,
                'notes'         => 'Demo team',
            ] );
            $team_id = (int) $wpdb->insert_id;

            $this->registry->tag( 'team', $team_id, [
                'age_group'  => $age_group,
                'coach_slot' => $coach_slot,
            ] );

            $teams[] = (object) [
                'id'            => $team_id,
                'name'          => $name,
                'age_group'     => $age_group,
                'head_coach_id' => $head_coach_id,
            ];
        }
        return $teams;
    }

    private function academyName(): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE config_key = %s",
            'academy_name'
        ) );
        return $name ? (string) $name : 'Demo Academy';
    }
}
