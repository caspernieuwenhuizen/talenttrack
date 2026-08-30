<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\TeamDevelopment\BlueprintChemistryEngine;
use TT\Modules\TeamDevelopment\Repositories\ChemistrySnapshotsRepository;
use TT\Modules\TeamDevelopment\Repositories\PairingsRepository;
use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;

/**
 * TeamDevelopmentGenerator — the team's shape and how it is playing.
 *
 * Assigns a seeded formation template per team, sets a playing-style mix,
 * builds a match-day blueprint with its slot assignments, records a few
 * coach-marked pairings, and takes chemistry snapshots across the window.
 *
 * The formations, formation positions and set pieces are shipped
 * methodology content — the club gets them from migrations, so this assigns
 * and uses them rather than inventing a parallel set.
 *
 * Chemistry snapshots are **computed by the real engine** from the blueprint
 * lineup rather than invented. A stored score that disagrees with what the
 * engine would recompute makes the module look broken the moment anyone
 * opens it.
 */
class TeamDevelopmentGenerator implements DependentGeneratorInterface {

    /** Age-appropriate shapes — younger squads play smaller-sided shapes. */
    private const SHAPE_BY_AGE = [
        8  => '4-3-3',
        10 => '4-3-3',
        12 => '4-3-3',
        14 => '4-4-2',
        16 => '4-2-3-1',
        19 => '3-5-2',
    ];

