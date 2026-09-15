<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;

/**
 * PlayerProfileGenerator — the rest of a player's record: where they came
 * from, what the club rates them on, and the club's own extra fields.
 *
 * Writes tt_player_team_history, tt_player_attribute_values,
 * tt_custom_fields + tt_custom_values, and tt_goal_links.
 *
 * Attribute values are complete rather than sampled: the chemistry surfaces
 * compute across the full matrix, and a partially-filled one renders as a
 * broken feature rather than a sparse one.
 */
class PlayerProfileGenerator implements DependentGeneratorInterface {

    /**
     * Club-authored custom fields a fresh install has none of. Kept small
     * and uncontroversial — these show up in screenshots.
     *
     * @var array<string, array<int, array{key:string, label:string, type:string, options:?string}>>
     */
    private const CUSTOM_FIELDS_BY_LANGUAGE = [
        'en_US' => [
            [ 'key' => 'school_name',      'label' => 'School',                'type' => 'text',   'options' => null ],
            [ 'key' => 'travel_distance',  'label' => 'Travel distance (km)',  'type' => 'number', 'options' => null ],
            [ 'key' => 'preferred_number', 'label' => 'Preferred shirt number', 'type' => 'number', 'options' => null ],
        ],
        'nl_NL' => [
            [ 'key' => 'school_name',      'label' => 'School',                 'type' => 'text',   'options' => null ],
            [ 'key' => 'travel_distance',  'label' => 'Reisafstand (km)',       'type' => 'number', 'options' => null ],
            [ 'key' => 'preferred_number', 'label' => 'Gewenst rugnummer',      'type' => 'number', 'options' => null ],
        ],
    ];

    /** @var array<string, string[]> */
    private const SCHOOLS_BY_LANGUAGE = [
        'en_US' => [ 'St. Mary\'s', 'Riverside Academy', 'Northgate School', 'Parkview High', 'Eastfield School' ],
        'nl_NL' => [ 'De Wingerd', 'Het Baken', 'Sint-Jozefschool', 'De Regenboog', 'Willibrordusschool' ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    private int $weeks;

    private string $language;

    private DemoCalendar $calendar;

    private DemoRoster $roster;

    public static function category(): string {
        return 'player_profile';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self(
            $ctx->registry,
            $ctx->historicPlayers(),
            $ctx->teams,
            $ctx->weeks(),
            $ctx->contentLanguage,
            $ctx->calendar(),
            $ctx->roster()
        );
    }

    /**
     * @param object[] $players
     * @param object[] $teams
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        int $weeks,
        string $language = '',
        ?DemoCalendar $calendar = null,
        ?DemoRoster $roster = null
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
        $this->calendar = $calendar ?? new DemoCalendar( $this->weeks );
        $this->roster   = $roster ?? new DemoRoster( $this->calendar, $teams, $players );
    }

    public function generate(): int {
        $total  = 0;
        $total += $this->generateTeamHistory();
        $total += $this->generateAttributeValues();
        $total += $this->generateCustomFields();
        $total += $this->generateGoalLinks();
        return $total;
    }

    /**
     * One spell per season the player was at the academy, oldest first, the
     * last one open-ended for anyone still there.
     *
     * #3402 — the spells come from `DemoRoster`, which is also what decides
     * whose attendance, evaluations and test results a past season's squad
     * carries. Before that they were drawn independently, so the age-group
     * history on a player's profile contradicted the work on the same page.
     *
     * The player's `date_joined` is aligned to the first spell for the same
     * reason: a record claiming a player joined three years ago while their
     * history starts last August is the same contradiction one column over.
     */
    private function generateTeamHistory(): int {
        global $wpdb;

        $total = 0;
        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;

            $spells = $this->roster->spellsFor( $player_id );
            if ( $spells === [] ) continue;

            foreach ( $spells as $index => $spell ) {
                $wpdb->insert( "{$wpdb->prefix}tt_player_team_history", [
                    'club_id'   => CurrentClub::id(),
                    'player_id' => $player_id,
                    'team_id'   => (int) $spell['team_id'],
                    'joined_at' => (string) $spell['joined_at'],
                    'left_at'   => $spell['left_at'],
                ] );
                $id = (int) $wpdb->insert_id;
                if ( ! $id ) continue;

                $this->registry->tag( 'player_team_history', $id, array_merge(
                    [ 'player_id' => $player_id ],
                    $spell['left_at'] === null ? [ 'current' => 1 ] : []
                ) );
                $total++;
                unset( $index );
            }

            $wpdb->update(
                "{$wpdb->prefix}tt_players",
                [ 'date_joined' => (string) $spells[0]['joined_at'] ],
                [ 'id' => $player_id, 'club_id' => CurrentClub::id() ]
            );
        }
        return $total;
    }

    /**
     * One value per player per active attribute definition. Definitions are
     * seeded by migration 0178 — this never creates them.
     */
    private function generateAttributeValues(): int {
        global $wpdb;

        $defs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, attr_group, min_value, max_value FROM {$wpdb->prefix}tt_player_attribute_defs
              WHERE club_id = %d AND is_active = 1 AND archived_at IS NULL",
            CurrentClub::id()
        ) );
        if ( ! $defs ) return 0;

