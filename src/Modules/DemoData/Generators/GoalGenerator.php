<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * GoalGenerator — writes tt_goals.
 *
 * 1-2 goals per player, spread across status states: mostly in-progress,
 * some completed, some pending, occasional on-hold. Priority mix
 * roughly 20% High / 60% Medium / 20% Low.
 *
 * **Content language:** the generator stores rows in the language the
 * operator chose on the Generate form (defaults to site locale).
 * Earlier implementations tried to route the titles through the plugin
 * `.po` via `switch_to_locale()` + `__()`, but that only works when
 * the `.mo` file has been recompiled — which is a manual tooling step
 * and silently fails in development setups that edit the `.po` but
 * never run `msgfmt`. So the content pool is now a first-class per-
 * language array embedded in this class. Reliable regardless of
 * translation-tooling state; easy to extend when a new locale is
 * added.
 *
 * Adding a new language: add a key to `TITLES_BY_LANGUAGE` plus the
 * matching `DESCRIPTION_SUFFIX_BY_LANGUAGE` entry. `resolveLanguage()`
 * falls back from the full locale (`nl_NL`) → language prefix match
 * (`nl_*` → `nl_NL`) → English canonical.
 */
class GoalGenerator {

    /**
     * @var array<string, string[]>
     *
     * Pool curated for youth football (JO8–JO19): technical fundamentals,
     * age-appropriate decision-making, youth-relevant physical work
     * (acceleration, body shielding — not senior-football "VO2 recovery"
     * vocabulary), and concrete behaviour goals. Heading-related goals
     * are deliberately absent because heading is restricted/banned in
     * most youth football regulations (FA, KNVB, UEFA) under U12 and
     * limited U13–U18.
     */
    private const TITLES_BY_LANGUAGE = [
        'en_US' => [
            'Improve weak foot passing',
            'First-touch control under pressure',
            'Close ball control while dribbling at speed',
            'Receiving and turning out of pressure',
            'Acceleration over the first 5 metres',
            'Striking technique — placing shots into the corners',
            '1v1 defending consistency',
            'Choosing when to dribble vs when to pass',
            'Tracking opponents in transition',
            'Composure when behind in a match',
            'Communication when defending',
            'Bouncing back after a mistake — focus on the next play',
            'Shielding the ball using body position',
            'Set-piece delivery accuracy',
        ],
        'nl_NL' => [
            'Passing met de zwakke voet verbeteren',
            'Aanname onder druk',
            'Bal aan de voet houden tijdens snel dribbelen',
            'Aannemen en uit de druk draaien',
            'Acceleratie over de eerste 5 meter',
            'Schiettechniek — schoten in de hoeken plaatsen',
            'Constantheid in 1-op-1 verdedigen',
            'Kiezen tussen dribbelen of passen',
            'Loopacties volgen in de omschakeling',
            'Kalmte bewaren bij een achterstand',
            'Communicatie tijdens het verdedigen',
            'Herstellen na een fout — meteen weer scherp',
            'De bal afschermen met je lichaam',
            'Nauwkeurigheid bij standaardsituaties',
        ],
    ];

