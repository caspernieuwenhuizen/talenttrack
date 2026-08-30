<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * MatchAnalysisGenerator — writes tt_match_analyses and its two children.
 *
 * A demo academy that has played matches should have opinions about them.
 * Without this, the match-analysis surface in a demo install is an empty
 * form, and an evaluator reading an empty form learns nothing about what
 * the module is for.
 *
 * ## Deliberately uneven
 *
 * Not every match gets every section, and roughly a third of the players
 * who appeared get a line. That is what real reviews look like: a coach
 * writes two sentences about the two children who stood out and says
 * nothing about the rest. A demo where every phase carries a tidy rating
 * and every player a note would teach an evaluator that this is a form to
 * be completed, which is the opposite of the intent.
 *
 * Deterministic throughout — demo data has to reproduce from a seed, so
 * the choices key off row ids rather than `rand()`.
 */
class MatchAnalysisGenerator implements DependentGeneratorInterface {

    /** @var array<string, list<string>> */
    private const SUMMARIES = [
        'en_US' => [
            'Started slowly and grew into it. Second half was the football we have been training.',
            'Better than the result says. We created enough; we finished almost none of it.',
            'Hard afternoon. We were second to nearly every loose ball in the first twenty minutes.',
            'Comfortable. The shape held and nobody had to be told twice where to stand.',
        ],
        'nl_NL' => [
            'Traag begonnen en er langzaam ingegroeid. Tweede helft was het voetbal waarop we trainen.',
            'Beter dan de uitslag zegt. We creëerden genoeg; we maakten er bijna niets van af.',
            'Zware middag. We waren de eerste twintig minuten bij vrijwel elke tweede bal te laat.',
            'Comfortabel. De organisatie hield stand en niemand hoefde twee keer te horen waar hij moest staan.',
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const SECTION_NOTES = [
        'en_US' => [
            MethodologyEnums::FUNCTION_AANVALLEN => [
                'Built out from the back patiently when they pressed.',
                'Too few runs behind the last line.',
                'The switch to the far winger came on twice and worked both times.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN => [
                'First pass after winning it went backwards too often.',
                'Two counters that ended in a shot. That is the idea.',
            ],
            MethodologyEnums::FUNCTION_VERDEDIGEN => [
                'Compact between the lines, they had nothing through the middle.',
                'The line dropped as soon as we lost the ball on their half.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN => [
                'Slow to close down after losing it. Costs us the first goal.',
                'Better after half time — the nearest player pressed straight away.',
            ],
            MatchAnalysisEnums::SECTION_SET_PIECES_ATTACK => [
                'Our corners still land on the first defender.',
                'The short free kick worked once and was read the second time.',
            ],
            MatchAnalysisEnums::SECTION_SET_PIECES_DEFEND => [
                'Defended their free kicks well; everyone picked a man.',
                'Second ball after their corners went to them twice.',
            ],
        ],
        'nl_NL' => [
            MethodologyEnums::FUNCTION_AANVALLEN => [
                'Rustig van achteruit opgebouwd toen ze druk zetten.',
                'Te weinig loopacties in de diepte achter de laatste lijn.',
                'De wissel naar de verre buitenspeler kwam er twee keer uit en werkte allebei de keren.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN => [
                'De eerste pass na balwinst ging te vaak achteruit.',
                'Twee counters die op een schot eindigden. Dat is de bedoeling.',
            ],
            MethodologyEnums::FUNCTION_VERDEDIGEN => [
                'Compact tussen de linies, door het midden kwamen ze er niet door.',
                'De linie zakte meteen zodra we op hun helft de bal verloren.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN => [
                'Traag in het druk zetten na balverlies. Kost ons de eerste tegengoal.',
                'Na rust beter — de dichtstbijzijnde speler ging er direct op.',
            ],
            MatchAnalysisEnums::SECTION_SET_PIECES_ATTACK => [
                'Onze corners komen nog steeds op de eerste verdediger.',
                'De korte vrije trap werkte één keer en werd de tweede keer gelezen.',
            ],
            MatchAnalysisEnums::SECTION_SET_PIECES_DEFEND => [
                'Hun vrije trappen goed verdedigd; iedereen pakte een man.',
                'De tweede bal na hun corners was twee keer voor hen.',
            ],
        ],
    ];

    /** @var array<string, list<string>> */
    private const PLAYER_NOTES = [
        'en_US' => [
            'Took the ball on the half-turn under pressure, twice, without being asked.',
            'Kept talking the back four through it. Quietly running the line.',
            'Stopped tracking his winger after the hour. That is where the second goal came from.',
            'Won everything in the air. Held the ball up when we needed a breather.',
            'Rushed the two chances he had. Worth a calm word before Saturday.',
            'Did exactly the job he was given and did not need reminding.',
        ],
        'nl_NL' => [
            'Nam de bal twee keer onder druk in de draai aan, zonder dat het gevraagd werd.',
            'Bleef de laatste linie coachen. Stuurt de lijn stilletjes aan.',
            'Ging zijn buitenspeler na een uur niet meer volgen. Daar komt de tweede tegengoal vandaan.',
            'Won alles in de lucht. Hield de bal vast toen we even lucht nodig hadden.',
            'Overhaastte zijn twee kansen. Voor zaterdag rustig even bespreken.',
            'Deed precies wat hem gevraagd was en had daar geen herinnering voor nodig.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var array<string,int> */
    private array $users;

    private string $language;

    public static function category(): string {
        return 'match_analyses';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->users, $ctx->contentLanguage );
    }

    /** @param array<string,int> $users */
    public function __construct( DemoBatchRegistry $registry, array $users, string $language = '' ) {
        $this->registry = $registry;
        $this->users    = $users;
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $lang    = self::resolveLanguage( $this->language );
        $club    = CurrentClub::id();
        $author  = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $ratings = [
            MatchAnalysisEnums::RATING_WENT_WELL,
            MatchAnalysisEnums::RATING_MIXED,
            MatchAnalysisEnums::RATING_NEEDS_WORK,
        ];
        $markers = [
            MatchAnalysisEnums::MARKER_STOOD_OUT,
            MatchAnalysisEnums::MARKER_BELOW_PAR,
            MatchAnalysisEnums::MARKER_AS_EXPECTED,
        ];

        $matches = $this->batchMatches();

        $total = 0;
        $n     = 0;

        foreach ( $matches as $m => $match ) {
            $activity_id = (int) $match->id;

            // Two in three. A coach does not write up every game.
            //
            // #3184 — keyed off the match's position in this batch, not off
            // its row id. An auto-increment id is not reproducible: the same
            // preset and seed run into two installs, or twice into one, gets
            // different ids and therefore a different two-thirds. That is
            // what made a single generation pass and a stepped one disagree,
            // and what made the suite's answer depend on whether an
            // unrelated test file wrote to `tt_activities` first.
            if ( $m % 3 === 0 ) continue;

            $prep = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_match_prep WHERE activity_id = %d AND club_id = %d",
                $activity_id, $club
            ) );
            $exec = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_match_execution WHERE activity_id = %d AND club_id = %d",
                $activity_id, $club
            ) );

            $when = (string) $match->session_date . ' 19:30:00';

            $ok = $wpdb->insert( "{$wpdb->prefix}tt_match_analyses", [
                'uuid'               => self::uuid(),
                'club_id'            => $club,
                'activity_id'        => $activity_id,
                'match_prep_id'      => $prep ?: null,
                'match_execution_id' => $exec ?: null,
                'summary'            => self::SUMMARIES[ $lang ][ $n % count( self::SUMMARIES[ $lang ] ) ],
                'status'             => MatchAnalysisEnums::STATUS_FINAL,
                'share_token_seed'   => '',
                'created_by'         => $author ?: null,
                'created_at'         => $when,
                'updated_at'         => $when,
            ] );
            $n++;
            if ( $ok === false ) continue;

            $analysis_id = (int) $wpdb->insert_id;
            $this->registry->tag( 'match_analysis', $analysis_id, [ 'activity_id' => $activity_id ] );
            $total++;

            // Three of the five rated sections per match, rotating which
            // ones — a review that always covers the same phases would
            // read as a template rather than as a coach's attention.
            $sections = array_keys( self::SECTION_NOTES[ $lang ] );
            $offset   = $m % count( $sections );
            for ( $i = 0; $i < 3; $i++ ) {
                $key   = $sections[ ( $offset + $i ) % count( $sections ) ];
                $notes = self::SECTION_NOTES[ $lang ][ $key ];

                $ok = $wpdb->insert( "{$wpdb->prefix}tt_match_analysis_sections", [
                    'club_id'     => $club,
                    'analysis_id' => $analysis_id,
                    'section_key' => $key,
                    'rating'      => $ratings[ ( $m + $i ) % count( $ratings ) ],
                    'updated_at'  => $when,
                ] );
                if ( $ok !== false ) {
                    $this->registry->tag( 'match_analysis_section', (int) $wpdb->insert_id, [] );
                    $total++;

                    $total += $this->writeNotes(
                        $club,
                        $analysis_id,
                        'section',
                        $key,
                        null,
                        array_slice( $notes, 0, 1 + ( ( $m + $i ) % 2 ) ),
                        $m + $i,
                        $when
                    );
                }
            }

            // Players who actually appeared, roughly one in three of them.
            $players = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT player_id, COALESCE( minutes_override, minutes_played ) AS minutes
                   FROM {$wpdb->prefix}tt_attendance
                  WHERE activity_id = %d AND club_id = %d AND is_guest = 0
                    AND record_type = 'actual' AND status IN ( 'present', 'late' )
               ORDER BY player_id ASC",
                $activity_id, $club
            ) );

            // #3184 — the player's position in the appearance list, not their
            // row id, for the same reason the match's position replaced its
            // activity id above.
            foreach ( $players as $index => $row ) {
                if ( ( $index + $m ) % 3 !== 0 ) continue;

                $player_id = (int) $row->player_id;
                if ( $player_id <= 0 ) continue;

                $ok = $wpdb->insert( "{$wpdb->prefix}tt_match_analysis_players", [
                    'club_id'        => $club,
                    'analysis_id'    => $analysis_id,
                    'player_id'      => $player_id,
                    'marker'         => $markers[ $n % count( $markers ) ],
                    'team_function'  => $sections[ ( $index + $m ) % count( $sections ) ],
                    'minutes_played' => $row->minutes !== null ? (int) $row->minutes : null,
                    'updated_at'     => $when,
                ] );
                $n++;
                if ( $ok === false ) continue;

                $this->registry->tag( 'match_analysis_player', (int) $wpdb->insert_id, [
                    'player_id' => $player_id,
                ] );
                $total++;

                // Two notes for roughly half of the marked players — the
                // case #3091 exists for. One note for the rest, because a
                // demo where every player has a plus and a minus reads as
                // a form to fill in rather than as a coach's attention.
                $pool  = self::PLAYER_NOTES[ $lang ];
                $bodies = [ $pool[ $n % count( $pool ) ] ];
                if ( ( $index + $m ) % 2 === 0 ) {
                    $bodies[] = $pool[ ( $n + 3 ) % count( $pool ) ];
                }

                $total += $this->writeNotes(
                    $club,
                    $analysis_id,
                    'player',
                    null,
                    $player_id,
                    $bodies,
                    $n,
                    $when
                );
            }
        }

        return $total;
    }

    /**
     * The games this batch wrote that have been played, oldest first.
     *
     * #3184 — this used to read every game in the club with no analysis
     * yet. Two consequences, and they are the same defect seen from two
     * sides:
     *
     *   - on a real install, a second generation run met run one's matches,
     *     so it wrote fewer rows than the first (before #3102 it collided
     *     with `uk_activity` and printed a wpdb error; after #3102 it
     *     skipped them quietly, which is tidier and no more reproducible);
     *   - in the test suite, it made `DemoRunChunkingTest`'s single-pass /
     *     stepped-run comparison depend on the contents *and the
     *     auto-increment position* of `tt_activities`, so adding an
     *     unrelated test file that sorted earlier and wrote an activity
     *     turned a green run red.
     *
     * Reading the batch is what the generator meant all along: an analysis
     * belongs to a match this run created. Ordering by `session_date` then
     * `id` keeps the sequence stable for the ordinal-based choices above —
     * ids differ between runs, positions do not.
     *
     * @return list<object>
     */
    private function batchMatches(): array {
        global $wpdb;

        $ids = $this->registry->entityIds( 'activity' );
        if ( ! $ids ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Only matches that have been played. A demo full of analyses of
        // fixtures still in the future would misrepresent the flow.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.session_date
               FROM {$wpdb->prefix}tt_activities a
          LEFT JOIN {$wpdb->prefix}tt_match_analyses ma
                 ON ma.activity_id = a.id AND ma.club_id = a.club_id
              WHERE a.id IN ({$placeholders})
                AND a.club_id = %d
                AND a.activity_type_key IN ( 'game', 'match' )
                AND a.session_date <= %s
                AND ma.id IS NULL
           ORDER BY a.session_date ASC, a.id ASC",
            ...array_merge( $ids, [ CurrentClub::id(), current_time( 'Y-m-d' ) ] )
        ) );

        return is_array( $rows ) ? array_values( $rows ) : [];
    }

    /**
     * Notes for one section or one player, with their marks (#3091).
     *
     * The valence rotates rather than being random: a demo academy should
     * show all three states — plus, minus and unmarked — because an
     * evaluator looking at the surface needs to see that neutral is
     * allowed. `$seed` is the caller's own counter, so the same install
     * regenerates identically.
     *
     * @param list<string> $bodies
     * @return int rows written
     */
    private function writeNotes(
        int $club,
        int $analysis_id,
        string $scope,
        ?string $section_key,
        ?int $player_id,
        array $bodies,
        int $seed,
        string $when
    ): int {
        global $wpdb;

        $valences = [ 'plus', '', 'minus' ];
        $written  = 0;
        $position = 0;

        foreach ( $bodies as $body ) {
            $body = trim( (string) $body );
            if ( $body === '' ) continue;

            $ok = $wpdb->insert( "{$wpdb->prefix}tt_match_analysis_notes", [
                'uuid'        => self::uuid(),
                'club_id'     => $club,
                'analysis_id' => $analysis_id,
                'scope'       => $scope,
                'section_key' => $section_key,
                'player_id'   => $player_id,
                'valence'     => $valences[ ( $seed + $position ) % count( $valences ) ],
                'body'        => mb_substr( $body, 0, 255 ),
                'position'    => $position,
                'updated_at'  => $when,
            ] );

            if ( $ok !== false ) {
                $this->registry->tag( 'match_analysis_note', (int) $wpdb->insert_id, [] );
                $written++;
            }

            $position++;
        }

        return $written;
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
        if ( isset( self::SUMMARIES[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::SUMMARIES ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