    /** @var array<string, array{blueprint:string, pairing:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'blueprint' => 'Match-day shape',
            'pairing'   => 'Reads each other well; keep them on the same side.',
        ],
        'nl_NL' => [
            'blueprint' => 'Wedstrijdopstelling',
            'pairing'   => 'Vinden elkaar goed; houd ze aan dezelfde kant.',
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
        return 'team_development';
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
        global $wpdb;

        $templates = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, formation_shape, slots_json FROM {$wpdb->prefix}tt_formation_templates
              WHERE club_id = %d AND archived_at IS NULL",
            CurrentClub::id()
        ) );
        if ( ! $templates ) return 0;

        $copy      = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $author    = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $blueprints = new TeamBlueprintsRepository();
        $pairings   = new PairingsRepository();
        $snapshots  = new ChemistrySnapshotsRepository();
        $engine     = new BlueprintChemistryEngine();

        $players_by_team = [];
        foreach ( $this->players as $p ) {
            $players_by_team[ (int) ( $p->team_id ?? 0 ) ][] = (int) $p->id;
        }

        $total = 0;
        foreach ( $this->teams as $team ) {
            $team_id = (int) $team->id;
            $roster  = $players_by_team[ $team_id ] ?? [];
            if ( ! $roster ) continue;

            $template = $this->templateForTeam( $templates, isset( $team->age_group ) ? (string) $team->age_group : '' );
            $coach_id = (int) ( $team->head_coach_user_id ?? $author );

            // #3216 — `tt_team_formations` and `tt_team_playing_styles` both
            // carry `UNIQUE KEY uniq_team (team_id)`: a team has one formation
            // and one playing style, ever. So a team that already has them is
            // skipped rather than re-inserted.
            //
            // The teams here are this batch's when the operator generated
            // teams, and the club's whole squad list when they unchecked
            // Teams to build on what already exists. That second path is the
            // one that meets a team a previous run already dressed — which
            // used to be a duplicate-key error and a row silently not written.
            if ( ! $this->teamHasRow( 'tt_team_formations', $team_id ) ) {
                $wpdb->insert( "{$wpdb->prefix}tt_team_formations", [
                    'club_id'               => CurrentClub::id(),
                    'team_id'               => $team_id,
                    'formation_template_id' => (int) $template->id,
                    'assigned_by'           => $coach_id,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'team_formation', $id, [ 'team_id' => $team_id ] );
                    $total++;
                }
            }

            // Playing style — three weights the UI reads as a split of 100.
            [ $possession, $counter, $press ] = $this->drawStyleWeights();
            if ( ! $this->teamHasRow( 'tt_team_playing_styles', $team_id ) ) {
                $wpdb->insert( "{$wpdb->prefix}tt_team_playing_styles", [
                    'club_id'           => CurrentClub::id(),
                    'team_id'           => $team_id,
                    'possession_weight' => $possession,
                    'counter_weight'    => $counter,
                    'press_weight'      => $press,
                    'updated_by'        => $coach_id,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'team_playing_style', $id, [ 'team_id' => $team_id ] );
                    $total++;
                }
            }

            // Blueprint + slot assignments.
            $blueprint_id = $blueprints->create(
                $team_id,
                $copy['blueprint'] . ' — ' . (string) $template->formation_shape,
                (int) $template->id,
                $coach_id
            );
            if ( $blueprint_id <= 0 ) continue;
            $this->registry->tag( 'team_blueprint', $blueprint_id, [ 'team_id' => $team_id ] );
            $total++;

            $slots  = $this->slotLabels( $template );
            $lineup = [];
            foreach ( $slots as $i => $slot_label ) {
                if ( ! isset( $roster[ $i ] ) ) break;
                $lineup[ $slot_label ] = (int) $roster[ $i ];
            }
            if ( $lineup ) {
                $blueprints->replaceAssignments( $blueprint_id, $lineup );
                $total += $this->tagAssignments( $blueprint_id );
            }

            // Coach-marked pairings: these are notes a coach makes, not
            // computed scores, so a handful per team is the realistic shape.
            $pair_count = min( 3, (int) floor( count( $roster ) / 4 ) );
            for ( $i = 0; $i < $pair_count; $i++ ) {
                $a = (int) $roster[ ( $i * 2 ) % count( $roster ) ];
                $b = (int) $roster[ ( $i * 2 + 1 ) % count( $roster ) ];
                if ( $a === $b ) continue;
                $pairing_id = $pairings->add( $team_id, $a, $b, $copy['pairing'], $coach_id );
                if ( $pairing_id > 0 ) {
                    $this->registry->tag( 'team_chemistry_pairing', $pairing_id, [ 'team_id' => $team_id ] );
                    $total++;
                }
            }

            $total += $this->recordSnapshots( $engine, $snapshots, $team_id, $template, $lineup );
        }

        return $total;
    }

    /**
     * Snapshots across the window. The most recent one is computed by the
     * chemistry engine from the blueprint lineup; the earlier points are
     * that same score walked backwards, so the trend line is a plausible
     * path to a real number rather than noise ending on a fiction.
     *
     * @param array<string,int> $lineup
     */
    private function recordSnapshots(
        BlueprintChemistryEngine $engine,
        ChemistrySnapshotsRepository $snapshots,
        int $team_id,
        object $template,
        array $lineup
    ): int {
        global $wpdb;

        if ( ! $lineup ) return 0;

        $slots = json_decode( (string) ( $template->slots_json ?? '[]' ), true );
        if ( ! is_array( $slots ) ) $slots = [];

        $computed = $engine->computeForLineup( $team_id, $slots, $lineup );
        $current  = isset( $computed['team_score'] ) ? (int) $computed['team_score'] : 0;
        if ( $current <= 0 ) {
            // The engine declines to score when the lineup has too few
            // adjacent pairs. Nothing to snapshot — better an empty series
            // than an invented one.
            return 0;
        }

        $points = max( 3, min( 8, (int) round( $this->weeks / 4 ) ) );
        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();
        $step = (int) floor( ( time() - $window_start ) / max( 1, $points - 1 ) );

        $total = 0;
        for ( $i = 0; $i < $points; $i++ ) {
            $is_latest = ( $i === $points - 1 );
            // Earlier points sit a little below today's score, with wobble.
            $drift = $is_latest ? 0 : (int) round( ( $points - 1 - $i ) * 1.5 ) + mt_rand( -2, 2 );
            $score = max( 1, min( 100, $current - $drift ) );

            $snapshot_id = $snapshots->record( $team_id, $score, $is_latest ? 'blueprint_save' : 'match_complete' );
            if ( $snapshot_id <= 0 ) continue;

            // record() stamps "now"; move the earlier points back onto the
            // window so the series spans the season.
            $wpdb->update(
                "{$wpdb->prefix}tt_team_chemistry_snapshots",
                [ 'computed_at' => gmdate( 'Y-m-d H:i:s', $window_start + ( $i * $step ) ) ],
                [ 'id' => $snapshot_id, 'club_id' => CurrentClub::id() ]
            );

            $this->registry->tag( 'team_chemistry_snapshot', $snapshot_id, [ 'team_id' => $team_id ] );
            $total++;
        }
        return $total;
    }

