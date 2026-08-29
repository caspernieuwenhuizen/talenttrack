<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * StaffDevelopmentGenerator — certifications, development plans, goals,
 * evaluations and mentorships for the club's staff.
 *
 * These rows exist in service of a player record, not as an HR module: a head
 * of academy uses a coach's badges and development plan to decide who develops
 * which players. That is the justification for generating them at all.
 */
class StaffDevelopmentGenerator implements DependentGeneratorInterface {

    /** Coaching badges, roughly the KNVB/UEFA ladder. */
    private const CERTIFICATIONS = [
        [ 'name' => 'UEFA C', 'issuer' => 'KNVB', 'years_valid' => 0 ],
        [ 'name' => 'UEFA B', 'issuer' => 'KNVB', 'years_valid' => 0 ],
        [ 'name' => 'UEFA A', 'issuer' => 'KNVB', 'years_valid' => 0 ],
        [ 'name' => 'First aid / CPR', 'issuer' => 'Rode Kruis', 'years_valid' => 2 ],
        [ 'name' => 'Safeguarding', 'issuer' => 'KNVB', 'years_valid' => 3 ],
    ];

    /** @var array<string, array{goals:string[], strengths:string, areas:string, actions:string, narrative:string, eval_note:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'goals' => [
                'Complete the UEFA B module this season',
                'Run a session observed by the head of academy each block',
                'Improve individual feedback after matches',
                'Lead one parent evening this season',
            ],
            'strengths'  => 'Clear session organisation; players know what is expected.',
            'areas'      => 'Individual feedback is thin after matches.',
            'actions'    => 'Two one-to-one conversations per block, logged in the player files.',
            'narrative'  => 'Developing steadily. Ready for a bigger age group next season.',
            'eval_note'  => 'Consistent, well prepared, good rapport with the group.',
        ],
        'nl_NL' => [
            'goals' => [
                'Dit seizoen de UEFA B-module afronden',
                'Elk blok een training laten meekijken door het hoofd opleiding',
                'Individuele terugkoppeling na wedstrijden verbeteren',
                'Dit seizoen één ouderavond leiden',
            ],
            'strengths'  => 'Heldere trainingsopbouw; spelers weten wat er verwacht wordt.',
            'areas'      => 'Individuele terugkoppeling na wedstrijden blijft achter.',
            'actions'    => 'Twee individuele gesprekken per blok, vastgelegd in de spelersdossiers.',
            'narrative'  => 'Ontwikkelt gestaag. Klaar voor een hogere leeftijdsgroep volgend seizoen.',
            'eval_note'  => 'Constant, goed voorbereid, prettige omgang met de groep.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var array<string,int> */
    private array $users;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'staff_development';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
    }

    /** @param array<string,int> $users */
    public function __construct( DemoBatchRegistry $registry, array $users, int $weeks, string $language = '' ) {
        $this->registry = $registry;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $staff = $this->staffPeople();
        if ( ! $staff ) return 0;

        $copy       = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $reviewer   = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $season_id  = $this->currentSeasonId();
        $categories = $this->evalCategoryIds();
        $cert_types = $this->certTypeIds();

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        $total = 0;
        foreach ( $staff as $index => $person_id ) {
            // Certifications: everyone holds a couple, and one is close to
            // expiry so the renewal surface has something to show. Skipped
            // entirely when the club has defined no certificate types —
            // `cert_type_lookup_id` is NOT NULL and generators consume
            // lookups rather than inventing them.
            $cert_count = $cert_types ? mt_rand( 2, 3 ) : 0;
            for ( $i = 0; $i < $cert_count; $i++ ) {
                $cert     = self::CERTIFICATIONS[ ( $index + $i ) % count( self::CERTIFICATIONS ) ];
                $issued   = strtotime( '-' . mt_rand( 1, 6 ) . ' years' ) ?: time();
                $expires  = (int) $cert['years_valid'] > 0
                    ? gmdate( 'Y-m-d', strtotime( '+' . mt_rand( 1, 14 ) . ' months' ) ?: time() )
                    : null;

                $wpdb->insert( "{$wpdb->prefix}tt_staff_certifications", [
                    'club_id'             => CurrentClub::id(),
                    'person_id'           => $person_id,
                    'cert_type_lookup_id' => (int) $cert_types[ ( $index + $i ) % count( $cert_types ) ],
                    'issuer'              => $cert['issuer'] . ' — ' . $cert['name'],
                    'issued_on'           => gmdate( 'Y-m-d', $issued ),
                    'expires_on'          => $expires,
                    'document_url'        => null,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'staff_certification', $id, [ 'person_id' => $person_id ] );
                    $total++;
                }
            }

            // Development plan.
            $wpdb->insert( "{$wpdb->prefix}tt_staff_pdp", [
                'club_id'              => CurrentClub::id(),
                'uuid'                 => self::uuid(),
                'person_id'            => $person_id,
                'season_id'            => $season_id,
                'strengths'            => $copy['strengths'],
                'development_areas'    => $copy['areas'],
                'actions_next_quarter' => $copy['actions'],
                'narrative'            => $copy['narrative'],
                'last_reviewed_at'     => gmdate( 'Y-m-d H:i:s', $window_start + (int) ( $this->weeks * 0.5 * WEEK_IN_SECONDS ) ),
                'last_reviewed_by'     => $reviewer,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'staff_pdp', $id, [ 'person_id' => $person_id ] );
                $total++;
            }

            // Two or three development goals apiece.
            $goal_count = mt_rand( 2, 3 );
            $titles = $copy['goals'];
            for ( $i = 0; $i < $goal_count; $i++ ) {
                $wpdb->insert( "{$wpdb->prefix}tt_staff_goals", [
                    'club_id'             => CurrentClub::id(),
                    'person_id'           => $person_id,
                    'season_id'           => $season_id,
                    'title'               => (string) $titles[ ( $index + $i ) % count( $titles ) ],
                    'description'         => $copy['actions'],
                    'status'              => $i === 0 ? 'completed' : 'in_progress',
                    'priority'            => $i === 0 ? 'high' : 'medium',
                    'due_date'            => gmdate( 'Y-m-d', strtotime( '+' . mt_rand( 30, 180 ) . ' days' ) ?: time() ),
                    'cert_type_lookup_id' => null,
                    'created_by'          => $reviewer,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'staff_goal', $id, [ 'person_id' => $person_id ] );
                    $total++;
                }
            }

            // An evaluation with per-category ratings for most of the staff.
            if ( mt_rand( 1, 100 ) <= 70 ) {
                $wpdb->insert( "{$wpdb->prefix}tt_staff_evaluations", [
                    'club_id'          => CurrentClub::id(),
                    'person_id'        => $person_id,
                    'reviewer_user_id' => $reviewer,
                    'review_kind'      => 'mid_season',
                    'season_id'        => $season_id,
                    'eval_date'        => gmdate( 'Y-m-d', $window_start + (int) ( $this->weeks * 0.6 * WEEK_IN_SECONDS ) ),
                    'notes'            => $copy['eval_note'],
                ] );
                $evaluation_id = (int) $wpdb->insert_id;
                if ( $evaluation_id ) {
                    $this->registry->tag( 'staff_evaluation', $evaluation_id, [ 'person_id' => $person_id ] );
                    $total++;

                    foreach ( $categories as $category_id ) {
                        $wpdb->insert( "{$wpdb->prefix}tt_staff_eval_ratings", [
                            'evaluation_id' => $evaluation_id,
                            'category_id'   => (int) $category_id,
                            'rating'        => round( mt_rand( 60, 90 ) / 10, 1 ),
                            'comment'       => null,
                        ] );
                        $rating_id = (int) $wpdb->insert_id;
                        if ( $rating_id ) {
                            $this->registry->tag( 'staff_eval_rating', $rating_id );
                            $total++;
                        }
                    }
                }
            }
        }

        $total += $this->generateMentorships( $staff, $reviewer );
        return $total;
    }

    /**
     * Pair senior staff with junior staff. One pairing per two people, so a
     * small staff gets one and a large one gets several.
     *
     * @param int[] $staff
     */
    private function generateMentorships( array $staff, int $reviewer ): int {
        global $wpdb;

        $pairs = (int) floor( count( $staff ) / 2 );
        $pairs = max( 1, min( 4, $pairs ) );

        $total = 0;
        for ( $i = 0; $i < $pairs; $i++ ) {
            $mentor = (int) $staff[ $i ];
            $mentee = (int) $staff[ count( $staff ) - 1 - $i ];
            if ( $mentor === $mentee ) continue;

            $wpdb->insert( "{$wpdb->prefix}tt_staff_mentorships", [
                'club_id'          => CurrentClub::id(),
                'mentor_person_id' => $mentor,
                'mentee_person_id' => $mentee,
                'started_on'       => gmdate( 'Y-m-d', strtotime( '-' . mt_rand( 2, 10 ) . ' months' ) ?: time() ),
                'ended_on'         => null,
                'created_by'       => $reviewer,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'staff_mentorship', $id );
                $total++;
            }
        }
        return $total;
    }

    /**
     * People rows for the club's staff. Uses the demo-tagged people when the
     * batch created them, and otherwise whatever staff the club already has.
     *
     * @return int[]
     */
    private function staffPeople(): array {
        global $wpdb;

        // #3102 — people who do not already have a staff PDP. `ORDER BY id
        // LIMIT 12` returns the same first twelve on a second run, and
        // `currentSeasonId()` returns the same season, so the insert met
        // `uk_person_season (person_id, season_id)` and failed. It collides
        // even on a club with no seasons: the missing row casts to 0, and 0
        // is not NULL, so MySQL treats two of them as equal.
        //
        // Filtering here rather than at the insert also stops the sibling
        // tables — certifications, staff goals, evaluations, mentorships —
        // duplicating their rows, since none of them carries a unique key to
        // refuse a second copy.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.id FROM {$wpdb->prefix}tt_people p
          LEFT JOIN {$wpdb->prefix}tt_staff_pdp sp
                 ON sp.person_id = p.id AND sp.club_id = p.club_id
              WHERE p.club_id = %d AND p.archived_at IS NULL AND sp.id IS NULL
           ORDER BY p.id LIMIT 12",
            CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $ids );
    }

    private function currentSeasonId(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_seasons WHERE club_id = %d ORDER BY is_current DESC, start_date DESC LIMIT 1",
            CurrentClub::id()
        ) );
    }

    /**
     * Certificate-type lookup ids. Empty on a club that has not defined any —
     * the vocabulary is admin-editable and no migration seeds it, so
     * certifications are skipped rather than inventing lookup rows.
     *
     * @return int[]
     */
    private function certTypeIds(): array {
        $out = [];
        foreach ( \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'cert_type' ) as $item ) {
            $out[] = (int) $item->id;
        }
        return $out;
    }

    /** @return int[] */
    private function evalCategoryIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_eval_categories WHERE club_id = %d ORDER BY id LIMIT 4",
            CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * #3102 — outside the seeded stream, so a second run into the same
     * install does not re-mint the uuid the first one already stored. See
     * \TT\Modules\DemoData\DemoUuid.
     */
    private static function uuid(): string {
        return \TT\Modules\DemoData\DemoUuid::mint();
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
