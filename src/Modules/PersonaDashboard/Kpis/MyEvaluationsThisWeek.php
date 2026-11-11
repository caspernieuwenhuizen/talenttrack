<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Shared\Frontend\Components\BackLink;

class MyEvaluationsThisWeek extends AbstractKpiDataSource {
    public function id(): string { return 'my_evaluations_this_week'; }
    public function label(): string { return __( 'My evaluations this week', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    /**
     * The evaluations list, filtered to the evaluations this coach wrote
     * over the same 7 days compute() counts (#1213). #5048 — the card used
     * to open `my-evaluations`, the player and parent view, which refuses a
     * coach. The list accepts `filter[coach_id]` for the caller's own id.
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        return BackLink::appendTo( add_query_arg( [
            'filter' => [
                'coach_id'  => $ctx->user_id,
                'date_from' => self::daysBeforeToday( self::WINDOW_DAYS ),
            ],
        ], $ctx->viewUrl( $view ) ) );
    }

    /** The window compute() counts and linkUrl() filters, in days before today. */
    private const WINDOW_DAYS = 7;

    /**
     * A date `$days` before today, in the site's timezone (`current_time()`,
     * the clock `EvaluationDateRule` dates an evaluation against). A
     * negative `$days` is a date after today.
     */
    private static function daysBeforeToday( int $days ): string {
        $today = new \DateTimeImmutable( current_time( 'Y-m-d' ), wp_timezone() );
        $step  = new \DateInterval( 'P' . abs( $days ) . 'D' );
        return ( $days >= 0 ? $today->sub( $step ) : $today->add( $step ) )->format( 'Y-m-d' );
    }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        // tt_evaluations (migration 0001) exists on every install; no table check.
        // Some installs use `coach_id` for evaluation authorship rather than
        // `created_by`. Detect once per call and pick the right column.
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'created_by'",
            $table
        ) );
        $author_col = $col ? 'created_by' : 'coach_id';

        // v3.110.182 (#781) — demo-mode scope so coach's count matches
        // the evaluations list under the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );

        // #5048 — counted on `eval_date`, the date of the evaluation and the
        // column the list this card opens filters on (`filter[date_from]`).
        // On `created_at`, an evaluation entered today for a match two weeks
        // ago counted here and was missing from the list.
        $since = self::daysBeforeToday( self::WINDOW_DAYS );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // The list's other narrowing too: this club, and active evaluations
        // only (no archived or recycle-bin rows), the clause `list_evals`
        // applies on its default view.
        $active = ArchiveRepository::filterClause( 'active', 'e' );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} e WHERE e.club_id = %d AND {$active} AND e.{$author_col} = %d AND e.eval_date >= %s {$scope}",
            $club_id,
            $user_id,
            $since
        ) );

        // Sparkline: 4 trailing weekly buckets of *my* evaluations, on the
        // same date. The newest bucket is the count's window, so it runs
        // through today; each older one is the 7 days before it.
        $sparkline = [];
        for ( $w = 3; $w >= 0; $w-- ) {
            $start = self::daysBeforeToday( ( $w + 1 ) * self::WINDOW_DAYS );
            $end   = $w === 0 ? self::daysBeforeToday( -1 ) : self::daysBeforeToday( $w * self::WINDOW_DAYS );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sparkline[] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} e WHERE e.club_id = %d AND {$active} AND e.{$author_col} = %d AND e.eval_date >= %s AND e.eval_date < %s {$scope}",
                $club_id, $user_id, $start, $end
            ) );
        }
        $trend = null;
        if ( count( $sparkline ) >= 2 ) {
            $last = end( $sparkline );
            $prev = $sparkline[ count( $sparkline ) - 2 ];
            $trend = $last > $prev ? 'up' : ( $last < $prev ? 'down' : 'flat' );
        }
        return KpiValue::of( (string) $count, $trend, null, $sparkline );
    }
}
