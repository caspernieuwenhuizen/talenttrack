<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;

/**
 * MassAnnouncementSender (#3693) — the send behind a team announcement.
 *
 * `mass_announcement` has been a registered message type with shipped
 * copy, a catalog entry and an opt-out row on *My settings* since the
 * module shipped, and nothing has ever constructed one. So a team
 * manager with something to tell twelve families said it on WhatsApp,
 * outside the academy's record, its quiet hours and its opt-outs.
 *
 * ## Two tiers of sender
 *
 * A team announcement is not a safeguarding broadcast, and the
 * permission shape says so. Two capabilities, not one:
 *
 *   - `tt_send_team_announcement` — the teams this person actually
 *     holds. A head coach or a team manager telling their own squad to
 *     bring a white shirt is ordinary team business, and the academy
 *     that has to ask an administrator to send it will keep using
 *     WhatsApp instead.
 *   - `tt_send_academy_announcement` — any team, an age group, or the
 *     whole academy. Reaching families whose child this person does not
 *     coach is an academy-level act, so it is an academy-level grant.
 *
 * Holding the academy cap implies the team one: somebody who may
 * announce to everybody may announce to one squad.
 *
 * ## The audience is decided here, not in the screen
 *
 * `canSend()` is the authority, and the REST route calls it before it
 * sends. Hiding the academy option from a team manager's dropdown is a
 * courtesy; refusing the request when they post another team's id is
 * the rule. Both surfaces ask this class, so they cannot disagree.
 *
 * ## What it does not do
 *
 * It does not bypass anything. An announcement is opt-outable, and
 * quiet hours hold one sent at 22:40 until the morning — there is no
 * override, because a message that could not wait until 07:00 is not an
 * announcement and has its own template. That is the whole difference
 * between this class and `SafeguardingBroadcastSender`, which is why
 * they are two classes rather than one with a flag.
 *
 * Recipients come from `forPlayerWithParents()` — a parent is not
 * dropped from "no training on Saturday" on the grounds that their
 * child is 15, because the person driving to the pitch is the one who
 * needs to know. **One message per person**: a parent with two children
 * in the age group receives one copy.
 */
final class MassAnnouncementSender {

    /** Announce to a team this person holds. */
    public const CAP_TEAM = 'tt_send_team_announcement';

    /** Announce to any team, an age group, or the whole academy. */
    public const CAP_ACADEMY = 'tt_send_academy_announcement';

    public const SCOPE_TEAM      = 'team';
    public const SCOPE_AGE_GROUP = 'age_group';
    public const SCOPE_ACADEMY   = 'academy';

    public const TEMPLATE_KEY = 'mass_announcement';

    /** Longest subject the compose step accepts, in characters. */
    public const MAX_SUBJECT = 120;

    /* ---- who may send what ---------------------------------------------- */

    /**
     * May this user send an announcement at all, on either tier?
     */
    public static function canAnnounce( int $user_id ): bool {
        return self::holdsTeamTier( $user_id ) || self::holdsAcademyTier( $user_id );
    }

    public static function holdsTeamTier( int $user_id ): bool {
        return $user_id > 0 && ( user_can( $user_id, self::CAP_TEAM ) || self::holdsAcademyTier( $user_id ) );
    }

    public static function holdsAcademyTier( int $user_id ): bool {
        return $user_id > 0 && user_can( $user_id, self::CAP_ACADEMY );
    }

    /**
     * The authority the route and the wizard both ask.
     *
     * Deliberately answers on the audience rather than on the actor: the
     * question a send has to settle is not "is this person senior" but
     * "may this person reach these families".
     */
    public function canSend( int $user_id, string $scope, int $team_id = 0, string $age_group = '' ): bool {
        if ( $user_id <= 0 ) return false;

        if ( self::holdsAcademyTier( $user_id ) ) {
            switch ( $scope ) {
                case self::SCOPE_ACADEMY:
                    return true;
                case self::SCOPE_AGE_GROUP:
                    return $age_group !== '' && in_array( $age_group, $this->ageGroups(), true );
                case self::SCOPE_TEAM:
                    return $this->teamExists( $team_id );
            }
            return false;
        }

        if ( ! user_can( $user_id, self::CAP_TEAM ) ) return false;

        // The team tier reaches teams and nothing else. An age group is
        // other people's squads by definition, and the academy is every
        // family in it.
        if ( $scope !== self::SCOPE_TEAM || $team_id <= 0 ) return false;

        foreach ( $this->teamsFor( $user_id ) as $team ) {
            if ( $team['id'] === $team_id ) return true;
        }
        return false;
    }

