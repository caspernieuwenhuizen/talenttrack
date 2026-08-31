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
 * functional-role system. #1315 retired the legacy `head_coach_id`
 * column on `tt_teams`; head-coach attribution downstream
 * (ActivityGenerator, EvaluationGenerator, PlayerGenerator) reads
 * the WP user id directly off the team row's `head_coach_user_id`
 * field, populated here from the `coach<N>` slot.
 */
class TeamGenerator implements GeneratorInterface {

    private DemoBatchRegistry $registry;

    /** @var array<string,int> slot => user id */
    private array $users;

    /** @var array<string,int> slot => tt_people.id (from PeopleGenerator) */
    private array $persons;

    private int $count;

    private ?string $club_name_override;

    public static function category(): string {
        return 'teams';
    }

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
     * @return object[] Inserted team rows (id, name, age_group, head_coach_user_id).
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

        // #3242 — spread across the ladder rather than taking the youngest
        // N. See `spreadAcrossLadder()`.
        $age_groups = self::spreadAcrossLadder( self::agedGroupsOnly( $age_groups ), $count );

        // Resolve functional role ids once.
        $head_coach_fn_id      = $this->functionalRoleId( 'head_coach' );
        $assistant_coach_fn_id = $this->functionalRoleId( 'assistant_coach' );

        for ( $i = 0; $i < $count; $i++ ) {
            $age_group           = $age_groups[ $i ];
            $coach_slot          = 'coach' . ( $i + 1 );
            $assistant_slot      = 'assistant' . ( $i + 1 );
            $head_coach_user_id  = (int) ( $this->users[ $coach_slot ] ?? 0 );
            $head_coach_pid      = (int) ( $this->persons[ $coach_slot ] ?? 0 );
            $assistant_pid       = (int) ( $this->persons[ $assistant_slot ] ?? 0 );

            $name = trim( $club_name . ' ' . $age_group );
            $wpdb->insert( "{$wpdb->prefix}tt_teams", [
                'club_id'   => CurrentClub::id(),
                'name'      => $name,
                'age_group' => $age_group,
                'notes'     => 'Demo team',
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
                'id'                  => $team_id,
                'name'                => $name,
                'age_group'           => $age_group,
                // #1315 — `head_coach_id` column retired from tt_teams.
                // Downstream demo generators read the user id from this
                // shape field, populated from the `coach<N>` slot.
                'head_coach_user_id'  => $head_coach_user_id,
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
            // #1314 — write the is_head_coach flag at the demo insert
            // site too, so seeded teams have a correct head-coach
            // attribution without depending on the backfill migration.
            'is_head_coach'      => $role_key === 'head_coach' ? 1 : 0,
        ] );
        $team_person_id = (int) $wpdb->insert_id;

        // #2571 — mirror the assignment into `tt_user_role_scopes`. Without
        // this the generated coaches hold no team scope, so every
        // team-scoped read (Evaluations list, wizard player picker) returns
        // nothing for them on a demo install. Read `insert_id` first — the
        // sync runs its own queries and would clobber it.
        \TT\Infrastructure\People\PeopleRepository::syncTeamScopeRow( $team_id, $person_id );

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
     * Age groups whose name actually carries an age (#3242).
     *
     * `PlayerGenerator::ageFromGroup()` reads the first number out of the
     * group name and falls back to 11 when there isn't one, so a group like
     * **Senior** produces a squad of eleven-year-olds. Harmless while the
     * generator only ever took the youngest few; the moment it anchors on
     * the oldest configured group — which is the whole point of the spread
     * — that group is exactly the one most likely to be a non-numeric
     * catch-all, and the demo's oldest squad would be a senior team full of
     * children with no potential band because nothing thinks they are old
     * enough.
     *
     * A club is free to configure such a group; a youth academy demo just
     * should not build a squad out of it. If filtering leaves nothing, the
     * ladder is handed back untouched rather than failing — a generator is
     * not the place to reject an install's reference data.
     *
     * @param string[] $ladder
     * @return string[]
     */
    public static function agedGroupsOnly( array $ladder ): array {
        $aged = array_values( array_filter(
            $ladder,
            static fn( string $g ): bool => preg_match( '/\d/', $g ) === 1
        ) );

        return $aged === [] ? array_values( $ladder ) : $aged;
    }

    /**
     * Pick `$count` age groups spread evenly across the configured ladder,
     * always including the youngest and the oldest (#3242).
     *
     * This used to be `array_slice( $age_groups, 0, $count )` — the first
     * N, which on any conventionally-ordered ladder means the **youngest**
     * N. The small preset therefore produced a demo academy of U7, U8 and
     * U9 whose oldest player was seven, and that is not an academy several
     * of the product's central features can be demonstrated on:
     *
     *   - a potential band describing a professional ceiling is not a
     *     judgement anybody should be modelling on a seven-year-old, and
     *     #3265 now declines to ask below thirteen;
     *   - PDP cycles, evaluations with development plans and trials are
     *     all the same story.
     *
     * Spreading means the demo shows the journey rather than one end of
     * it: a youngest squad, an oldest squad, and something in between.
     * Anchoring both ends is what guarantees a squad above the potential
     * floor exists at all, which is the property this issue needs.
     *
     * Deterministic — a pure function of the ladder and the count, so the
     * generator's reproducibility contract is untouched and two runs of
     * the same preset still produce the same academy.
     *
     * @param string[] $ladder youngest first
     * @return string[]
     */
    public static function spreadAcrossLadder( array $ladder, int $count ): array {
        $ladder = array_values( $ladder );
        $total  = count( $ladder );

        if ( $count <= 0 || $total === 0 ) return [];
        if ( $count >= $total ) return $ladder;
        if ( $count === 1 ) return [ $ladder[ $total - 1 ] ];

        $out = [];
        for ( $i = 0; $i < $count; $i++ ) {
            // Both ends inclusive: i = 0 lands on the first entry, and
            // i = count - 1 on the last.
            $out[] = $ladder[ (int) round( $i * ( $total - 1 ) / ( $count - 1 ) ) ];
        }

        return $out;
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
