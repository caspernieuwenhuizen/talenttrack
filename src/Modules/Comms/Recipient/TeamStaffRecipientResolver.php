<?php
namespace TT\Modules\Comms\Recipient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\ContactResolver;
use TT\Infrastructure\Recipients\HeadOfDevelopmentLookup;
use TT\Infrastructure\Recipients\TeamStaffLookup;
use TT\Modules\Comms\Domain\Recipient;

/**
 * TeamStaffRecipientResolver (#3811) — "who on this team should hear about
 * this", as a `Recipient[]`.
 *
 * The staff counterpart of {@see RecipientResolver}, whose docblock has said
 * since the module shipped that staff callers build `Recipient::coach()`
 * themselves. There were four such call sites and between them they could
 * name club administrators, the subject of a record, and head coaches —
 * which is why a team manager opted in to nineteen message types received
 * none of them.
 *
 * Division of labour: `Infrastructure\Recipients\*` answers *who* (the SQL,
 * shared with the alerts engine and the workflow assignee resolver), this
 * class turns those user ids into addressed recipients. Comms keeps
 * ownership of `Recipient` construction; the join stays in one place.
 *
 * Every recipient is `Recipient::coach()` with no subject player. These
 * messages are about a team's calendar or its paperwork, and attaching a
 * child to them would put a named minor in an audit row that is not about
 * them.
 */
final class TeamStaffRecipientResolver {

    /**
     * The staff who run a team — head coach, assistant, team manager.
     *
     * @return Recipient[]
     */
    public function forTeam( int $team_id ): array {
        if ( $team_id <= 0 ) return [];

        return $this->build( TeamStaffLookup::forTeam( $team_id, TeamStaffLookup::RUNS_THE_TEAM ) );
    }

    /**
     * The staff who run a team, plus the academy's heads of development.
     *
     * The combination the absence flag has always described and never sent
     * to: the people who see the player every week, and the person whose job
     * is the pattern across teams.
     *
     * @return Recipient[]
     */
    public function forTeamWithHeadOfDevelopment( int $team_id, ?int $club_id = null ): array {
        $user_ids = $team_id > 0
            ? TeamStaffLookup::forTeam( $team_id, TeamStaffLookup::RUNS_THE_TEAM )
            : [];

        foreach ( HeadOfDevelopmentLookup::forClub( $club_id ) as $hod_user_id ) {
            $user_ids[] = $hod_user_id;
        }

        return $this->build( $user_ids );
    }

    /**
     * @param list<int> $user_ids
     * @return Recipient[]
     */
    private function build( array $user_ids ): array {
        $out  = [];
        $seen = [];

        foreach ( $user_ids as $user_id ) {
            $user_id = (int) $user_id;
            if ( $user_id <= 0 || isset( $seen[ $user_id ] ) ) continue;
            // A head coach who is also the head of development is one
            // person and gets one message.
            $seen[ $user_id ] = true;

            // A deleted WP account can outlive its `tt_people` row's
            // `wp_user_id`; addressing it would only produce a bounce.
            if ( ! get_userdata( $user_id ) ) continue;

            $out[] = Recipient::coach(
                $user_id,
                null,
                (string) ( ContactResolver::emailForUser( $user_id ) ?? '' ),
                (string) ( ContactResolver::phoneForUser( $user_id ) ?? '' ),
                (string) get_user_meta( $user_id, 'locale', true )
            );
        }

        return $out;
    }
}