    /**
     * The teams this user may announce to — every active team for an
     * academy sender, the ones they are assigned to for a team sender.
     *
     * @return list<array{id:int,name:string,age_group:string}>
     */
    public function teamsFor( int $user_id ): array {
        if ( self::holdsAcademyTier( $user_id ) ) return $this->allTeams();

        $out = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id <= 0 ) continue;
            $out[] = [
                'id'        => $id,
                'name'      => (string) ( $team->name ?? '' ),
                'age_group' => (string) ( $team->age_group ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Age groups in use across the academy's active teams.
     *
     * @return list<string>
     */
    public function ageGroups(): array {
        $out = [];
        foreach ( $this->allTeams() as $team ) {
            $group = $team['age_group'];
            if ( $group !== '' && ! in_array( $group, $out, true ) ) $out[] = $group;
        }
        sort( $out );
        return $out;
    }

    /* ---- the audience ---------------------------------------------------- */

    /**
     * Everyone this audience would reach, deduplicated.
     *
     * @return Recipient[]
     */
    public function audience( string $scope, int $team_id = 0, string $age_group = '' ): array {
        $players = $this->playerIds( $scope, $team_id, $age_group );
        if ( $players === [] ) return [];

        $resolver = new RecipientResolver();
        $out      = [];

        foreach ( $players as $player_id ) {
            foreach ( $resolver->forPlayerWithParents( $player_id ) as $recipient ) {
                $key = self::identityKey( $recipient );
                if ( $key === '' || isset( $out[ $key ] ) ) continue;
                $out[ $key ] = $recipient;
            }
        }

        return array_values( $out );
    }

    /**
     * How many people it reaches. The number the confirm step states, so
     * it is counted rather than estimated.
     */
    public function recipientCount( string $scope, int $team_id = 0, string $age_group = '' ): int {
        return count( $this->audience( $scope, $team_id, $age_group ) );
    }

    /**
     * Dry run, for the confirm step's warnings — who has opted out, and
     * whether quiet hours will hold this until morning.
     *
     * @return CommsResult[]
     */
    public function preflight( string $scope, int $team_id = 0, string $age_group = '' ): array {
        $recipients = $this->audience( $scope, $team_id, $age_group );
        if ( $recipients === [] ) return [];

        return ( new CommsService() )->preflight( new CommsRequest(
            self::TEMPLATE_KEY,
            MessageType::MASS_ANNOUNCEMENT,
            CurrentClub::id(),
            get_current_user_id(),
            $recipients
        ) );
    }

    /**
     * Send it.
     *
     * No `urgent` flag, on purpose. An announcement takes quiet hours
     * like any other non-operational message: one written at 22:40 is
     * held and delivered in the morning.
     *
     * @return CommsResult[] one per recipient; empty when the audience
     *                       resolved to nobody, which the caller reports
     *                       rather than treating as a send.
     */
    public function send( string $subject, string $body, string $scope, int $team_id = 0, string $age_group = '' ): array {
        $recipients = $this->audience( $scope, $team_id, $age_group );
        if ( $recipients === [] ) return [];

        return CommsDispatcher::dispatchSync(
            self::TEMPLATE_KEY,
            [
                'announcement_subject' => $subject,
                'announcement_body'    => $body,
                'sender_name'          => self::senderName(),
            ],
            $recipients,
            [ 'message_type' => MessageType::MASS_ANNOUNCEMENT ]
        );
    }

    /* ---- input shaping ---------------------------------------------------- */

    /** Normalise a caller-supplied scope; anything unrecognised is one team. */
    public static function sanitizeScope( string $raw ): string {
        return in_array( $raw, [ self::SCOPE_ACADEMY, self::SCOPE_AGE_GROUP ], true )
            ? $raw
            : self::SCOPE_TEAM;
    }

    /**
     * The audience as one value, the way the wizard's single control
     * carries it: `academy`, `age:O13`, or `team:12`. One answer, and no
     * way to submit a team scope with no team attached.
     *
     * @return array{scope:string,team_id:int,age_group:string}
     */
    public static function parseAudience( string $raw ): array {
        $none = [ 'scope' => '', 'team_id' => 0, 'age_group' => '' ];

        if ( $raw === self::SCOPE_ACADEMY ) {
            return [ 'scope' => self::SCOPE_ACADEMY, 'team_id' => 0, 'age_group' => '' ];
        }
        if ( preg_match( '/^team:(\d+)$/', $raw, $m ) === 1 ) {
            return [ 'scope' => self::SCOPE_TEAM, 'team_id' => (int) $m[1], 'age_group' => '' ];
        }
        if ( strpos( $raw, 'age:' ) === 0 ) {
            $group = trim( substr( $raw, 4 ) );
            if ( $group === '' ) return $none;
            return [ 'scope' => self::SCOPE_AGE_GROUP, 'team_id' => 0, 'age_group' => $group ];
        }
        return $none;
    }

    /** The inverse of `parseAudience()`. */
    public static function audienceValue( string $scope, int $team_id = 0, string $age_group = '' ): string {
        if ( $scope === self::SCOPE_TEAM ) return 'team:' . $team_id;
        if ( $scope === self::SCOPE_AGE_GROUP ) return 'age:' . $age_group;
        return self::SCOPE_ACADEMY;
    }

    /**
     * The audience in words, for the confirm step and the wizard's
     * summary. Never an internal scope key — the sender is agreeing to
     * reach people, not to a value in a select.
     */
    public function audienceLabel( string $scope, int $team_id = 0, string $age_group = '' ): string {
        if ( $scope === self::SCOPE_ACADEMY ) {
            return __( 'Every family in the academy.', 'talenttrack' );
        }
        if ( $scope === self::SCOPE_AGE_GROUP ) {
            return sprintf(
                /* translators: %s: an age group, for example O13 */
                __( 'Every family in %s.', 'talenttrack' ),
                $age_group
            );
        }
        foreach ( $this->allTeams() as $team ) {
            if ( $team['id'] === $team_id ) {
                return sprintf(
                    /* translators: %s: the team's name */
                    __( 'The families of %s.', 'talenttrack' ),
                    $team['name']
                );
            }
        }
        return __( 'One team.', 'talenttrack' );
    }

    /* ---- queries ---------------------------------------------------------- */

    /**
     * The players whose families the announcement reaches.
     *
     * Archived and trashed players are excluded through the shared
     * `filterClause()`: a family whose child left last season is not on
     * the distribution list for this season's kit.
     *
     * @return list<int>
     */
    private function playerIds( string $scope, int $team_id, string $age_group ): array {
        global $wpdb;
        $players = $wpdb->prefix . 'tt_players';
        $teams   = $wpdb->prefix . 'tt_teams';
        $active  = ArchiveRepository::filterClause( 'active', 'p' );
        $club    = QueryHelpers::clubScopeWhere( 'p' );

        if ( $scope === self::SCOPE_TEAM ) {
            if ( $team_id <= 0 ) return [];
            $rows = $wpdb->get_col( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT p.id FROM {$players} p
                  WHERE {$club} AND {$active} AND p.team_id = %d
                  ORDER BY p.id ASC",
                $team_id
            ) );
        } elseif ( $scope === self::SCOPE_AGE_GROUP ) {
            if ( $age_group === '' ) return [];
            $rows = $wpdb->get_col( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT p.id FROM {$players} p
                  INNER JOIN {$teams} t ON t.id = p.team_id AND t.club_id = p.club_id
                  WHERE {$club} AND {$active} AND t.age_group = %s
                  ORDER BY p.id ASC",
                $age_group
            ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_col(
                "SELECT p.id FROM {$players} p WHERE {$club} AND {$active} ORDER BY p.id ASC"
            );
        }

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $out[] = $id;
        }
        return $out;
    }

    /** @return list<array{id:int,name:string,age_group:string}> */
    private function allTeams(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, name, age_group FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND " . ArchiveRepository::filterClause( 'active' ) . "
              ORDER BY name ASC",
            CurrentClub::id()
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id <= 0 ) continue;
            $out[] = [
                'id'        => $id,
                'name'      => (string) ( $row['name'] ?? '' ),
                'age_group' => (string) ( $row['age_group'] ?? '' ),
            ];
        }
        return $out;
    }

    private function teamExists( int $team_id ): bool {
        if ( $team_id <= 0 ) return false;
        foreach ( $this->allTeams() as $team ) {
            if ( $team['id'] === $team_id ) return true;
        }
        return false;
    }

    /**
     * What makes two resolved recipients the same person: the account
     * where there is one, otherwise the address the message would go to.
     * Somebody with neither has no identity to deduplicate on and no way
     * to be reached, so they are dropped rather than becoming an
     * unreachable row per child.
     */
    private static function identityKey( Recipient $recipient ): string {
        if ( $recipient->userId > 0 ) return 'u:' . $recipient->userId;
        $email = strtolower( trim( $recipient->emailAddress ) );
        if ( $email !== '' ) return 'e:' . $email;
        $phone = trim( $recipient->phoneE164 );
        if ( $phone !== '' ) return 'p:' . $phone;
        return '';
    }

    private static function senderName(): string {
        $name = trim( (string) wp_get_current_user()->display_name );
        return $name !== '' ? $name : __( 'The academy', 'talenttrack' );
    }
}