        // #3216 — `uniq_player_attr (player_id, attribute_def_id)` allows one
        // value per player per definition, ever. `$this->players` is this
        // batch's when the operator generated players and the club's whole
        // roster when they unchecked Players to build on an existing squad;
        // the second path meets players a previous run already scored.
        $already = $this->playersWithAttributeValues();

        $total = 0;
        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;
            if ( isset( $already[ $player_id ] ) ) continue;

            // A per-player baseline keeps one player consistently stronger
            // than another across every attribute, with per-group tilt on
            // top so the radar has a shape instead of a circle.
            $baseline = mt_rand( 42, 78 );
            $tilt     = [];
            foreach ( $defs as $d ) {
                $group = (string) $d->attr_group;
                if ( ! isset( $tilt[ $group ] ) ) $tilt[ $group ] = mt_rand( -12, 12 );
            }

            foreach ( $defs as $d ) {
                $min   = (int) $d->min_value;
                $max   = (int) $d->max_value;
                $value = $baseline + $tilt[ (string) $d->attr_group ] + mt_rand( -6, 6 );
                $value = max( $min, min( $max, $value ) );

                $wpdb->insert( "{$wpdb->prefix}tt_player_attribute_values", [
                    'club_id'          => CurrentClub::id(),
                    'uuid'             => self::uuid(),
                    'player_id'        => $player_id,
                    'attribute_def_id' => (int) $d->id,
                    'value'            => $value,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'player_attribute_value', $id );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * Club-authored custom fields, plus a value per player. Reuses fields
     * that already exist so a second run doesn't duplicate the definitions.
     */
    private function generateCustomFields(): int {
        global $wpdb;

        $lang    = self::resolveLanguage( $this->language );
        $schools = self::SCHOOLS_BY_LANGUAGE[ $lang ];
        $total   = 0;

        $field_ids = [];
        foreach ( self::CUSTOM_FIELDS_BY_LANGUAGE[ $lang ] as $sort => $field ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_custom_fields
                  WHERE club_id = %d AND entity_type = 'player' AND field_key = %s LIMIT 1",
                CurrentClub::id(), $field['key']
            ) );
            if ( $existing > 0 ) {
                $field_ids[ $field['key'] ] = $existing;
                continue;
            }

            $wpdb->insert( "{$wpdb->prefix}tt_custom_fields", [
                'club_id'     => CurrentClub::id(),
                'entity_type' => 'player',
                'field_key'   => $field['key'],
                'label'       => $field['label'],
                'field_type'  => $field['type'],
                'options'     => $field['options'],
                'is_required' => 0,
                'is_active'   => 1,
                'sort_order'  => ( $sort + 1 ) * 10,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( ! $id ) continue;
            $field_ids[ $field['key'] ] = $id;
            $this->registry->tag( 'custom_field', $id, [ 'field_key' => $field['key'] ] );
            $total++;
        }

        $filled = [];
        foreach ( $field_ids as $fid ) {
            $filled[ (int) $fid ] = $this->playersWithCustomValue( (int) $fid );
        }

        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;

            $values = [
                'school_name'      => $schools[ mt_rand( 0, count( $schools ) - 1 ) ],
                'travel_distance'  => (string) mt_rand( 2, 35 ),
                'preferred_number' => (string) mt_rand( 2, 25 ),
            ];

            foreach ( $values as $key => $value ) {
                $field_id = (int) ( $field_ids[ $key ] ?? 0 );
                if ( $field_id <= 0 ) continue;
                // #3216 — one value per (player, field); see the note above
                // `playersWithCustomValue()`.
                if ( isset( $filled[ $field_id ][ $player_id ] ) ) continue;

                $wpdb->insert( "{$wpdb->prefix}tt_custom_values", [
                    'club_id'     => CurrentClub::id(),
                    'entity_type' => 'player',
                    'entity_id'   => $player_id,
                    'field_id'    => $field_id,
                    'value'       => $value,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'custom_value', $id );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * Player ids that already carry at least one attribute value (#3216),
     * as a set so the loop is one lookup rather than one query per player.
     *
     * @return array<int, true>
     */
    private function playersWithAttributeValues(): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT player_id FROM {$wpdb->prefix}tt_player_attribute_values WHERE club_id = %d",
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $ids as $id ) $out[ (int) $id ] = true;
        return $out;
    }

    /**
     * Player ids that already carry a value for this custom field (#3216) —
     * `uniq_entity_field (entity_type, entity_id, field_id)`.
     *
     * @return array<int, true>
     */
    private function playersWithCustomValue( int $field_id ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT entity_id FROM {$wpdb->prefix}tt_custom_values
              WHERE entity_type = 'player' AND field_id = %d AND club_id = %d",
            $field_id,
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $ids as $id ) $out[ (int) $id ] = true;
        return $out;
    }

    /**
     * Link about half the generated goals to an evaluation of the same
     * player, so the goal → evidence trail is visible rather than implied,
     * and tag most goals with the methodology principles they develop
     * (#2566) so a demo academy shows the coverage mechanism working
     * instead of an empty panel.
     */
    private function generateGoalLinks(): int {
        global $wpdb;

        $principle_ids = $this->clubPrincipleIds();

        $total = 0;
        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;

            $goals = $wpdb->get_col( $wpdb->prepare(
                "SELECT g.id FROM {$wpdb->prefix}tt_goals g
                   JOIN {$wpdb->prefix}tt_demo_tags d
                     ON d.entity_type = 'goal' AND d.entity_id = g.id AND d.club_id = %d
                  WHERE g.player_id = %d AND g.club_id = %d",
                CurrentClub::id(), $player_id, CurrentClub::id()
            ) );
            if ( ! $goals ) continue;

            $total += $this->linkPrinciples( $goals, $principle_ids );

            $evals = $wpdb->get_col( $wpdb->prepare(
                "SELECT e.id FROM {$wpdb->prefix}tt_evaluations e
                   JOIN {$wpdb->prefix}tt_demo_tags d
                     ON d.entity_type = 'evaluation' AND d.entity_id = e.id AND d.club_id = %d
                  WHERE e.player_id = %d AND e.club_id = %d
                  ORDER BY e.eval_date DESC LIMIT 10",
                CurrentClub::id(), $player_id, CurrentClub::id()
            ) );
            if ( ! $evals ) continue;

            foreach ( $goals as $goal_id ) {
                if ( mt_rand( 1, 100 ) > 50 ) continue;
                $eval_id = (int) $evals[ mt_rand( 0, count( $evals ) - 1 ) ];

                // uniq_goal_target makes a repeat pairing a no-op.
                $ok = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}tt_goal_links (goal_id, link_type, link_id, club_id)
                     VALUES (%d, 'evaluation', %d, %d)",
                    (int) $goal_id, $eval_id, CurrentClub::id()
                ) );
                $id = (int) $wpdb->insert_id;
                if ( $ok && $id ) {
                    $this->registry->tag( 'goal_link', $id );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * #2566 — the principles a demo goal develops. Four of five goals get
     * one or two, which is roughly what a coaching staff manages in
     * practice; leaving a fifth untagged keeps the coverage panel honest
     * about the goals nobody got to.
     *
     * @param list<int|string> $goal_ids
     * @param list<int>        $principle_ids
     */
    private function linkPrinciples( array $goal_ids, array $principle_ids ): int {
        global $wpdb;
        if ( ! $principle_ids ) return 0;

        $total = 0;
        foreach ( $goal_ids as $goal_id ) {
            if ( mt_rand( 1, 100 ) > 80 ) continue;

            $wanted = mt_rand( 1, 2 );
            $picked = [];
            for ( $i = 0; $i < $wanted; $i++ ) {
                $pid = (int) $principle_ids[ mt_rand( 0, count( $principle_ids ) - 1 ) ];
                if ( isset( $picked[ $pid ] ) ) continue;
                $picked[ $pid ] = true;

                // uniq_goal_target makes a repeat pairing a no-op.
                $ok = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}tt_goal_links (goal_id, link_type, link_id, club_id)
                     VALUES (%d, 'principle', %d, %d)",
                    (int) $goal_id, $pid, CurrentClub::id()
                ) );
                $id = (int) $wpdb->insert_id;
                if ( $ok && $id ) {
                    $this->registry->tag( 'goal_link', $id );
                    $total++;
                }
            }
        }
        return $total;
    }

    /** @return list<int> the club's non-archived principles. */
    private function clubPrincipleIds(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_principles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE club_id = %d AND archived_at IS NULL ORDER BY code ASC",
            CurrentClub::id()
        ) );
        return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
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
        if ( isset( self::CUSTOM_FIELDS_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::CUSTOM_FIELDS_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
