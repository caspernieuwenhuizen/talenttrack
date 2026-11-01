<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\Fact;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\ReportStudio\Domain\BlockOption;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportSubjectAccess;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\SubjectFilters;

/**
 * Your own question (#4568) — one declared measure of one analytics fact,
 * optionally broken down by one declared dimension, for the block's subject
 * and period. Built on `FactQuery`, so it shares the explorer's measures,
 * its row scope and its row cap. Nobody composes an aggregation: the fact,
 * the measure and the breakdown are each picked from what the fact declares.
 */
final class OwnQuestionBlock implements ReportBlock {

    /**
     * The player-report section each fact belongs to. An external audience
     * is offered a fact only when its section is on that audience's list in
     * `PlayerReportAudience`; a fact with no section here never is.
     */
    private const FACT_SECTIONS = [
        'attendance'              => PlayerReportBlock::ATTENDANCE,
        'evaluations'             => PlayerReportBlock::RATINGS,
        'evaluations_per_session' => PlayerReportBlock::RATINGS,
        'goals'                   => PlayerReportBlock::GOALS,
    ];

    private const EXTERNAL_HIDDEN_BREAKDOWNS = [ 'player_id', 'evaluator_id' ];

    public function key(): string {
        return 'own_question';
    }

    public function label(): string {
        return __( 'Your own question', 'talenttrack' );
    }

    public function scopes(): array {
        return ReportContext::SCOPES;
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ];
    }

    public function capability(): string {
        return 'tt_view_analytics';
    }

    public function options( ReportContext $context ): array {
        $facts      = [];
        $measures   = [];
        $breakdowns = [];
        foreach ( $this->facts( $context ) as $fact ) {
            $facts[ $fact->key ] = $fact->label;
            foreach ( $fact->measures as $m ) {
                $measures[ $fact->key ][ $m->key ] = $m->label;
            }
            $breakdowns[ $fact->key ] = [];
            foreach ( $fact->dimensions as $d ) {
                // A breakdown by player is another child's figure, and one
                // by evaluator names staff, so neither reaches an external
                // audience.
                if ( $context->isExternal() && in_array( $d->key, self::EXTERNAL_HIDDEN_BREAKDOWNS, true ) ) continue;
                $breakdowns[ $fact->key ][ $d->key ] = $d->label;
            }
        }
        return [
            new BlockOption( 'fact', BlockOption::TYPE_ENUM, _x( 'Data', 'report studio option', 'talenttrack' ), $facts, true ),
            new BlockOption( 'measure', BlockOption::TYPE_MEASURE, _x( 'Measure', 'report studio option', 'talenttrack' ), $measures, true, null, 'fact' ),
            new BlockOption( 'breakdown', BlockOption::TYPE_BREAKDOWN, __( 'Break down by', 'talenttrack' ), $breakdowns, false, null, 'fact' ),
        ];
    }

    public function available( ReportContext $context ): bool {
        return $this->facts( $context ) !== [];
    }

    public function externalKeys(): array {
        return [ 'fact', 'measure', 'measure_label', 'unit', 'breakdown', 'rows', 'value', 'truncated' ];
    }

    public function render( ReportContext $context, array $options ): array {
        $fact    = FactRegistry::find( (string) ( $options['fact'] ?? '' ) );
        $measure = $fact?->measure( (string) ( $options['measure'] ?? '' ) );
        if ( $fact === null || $measure === null ) return [];
        // The options were validated against facts(); asked again so a
        // caller that skips the engine still cannot reach another fact.
        if ( ! in_array( $fact, $this->facts( $context ), true ) ) return [];

        $breakdown = is_string( $options['breakdown'] ?? null ) ? (string) $options['breakdown'] : '';
        $dim       = $breakdown !== '' ? $fact->dimension( $breakdown ) : null;
        $filters   = SubjectFilters::for( $context, $fact ) ?? [];

        $result = SharedReads::remember(
            'own_question:' . $fact->key . ':' . $measure->key . ':' . $breakdown,
            $context,
            static function () use ( $fact, $measure, $dim, $filters, $context ): array {
                $r = FactQuery::query( $fact->key, $dim !== null ? [ $dim->key ] : [], [ $measure->key ], $filters, ReportSubjectAccess::factScope( $context ) );
                return [ 'rows' => $r->rows, 'truncated' => $r->truncated ];
            }
        );

        $payload = [
            'fact'          => $fact->key,
            'measure'       => $measure->key,
            'measure_label' => $measure->label,
            'unit'          => $measure->unit,
            'breakdown'     => $dim?->key,
            'truncated'     => (bool) ( $result['truncated'] ?? false ),
        ];

        $source = (array) ( $result['rows'] ?? [] );
        if ( $dim === null ) {
            $first            = $source[0] ?? null;
            $payload['value'] = is_object( $first ) ? self::number( $first->{ $measure->key } ?? null ) : null;
            return $payload;
        }

        $values = [];
        foreach ( $source as $row ) {
            if ( is_object( $row ) ) $values[] = $row->{ $dim->key } ?? null;
        }
        DimensionValueResolver::prime( $dim, $values );
        $rows = [];
        foreach ( $source as $row ) {
            if ( ! is_object( $row ) ) continue;
            $raw    = $row->{ $dim->key } ?? null;
            $rows[] = [
                'key'   => $raw === null ? null : (string) $raw,
                'label' => DimensionValueResolver::resolve( $dim, $raw ),
                'value' => self::number( $row->{ $measure->key } ?? null ),
            ];
        }
        $payload['rows'] = $rows;
        return $payload;
    }

    /**
     * The facts this reader may ask about for this subject: readable with
     * the reader's scope, and carrying the dimension the subject narrows on.
     *
     * @return list<Fact>
     */
    private function facts( ReportContext $context ): array {
        $scope   = ReportSubjectAccess::factScope( $context );
        $allowed = PlayerReportAudience::allowlist( $context->audience );
        $out     = [];
        foreach ( FactRegistry::all() as $fact ) {
            if ( $allowed !== null ) {
                $section = self::FACT_SECTIONS[ $fact->key ] ?? null;
                if ( $section === null || ! in_array( $section, $allowed, true ) ) continue;
            }
            if ( ! FactQuery::canRead( $fact->key, $scope ) ) continue;
            if ( SubjectFilters::for( $context, $fact ) === null ) continue;
            $out[] = $fact;
        }
        return $out;
    }

    private static function number( mixed $value ): ?float {
        return is_numeric( $value ) ? round( (float) $value, 1 ) : null;
    }
}
