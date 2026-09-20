<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\SeedLoader;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;

/**
 * PipelineGenerator — the front of the player journey.
 *
 * Scouting visits and the prospects found on them. Trial cases are the
 * `trials` category's and live in `TrialCaseGenerator`: each dependent
 * category runs its writer once, so a class that wrote both wrote the
 * trials twice (#3565).
 */
class PipelineGenerator implements DependentGeneratorInterface {

    /** @var array<string, array{visit_note:string, scouting_note:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'visit_note'    => 'District tournament — several age groups playing across the afternoon.',
            'scouting_note' => 'Comfortable on the ball, good first touch. Worth a closer look.',
        ],
        'nl_NL' => [
            'visit_note'    => 'Districtstoernooi — meerdere leeftijdsgroepen spelen door de middag heen.',
            'scouting_note' => 'Comfortabel aan de bal, goede aanname. De moeite waard om verder te bekijken.',
        ],
    ];

    /** Clubs a prospect might be playing for when spotted. */
    private const CURRENT_CLUBS = [
        'SV Nieuwland', 'VV De Meern', 'RKSV Wilhelmina', 'FC Bergwijk',
        'SC Oostvogels', 'VV Rijnstreek', 'SV Kastanjelaan',
    ];

    private DemoBatchRegistry $registry;

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
        return new self( $ctx->registry, $ctx->teams, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
    }

    /**
     * @param object[] $teams
     * @param array<string,int> $users
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $teams,
        array $users,
        int $weeks,
        string $language = ''
    ) {
        $this->registry = $registry;
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
                // The discovery link is written as an observation by
                // `ProspectsRepository::create()`; tag it so the batch can
                // be wiped, then re-sight roughly every third prospect at a
                // different visit. A pool where nobody was ever watched
                // twice would demo the feature by hiding it.
                $total += $this->recordObservations( $id, $visit_id, $visits, $i );
            }
        }
        return $total;
    }

    /**
     * #3711 — tag the discovery observation and add a later sighting for
     * some prospects.
     *
     * @param int[] $visits
     */
    private function recordObservations( int $prospect_id, ?int $visit_id, array $visits, int $index ): int {
        $observations = new ProspectVisitObservationsRepository();
        $added = 0;

        if ( $visit_id !== null && $visit_id > 0 ) {
            $discovery = $observations->find( $prospect_id, $visit_id );
            if ( $discovery !== null ) {
                $this->registry->tag( 'prospect_visit_observation', (int) $discovery->id, [
                    'prospect_id' => $prospect_id,
                    'discovery'   => true,
                ] );
                $added++;
            }
        }

        if ( $index % 3 !== 0 || count( $visits ) < 2 ) return $added;

        // A different visit from the one they were found at.
        $candidates = array_values( array_filter(
            array_map( 'intval', $visits ),
            static fn ( int $v ): bool => $v > 0 && $v !== (int) $visit_id
        ) );
        if ( ! $candidates ) return $added;

        $later = $candidates[ $index % count( $candidates ) ];
        $id    = $observations->link( $prospect_id, $later );
        if ( $id > 0 ) {
            $this->registry->tag( 'prospect_visit_observation', $id, [
                'prospect_id' => $prospect_id,
                'discovery'   => false,
            ] );
            $added++;
        }
        return $added;
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
