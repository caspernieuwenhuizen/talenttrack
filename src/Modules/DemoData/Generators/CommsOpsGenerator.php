<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * CommsOpsGenerator — thread messages with their read state, plus the
 * operator-facing records that make an install look used: saved filters,
 * report presets, workflow tasks and invitations.
 *
 * **Nothing here goes through a service that sends.** Invitations and workflow
 * tasks are inserted directly rather than through the invite or workflow
 * dispatch paths, because a demo run must not email anyone or fire the
 * engine's side effects. Invitation tokens are random but the rows are marked
 * so they are recognisable as demo data.
 *
 * Thread copy is deliberately bland: these are minors' records and the text
 * ends up in screenshots and the pitch deck.
 */
class CommsOpsGenerator implements DependentGeneratorInterface {

    /** @var array<string, array{coach_parent:string[], coach_coach:string[], announcement:string[]}> */
    private const MESSAGES_BY_LANGUAGE = [
        'en_US' => [
            'coach_parent' => [
                'Thanks for letting us know about Thursday — see you at the weekend.',
                'He trained well this week and was much sharper in the small-sided games.',
                'No problem, we will keep an eye on the workload for the next two weeks.',
            ],
            'coach_coach' => [
                'Shall we swap the last two exercises next session? The tempo dropped.',
                'Agreed. I will set the pitch out a bit wider.',
            ],
            'announcement' => [
                'Training moves to the main pitch from next week.',
                'Reminder: kit orders close on Friday.',
            ],
        ],
        'nl_NL' => [
            'coach_parent' => [
                'Bedankt voor het doorgeven van donderdag — tot het weekend.',
                'Hij heeft deze week goed getraind en was veel scherper in de partijvormen.',
                'Geen probleem, we houden de belasting de komende twee weken in de gaten.',
            ],
            'coach_coach' => [
                'Zullen we de laatste twee oefeningen omdraaien volgende training? Het tempo zakte.',
                'Prima. Ik zet het veld iets breder uit.',
            ],
            'announcement' => [
                'De training verhuist vanaf volgende week naar het hoofdveld.',
                'Herinnering: kledingbestellingen sluiten vrijdag.',
            ],
        ],
    ];