    /**
     * Does this team already have its one row in a `uniq_team` table (#3216)?
     *
     * @param string $table Unprefixed table name, a literal from this class.
     */
    private function teamHasRow( string $table, int $team_id ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE team_id = %d AND club_id = %d",
            $team_id,
            CurrentClub::id()
        ) ) > 0;
    }

    /** Tag the assignment rows the blueprint repository just wrote. */
    private function tagAssignments( int $blueprint_id ): int {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT a.id FROM {$wpdb->prefix}tt_team_blueprint_assignments a
               LEFT JOIN {$wpdb->prefix}tt_demo_tags d
                      ON d.entity_type = 'team_blueprint_assignment' AND d.entity_id = a.id AND d.club_id = %d
              WHERE a.blueprint_id = %d AND a.club_id = %d AND d.id IS NULL",
            CurrentClub::id(), $blueprint_id, CurrentClub::id()
        ) );
        $total = 0;
        foreach ( (array) $ids as $id ) {
            $this->registry->tag( 'team_blueprint_assignment', (int) $id );
            $total++;
        }
        return $total;
    }

    /**
     * Slot labels from the template's slots_json, falling back to shirt
     * numbers when the template stores something this doesn't recognise.
     *
     * @return string[]
     */
    private function slotLabels( object $template ): array {
        $slots = json_decode( (string) ( $template->slots_json ?? '[]' ), true );
        $out = [];
        if ( is_array( $slots ) ) {
            foreach ( $slots as $slot ) {
                if ( is_array( $slot ) && isset( $slot['label'] ) ) {
                    $out[] = (string) $slot['label'];
                } elseif ( is_string( $slot ) ) {
                    $out[] = $slot;
                }
            }
        }
        if ( $out ) return $out;

        for ( $i = 1; $i <= 11; $i++ ) {
            $out[] = (string) $i;
        }
        return $out;
    }

    /** Pick an age-appropriate shape, falling back to whatever is seeded. */
    private function templateForTeam( array $templates, string $age_group ): object {
        $age = 12;
        if ( preg_match( '/(\d+)/', $age_group, $m ) ) {
            $age = (int) $m[1];
        }

        $wanted = '4-3-3';
        foreach ( self::SHAPE_BY_AGE as $max_age => $shape ) {
            if ( $age <= $max_age ) {
                $wanted = $shape;
                break;
            }
        }

        $matching = [];
        foreach ( $templates as $t ) {
            if ( (string) $t->formation_shape === $wanted ) $matching[] = $t;
        }
        if ( $matching ) {
            return $matching[ mt_rand( 0, count( $matching ) - 1 ) ];
        }
        return $templates[ mt_rand( 0, count( $templates ) - 1 ) ];
    }

    /**
     * Three weights summing to exactly 100, varied per team so the
     * comparison view isn't a wall of identical bars.
     *
     * @return array{0:int, 1:int, 2:int}
     */
    private function drawStyleWeights(): array {
        $possession = mt_rand( 20, 55 );
        $counter    = mt_rand( 15, min( 50, 95 - $possession ) );
        $press      = 100 - $possession - $counter;
        if ( $press < 5 ) {
            $possession -= ( 5 - $press );
            $press = 5;
        }
        return [ $possession, $counter, $press ];
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
