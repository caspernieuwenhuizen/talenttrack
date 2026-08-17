<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\SeedLoader;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialExtensionsRepository;

/**
 * PipelineGenerator — the front of the player journey.
 *
 * Scouting visits, the prospects found on them, and trial cases with their
 * staff panel, assessments and extensions.
 *
 * Two populations of trial case, both of which matter:
 *
 *  - **historical** cases on existing players, closed with an admit decision.
 *    Without these a demo academy's players appear fully signed, from nowhere,
 *    and the journey the architecture is built around has no beginning.
 *  - **open** cases on current trialists, so the surface a scout works on
 *    every week has something on it.
 *
 * Trial start dates are placed before the player's own roster join date, so
 * the timeline reads in order.
 */
class PipelineGenerator implements DependentGeneratorInterface {

    /** @var array<string, array{visit_note:string, scouting_note:string, panel:string, input:string, extension:string, decision:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'visit_note'    => 'District tournament — several age groups playing across the afternoon.',
            'scouting_note' => 'Comfortable on the ball, good first touch. Worth a closer look.',
            'panel'         => 'Trial panel',
            'input'         => 'Settled in quickly. Handles the tempo; needs work defending in transition.',
            'extension'     => 'Missed two sessions through illness — extending to see a fair sample.',
            'decision'      => 'Consistent across the trial period. Offered a place in the age group.',
        ],
        'nl_NL' => [
            'visit_note'    => 'Districtstoernooi — meerdere leeftijdsgroepen spelen door de middag heen.',
            'scouting_note' => 'Comfortabel aan de bal, goede aanname. De moeite waard om verder te bekijken.',
            'panel'         => 'Beoordelingspanel',
            'input'         => 'Snel zijn draai gevonden. Kan het tempo aan; moet groeien in het verdedigen bij omschakeling.',
            'extension'     => 'Twee trainingen gemist door ziekte — verlengd voor een eerlijk beeld.',
            'decision'      => 'Constant gedurende de proefperiode. Plek aangeboden in de leeftijdsgroep.',
        ],
    ];

    /** Clubs a prospect might be playing for when spotted. */
    private const CURRENT_CLUBS = [
        'SV Nieuwland', 'VV De Meern', 'RKSV Wilhelmina', 'FC Bergwijk',
        'SC Oostvogels', 'VV Rijnstreek', 'SV Kastanjelaan',
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
        return 'pipeline';
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
        $copy   = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $total  = 0;
        $visits = $this->generateVisits( $copy );
        $total += count( $visits );
        $total += $this->generateProspects( $copy, $visits );
        $total += $this->generateTrialCases( $copy );
        return $total;
    }

