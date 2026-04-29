<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Widget — every shipped widget type implements this.
 *
 *   id()              — slug used in widget refs ("kpi_card", "navigation_tile:my-team").
 *   label()           — picker entry, already translated.
 *   defaultSize()     — Size::S | M | L | XL.
 *   allowedSizes()    — sizes the editor can resize the widget to.
 *   defaultMobilePriority() — 1 = first, higher = later in mobile stack.
 *   personaContext()  — academy / coach / player_parent — drives editor filter.
 *   capRequired()     — required user cap for the widget to render. Empty = none.
 *   moduleClass()     — module that owns this widget; disabled module hides it.
 *   render()          — outputs HTML for one rendered slot.
 */
interface Widget {

    public function id(): string;

    public function label(): string;

    public function defaultSize(): string;

    /** @return list<string> */
    public function allowedSizes(): array;

    public function defaultMobilePriority(): int;

    public function personaContext(): string;

    public function capRequired(): string;

    public function moduleClass(): string;

    public function render( WidgetSlot $slot, RenderContext $ctx ): string;
}
