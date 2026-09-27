<?php
namespace TT\Modules\Trials\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\People\StaffDirectory;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Repositories\TrialCaseChecklistRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\TrialGroupTeam;

/**
 * TrialCaseChecklistService (#4008) — what has to happen after a player is
 * admitted, and whether it has.
 *
 * An ADMIT decision used to be followed by three steps nobody recorded: the
 * letter going out, the player being put on a team, and somebody getting a
 * guardian's e-mail address. Each was assumed to be someone else's, and
 * the case showed none of them. This keeps the three on the case where the
 * whole panel can see them.
 *
 * ## Deliberately not a workflow chain
 *
 * `POST trial-cases/{id}/decision` refuses the rolling-membership decisions
 * because a decision written over HTTP must not drive the workflow chain.
 * The checklist keeps that design: the rows are created off the decision
 * hook, like the player-status transition beside it, and nothing here
 * schedules, assigns a task or starts anything.
 *
 * ## An item ticks itself
 *
 * Each item names a condition the data can answer, and the case checks it
 * whenever the checklist is read — no cron, no listener. Once met, the tick
 * is stored with `done_auto` set, so the panel sees when it happened. Every
 * item can also be ticked and unticked by hand; an item whose condition is
 * met ticks itself again on the next read, because the thing it stands for
 * has happened.
 *
 *   - `letter_sent`      — the case has a live admittance letter.
 *   - `player_on_team`   — the player sits on an academy team rather than
 *                          on none or on the trial group. A trial case has
 *                          no target team of its own, so any academy team
 *                          counts.
 *   - `guardian_contact` — the player has a guardian e-mail address on
 *                          record, or a linked parent account with one.
 */
final class TrialCaseChecklistService {

    public const LETTER_SENT      = 'letter_sent';
    public const PLAYER_ON_TEAM   = 'player_on_team';
    public const GUARDIAN_CONTACT = 'guardian_contact';

    /**
     * The items each decision gets. Only ADMIT has a list: a denial has no
     * team to join, and a list showing steps that cannot apply would teach
     * the panel to ignore it.
     *
     * @var array<string,list<string>>
     */
    private const ITEMS_BY_DECISION = [
        TrialCaseDecision::ADMIT => [ self::LETTER_SENT, self::PLAYER_ON_TEAM, self::GUARDIAN_CONTACT ],
    ];

    private TrialCaseChecklistRepository $repo;

    public function __construct( ?TrialCaseChecklistRepository $repo = null ) {
        $this->repo = $repo ?? new TrialCaseChecklistRepository();
    }

    public static function init(): void {
        add_action( 'tt_trial_decision_recorded', [ __CLASS__, 'onDecisionRecorded' ], 20, 3 );
    }

    /**
     * Every path that records a decision fires this hook, so the case
     * screen, the REST route and the workflow forms all create the list.
     */
    public static function onDecisionRecorded( int $case_id, int $player_id = 0, string $decision = '' ): void {
        ( new self() )->seedFor( $case_id, $decision );
    }

    /**
     * Create the rows a decision warrants. Idempotent: an item already on
     * the case is left as it is.
     *
     * @return int rows written
     */
    public function seedFor( int $case_id, string $decision ): int {
        $written = 0;
        foreach ( self::ITEMS_BY_DECISION[ $decision ] ?? [] as $key ) {
            if ( $this->repo->ensureItem( $case_id, $key ) ) $written++;
        }
        return $written;
    }

    /** @return list<string> */
    public static function itemKeys(): array {
        return self::ITEMS_BY_DECISION[ TrialCaseDecision::ADMIT ];
    }

    public static function labelFor( string $key ): string {
        switch ( $key ) {
            case self::LETTER_SENT:      return __( 'Admittance letter sent', 'talenttrack' );
            case self::PLAYER_ON_TEAM:   return __( 'Player added to a team', 'talenttrack' );
            case self::GUARDIAN_CONTACT: return __( 'Guardian contact collected', 'talenttrack' );
        }
        return $key;
    }

    /**
     * The checklist as a reader sees it, after the automatic ticks have
     * been brought up to date.
     *
     * @return list<array{
     *   item_key:string, label:string, done:bool, done_at:?string,
     *   done_by:?int, done_by_name:string, done_automatically:bool,
     *   assignee_user_id:?int, assignee_name:string
     * }>
     */
    public function itemsFor( int $case_id ): array {
        $rows = $this->repo->listForCase( $case_id );
        if ( $rows === [] ) return [];

        $case      = ( new TrialCasesRepository() )->find( $case_id );
        $player_id = $case ? (int) ( ( (array) $case )['player_id'] ?? 0 ) : 0;

        $out = [];
        foreach ( $rows as $row ) {
            $data = (array) $row;
            $key  = (string) ( $data['item_key'] ?? '' );

            if ( empty( $data['done_at'] ) && $this->conditionMet( $key, $case_id, $player_id ) ) {
                $now = current_time( 'mysql', true );
                $this->repo->update( $case_id, $key, [ 'done_at' => $now, 'done_by' => null, 'done_auto' => 1 ] );
                $data['done_at']   = $now;
                $data['done_by']   = null;
                $data['done_auto'] = 1;
            }

            $out[] = self::shape( $data );
        }
        return $out;
    }

