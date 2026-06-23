<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TrainingEvalDefaults (#1643) — presentation policy for training
 * evaluations.
 *
 * For TRAINING evaluations the `mental` main category is surfaced
 * first and pre-expanded by default. This class is the single source
 * of that policy so the rule lives in a domain/config layer rather
 * than being hardcoded across the three rate surfaces (the activity-
 * first wizard, the player-first wizard, and the flat coach form).
 *
 * This is a default/presentation rule only: the coach can still rate
 * any category and is never blocked from saving without a mental
 * rating.
 */
final class TrainingEvalDefaults {

    /** Name of the training eval type in tt_lookups (lookup_type='eval_type'). */
    const TRAINING_TYPE_NAME = 'Training';

    /** Stable category_key of the category surfaced first + pre-expanded. */
    const PRIORITY_CATEGORY_KEY = 'mental';

    /**
     * True when an activity's `activity_type_key` denotes a training
     * activity.
     */
    public static function isTrainingActivityType( string $activity_type_key ): bool {
        return strtolower( trim( $activity_type_key ) ) === 'training';
    }

    /**
     * True when an eval type name is the training type. Case-insensitive
     * to tolerate localised / re-cased lookup names.
     */
    public static function isTrainingTypeName( string $eval_type_name ): bool {
        return strtolower( trim( $eval_type_name ) ) === strtolower( self::TRAINING_TYPE_NAME );
    }

    /**
     * Stable sort moving the priority (`mental`) row to the front while
     * preserving the relative order of every other row.
     *
     * Rows are stdClass objects carrying a `category_key` property. When
     * a row lacks that property the match is impossible, so the array is
     * returned unchanged.
     *
     * @param array $rows
     * @return array
     */
    public static function sortPriorityFirst( array $rows ): array {
        $priority = [];
        $rest     = [];
        foreach ( $rows as $row ) {
            if ( is_object( $row )
                && isset( $row->category_key )
                && (string) $row->category_key === self::PRIORITY_CATEGORY_KEY
            ) {
                $priority[] = $row;
            } else {
                $rest[] = $row;
            }
        }
        if ( empty( $priority ) ) {
            return $rows;
        }
        return array_merge( $priority, $rest );
    }

    /**
     * True when a category should render pre-expanded (Detailed) by
     * default under the training policy.
     */
    public static function shouldExpand( string $category_key ): bool {
        return $category_key === self::PRIORITY_CATEGORY_KEY;
    }
}
