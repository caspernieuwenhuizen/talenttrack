<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
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
 * Staff wiring: for each team we create two tt_team_people rows —
 * coach<N> as head_coach, assistant<N> as assistant_coach — via the
 * functional-role system. The legacy `head_coach_id` column is still
 * set for backcompat with `QueryHelpers::get_teams_for_coach()` and
 * any other v1 consumers that haven't migrated yet.
 */
class TeamGenerator {

    private DemoBatchRegistry $registry;

    /** @var array<string,int> slot => user id */
    private array $users;

    /** @var array<string,int> slot => tt_people.id (from PeopleGenerator) */
    private array $persons;

    private int $count;

    private ?string $club_name_override;

    /**
     * @param array<string,int> $users
     * @param array<string,int> $persons
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $users,
        array $persons,
        int $count,
        ?string $club_name_override = null
    ) {
        $this->registry           = $registry;
        $this->users              = $users;
        $this->persons            = $persons;
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

        // Resolve functional role ids once.
        $head_coach_fn_id      = $this->functionalRoleId( 'head_coach' );
        $assistant_coach_fn_id = $this->functionalRoleId( 'assistant_coach' );

        for ( $i = 0; $i < $count; $i++ ) {
            $age_group       = $age_groups[ $i ];
            $coach_slot      = 'coach' . ( $i + 1 );
            $assistant_slot  = 'assistant' . ( $i + 1 );
            $head_coach_id   = (int) ( $this->users[ $coach_slot ] ?? 0 );
            $head_coach_pid  = (int) ( $this->persons[ $coach_slot ] ?? 0 );
            $assistant_pid   = (int) ( $this->persons[ $assistant_slot ] ?? 0 );

            $name = trim( $club_name . ' ' . $age_group );
            $wpdb->insert( "{$wpdb->prefix}tt_teams", [
                'club_id'       => CurrentClub::id(),
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

            if ( $team_id > 0 && $head_coach_pid > 0 ) {
                $this->assignPersonToTeam( $team_id, $head_coach_pid, 'head_coach', $head_coach_fn_id );
            }
            if ( $team_id > 0 && $assistant_pid > 0 ) {
                $this->assignPersonToTeam( $team_id, $assistant_pid, 'assistant_coach', $assistant_coach_fn_id );
            }

            $teams[] = (object) [
                'id'            => $team_id,
                'name'          => $name,
                'age_group'     => $age_group,
                'head_coach_id' => $head_coach_id,
            ];
        }
        return $teams;
    }

    private function assignPersonToTeam( int $team_id, int $person_id, string $role_key, int $functional_role_id ): void {
        global $wpdb;
        // uniq constraints on (team_id, person_id, role_in_team) and
        // (team_id, person_id, functional_role_id) mean a duplicate
        // assignment silently no-ops; still tag what's present.
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id'            => CurrentClub::id(),
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'role_in_team'       => $role_key,
            'functional_role_id' => $functional_role_id ?: null,
        ] );
        $team_person_id = (int) $wpdb->insert_id;
        if ( $team_person_id > 0 ) {
            $this->registry->tag( 'team_person', $team_person_id, [
                'team_id'   => $team_id,
                'person_id' => $person_id,
                'role_key'  => $role_key,
            ] );
        }
    }

    private function functionalRoleId( string $role_key ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $role_key, CurrentClub::id()
        ) );
        return $id;
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
        $name = \TT\Infrastructure\Query\QueryHelpers::get_config( 'academy_name', '' );
        return $name !== '' ? $name : 'Demo Academy';
    }
}
