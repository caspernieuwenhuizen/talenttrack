<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WidgetSlot — one placed widget on a persona's grid.
 *
 *   widget_id        — base widget type ("kpi_card", "navigation_tile").
 *   data_source      — optional secondary key ("active_players_total",
 *                      "my-team"). For navigation_tile this is the
 *                      tt_view slug; for kpi_card this is the KPI id;
 *                      for action_card this is the action id.
 *   size             — S | M | L | XL.
 *   x, y             — column start (0-11) + row start (0+).
 *   row_span         — 1-4 (XL hero = 2, XL data table = 3+).
 *   mobile_priority  — 1+ (lower first); ties break on grid order.
 *   mobile_visible   — false hides the slot below the tablet breakpoint.
 *   persona_label    — optional override for the displayed label
 *                      (only meaningful for navigation_tile + action_card).
 */
final class WidgetSlot {

    public string $widget_id;
    public string $data_source;
    public string $size;
    public int $x;
    public int $y;
    public int $row_span;
    public int $mobile_priority;
    public bool $mobile_visible;
    public string $persona_label;

    public function __construct(
        string $widget_id,
        string $data_source = '',
        string $size = Size::M,
        int $x = 0,
        int $y = 0,
        int $row_span = 1,
        int $mobile_priority = 50,
        bool $mobile_visible = true,
        string $persona_label = ''
    ) {
        $this->widget_id       = $widget_id;
        $this->data_source     = $data_source;
        $this->size            = Size::isValid( $size ) ? $size : Size::M;
        $this->x               = max( 0, min( 11, $x ) );
        $this->y               = max( 0, $y );
        $this->row_span        = max( 1, min( 4, $row_span ) );
        $this->mobile_priority = max( 1, $mobile_priority );
        $this->mobile_visible  = $mobile_visible;
        $this->persona_label   = $persona_label;
    }

    /**
     * Compose a `widget_id[:data_source]` ref (the form stored in JSON).
     */
    public function ref(): string {
        return $this->data_source !== '' ? $this->widget_id . ':' . $this->data_source : $this->widget_id;
    }

    /** @param array<string,mixed> $row */
    public static function fromArray( array $row ): self {
        $widget_ref = (string) ( $row['widget'] ?? $row['widget_id'] ?? '' );
        [ $widget_id, $data_source ] = self::splitRef( $widget_ref );
        return new self(
            $widget_id,
            $data_source,
            (string) ( $row['size'] ?? Size::M ),
            (int) ( $row['x'] ?? 0 ),
            (int) ( $row['y'] ?? 0 ),
            (int) ( $row['row_span'] ?? 1 ),
            (int) ( $row['mobile_priority'] ?? 50 ),
            (bool) ( $row['mobile_visible'] ?? true ),
            (string) ( $row['persona_label'] ?? '' )
        );
    }

    /** @return array{0:string,1:string} */
    public static function splitRef( string $ref ): array {
        if ( strpos( $ref, ':' ) === false ) return [ $ref, '' ];
        [ $left, $right ] = explode( ':', $ref, 2 );
        return [ $left, $right ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return [
            'widget'          => $this->ref(),
            'size'            => $this->size,
            'x'               => $this->x,
            'y'               => $this->y,
            'row_span'        => $this->row_span,
            'mobile_priority' => $this->mobile_priority,
            'mobile_visible'  => $this->mobile_visible,
            'persona_label'   => $this->persona_label,
        ];
    }
}