    /** @var array<string, array{filters:array<string,string>, presets:array<string,string>, task:string}> */
    private const OPS_BY_LANGUAGE = [
        'en_US' => [
            'filters' => [
                'players' => 'Needs attention',
                'teams'   => 'My age groups',
            ],
            'presets' => [
                'Monthly parent update' => 'parent_monthly',
                'Squad overview'        => 'standard',
            ],
            'task' => 'Follow up after the last evaluation round',
        ],
        'nl_NL' => [
            'filters' => [
                'players' => 'Aandacht nodig',
                'teams'   => 'Mijn leeftijdsgroepen',
            ],
            'presets' => [
                'Maandelijkse ouderupdate' => 'parent_monthly',
                'Selectieoverzicht'        => 'standard',
            ],
            'task' => 'Opvolging na de laatste evaluatieronde',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'comms_ops';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->teams, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
    }

    /**
     * @param object[] $players
     * @param object[] $teams
     * @param array<string,int> $users
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        array $users,
        int $weeks,
        string $language = ''
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        $total  = 0;
        $total += $this->generateThreads();
        $total += $this->generateSavedFilters();
        $total += $this->generateReportPresets();
        $total += $this->generateWorkflowTasks();
        $total += $this->generateInvitations();
        return $total;
    }

    /**
     * A conversation on a sample of players and one per team, with read state
     * left uneven so unread badges are non-zero for at least one persona.
     */
    private function generateThreads(): int {
        global $wpdb;

        $lang     = self::resolveLanguage( $this->language, self::MESSAGES_BY_LANGUAGE );
        $messages = self::MESSAGES_BY_LANGUAGE[ $lang ];

        $coach  = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $parent = (int) ( $this->users['parent'] ?? $coach );

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        $total = 0;

        // Player threads: coach and guardian talking about one player.
        foreach ( $this->players as $index => $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;
            if ( $index % 3 !== 0 ) continue;   // a third of the squad

            $when = $window_start + (int) ( $this->weeks * 0.6 * WEEK_IN_SECONDS );
            foreach ( $messages['coach_parent'] as $i => $body ) {
                $author = $i === 0 ? $parent : $coach;
                $total += $this->insertMessage( 'player', $player_id, $author, $body, $when + ( $i * HOUR_IN_SECONDS ) );
            }

            // The coach has read it; the parent has not read the last reply,
            // so an unread badge is actually visible somewhere.
            $total += $this->markRead( 'player', $player_id, $coach, $when + ( 4 * HOUR_IN_SECONDS ) );
        }

        // Team threads: staff talking shop, plus an announcement.
        foreach ( $this->teams as $team ) {
            $team_id = (int) $team->id;
            $when    = $window_start + (int) ( $this->weeks * 0.8 * WEEK_IN_SECONDS );

            foreach ( $messages['coach_coach'] as $i => $body ) {
                $total += $this->insertMessage( 'team', $team_id, $coach, $body, $when + ( $i * HOUR_IN_SECONDS ) );
            }
            foreach ( $messages['announcement'] as $i => $body ) {
                $total += $this->insertMessage( 'team', $team_id, $coach, $body, $when + ( ( $i + 3 ) * HOUR_IN_SECONDS ) );
            }
            $total += $this->markRead( 'team', $team_id, $coach, $when + DAY_IN_SECONDS );
        }

        return $total;
    }

    private function insertMessage( string $thread_type, int $thread_id, int $author, string $body, int $when ): int {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_thread_messages", [
            'club_id'        => CurrentClub::id(),
            'uuid'           => self::uuid(),
            'thread_type'    => $thread_type,
            'thread_id'      => $thread_id,
            'author_user_id' => $author,
            'body'           => $body,
            'visibility'     => 'public',
            'is_system'      => 0,
            'created_at'     => gmdate( 'Y-m-d H:i:s', $when ),
        ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 0;

        $this->registry->tag( 'thread_message', $id, [ 'thread_type' => $thread_type, 'thread_id' => $thread_id ] );
        return 1;
    }

    private function markRead( string $thread_type, int $thread_id, int $user_id, int $when ): int {
        global $wpdb;

        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}tt_thread_reads (user_id, thread_type, thread_id, club_id, last_read_at)
             VALUES (%d, %s, %d, %d, %s)",
            $user_id, $thread_type, $thread_id, CurrentClub::id(), gmdate( 'Y-m-d H:i:s', $when )
        ) );
        if ( ! $ok ) return 0;

        // No surrogate id — tagged by thread so the wipe reaches the read
        // state for this thread only (DemoCoverage::TABLE_QUIRKS).
        $this->registry->tag( 'thread_read', $thread_id, [ 'thread_type' => $thread_type ] );
        return 1;
    }

    private function generateSavedFilters(): int {
        global $wpdb;

        $lang  = self::resolveLanguage( $this->language, self::OPS_BY_LANGUAGE );
        $owner = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $total = 0;
        foreach ( self::OPS_BY_LANGUAGE[ $lang ]['filters'] as $view_key => $name ) {
            $wpdb->insert( "{$wpdb->prefix}tt_saved_filters", [
                'club_id'      => CurrentClub::id(),
                'uuid'         => self::uuid(),
                'user_id'      => $owner,
                'view_key'     => (string) $view_key,
                'name'         => (string) $name,
                'filters_json' => (string) wp_json_encode( [ 'status' => 'active' ] ),
                'is_default'   => 0,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'saved_filter', $id );
                $total++;
            }
        }
        return $total;
    }

    private function generateReportPresets(): int {
        global $wpdb;

        $lang  = self::resolveLanguage( $this->language, self::OPS_BY_LANGUAGE );
        $owner = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $total = 0;
        foreach ( self::OPS_BY_LANGUAGE[ $lang ]['presets'] as $name => $audience ) {
            $wpdb->insert( "{$wpdb->prefix}tt_report_presets", [
                'club_id'    => CurrentClub::id(),
                'name'       => (string) $name,
                'config'     => (string) wp_json_encode( [ 'audience' => $audience, 'scope' => 'season' ] ),
                'created_by' => $owner,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'report_preset', $id );
                $total++;
            }
        }
        return $total;
    }

    /**
     * Workflow tasks are inserted directly rather than raised through the
     * engine: a demo run must not fire triggers or their side effects.
     */
    private function generateWorkflowTasks(): int {
        global $wpdb;

        $lang     = self::resolveLanguage( $this->language, self::OPS_BY_LANGUAGE );
        $assignee = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $total = 0;
        foreach ( $this->players as $index => $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 || $index % 4 !== 0 ) continue;

            $completed = mt_rand( 1, 100 ) <= 50;
            $created   = strtotime( '-' . mt_rand( 5, 40 ) . ' days' ) ?: time();

            $wpdb->insert( "{$wpdb->prefix}tt_workflow_tasks", [
                'club_id'          => CurrentClub::id(),
                'template_key'     => 'post_evaluation_followup',
                'assignee_user_id' => $assignee,
                'status'           => $completed ? 'completed' : 'open',
                'created_at'       => gmdate( 'Y-m-d H:i:s', $created ),
                'due_at'           => gmdate( 'Y-m-d H:i:s', $created + ( 14 * DAY_IN_SECONDS ) ),
                'completed_at'     => $completed ? gmdate( 'Y-m-d H:i:s', $created + ( 5 * DAY_IN_SECONDS ) ) : null,
                'player_id'        => $player_id,
                'team_id'          => (int) ( $p->team_id ?? 0 ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'workflow_task', $id, [ 'player_id' => $player_id ] );
                $total++;
            }
        }
        return $total;
    }

    /**
     * Invitation rows in all four states so the admin view shows each one.
     * Inserted directly — the invite service sends email, which a generate
     * run must never do.
     */
    private function generateInvitations(): int {
        global $wpdb;

        $creator = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $states  = [
            [ 'pending',  null, null ],
            [ 'accepted', '-8 days', null ],
            [ 'expired',  null, null ],
            [ 'revoked',  null, '-3 days' ],
        ];

        $total = 0;
        foreach ( $states as $index => [ $status, $accepted, $revoked ] ) {
            $player = $this->players[ $index ] ?? null;
            if ( ! $player ) break;

            $expires = $status === 'expired'
                ? gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ?: time() )
                : gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) ?: time() );

            $wpdb->insert( "{$wpdb->prefix}tt_invitations", [
                'club_id'          => CurrentClub::id(),
                // Random but clearly demo-shaped, and the row is demo-tagged.
                'token'            => 'demo-' . wp_generate_password( 40, false, false ),
                'kind'             => 'parent',
                'target_player_id' => (int) $player->id,
                'prefill_first_name' => (string) ( $player->first_name ?? '' ),
                'prefill_last_name'  => (string) ( $player->last_name ?? '' ),
                'prefill_email'      => null,
                'locale'           => $this->language,
                'created_by'       => $creator,
                'expires_at'       => $expires,
                'accepted_at'      => $accepted !== null ? gmdate( 'Y-m-d H:i:s', strtotime( $accepted ) ?: time() ) : null,
                'revoked_at'       => $revoked !== null ? gmdate( 'Y-m-d H:i:s', strtotime( $revoked ) ?: time() ) : null,
                'revoked_by'       => $revoked !== null ? $creator : null,
                'status'           => $status,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'invitation', $id, [ 'status' => $status ] );
                $total++;
            }
        }
        return $total;
    }

    private static function uuid(): string {
        return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }

    /** @param array<string,mixed> $pool */
    private static function resolveLanguage( string $locale, array $pool ): string {
        if ( isset( $pool[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( $pool ) as $key ) {
            if ( strpos( (string) $key, $prefix ) === 0 ) return (string) $key;
        }
        return 'en_US';
    }
}