    /**
     * Tick or untick an item by hand.
     *
     * @return bool false when the case carries no such item
     */
    public function setDone( int $case_id, string $key, bool $done, int $actor_id ): bool {
        if ( ! $this->hasItem( $case_id, $key ) ) return false;
        return $this->repo->update( $case_id, $key, $done
            ? [ 'done_at' => current_time( 'mysql', true ), 'done_by' => $actor_id > 0 ? $actor_id : null, 'done_auto' => 0 ]
            : [ 'done_at' => null, 'done_by' => null, 'done_auto' => 0 ]
        );
    }

    /**
     * Name who does an item, or clear it with null.
     *
     * @return string `ok`, `no_item`, or `not_staff` when the account is not
     *                a staff member of this club
     */
    public function setAssignee( int $case_id, string $key, ?int $user_id ): string {
        if ( ! $this->hasItem( $case_id, $key ) ) return 'no_item';
        if ( $user_id !== null && ! self::isStaffAccount( $user_id ) ) return 'not_staff';
        $this->repo->update( $case_id, $key, [ 'assignee_user_id' => $user_id ] );
        return 'ok';
    }

    public function hasItem( int $case_id, string $key ): bool {
        foreach ( $this->repo->listForCase( $case_id ) as $row ) {
            if ( (string) ( ( (array) $row )['item_key'] ?? '' ) === $key ) return true;
        }
        return false;
    }

    /**
     * Is this account somebody the staff directory offers? The same list
     * `GET /staff` searches, so the picker and the write agree.
     */
    public static function isStaffAccount( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        foreach ( ( new StaffDirectory() )->all() as $staff ) {
            if ( (int) ( $staff['user_id'] ?? 0 ) === $user_id ) return true;
        }
        return false;
    }

    private function conditionMet( string $key, int $case_id, int $player_id ): bool {
        switch ( $key ) {
            case self::LETTER_SENT:      return self::hasLiveAdmittanceLetter( $case_id );
            case self::PLAYER_ON_TEAM:   return self::isOnAcademyTeam( $player_id );
            case self::GUARDIAN_CONTACT: return self::hasGuardianEmail( $player_id );
        }
        return false;
    }

    private static function hasLiveAdmittanceLetter( int $case_id ): bool {
        if ( $case_id <= 0 ) return false;
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_player_reports
              WHERE JSON_EXTRACT(config_json, '$.case_id') = %d
                AND audience = %s
                AND revoked_at IS NULL
                AND club_id = %d
              LIMIT 1",
            $case_id,
            AudienceType::TRIAL_ADMITTANCE,
            CurrentClub::id()
        ) );
        return (int) $found > 0;
    }

    private static function isOnAcademyTeam( int $player_id ): bool {
        if ( $player_id <= 0 ) return false;
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.id, t.team_kind
               FROM {$p}tt_players pl
               JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
              WHERE pl.id = %d AND pl.club_id = %d
              LIMIT 1",
            $player_id,
            CurrentClub::id()
        ) );
        if ( ! $row ) return false;
        $team = (array) $row;
        return (int) ( $team['id'] ?? 0 ) > 0
            && (string) ( $team['team_kind'] ?? '' ) !== TrialGroupTeam::TEAM_KIND;
    }

    private static function hasGuardianEmail( int $player_id ): bool {
        if ( $player_id <= 0 ) return false;
        global $wpdb;
        $email = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT guardian_email FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id,
            CurrentClub::id()
        ) );
        if ( trim( $email ) !== '' ) return true;

        foreach ( ( new \TT\Modules\Invitations\PlayerParentsRepository() )->parentsForPlayer( $player_id ) as $parent_id ) {
            $user = get_userdata( $parent_id );
            if ( $user instanceof \WP_User && (string) $user->user_email !== '' ) return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{
     *   item_key:string, label:string, done:bool, done_at:?string,
     *   done_by:?int, done_by_name:string, done_automatically:bool,
     *   assignee_user_id:?int, assignee_name:string
     * }
     */
    private static function shape( array $data ): array {
        $key      = (string) ( $data['item_key'] ?? '' );
        $done_at  = empty( $data['done_at'] ) ? null : (string) $data['done_at'];
        $done_by  = empty( $data['done_by'] ) ? null : (int) $data['done_by'];
        $assignee = empty( $data['assignee_user_id'] ) ? null : (int) $data['assignee_user_id'];

        return [
            'item_key'           => $key,
            'label'              => self::labelFor( $key ),
            'done'               => $done_at !== null,
            'done_at'            => $done_at,
            'done_by'            => $done_by,
            'done_by_name'       => self::nameOf( $done_by ),
            'done_automatically' => $done_at !== null && ! empty( $data['done_auto'] ),
            'assignee_user_id'   => $assignee,
            'assignee_name'      => self::nameOf( $assignee ),
        ];
    }

    private static function nameOf( ?int $user_id ): string {
        if ( $user_id === null || $user_id <= 0 ) return '';
        $user = get_userdata( $user_id );
        return $user instanceof \WP_User ? (string) $user->display_name : '';
    }
}
