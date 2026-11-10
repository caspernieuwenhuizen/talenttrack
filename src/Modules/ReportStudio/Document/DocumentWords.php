<?php
namespace TT\Modules\ReportStudio\Document;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Frontend\FrontendReportStudioView;
use TT\Shared\Dates\TTDate;

/**
 * DocumentWords (#5021) — the words a Studio document prints around a
 * block's figures: column headings and dates, for one document's subject and
 * period. The column headings are the ones the canvas shows, so a column
 * reads the same on the canvas and on paper.
 */
final class DocumentWords {

    /** @var array<string,string>|null */
    private ?array $columns = null;

    /** @param array<string,mixed> $context the document's `context` */
    public function __construct( private readonly array $context, private readonly int $userId ) {}

    public function from(): string {
        return is_string( $this->context['from'] ?? null ) ? (string) $this->context['from'] : '';
    }

    public function to(): string {
        return is_string( $this->context['to'] ?? null ) ? (string) $this->context['to'] : '';
    }

    /** A Y-m-d day in the academy's date format; the raw value when it is not one. */
    public function day( mixed $ymd ): string {
        $ymd = is_scalar( $ymd ) ? (string) $ymd : '';
        if ( $ymd === '' ) return '';
        $out = TTDate::date( $ymd );
        return $out !== '' ? $out : $ymd;
    }

    /**
     * A column's heading: the measure or breakdown the block names, else the
     * canvas's word for the payload key.
     *
     * @param array<string,mixed> $block the read block
     * @param array<string,mixed> $data  its payload
     */
    public function column( string $column, string $key, array $block, array $data ): string {
        if ( $column === 'value' && is_string( $data['measure_label'] ?? null ) && $data['measure_label'] !== '' ) {
            return (string) $data['measure_label'];
        }
        if ( $column === 'label' && is_string( $data['breakdown'] ?? null ) && $data['breakdown'] !== '' ) {
            $label = $this->breakdownLabel( $key, $block, (string) $data['breakdown'] );
            if ( $label !== '' ) return $label;
        }
        $this->columns ??= FrontendReportStudioView::columns();
        return $this->columns[ $column ] ?? _x( 'Value', 'report studio column', 'talenttrack' );
    }

    /** @param array<string,mixed> $block */
    private function breakdownLabel( string $key, array $block, string $breakdown ): string {
        $renderer = BlockRegistry::find( $key );
        if ( $renderer === null ) return '';
        $options  = is_array( $block['options'] ?? null ) ? $block['options'] : [];
        $fact     = is_scalar( $options['fact'] ?? null ) ? (string) $options['fact'] : '';
        $scope    = is_string( $this->context['scope'] ?? null ) ? (string) $this->context['scope'] : ReportContext::SCOPE_TEAM;
        $audience = is_string( $this->context['audience'] ?? null ) ? (string) $this->context['audience'] : PlayerReportAudience::INTERNAL;

        foreach ( $renderer->options( ReportContext::forListing( $scope, $audience, $this->userId ) ) as $option ) {
            if ( $option->key !== 'breakdown' ) continue;
            $set   = $fact !== '' && is_array( $option->choices[ $fact ] ?? null ) ? $option->choices[ $fact ] : [];
            $label = $set[ $breakdown ] ?? null;
            return is_string( $label ) ? $label : '';
        }
        return '';
    }
}
