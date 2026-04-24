<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * TeamGenerator — creates the academy's teams, one per configured age group.
 *
 * Age groups are read from `tt_lookups.age_group` so the generator
 * always matches whatever reference data the current install has
 * configured. If the lookup is empty, generation fails loudly with a
 * helpful error pointing at Configuration → Age Groups.
 *
 * Team name shape: `{club name} {age group}` (e.g. "FC Groningen JO11").
 * Club name is provided per-generate via the admin form; if blank the
 * stored `academy_name` config is used; if that's also unset, the
 * placeholder "Demo Academy" is used.
 *
 * Head coach comes from the coach<N>@ slot pool.
 */
class TeamGenerator {

    private DemoBatchRegistry $registry;

    /** @var array<string,int> slot => user id */
    private array $users;

    private int $count;

    private ?string $club_name_override;

    /**
     * @param array<string,int> $users
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $users,
        int $count,
        ?string $club_name_override = null
    ) {
        $this->registry           = $registry;
        $this->users              = $users;
        $this->count              = $count;
        $this->club_name_override = $club_name_override !== null && trim( $club_name_override ) !== ''
            ? trim( $club_name_override )
            : null;
    }

    /**
     * @return object[] Inserted team rows (id, name, age_group, head_coach_id).
     */
    public function generate(): array {
        global $wpdb;

        $age_groups = $this->ageGroupsFromLookup();
        if ( ! $age_groups ) {
            throw new \RuntimeException(
                'No age groups configured. Add entries under TalentTrack → Configuration → Age Groups before generating demo data.'
            );
        }

        $club_name = $this->clubName();
        $teams     = [];
        $count     = min( $this->count, count( $age_groups ), 12 ); // cap at coach pool size

        for ( $i = 0; $i < $count; $i++ ) {
            $age_group = $age_groups[ $i ];
            $coach_slot = 'coach' . ( $i + 1 );
            $head_coach_id = (int) ( $this->users[ $coach_slot ] ?? 0 );

            $name = trim( $club_name . ' ' . $age_group );
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

    /**
     * @return string[]
     */
    private function ageGroupsFromLookup(): array {
        $rows = QueryHelpers::get_lookups( 'age_group' );
        $out = [];
        foreach ( $rows as $r ) {
            $name = trim( (string) $r->name );
            if ( $name !== '' ) $out[] = $name;
        }
        return $out;
    }

    private function clubName(): string {
        if ( $this->club_name_override !== null ) {
            return $this->club_name_override;
        }
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE config_key = %s",
            'academy_name'
        ) );
        return $name ? (string) $name : 'Demo Academy';
    }
}
