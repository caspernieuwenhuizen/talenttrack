<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GridLayout — ordered list of WidgetSlots making up a persona's grid.
 *
 * The grid itself is virtual — placement is by (x, y) for desktop and
 * by mobile_priority for the collapsed mobile stack. The class stays
 * dumb on purpose: rendering decisions live in GridRenderer.
 */
final class GridLayout {

    /** @var list<WidgetSlot> */
    private array $slots;

    /** @param list<WidgetSlot> $slots */
    public function __construct( array $slots = [] ) {
        $this->slots = array_values( $slots );
    }

    /** @return list<WidgetSlot> */
    public function slots(): array {
        return $this->slots;
    }

    public function add( WidgetSlot $slot ): void {
        $this->slots[] = $slot;
    }

    public function isEmpty(): bool {
        return $this->slots === [];
    }

    /** @return list<WidgetSlot> ordered for desktop render (y, then x). */
    public function desktopOrder(): array {
        $copy = $this->slots;
        usort( $copy, static function ( WidgetSlot $a, WidgetSlot $b ): int {
            if ( $a->y !== $b->y ) return $a->y <=> $b->y;
            return $a->x <=> $b->x;
        } );
        return $copy;
    }

    /** @return list<WidgetSlot> ordered for mobile stack (priority, then grid). */
    public function mobileOrder(): array {
        $copy = array_values( array_filter( $this->slots, static fn( WidgetSlot $s ): bool => $s->mobile_visible ) );
        usort( $copy, static function ( WidgetSlot $a, WidgetSlot $b ): int {
            if ( $a->mobile_priority !== $b->mobile_priority ) return $a->mobile_priority <=> $b->mobile_priority;
            if ( $a->y !== $b->y ) return $a->y <=> $b->y;
            return $a->x <=> $b->x;
        } );
        return $copy;
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return array_map( static fn( WidgetSlot $s ): array => $s->toArray(), $this->slots );
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function fromArray( array $rows ): self {
        $slots = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slots[] = WidgetSlot::fromArray( $row );
        }
        return new self( $slots );
    }
}