    /**
     * Scouting visits across the window: mostly completed, the next couple
     * planned, one cancelled.
     *
     * @param array<string,string> $copy
     * @return int[] visit ids
     */
    private function generateVisits( array $copy ): array {
        $repo  = new ScoutingVisitsRepository();
        $scout = (int) ( $this->users['scout'] ?? $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        $count = max( 2, min( 10, (int) round( $this->weeks / 3 ) ) );
        $age_groups = [];
        foreach ( $this->teams as $t ) {
            if ( isset( $t->age_group ) && (string) $t->age_group !== '' ) {
                $age_groups[] = (string) $t->age_group;
            }
        }

        $ids = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $when = $window_start + (int) ( ( $i / max( 1, $count - 1 ) ) * $this->weeks * 1.1 * WEEK_IN_SECONDS );

            $status = 'completed';
            if ( $when > time() ) {
                $status = 'planned';
            } elseif ( $i === 1 && $count > 3 ) {
                $status = 'cancelled';
            }

            $id = $repo->create( [
                'scout_user_id'     => $scout,
                'visit_date'        => gmdate( 'Y-m-d', $when ),
                'visit_time'        => '10:00:00',
                'location'          => self::CURRENT_CLUBS[ $i % count( self::CURRENT_CLUBS ) ],
                'event_description' => $copy['visit_note'],
                'age_groups_csv'    => implode( ',', array_slice( $age_groups, 0, 3 ) ),
                'notes'             => null,
                'status'            => $status,
            ] );
            if ( $id > 0 ) {
                $this->registry->tag( 'scouting_visit', $id, [ 'status' => $status ] );
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Prospects found on the completed visits. Names come from the same Dutch
     * seed pools the roster uses, so the pipeline reads like the same academy.
     *
     * @param array<string,string> $copy
     * @param int[] $visits
     */
    private function generateProspects( array $copy, array $visits ): int {
        $repo   = new ProspectsRepository();
        $scout  = (int) ( $this->users['scout'] ?? $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $first  = SeedLoader::firstNames();
        $last   = SeedLoader::lastNames();
        if ( ! $first || ! $last ) return 0;

        $age_groups = $this->lookupIds( 'age_group' );
        $positions  = $this->lookupIds( 'position' );

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        // Scale with the preset: a handful on tiny, a proper pool on large.
        $count = max( 4, min( 30, (int) round( $this->weeks * 0.8 ) ) );

        $total = 0;
        for ( $i = 0; $i < $count; $i++ ) {
            $age = mt_rand( 8, 17 );
            $dob = gmdate( 'Y-m-d', strtotime( '-' . $age . ' years -' . mt_rand( 0, 300 ) . ' days' ) ?: time() );

            $discovered = $window_start + (int) ( ( $i / max( 1, $count - 1 ) ) * $this->weeks * WEEK_IN_SECONDS );
            $visit_id   = $visits ? (int) $visits[ mt_rand( 0, count( $visits ) - 1 ) ] : null;

            $id = $repo->create( [
                'first_name'                   => (string) $first[ mt_rand( 0, count( $first ) - 1 ) ],
                'last_name'                    => (string) $last[ mt_rand( 0, count( $last ) - 1 ) ],
                'date_of_birth'                => $dob,
                'age_group_lookup_id'          => $age_groups ? (int) $age_groups[ array_rand( $age_groups ) ] : null,
                'discovered_at'                => gmdate( 'Y-m-d', $discovered ),
                'discovered_by_user_id'        => $scout,
                'scouting_visit_id'            => $visit_id,
                'discovered_at_event'          => $copy['visit_note'],
                'current_club'                 => self::CURRENT_CLUBS[ $i % count( self::CURRENT_CLUBS ) ],
                'preferred_position_lookup_id' => $positions ? (int) $positions[ array_rand( $positions ) ] : null,
                'scouting_notes'               => $copy['scouting_note'],
            ] );
            if ( $id > 0 ) {
                $this->registry->tag( 'prospect', $id, [ 'visit_id' => $visit_id ] );
                $total++;
            }
        }
        return $total;
    }

    /**
     * Trial cases: historical closed ones on existing players, plus a couple
     * of open ones. Staff panel, assessments and extensions hang off both.
     *
     * @param array<string,string> $copy
     */
    private function generateTrialCases( array $copy ): int {
        global $wpdb;

        $cases      = new TrialCasesRepository();
        $staff_repo = new TrialCaseStaffRepository();
        $ext_repo   = new TrialExtensionsRepository();

        $track_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_trial_tracks
              WHERE club_id = %d AND archived_at IS NULL ORDER BY sort_order LIMIT 1",
            CurrentClub::id()
        ) );
        if ( $track_id <= 0 ) return 0;

        $hjo   = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $panel = $this->panelUserIds();

        $total = 0;
        foreach ( $this->players as $index => $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;

            // Every third player carries a historical trial; the first two
            // players in the roster keep an open case.
            $is_open = $index < 2;
            if ( ! $is_open && ( $index % 3 ) !== 0 ) continue;

            // The trial has to finish before the player joined the roster,
            // or the journey reads out of order.
            $joined_ts = isset( $p->date_joined ) && $p->date_joined
                ? ( strtotime( (string) $p->date_joined ) ?: time() )
                : time();

            $duration = mt_rand( 21, 56 );
            if ( $is_open ) {
                $start_ts = time() - ( mt_rand( 5, 20 ) * DAY_IN_SECONDS );
                $end_ts   = $start_ts + ( $duration * DAY_IN_SECONDS );
            } else {
                $end_ts   = $joined_ts - ( mt_rand( 1, 10 ) * DAY_IN_SECONDS );
                $start_ts = $end_ts - ( $duration * DAY_IN_SECONDS );
            }

            $case_id = $cases->create( [
                'player_id'  => $player_id,
                'track_id'   => $track_id,
                'start_date' => gmdate( 'Y-m-d', $start_ts ),
                'end_date'   => gmdate( 'Y-m-d', $end_ts ),
                'created_by' => $hjo,
                'notes'      => null,
            ] );
            if ( $case_id <= 0 ) continue;

            $this->registry->tag( 'trial_case', $case_id, [ 'player_id' => $player_id, 'open' => $is_open ? 1 : 0 ] );
            $total++;

            // Fire the same hook the Trials module fires, so the journey gets
            // its trial_started event in exactly the production shape.
            do_action( 'tt_trial_started', $case_id, $player_id );

            // Staff panel of two or three, most of whom submit an assessment.
            $panel_size = min( count( $panel ), mt_rand( 2, 3 ) );
            for ( $i = 0; $i < $panel_size; $i++ ) {
                $user_id = (int) $panel[ $i ];
                $staff_id = $staff_repo->assign( $case_id, $user_id, $copy['panel'] );
                if ( $staff_id > 0 ) {
                    $this->registry->tag( 'trial_case_staff', $staff_id );
                    $total++;
                }

                if ( mt_rand( 1, 100 ) <= 80 ) {
                    $total += $this->recordStaffInput( $case_id, $user_id, $copy, $end_ts );
                }
            }

            // A couple of open cases get an extension.
            if ( $is_open && mt_rand( 1, 100 ) <= 50 ) {
                $new_end = gmdate( 'Y-m-d', $end_ts + ( 14 * DAY_IN_SECONDS ) );
                $ext_id  = $ext_repo->record(
                    $case_id,
                    gmdate( 'Y-m-d', $end_ts ),
                    $new_end,
                    $copy['extension'],
                    $hjo
                );
                if ( $ext_id > 0 ) {
                    $this->registry->tag( 'trial_extension', $ext_id );
                    $total++;
                }
            }

            if ( ! $is_open ) {
                // Closed with an admit decision — that is what makes the
                // player's journey start at a trial rather than nowhere.
                $cases->recordDecision( $case_id, 'admit', $hjo, $copy['decision'] );
                do_action( 'tt_trial_decision_recorded', $case_id, $player_id, 'admit', gmdate( 'Y-m-d H:i:s', $end_ts ) );
            }
        }
        return $total;
    }

    /** @param array<string,string> $copy */
    private function recordStaffInput( int $case_id, int $user_id, array $copy, int $end_ts ): int {
        global $wpdb;

        $ratings = [];
        foreach ( [ 'technical', 'tactical', 'physical', 'mental' ] as $key ) {
            $ratings[ $key ] = round( mt_rand( 55, 85 ) / 10, 1 );
        }
        $overall = round( array_sum( $ratings ) / count( $ratings ), 2 );

        $wpdb->insert( "{$wpdb->prefix}tt_trial_case_staff_inputs", [
            'club_id'               => CurrentClub::id(),
            'case_id'               => $case_id,
            'user_id'               => $user_id,
            'submitted_at'          => gmdate( 'Y-m-d H:i:s', $end_ts ),
            'category_ratings_json' => (string) wp_json_encode( $ratings ),
            'overall_rating'        => $overall,
            'free_text_notes'       => $copy['input'],
        ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 0;

        $this->registry->tag( 'trial_case_staff_input', $id );
        return 1;
    }

    /**
     * Staff who can sit on a trial panel — the coaching personas from the
     * demo user set.
     *
     * @return int[]
     */
    private function panelUserIds(): array {
        $ids = [];
        foreach ( [ 'hjo', 'hjo2', 'scout', 'staff' ] as $slot ) {
            $id = (int) ( $this->users[ $slot ] ?? 0 );
            if ( $id > 0 ) $ids[] = $id;
        }
        foreach ( $this->teams as $t ) {
            $coach = (int) ( $t->head_coach_user_id ?? 0 );
            if ( $coach > 0 && ! in_array( $coach, $ids, true ) ) $ids[] = $coach;
        }
        return $ids;
    }

    /** @return array<int,int> lookup ids */
    private function lookupIds( string $type ): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( $type ) as $item ) {
            $out[] = (int) $item->id;
        }
        return $out;
    }

    private static function resolveLanguage( string $locale ): string {
        if ( isset( self::COPY_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::COPY_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
