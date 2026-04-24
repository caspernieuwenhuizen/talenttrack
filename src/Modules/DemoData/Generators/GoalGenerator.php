<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * GoalGenerator — writes tt_goals.
 *
 * 1-2 goals per player, spread across status states: mostly in-progress,
 * some completed, some pending, occasional on-hold. Priority mix
 * roughly 20% High / 60% Medium / 20% Low.
 *
 * Goal titles come from a short domain list; descriptions are
 * derivative of the title plus a neutral sentence.
 */
class GoalGenerator {

    private const TITLES = [
        'Improve weak foot passing',
        'First-touch control under pressure',
        'Consistency in 1v1 defending',
        'Build up attacking headers',
        'Off-the-ball positioning',
        'Scanning before receiving',
        'Finishing from cutbacks',
        'Tracking runners in transition',
        'Set-piece delivery accuracy',
        'Shape under high press',
        'Fitness — recover faster between sprints',
        'Leadership — communicate more on pitch',
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var array<string,int> */
    private array $users;

    /**
     * @param object[] $players
     * @param array<string,int> $users slot => user id
     */
    public function __construct( DemoBatchRegistry $registry, array $players, array $users ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->users    = $users;
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

        $total = 0;
        foreach ( $this->players as $p ) {
            $goal_count = mt_rand( 1, 2 );
            $used_titles = [];
            for ( $i = 0; $i < $goal_count; $i++ ) {
                $title = $this->pickTitle( $used_titles );
                $used_titles[ $title ] = true;

                $status   = $this->pickStatus( $status_labels, $default_status );
                $priority = $this->pickPriority( $priority_labels, $default_priority );

                $days_ahead = mt_rand( 14, 90 );
                $due_date = gmdate( 'Y-m-d', strtotime( "+{$days_ahead} days" ) ?: time() );

                $wpdb->insert( "{$wpdb->prefix}tt_goals", [
                    'player_id'   => (int) $p->id,
                    'title'       => $title,
                    'description' => $title . '. Track progress weekly with the coach.',
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
                    ] );
                    $total++;
                }
            }
        }
        return $total;
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

    /** @param array<string,true> $used */
    private function pickTitle( array $used ): string {
        for ( $tries = 0; $tries < 40; $tries++ ) {
            $title = self::TITLES[ mt_rand( 0, count( self::TITLES ) - 1 ) ];
            if ( ! isset( $used[ $title ] ) ) return $title;
        }
        return self::TITLES[0];
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
