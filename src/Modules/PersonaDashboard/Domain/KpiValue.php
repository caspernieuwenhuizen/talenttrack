<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KpiValue — the rendered output of a KPI data source.
 *
 * `current` is the headline number (always a string so KPIs that emit
 * percentages, ratios, and labels can share the type). `trend` is one
 * of 'up' | 'down' | 'flat' | null. `delta` is an optional secondary
 * label ("+3 since last month"). `sparkline` is a list of 4–12 numeric
 * points; the renderer interpolates a polyline. `is_available` is the
 * unavailable() escape hatch for KPIs whose backing epic hasn't shipped.
 */
final class KpiValue {

    public string $current;
    public ?string $trend;
    public ?string $delta;
    /** @var list<float> */
    public array $sparkline;
    public bool $is_available;

    /**
     * @param list<float> $sparkline
     */
    private function __construct(
        string $current,
        ?string $trend,
        ?string $delta,
        array $sparkline,
        bool $is_available
    ) {
        $this->current      = $current;
        $this->trend        = $trend;
        $this->delta        = $delta;
        $this->sparkline    = $sparkline;
        $this->is_available = $is_available;
    }

    /**
     * @param list<float> $sparkline
     */
    public static function of( string $current, ?string $trend = null, ?string $delta = null, array $sparkline = [] ): self {
        return new self( $current, $trend, $delta, $sparkline, true );
    }

    /** Placeholder for KPIs whose backing epic hasn't shipped yet. */
    public static function unavailable(): self {
        return new self( '—', null, null, [], false );
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return [
            'current'      => $this->current,
            'trend'        => $this->trend,
            'delta'        => $this->delta,
            'sparkline'    => $this->sparkline,
            'is_available' => $this->is_available,
        ];
    }
}