    /** @var array<string, string> */
    private const DESCRIPTION_SUFFIX_BY_LANGUAGE = [
        'en_US' => 'Track progress weekly with the coach.',
        'nl_NL' => 'Wekelijks voortgang bespreken met de coach.',
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var array<string,int> */
    private array $users;

    private string $language;

    /**
     * @param object[] $players
     * @param array<string,int> $users slot => user id
     * @param string $language WP locale used as target for goal titles + description suffix.
     */
    public function __construct( DemoBatchRegistry $registry, array $players, array $users, string $language = '' ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->users    = $users;
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $status_labels   = $this->lookupLabels( 'goal_status' );
        $priority_labels = $this->lookupLabels( 'goal_priority' );

        $default_status = $this->firstMatching( $status_labels, [ 'In Progress', 'In progress', 'Pending' ] )
            ?: ( $status_labels[0] ?? 'In Progress' );
        $default_priority = $this->firstMatching( $priority_labels, [ 'Medium' ] )
            ?: ( $priority_labels[0] ?? 'Medium' );

        $author_id = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $resolved_language  = self::resolveLanguage( $this->language );
        $titles             = self::TITLES_BY_LANGUAGE[ $resolved_language ];
        $description_suffix = self::DESCRIPTION_SUFFIX_BY_LANGUAGE[ $resolved_language ];

        $total = 0;
        foreach ( $this->players as $p ) {
            $goal_count = mt_rand( 1, 2 );
            $used_titles = [];
            for ( $i = 0; $i < $goal_count; $i++ ) {
                $title = $this->pickTitle( $titles, $used_titles );
                $used_titles[ $title ] = true;

                $status   = $this->pickStatus( $status_labels, $default_status );
                $priority = $this->pickPriority( $priority_labels, $default_priority );

                $days_ahead = mt_rand( 14, 90 );
                $due_date = gmdate( 'Y-m-d', strtotime( "+{$days_ahead} days" ) ?: time() );

                $wpdb->insert( "{$wpdb->prefix}tt_goals", [
                    'club_id'     => CurrentClub::id(),
                    'player_id'   => (int) $p->id,
                    'title'       => $title,
                    'description' => $title . '. ' . $description_suffix,
                    'status'      => $status,
                    'priority'    => $priority,
                    'due_date'    => $due_date,
                    'created_by'  => $author_id,
                ] );
                $goal_id = (int) $wpdb->insert_id;
                if ( $goal_id ) {
                    $this->registry->tag( 'goal', $goal_id, [
                        'player_id' => (int) $p->id,
                        'status'    => $status,
                        'language'  => $resolved_language,
                    ] );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * Map a WP locale (e.g. `nl_NL`, `nl_BE`, `de_DE`) to the closest
     * supported content-language key. Full locale match first, then
     * language-prefix match against any installed key, then en_US.
     */
    public static function resolveLanguage( string $locale ): string {
        if ( $locale !== '' && isset( self::TITLES_BY_LANGUAGE[ $locale ] ) ) {
            return $locale;
        }
        $prefix = substr( $locale, 0, 2 );
        if ( $prefix !== '' ) {
            foreach ( array_keys( self::TITLES_BY_LANGUAGE ) as $key ) {
                if ( substr( (string) $key, 0, 2 ) === $prefix ) return (string) $key;
            }
        }
        return 'en_US';
    }

    /** @return string[] */
    public static function supportedLanguages(): array {
        return array_keys( self::TITLES_BY_LANGUAGE );
    }

    /** @return string[] */
    private function lookupLabels( string $type ): array {
        $items = QueryHelpers::get_lookups( $type );
        $out = [];
        foreach ( $items as $it ) {
            $out[] = (string) $it->name;
        }
        return $out;
    }

    /**
     * @param string[] $available
     * @param string[] $preferred
     */
    private function firstMatching( array $available, array $preferred ): ?string {
        foreach ( $preferred as $want ) {
            if ( in_array( $want, $available, true ) ) return $want;
        }
        return null;
    }

    /**
     * @param string[] $pool Translated title pool under the active language.
     * @param array<string,true> $used
     */
    private function pickTitle( array $pool, array $used ): string {
        if ( ! $pool ) return '';
        for ( $tries = 0; $tries < 40; $tries++ ) {
            $title = $pool[ mt_rand( 0, count( $pool ) - 1 ) ];
            if ( ! isset( $used[ $title ] ) ) return $title;
        }
        return $pool[0];
    }

    /** @param string[] $available */
    private function pickStatus( array $available, string $default ): string {
        if ( ! $available ) return $default;
        // 60% in-progress, 20% completed, 15% pending, 5% on-hold
        $roll = mt_rand( 1, 100 );
        if ( $roll <= 60 ) return $this->firstMatching( $available, [ 'In Progress', 'In progress' ] ) ?: $default;
        if ( $roll <= 80 ) return $this->firstMatching( $available, [ 'Completed', 'Achieved' ] ) ?: $default;
        if ( $roll <= 95 ) return $this->firstMatching( $available, [ 'Pending', 'Not started' ] ) ?: $default;
        return $this->firstMatching( $available, [ 'On Hold', 'On hold', 'Cancelled' ] ) ?: $default;
    }

    /** @param string[] $available */
    private function pickPriority( array $available, string $default ): string {
        if ( ! $available ) return $default;
        $roll = mt_rand( 1, 100 );
        if ( $roll <= 20 ) return $this->firstMatching( $available, [ 'High' ] )   ?: $default;
        if ( $roll <= 80 ) return $this->firstMatching( $available, [ 'Medium' ] ) ?: $default;
        return $this->firstMatching( $available, [ 'Low' ] ) ?: $default;
    }
}
