<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\GoalStatus;

/**
 * GoalLifecycle (#4596) — the one SQL predicate that decides whether a goal
 * is open, modelled on {@see ActivityLifecycle}.
 *
 * `tt_goals.status` holds two spellings of the same status: the snake_case
 * codes the REST layer writes (`in_progress`) and the TitleCase names the
 * `goal_status` lookup is seeded with (`In Progress`), plus whatever an
 * operator added to the lookup. Every comparison therefore runs on the
 * normalised form, by the rule `LookupTranslator::normaliseName()` applies:
 * lower case, underscores and hyphens as spaces, whitespace collapsed. One
 * rule, so a status the lookup resolves is a status this counts.
 *
 * Open is `GoalStatus::OPEN` (owner decision on #4596). The custom-widget
 * goals source is the first consumer; the other places that define "open
 * goal" for themselves move onto this in a follow-up.
 *
 * The clauses are literal SQL built from constants and the caller's alias;
 * {@see self::statusClause()} carries its value as a placeholder.
 */
final class GoalLifecycle {

    /** A status, normalised the way the SQL below normalises the column. */
    public static function normalise( string $status ): string {
        return LookupTranslator::normaliseName( $status );
    }

    /**
     * The status column, normalised in SQL: lower case, `_` and `-` as
     * spaces, runs of spaces collapsed, trimmed. NULL stays NULL, so a goal
     * with no status matches no status and is not open.
     */
    public static function normalisedSql( string $alias = 'g' ): string {
        $col = self::column( $alias );
        $sql = "REPLACE( REPLACE( LOWER( {$col} ), '_', ' ' ), '-', ' ' )";
        // Three passes collapse up to eight spaces in a row, more than any
        // stored status carries.
        for ( $i = 0; $i < 3; $i++ ) $sql = "REPLACE( {$sql}, '  ', ' ' )";
        return "TRIM( {$sql} )";
    }

    /** "This goal is open." */
    public static function openClause( string $alias = 'g' ): string {
        $codes = array_map( static fn( string $code ): string => "'" . self::normalise( $code ) . "'", GoalStatus::OPEN );
        return self::normalisedSql( $alias ) . ' IN ( ' . implode( ', ', $codes ) . ' )';
    }

    /**
     * "This goal has this status", any spelling. The value is a `%s`
     * placeholder; pass `self::normalise( $status )` as its parameter.
     */
    public static function statusClause( string $alias = 'g' ): string {
        return self::normalisedSql( $alias ) . ' = %s';
    }

    private static function column( string $alias ): string {
        $alias = (string) preg_replace( '/[^A-Za-z0-9_]/', '', $alias );
        return $alias !== '' ? "{$alias}.status" : 'status';
    }
}
