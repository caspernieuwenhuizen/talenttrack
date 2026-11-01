<?php
namespace TT\Modules\Analytics\Reports\PlayerReportComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\RatingsBlockOptions;

/**
 * The ratings block (#4590): headline numbers and a per-category picture over
 * the window, from the evidence packet's evaluations.
 */
final class RatingsComposer {

    /** @return array<string,mixed> */
    public static function compose( PlayerReportContext $ctx ): array {
        $packet = $ctx->packet() ?? [];
        return self::ratings( (array) ( $packet['evaluations'] ?? [] ), RatingsBlockOptions::detail( $ctx->optionsFor( PlayerReportBlock::RATINGS ) ) );
    }

    /**
     * Headline numbers and a per-category picture over the window, from the
     * packet's evaluations — the same rows the Evidence tab lists, so the
     * averages here cannot disagree with the evaluations underneath them.
     *
     * #3989 — `has_subcategories` says whether anything in the window was
     * rated at subcategory level, whatever `$detail` is, so the panel knows
     * whether to offer the detailed view. With `$detail = 'sub'` (and
     * something to show), each main category carries `subcategories`: label,
     * latest, average and count, in the order the category tree gives them.
     * A main category rated only through its subcategories is listed too,
     * with no score of its own, so its subcategories have somewhere to sit.
     *
     * @param array<int|string,mixed> $evaluations newest first, as the packet orders them
     * @param array<int,array{label:string, order:int}>|null $tree category id =>
     *        its label and its place in the category tree; null reads it from
     *        the categories table, and only when subcategories are shown.
     * @return array<string,mixed>
     */
    public static function ratings( array $evaluations, string $detail = RatingsBlockOptions::MAIN, ?array $tree = null ): array {
        $overall = [];
        $by_cat  = [];
        $by_sub  = [];
        foreach ( $evaluations as $eval ) {
            if ( ! is_array( $eval ) ) continue;
            if ( isset( $eval['rating'] ) ) {
                $overall[] = [ 'date' => (string) ( $eval['eval_date'] ?? '' ), 'value' => (float) $eval['rating'] ];
            }
            foreach ( (array) ( $eval['categories'] ?? [] ) as $cat ) {
                if ( ! is_array( $cat ) ) continue;
                $id = (int) ( $cat['category_id'] ?? 0 );
                if ( $id <= 0 ) continue;

                if ( empty( $cat['is_main'] ) ) {
                    $parent = (int) ( $cat['parent_id'] ?? 0 );
                    if ( $parent <= 0 ) continue;
                    if ( ! isset( $by_sub[ $parent ][ $id ] ) ) {
                        $by_sub[ $parent ][ $id ] = [ 'category_id' => $id, 'label' => (string) ( $cat['label'] ?? '' ), 'latest' => (float) ( $cat['rating'] ?? 0 ), 'values' => [] ];
                    }
                    $by_sub[ $parent ][ $id ]['values'][] = (float) ( $cat['rating'] ?? 0 );
                    continue;
                }

                if ( ! isset( $by_cat[ $id ] ) ) {
                    // The first seen is the newest: the packet is newest-first.
                    $by_cat[ $id ] = [ 'category_id' => $id, 'label' => (string) ( $cat['label'] ?? '' ), 'latest' => (float) ( $cat['rating'] ?? 0 ), 'values' => [] ];
                }
                $by_cat[ $id ]['values'][] = (float) ( $cat['rating'] ?? 0 );
            }
        }

        $has_subs = $by_sub !== [];
        $detailed = $has_subs && $detail === RatingsBlockOptions::SUB;

        $categories = [];
        foreach ( $by_cat as $cat ) {
            $categories[] = [
                'category_id' => $cat['category_id'],
                'label'       => $cat['label'],
                'latest'      => $cat['latest'],
                'average'     => round( array_sum( $cat['values'] ) / count( $cat['values'] ), 1 ),
                'count'       => count( $cat['values'] ),
            ];
        }

        if ( $detailed ) {
            $tree = $tree ?? self::categoryTree();

            // A main category rated only through its subcategories.
            $mains = array_column( $categories, 'category_id' );
            $extra = array_values( array_diff( array_keys( $by_sub ), $mains ) );
            usort( $extra, static fn( int $a, int $b ): int => ( $tree[ $a ]['order'] ?? PHP_INT_MAX ) <=> ( $tree[ $b ]['order'] ?? PHP_INT_MAX ) ?: $a <=> $b );
            foreach ( $extra as $parent ) {
                $categories[] = [
                    'category_id' => $parent,
                    'label'       => $tree[ $parent ]['label'] ?? '',
                    'latest'      => null,
                    'average'     => null,
                    'count'       => 0,
                ];
            }

            foreach ( $categories as $i => $cat ) {
                $subs = array_values( $by_sub[ $cat['category_id'] ] ?? [] );
                // Tree order; a subcategory the tree does not know keeps the
                // order it was first seen in, after the ones it does.
                $seen = array_flip( array_column( $subs, 'category_id' ) );
                usort( $subs, static function ( array $a, array $b ) use ( $tree, $seen ): int {
                    $oa = $tree[ $a['category_id'] ]['order'] ?? PHP_INT_MAX;
                    $ob = $tree[ $b['category_id'] ]['order'] ?? PHP_INT_MAX;
                    return $oa <=> $ob ?: $seen[ $a['category_id'] ] <=> $seen[ $b['category_id'] ];
                } );
                $categories[ $i ]['subcategories'] = array_map( static fn( array $s ): array => [
                    'category_id' => $s['category_id'],
                    'label'       => $s['label'],
                    'latest'      => $s['latest'],
                    'average'     => round( array_sum( $s['values'] ) / count( $s['values'] ), 1 ),
                    'count'       => count( $s['values'] ),
                ], $subs );
            }
        }

        $values = array_column( $overall, 'value' );

        return [
            'evaluation_count'  => count( $evaluations ),
            'latest'            => $overall[0]['value'] ?? null,
            'latest_date'       => $overall[0]['date'] ?? null,
            'average'           => $values !== [] ? round( array_sum( $values ) / count( $values ), 1 ) : null,
            // Oldest first, so a trend line reads left to right.
            'series'            => array_reverse( $overall ),
            'categories'        => $categories,
            'evaluations'       => array_values( $evaluations ),
            'has_subcategories' => $has_subs,
            'detail'            => $detailed ? RatingsBlockOptions::SUB : RatingsBlockOptions::MAIN,
        ];
    }

    /**
     * Every category's label and place in the tree: main categories in their
     * order, each followed by its subcategories in theirs. Inactive ones are
     * included, because an evaluation from before a category was retired is
     * still evidence.
     *
     * @return array<int,array{label:string, order:int}>
     */
    private static function categoryTree(): array {
        $repo  = new \TT\Infrastructure\Evaluations\EvalCategoriesRepository();
        $rows  = $repo->getAll( false );
        $mains = [];
        $subs  = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_object( $row ) ) continue;
            if ( empty( $row->parent_id ) ) {
                $mains[] = $row;
            } else {
                $subs[ (int) ( $row->parent_id ?? 0 ) ][] = $row;
            }
        }

        $tree  = [];
        $order = 0;
        foreach ( $mains as $main ) {
            $id          = (int) ( $main->id ?? 0 );
            $tree[ $id ] = [ 'label' => \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) ( $main->label ?? '' ), $id ), 'order' => $order++ ];
            foreach ( $subs[ $id ] ?? [] as $sub ) {
                $sid          = (int) ( $sub->id ?? 0 );
                $tree[ $sid ] = [ 'label' => \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) ( $sub->label ?? '' ), $sid ), 'order' => $order++ ];
            }
        }
        return $tree;
    }
}
