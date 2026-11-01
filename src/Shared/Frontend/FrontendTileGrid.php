<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\Services\ActivityGridLink;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\TileGridStandard;
use TT\Shared\Frontend\Components\TileIconChip;
use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendTileGrid — v2.21.0 tile landing page for the frontend
 * dashboard shortcode.
 *
 * When a user lands on the dashboard without a specific ?tt_view
 * query param, they see a grid of tiles appropriate to their role:
 *
 *   - Player      → my card, my team, my evals, my sessions, my goals, my profile
 *   - Coach       → teams, players, evals, sessions, goals, podium, rate cards, comparison
 *   - Admin       → coach tiles + "Go to admin" + access control
 *   - Observer    → same discovery as coach (cap-gated), write actions blocked inside
 *
 * Tile visibility is driven entirely by WordPress capabilities so the
 * same tile set automatically respects the tt_readonly_observer role
 * (same as coach for viewing; write-blocked at controller level).
 *
 * Tapping a tile appends ?tt_view=<slug> and reloads. Handling of the
 * sub-views is left to the existing Player/Coach dashboard classes —
 * this layer is pure navigation chrome.
 */
class FrontendTileGrid {

    /**
     * Render the tile grid for the current user. Assumes we're inside
     * a `<div class="tt-dashboard">` already.
     */
    public static function render(): void {
        $user_id = get_current_user_id();

        // #1992 — a parent (no own player row, ≥1 linked child) gets a
        // CHILD-SCOPED rail, not the player-self Me-tiles, and no empty
        // "work of today" column. The decision (who is a parent viewer,
        // which child, which tiles) lives in the domain layer (§4); this
        // view only paints.
        if ( \TT\Infrastructure\Players\ParentChildResolver::isParentViewer( $user_id ) ) {
            self::renderParentGrid( $user_id );
            return;
        }

        $greeting = self::greeting( $user_id );

        // #0033 finalisation — tiles come from `TileRegistry`. The
        // registry filters by module-enabled state and per-user
        // capability; we only resolve URLs from the per-tile
        // `view_slug` against the current request's base URL.
        $base   = self::shortcodeBaseUrl();
        $groups = TileRegistry::tilesForUserGrouped( $user_id );
        foreach ( $groups as &$group ) {
            foreach ( $group['tiles'] as &$tile ) {
                if ( ! isset( $tile['url'] ) || $tile['url'] === '' ) {
                    $slug = (string) ( $tile['view_slug'] ?? '' );
                    $tile['url'] = $slug !== ''
                        ? add_query_arg( 'tt_view', $slug, $base )
                        : '';
                }
            }
            unset( $tile );
        }
        unset( $group );

        // #0036 — tile scale (50–150) drives icon + font sizes via a single
        // CSS custom property. 100 = baseline. #1587 — grid/card *layout*
        // (min-width, gap, padding, min-height, radius) now comes from the
        // academy-wide Tile appearance preset via TileGridStandard; the
        // legacy `tile_scale` is folded into the preset's min-width there,
        // and still drives typography here.
        $scale = (int) QueryHelpers::get_config( 'tile_scale', '100' );
        if ( $scale < 50 || $scale > 150 ) $scale = 100;
        $scale_factor = $scale / 100;

        // #1587 — emit the shared tile-grid standard CSS once; the dashboard
        // grid/card consume the same `--tt-tile-*` custom properties.
        echo TileGridStandard::styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static trusted CSS.

        ?>
        <style>
        .tt-ftile-grid-wrap { --tt-tile-scale: <?php echo esc_html( (string) $scale_factor ); ?>; <?php echo esc_html( TileGridStandard::cssVars() ); ?> }
        /* #1612 — the canvas shell (#1590) removed the theme's max-width, so on
           wide monitors the 2-column daily-use block stretched and the work
           groups reflowed 4–5 wide and sprawled right into the rail's zone.
           Contain the whole dashboard at the mockup's ~1320px so work groups
           stay in a consistent left column and "Setup & administration" aligns
           under the same width. No effect below 1320px → mobile unchanged. */
        .tt-ftile-grid-wrap { max-width: 1320px; }
        .tt-ftile-greeting {
            font-size: calc(17px * var(--tt-tile-scale));
            font-weight: 600;
            margin: 16px 0 14px;
            color: var(--tt-ink, #0e1a14);
        }
        .tt-ftile-section-label {
            font-size: calc(10px * var(--tt-tile-scale));
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--tt-primary-deep, #07261c);
            margin: calc(18px * var(--tt-tile-scale)) 0 calc(8px * var(--tt-tile-scale));
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tt-ftile-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--tt-line, #e3e6e1);
        }
        .tt-ftile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(var(--tt-tile-min-width, 220px), 1fr));
            gap: var(--tt-tile-gap, 10px);
        }
        .tt-ftile {
            display: flex;
            align-items: center;
            gap: calc(11px * var(--tt-tile-scale));
            background: var(--tt-paper, #fff);
            border: 1px solid var(--tt-line, #e3e6e1);
            border-left: 3px solid var(--tt-primary, #0b3d2e);
            border-radius: var(--tt-tile-radius, 12px);
            padding: var(--tt-tile-padding, 14px);
            text-decoration: none;
            color: var(--tt-ink, #0e1a14);
            min-height: var(--tt-tile-min-height, 76px);
            transition: transform 180ms cubic-bezier(0.2, 0.8, 0.2, 1),
                        box-shadow 180ms ease,
                        border-color 180ms ease;
        }
        .tt-ftile:hover, .tt-ftile:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(11, 61, 46, 0.10);
            border-color: var(--tt-line, #e3e6e1);
            color: var(--tt-ink, #0e1a14);
        }
        /* #1553 — tile icons render as Phosphor duotone glyphs inside an
           accent-tinted chip (`.tt-tile-chip`, shared via TileIconChip).
           The chip's own CSS drives box + glyph sizing; nothing further
           is needed here. */
        .tt-ftile-body {
            flex: 1;
            min-width: 0;
        }
        .tt-ftile-label {
            font-weight: 600;
            font-size: calc(14px * var(--tt-tile-scale));
            line-height: 1.25;
            margin: 0 0 calc(2px * var(--tt-tile-scale));
            color: var(--tt-ink, #0e1a14);
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .tt-ftile-desc {
            color: var(--tt-muted, #6a6d66);
            font-size: calc(12px * var(--tt-tile-scale));
            line-height: 1.35;
            margin: 0;
            word-break: break-word;
            overflow-wrap: anywhere;
            /* #1490 — safety net: clamp to 2 lines so an over-long
               description can never break the tile layout. */
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        @media (max-width: 480px) {
            .tt-ftile-grid { grid-template-columns: 1fr; }
        }

        /* #1598 — "stacked" tile layout: icon + title share the first line,
           description spans the full tile width beneath. The icon chip is
           sized to ~two title rows so a long title wraps next to it rather
           than widening the tile. Scoped to the active layout via the
           wrapper's data-tt-tile-layout attribute; "row" is unchanged. */
        [data-tt-tile-layout="stacked"] .tt-ftile {
            flex-direction: column;
            align-items: stretch;
            gap: calc(8px * var(--tt-tile-scale));
        }
        [data-tt-tile-layout="stacked"] .tt-ftile-head {
            display: flex;
            align-items: center;
            gap: calc(10px * var(--tt-tile-scale));
        }
        [data-tt-tile-layout="stacked"] .tt-ftile-head .tt-ftile-label {
            flex: 1;
            min-width: 0;
            margin: 0;
            /* allow the title to wrap to two rows beside the icon */
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        [data-tt-tile-layout="stacked"] .tt-tile-chip {
            width: calc(2.5rem * var(--tt-tile-scale, 1));
            height: calc(2.5rem * var(--tt-tile-scale, 1));
        }
        [data-tt-tile-layout="stacked"] .tt-tile-chip .tt-tile-chip__glyph {
            width: calc(1.5rem * var(--tt-tile-scale, 1));
            height: calc(1.5rem * var(--tt-tile-scale, 1));
        }
        [data-tt-tile-layout="stacked"] .tt-ftile-desc {
            /* full width below the icon row → room for an extra line */
            -webkit-line-clamp: 3;
            line-clamp: 3;
        }

        /* #1809 — academy-wide tile colour schemes. A third axis, independent
           of size preset and layout: only the tile's colours (border, fill,
           accent) change. Scoped to the active scheme via the wrapper's
           data-tt-tile-scheme attribute. "default" emits no rules — the base
           .tt-ftile above already renders it (white fill, hairline border,
           3px green left-accent). Tokens only, no raw hex. */

        /* A · Full brand border — white fill, full 1.5px green outline. The
           1.5px border + left-accent override beat the base's 1px / 3px. */
        [data-tt-tile-scheme="border"] .tt-ftile {
            border: 1.5px solid var(--tt-primary, #0b3d2e);
            border-left-width: 1.5px;
        }
        [data-tt-tile-scheme="border"] .tt-ftile:hover,
        [data-tt-tile-scheme="border"] .tt-ftile:focus {
            background: color-mix(in srgb, var(--tt-primary, #0b3d2e) 5%, #fff);
        }

        /* B · Gold-topped (default) — green border + 3px gold top edge,
           echoing the dashboard bar's gold underline. */
        [data-tt-tile-scheme="gold-topped"] .tt-ftile {
            border: 1.5px solid var(--tt-primary, #0b3d2e);
            border-top: 3px solid var(--tt-secondary, #e8b624);
            border-left-width: 1.5px;
        }
        [data-tt-tile-scheme="gold-topped"] .tt-ftile:hover,
        [data-tt-tile-scheme="gold-topped"] .tt-ftile:focus {
            background: color-mix(in srgb, var(--tt-primary, #0b3d2e) 5%, #fff);
        }

        /* C · Soft green fill — light green tint, green border, white chip. */
        [data-tt-tile-scheme="soft-fill"] .tt-ftile {
            background: color-mix(in srgb, var(--tt-primary, #0b3d2e) 7%, #fff);
            border: 1px solid color-mix(in srgb, var(--tt-primary, #0b3d2e) 25%, var(--tt-line, #e3e6e1));
            border-left-width: 1px;
        }
        [data-tt-tile-scheme="soft-fill"] .tt-ftile .tt-tile-chip {
            background: #fff;
        }
        [data-tt-tile-scheme="soft-fill"] .tt-ftile:hover,
        [data-tt-tile-scheme="soft-fill"] .tt-ftile:focus {
            background: color-mix(in srgb, var(--tt-primary, #0b3d2e) 12%, #fff);
        }

        /* D · Solid green — tiles match the top bar: dark green fill, gold
           bottom accent, white text, translucent chip. */
        [data-tt-tile-scheme="solid"] .tt-ftile {
            background: var(--tt-primary, #0b3d2e);
            border: 1px solid var(--tt-primary-deep, #07261c);
            border-bottom: 3px solid var(--tt-secondary, #e8b624);
            border-left-width: 1px;
            color: #fff;
        }
        [data-tt-tile-scheme="solid"] .tt-ftile .tt-ftile-label {
            color: #fff;
        }
        [data-tt-tile-scheme="solid"] .tt-ftile .tt-ftile-desc {
            color: #cfe0d7;
        }
        [data-tt-tile-scheme="solid"] .tt-ftile .tt-tile-chip {
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
        }
        [data-tt-tile-scheme="solid"] .tt-ftile:hover,
        [data-tt-tile-scheme="solid"] .tt-ftile:focus {
            background: var(--tt-primary-deep, #07261c);
        }

        /* F · Federation (#2512) — institutional: white card, a 3px brand top
           edge echoing the header band, and ONE chip colour instead of the
           per-module hues, so the glyph carries the meaning rather than an
           arbitrary palette. Gold is held back for hover, which keeps it
           meaning "this one" rather than decorating every tile at rest.
           Reads --tt-primary like the other schemes, so it stands on its own
           under the default theme too. */
        [data-tt-tile-scheme="federation"] .tt-ftile {
            background: #fff;
            border: 1px solid var(--tt-line, #e3e6e1);
            border-top: 3px solid var(--tt-primary, #0b3d2e);
            border-left-width: 1px;
        }
        [data-tt-tile-scheme="federation"] .tt-ftile .tt-tile-chip {
            /* Overrides the inline per-tile --tt-chip-accent by setting the
               painted properties directly — the custom property is inline,
               so only this wins without reaching for !important. */
            background: color-mix(in srgb, var(--tt-primary, #0b3d2e) 10%, #fff);
            color: var(--tt-primary, #0b3d2e);
        }
        [data-tt-tile-scheme="federation"] .tt-ftile:hover,
        [data-tt-tile-scheme="federation"] .tt-ftile:focus {
            border-top-color: var(--tt-secondary, #e8b624);
            background: color-mix(in srgb, var(--tt-primary, #0b3d2e) 4%, #fff);
        }

        /* E · Left accent — white fill, thick 4px green left edge that turns
           gold on hover. Closest to today, still clearly branded. */
        [data-tt-tile-scheme="left-accent"] .tt-ftile {
            border-left: 4px solid var(--tt-primary, #0b3d2e);
        }
        [data-tt-tile-scheme="left-accent"] .tt-ftile:hover,
        [data-tt-tile-scheme="left-accent"] .tt-ftile:focus {
            border-left-color: var(--tt-secondary, #e8b624);
            background: color-mix(in srgb, var(--tt-primary, #0b3d2e) 4%, #fff);
        }

        /* #1603 — desktop 2-column daily-use layout. Mobile-first base is a
           single column: the left work groups stack, then the "My work"
           rail follows beneath them. The rail drops to the bottom because
           it is the second DOM child. At >=1024px the section becomes a
           2-column grid (work groups left, sticky rail right). */
        .tt-ftile-daily {
            display: block;
        }
        .tt-ftile-rail {
            margin-top: calc(18px * var(--tt-tile-scale));
        }
        .tt-ftile-mywork {
            border: 1px solid var(--tt-line, #e3e6e1);
            border-radius: var(--tt-tile-radius, 12px);
            /* #1612 — subtly tinted panel background (mockup's .panel) so the
               "My work" rail reads as a separate panel, distinct from the
               white work-group cards. Rows stay white on the hover/focus
               states above. */
            background: var(--tt-bg-soft, #f4f6f3);
            padding: calc(12px * var(--tt-tile-scale));
        }
        .tt-ftile-mywork-head {
            font-size: calc(10px * var(--tt-tile-scale));
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--tt-primary-deep, #07261c);
            margin: 0 0 calc(8px * var(--tt-tile-scale));
        }
        .tt-ftile-mywork-rows {
            display: flex;
            flex-direction: column;
            gap: calc(4px * var(--tt-tile-scale));
        }
        .tt-ftile-mywork-row {
            display: flex;
            align-items: center;
            gap: calc(10px * var(--tt-tile-scale));
            min-height: 48px;
            padding: calc(6px * var(--tt-tile-scale)) calc(8px * var(--tt-tile-scale));
            border-left: 3px solid var(--tt-secondary, #e8b624);
            border-radius: var(--tt-tile-radius, 12px);
            background: var(--tt-paper, #fff);
            text-decoration: none;
            color: var(--tt-ink, #0e1a14);
            touch-action: manipulation;
            transition: background-color 180ms ease;
        }
        .tt-ftile-mywork-row:hover,
        .tt-ftile-mywork-row:focus,
        .tt-ftile-mywork-row:active {
            background: var(--tt-bg-soft, #f4f6f3);
            color: var(--tt-ink, #0e1a14);
        }
        .tt-ftile-mywork-row:focus-visible {
            outline: 2px solid var(--tt-primary, #0b3d2e);
            outline-offset: 2px;
        }
        .tt-ftile-mywork-row .tt-tile-chip {
            width: calc(2.25rem * var(--tt-tile-scale, 1));
            height: calc(2.25rem * var(--tt-tile-scale, 1));
        }
        .tt-ftile-mywork-row .tt-tile-chip .tt-tile-chip__glyph {
            width: calc(1.375rem * var(--tt-tile-scale, 1));
            height: calc(1.375rem * var(--tt-tile-scale, 1));
        }
        .tt-ftile-mywork-label {
            flex: 1;
            min-width: 0;
            font-weight: 600;
            font-size: calc(14px * var(--tt-tile-scale));
            line-height: 1.25;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        @media (min-width: 1024px) {
            .tt-ftile-daily--has-rail {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 320px;
                gap: calc(20px * var(--tt-tile-scale));
                align-items: start;
            }
            .tt-ftile-daily--has-rail .tt-ftile-daily-main { min-width: 0; }
            .tt-ftile-daily--has-rail .tt-ftile-rail {
                margin-top: 0;
                position: sticky;
                top: calc(16px + env(safe-area-inset-top, 0px));
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .tt-ftile-mywork-row { transition: none; }
        }
        </style>
        <?php echo TileIconChip::styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static trusted CSS. ?>

        <div class="tt-ftile-grid-wrap" <?php echo TileGridStandard::layoutAttr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — layoutAttr escapes its own value. ?> <?php echo TileGridStandard::styleAttr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — styleAttr escapes its own value. ?>>
        <div class="tt-ftile-greeting"><?php echo esc_html( $greeting ); ?></div>

        <?php
        // #0033 Sprint 4 — split groups into "Today's work" + "Setup &
        // administration" sections. Daily-use tiles (Me / Tasks / People
        // / Performance / Analytics) go up top, open by default;
        // admin/configuration tiles (Development / Administration) go
        // into a collapsible section, open only for admin personas.
        [ $work_groups, $setup_groups ] = self::splitByKind( $groups );
        $is_admin_persona = current_user_can( 'tt_edit_settings' );

        // #1821 — the player persona's dashboard is just their own journey
        // surfaces: render their work as tiles under "Today's work", not a
        // side rail. Keep the rail for every other persona. #4449 — the
        // persona the landing dresses as, so the legacy grid and the persona
        // dashboard agree on who is a player, switcher included.
        $is_player = \TT\Modules\Authorization\PersonaResolver::landingPersona( $user_id ) === 'player';

        if ( $is_player ) {
            // Me-group tiles flow into the main "Today's work" grid; no rail.
            $left_groups = $work_groups;
            $me_group    = null;
        } else {
            // #1603 — peel the personal "Me" group out of the left column; it
            // renders as the sticky "My work" rail on the right at >=1024px and
            // drops to the bottom below that. The remaining work groups render
            // in the left column in the explicit order from TileRegistry.
            [ $left_groups, $me_group ] = self::peelMeGroup( $work_groups );
        }

        // #4379 — the coach's week. Who sees it and what each row needs is
        // decided in CoachWeek; this view only paints the rows.
        $week = $is_player ? null : CoachWeek::forUser( $user_id );
        if ( $week !== null && ! $week['visible'] ) $week = null;
        if ( $week !== null ) {
            wp_enqueue_style( 'tt-frontend-coach-week', TT_PLUGIN_URL . 'assets/css/frontend-coach-week.css', [], TT_VERSION );
        }
        $daily_class = 'tt-ftile-daily'
            . ( $me_group !== null ? ' tt-ftile-daily--has-rail' : '' )
            . ( $week !== null ? ' tt-ftile-daily--has-week' : '' );
        ?>

        <details class="tt-ftile-section" open>
            <summary class="tt-ftile-section-summary"><?php esc_html_e( "Today's work", 'talenttrack' ); ?></summary>
            <div class="<?php echo esc_attr( $daily_class ); ?>">
                <?php if ( $week !== null ) self::renderWeekPanel( $week ); ?>
                <div class="tt-ftile-daily-main">
                    <?php self::renderGroups( $left_groups ); ?>
                </div>
                <?php if ( $me_group !== null ) : ?>
                    <aside class="tt-ftile-rail" aria-label="<?php echo esc_attr( (string) $me_group['label'] ); ?>">
                        <?php self::renderMyWorkPanel( $me_group ); ?>
                    </aside>
                <?php endif; ?>
            </div>
        </details>

        <?php if ( ! empty( $setup_groups ) ) : ?>
            <details class="tt-ftile-section"<?php echo $is_admin_persona ? ' open' : ''; ?>>
                <summary class="tt-ftile-section-summary"><?php esc_html_e( 'Setup & administration', 'talenttrack' ); ?></summary>
                <?php self::renderGroups( $setup_groups ); ?>
            </details>
        <?php endif; ?>
        </div>
        <style>
        .tt-ftile-section { margin-top: calc(14px * var(--tt-tile-scale)); }
        .tt-ftile-section-summary {
            font-size: calc(13px * var(--tt-tile-scale));
            font-weight: 700;
            letter-spacing: 0.04em;
            color: var(--tt-ink, #0e1a14);
            margin: 0 0 calc(8px * var(--tt-tile-scale));
            cursor: pointer;
            user-select: none;
        }
        .tt-ftile-section[open] > .tt-ftile-section-summary { color: var(--tt-primary-deep, #07261c); }
        </style>
        <?php
    }

    /**
     * Split rendered groups into work + setup buckets by each tile's own
     * `kind`, keeping the group heading on both sides when a group holds
     * some of each.
     *
     * v3.92.0 replaced a label-based heuristic ("Development" and
     * "Administration" are setup) with the tile's declared kind, but bucketed
     * whole groups: one `kind=work` tile pulled the entire group into
     * "Today's work". That is how trial tracks and letter templates — both
     * `setup`, both settings for the Trials module — came to sit beside the
     * operational Trial cases list (#3046). Splitting per tile is what lets
     * Configuration own the setup half without the work half following it.
     *
     * @param array<int, array{label:string, tiles:array}> $groups
     * @return array{0: list<array{label:string, tiles:list<array<string,mixed>>}>, 1: list<array{label:string, tiles:list<array<string,mixed>>}>}
     */
    private static function splitByKind( array $groups ): array {
        $work  = [];
        $setup = [];
        foreach ( $groups as $g ) {
            $w = [];
            $s = [];
            foreach ( $g['tiles'] as $t ) {
                if ( ( $t['kind'] ?? 'work' ) === 'setup' ) {
                    $s[] = $t;
                } else {
                    $w[] = $t;
                }
            }
            if ( $w !== [] ) $work[]  = [ 'label' => $g['label'], 'tiles' => $w ];
            if ( $s !== [] ) $setup[] = [ 'label' => $g['label'], 'tiles' => $s ];
        }
        return [ $work, $setup ];
    }

    /**
     * #1603 — separate the personal "Me" group from the rest of the
     * daily-use groups. The Me group is matched against the same `__('Me')`
     * source string registered in CoreSurfaceRegistration. Returns the
     * remaining groups (left column) plus the Me group (right rail), or
     * `null` for the rail when no Me group is visible for this user.
     *
     * @param array<int, array{label:string, tiles:array}> $groups
     * @return array{0: list<array{label:string, tiles:array}>, 1: array<string, mixed>|null}
     */
    private static function peelMeGroup( array $groups ): array {
        $me_label = __( 'Me', 'talenttrack' );
        $left     = [];
        $me       = null;
        foreach ( $groups as $g ) {
            if ( $me === null && (string) $g['label'] === $me_label ) {
                $me = $g;
                continue;
            }
            $left[] = $g;
        }
        return [ $left, $me ];
    }

    /**
     * #1603 — render the personal "Me" group as the "My work" rail: a
     * bordered panel with a clear heading and one compact row per tile
     * (accent-chip icon + title only, no description). Tile URLs / gating
     * are already resolved upstream; this is layout only.
     *
     * @param array{label:string, tiles:array} $group
     */
    private static function renderMyWorkPanel( array $group ): void {
        $tiles = $group['tiles'];
        if ( empty( $tiles ) ) {
            return;
        }
        ?>
        <div class="tt-ftile-mywork">
            <div class="tt-ftile-mywork-head"><?php esc_html_e( 'My work', 'talenttrack' ); ?></div>
            <div class="tt-ftile-mywork-rows">
                <?php foreach ( $tiles as $tile ) :
                    $chip  = TileIconChip::render(
                        (string) ( $tile['icon'] ?? '' ),
                        (string) ( $tile['color'] ?? '#0b3d2e' )
                    );
                    $label = esc_html( (string) ( $tile['label'] ?? '' ) );
                    $url   = esc_url( (string) ( $tile['url'] ?? '' ) );
                    // #2409 — same marker as the grid tiles, rail-sized.
                    $dev   = \TT\Shared\Frontend\Components\DevelopmentPill::badgeForViewSlug(
                        (string) ( $tile['view_slug'] ?? '' )
                    );
                    ?>
                    <a class="tt-ftile-mywork-row" href="<?php echo $url; ?>">
                        <?php echo $chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TileIconChip escapes its own attrs and IconRenderer returns trusted SVG. ?>
                        <span class="tt-ftile-mywork-label"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — esc_html'd above. ?></span>
                        <?php echo $dev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — DevelopmentPill escapes its own label + title. ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * #4379 — the "This week" panel: one row per activity, in date order,
     * from `CoachWeek::forUser()`. The service decides every state; this
     * turns them into words, chips and links.
     *
     * @param array<string, mixed> $week
     */
    private static function renderWeekPanel( array $week ): void {
        $rows     = is_array( $week['rows'] ?? null ) ? $week['rows'] : [];
        $start_ts = strtotime( (string) $week['week_start'] . ' 12:00:00' );
        $end_ts   = strtotime( (string) $week['week_end'] . ' 12:00:00' );
        $range    = $start_ts && $end_ts
            ? date_i18n( 'D j', $start_ts ) . ' – ' . date_i18n( 'D j M', $end_ts )
            : '';

        // #4492 — past days folded into one line that opens on tap or Enter.
        $folded = [];
        if ( ( $week['past'] ?? '' ) === 'fold' ) {
            $open = [];
            foreach ( $rows as $row ) {
                if ( ( (array) $row )['when'] === 'past' ) {
                    $folded[] = (array) $row;
                } else {
                    $open[] = $row;
                }
            }
            $rows = $open;
        }
        $next = is_array( $week['next_week'] ?? null ) ? $week['next_week'] : [];

        $classes = [ 'tt-week' ];
        if ( empty( $week['phone_on'] ) ) $classes[] = 'tt-week-off-phone';
        ?>
        <section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" aria-labelledby="tt-week-title">
            <div class="tt-week-head">
                <h2 class="tt-week-title" id="tt-week-title"><?php esc_html_e( 'This week', 'talenttrack' ); ?></h2>
                <?php if ( $range !== '' ) : ?>
                    <span class="tt-week-range"><?php echo esc_html( $range ); ?></span>
                <?php endif; ?>
            </div>
            <?php self::renderWeekSummary( $week ); ?>
            <?php if ( $rows !== [] || $folded !== [] ) : ?>
                <ul class="tt-week-list">
                    <?php if ( $folded !== [] ) : ?>
                        <li class="tt-week-fold-item">
                            <details class="tt-week-fold">
                                <summary class="tt-week-fold-summary"><?php echo self::weekFoldSummary( $week, $folded ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from escaped parts. ?></summary>
                                <ul class="tt-week-list">
                                    <?php foreach ( $folded as $row ) : ?>
                                        <li><?php self::renderWeekRow( $row, $week ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        </li>
                    <?php endif; ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <li><?php self::renderWeekRow( (array) $row, $week ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( empty( $week['has_match'] ) ) : ?>
                    <p class="tt-week-empty"><?php esc_html_e( 'No match this week.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            <?php else : ?>
                <p class="tt-week-empty"><?php esc_html_e( 'Nothing planned for your teams this week.', 'talenttrack' ); ?></p>
            <?php endif; ?>
            <?php self::renderWeekFollowUps( $week ); ?>
            <?php
            $next_class = self::weekDeviceClass( $week, 'next_week' );
            if ( $next !== [] && $next_class !== null ) :
                ?>
                <div class="<?php echo esc_attr( trim( 'tt-week-next ' . $next_class ) ); ?>">
                    <h3 class="tt-week-next-title"><?php esc_html_e( 'Next week', 'talenttrack' ); ?></h3>
                    <ul class="tt-week-list">
                        <?php foreach ( $next as $item ) : ?>
                            <li><?php self::renderWeekNextRow( (array) $item, ! empty( $week['multiple_teams'] ) ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * #4495 — the summary strip: trainings, matches and attendance for the
     * window, as three labelled figures. "—" when no register is recorded.
     *
     * @param array<string, mixed> $week
     */
    private static function renderWeekSummary( array $week ): void {
        $summary = is_array( $week['summary'] ?? null ) ? $week['summary'] : null;
        $class   = self::weekDeviceClass( $week, 'summary' );
        if ( $summary === null || $class === null ) return;

        $pct   = $summary['attendance_pct'] ?? null;
        $cells = [
            [ _x( 'Trainings', 'This week summary', 'talenttrack' ), (string) (int) ( $summary['trainings'] ?? 0 ) ],
            [ _x( 'Matches', 'This week summary', 'talenttrack' ), (string) (int) ( $summary['matches'] ?? 0 ) ],
            [ _x( 'Attendance', 'This week summary', 'talenttrack' ), $pct === null ? '—' : number_format_i18n( (float) $pct, 1 ) . '%' ],
        ];
        echo '<dl class="' . esc_attr( trim( 'tt-week-summary ' . $class ) ) . '">';
        foreach ( $cells as [ $label, $value ] ) {
            echo '<div class="tt-week-summary-cell"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
        }
        echo '</dl>';
    }

    /**
     * #4497 — "Follow-ups due this week": one line per follow-up block whose
     * field is set, each a link to the view it counts from. The service sets
     * a field only when the count is above 0 and the viewer may open that
     * view; the section shows only on a device where at least one line does.
     *
     * @param array<string, mixed> $week
     */
    private static function renderWeekFollowUps( array $week ): void {
        $lines = [];

        $pdp = $week['pdp_due'] ?? null;
        if ( is_array( $pdp ) ) {
            $n  = (int) ( $pdp['count'] ?? 0 );
            $ts = strtotime( (string) ( $pdp['closes'] ?? '' ) . ' 12:00:00' );
            $lines[] = [ 'pdp', 'pdp-planning', __( 'PDP conversations due', 'talenttrack' ), sprintf(
                /* translators: 1: number of players whose PDP talk is still to hold, 2: weekday the block closes */
                _n( '%1$d player · closes %2$s', '%1$d players · closes %2$s', $n, 'talenttrack' ),
                $n,
                $ts ? date_i18n( 'D', $ts ) : ''
            ) ];
        }
        $evals = $week['evals_due'] ?? null;
        if ( is_array( $evals ) ) {
            $n       = (int) ( $evals['count'] ?? 0 );
            $lines[] = [ 'evals', 'eval-coverage', __( 'Evaluations due', 'talenttrack' ), sprintf(
                /* translators: %d: number of players not evaluated in the open evaluation round */
                _n( '%d player not evaluated this round', '%d players not evaluated this round', $n, 'talenttrack' ),
                $n
            ) ];
        }
        $tasks = $week['tasks_due'] ?? null;
        if ( is_array( $tasks ) ) {
            $n       = (int) ( $tasks['count'] ?? 0 );
            $lines[] = [ 'tasks', 'my-tasks', __( 'My tasks due this week', 'talenttrack' ), sprintf(
                /* translators: %d: number of the viewer's tasks due this week */
                _n( '%d due', '%d due', $n, 'talenttrack' ),
                $n
            ) ];
        }

        $on_desktop = false;
        $on_phone   = false;
        $items      = [];
        foreach ( $lines as [ $block, $slug, $label, $text ] ) {
            $class = self::weekDeviceClass( $week, $block );
            if ( $class === null ) continue;
            if ( $class !== 'tt-week-off-desktop' ) $on_desktop = true;
            if ( $class !== 'tt-week-off-phone' ) $on_phone = true;
            $url     = BackLink::appendTo( add_query_arg( [ 'tt_view' => $slug ], RecordLink::dashboardUrl() ) ); /* tt-xview-ok — the block sets its field only for a viewer the view's gate admits */
            $items[] = '<li class="' . esc_attr( $class ) . '"><a class="tt-week-followup" href="' . esc_url( $url ) . '">'
                . '<span class="tt-week-followup-label">' . esc_html( $label ) . '</span> '
                . '<span class="tt-week-followup-count">' . esc_html( $text ) . '</span></a></li>';
        }
        $section = self::deviceClass( $on_desktop, $on_phone );
        if ( $section === null ) return;

        echo '<div class="' . esc_attr( trim( 'tt-week-followups ' . $section ) ) . '">';
        echo '<h3 class="tt-week-next-title">' . esc_html__( 'Follow-ups due this week', 'talenttrack' ) . '</h3>';
        echo '<ul class="tt-week-followup-list">' . implode( '', $items ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from escaped parts.
        echo '</div>';
    }

    /**
     * The one line that stands for the folded past days: which days, how
     * many activities, and whether their attendance is in.
     *
     * @param array<string, mixed>             $week
     * @param list<array<string, mixed>>       $rows
     */
    private static function weekFoldSummary( array $week, array $rows ): string {
        $days = [];
        foreach ( $rows as $row ) {
            $ts = strtotime( (string) ( $row['date'] ?? '' ) . ' 12:00:00' );
            if ( $ts ) $days[ date_i18n( 'D', $ts ) ] = true;
        }
        $count   = count( $rows );
        $missing = 0;
        $known   = 0;
        foreach ( $rows as $row ) {
            if ( ( $row['attendance_recorded'] ?? null ) === null ) continue;
            $known++;
            if ( $row['attendance_recorded'] === false ) $missing++;
        }

        return self::perDevice( static function ( string $device, string $class ) use ( $week, $days, $count, $missing, $known ): string {
            $parts   = [ implode( ', ', array_keys( $days ) ) ];
            /* translators: %d: number of activities on the days already past */
            $parts[] = sprintf( _n( '%d activity', '%d activities', $count, 'talenttrack' ), $count );
            if ( $known > 0 && in_array( 'attendance', (array) ( $week['blocks'][ $device ] ?? [] ), true ) ) {
                $parts[] = $missing === 0
                    ? __( 'attendance recorded', 'talenttrack' )
                    /* translators: %d: number of past activities whose attendance is not recorded */
                    : sprintf( _n( '%d without attendance', '%d without attendance', $missing, 'talenttrack' ), $missing );
            }
            return '<span class="' . esc_attr( trim( 'tt-week-fold-text ' . $class ) ) . '">' . esc_html( implode( ' · ', array_filter( $parts ) ) ) . '</span>';
        } );
    }

    /**
     * One activity of next week: day, type, what and when, as a link to the
     * activity.
     *
     * @param array<string, mixed> $item
     */
    private static function renderWeekNextRow( array $item, bool $multi_team ): void {
        $aid    = (int) ( $item['activity_id'] ?? 0 );
        $ts     = strtotime( (string) ( $item['date'] ?? '' ) . ' 12:00:00' );
        $what   = ! empty( $item['is_match'] ) ? (string) ( $item['opponent'] ?? '' ) : '';
        if ( $what === '' ) $what = (string) ( $item['title'] ?? '' );
        if ( $what === '' ) $what = (string) ( $item['type_label'] ?? '' );
        $meta   = [];
        if ( $multi_team && (string) ( $item['team_name'] ?? '' ) !== '' ) $meta[] = (string) $item['team_name'];
        if ( (string) ( $item['start_time'] ?? '' ) !== '' ) $meta[] = (string) $item['start_time'];
        $detail = CrossViewLink::allows( 'activities' ) ? RecordLink::detailUrlForWithBack( 'activities', $aid ) : '';
        $tag    = $detail !== '' ? 'a' : 'div';
        ?>
        <<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — a fixed tag name. ?> class="tt-week-row is-next"<?php if ( $detail !== '' ) : ?> href="<?php echo esc_url( $detail ); ?>"<?php endif; ?>>
            <span class="tt-week-day">
                <small><?php echo esc_html( $ts ? date_i18n( 'D', $ts ) : '' ); ?></small>
                <b><?php echo esc_html( $ts ? date_i18n( 'j', $ts ) : '' ); ?></b>
            </span>
            <span class="tt-week-body">
                <span class="tt-week-what">
                    <span class="tt-week-type"><?php echo esc_html( (string) ( $item['type_label'] ?? '' ) ); ?></span>
                    <?php echo esc_html( $what ); ?>
                </span>
                <?php if ( $meta !== [] ) : ?>
                    <span class="tt-week-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
                <?php endif; ?>
            </span>
        </<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — a fixed tag name. ?>>
        <?php
    }

    /**
     * #4492 — the device class of a block: '' when it is on for both
     * devices, `tt-week-off-phone` or `tt-week-off-desktop` when on for one,
     * null when it is off for both and nothing should render.
     *
     * @param array<string, mixed> $week
     */
    private static function weekDeviceClass( array $week, string $block ): ?string {
        $blocks = is_array( $week['blocks'] ?? null ) ? $week['blocks'] : [];
        return self::deviceClass(
            in_array( $block, (array) ( $blocks['desktop'] ?? [] ), true ),
            in_array( $block, (array) ( $blocks['phone'] ?? [] ), true )
        );
    }

    private static function deviceClass( bool $desktop, bool $phone ): ?string {
        if ( ! $desktop && ! $phone ) return null;
        if ( $desktop && $phone ) return '';
        return $desktop ? 'tt-week-off-phone' : 'tt-week-off-desktop';
    }

    /**
     * Markup that differs per device, rendered once when both devices see
     * the same and once per device otherwise, each copy hidden on the other
     * device by CSS. `$build( $device, $class )` returns '' for nothing.
     *
     * @param callable(string, string): string $build
     */
    private static function perDevice( callable $build ): string {
        $desktop = $build( 'desktop', '' );
        $phone   = $build( 'phone', '' );
        if ( $desktop === $phone ) return $desktop;
        return $build( 'desktop', 'tt-week-off-phone' ) . $build( 'phone', 'tt-week-off-desktop' );
    }

    /**
     * One row of the week. A row with a button of its own renders as a
     * block with buttons; any other row is one link to the activity. Each
     * part carries the device class of the block it belongs to (#4492).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $week
     */
    private static function renderWeekRow( array $row, array $week ): void {
        $aid      = (int) ( $row['activity_id'] ?? 0 );
        $when     = (string) ( $row['when'] ?? '' );
        $is_match = ! empty( $row['is_match'] );
        $multi    = ! empty( $week['multiple_teams'] );
        $ts       = strtotime( (string) ( $row['date'] ?? '' ) . ' 12:00:00' );
        $blocks   = is_array( $week['blocks'] ?? null ) ? $week['blocks'] : [];
        $on       = static fn( string $device, string $key ): bool => in_array( $key, (array) ( $blocks[ $device ] ?? [] ), true );

        $classes = [ 'tt-week-row' ];
        if ( $when === 'past' )  $classes[] = 'is-past';
        if ( $when === 'today' ) $classes[] = 'is-today';
        if ( $is_match )         $classes[] = 'is-match';

        $buttons = self::weekButtons( $row, $aid, $week );
        $detail  = CrossViewLink::allows( 'activities' ) ? RecordLink::detailUrlForWithBack( 'activities', $aid ) : '';
        $as_link = $buttons['html'] === '' && $detail !== '';

        $what_html = self::perDevice( static function ( string $device, string $class ) use ( $row, $is_match, $when, $on, $buttons, $detail, $as_link ): string {
            $eyebrow = [];
            if ( $on( $device, 'type' ) ) $eyebrow[] = (string) ( $row['type_label'] ?? '' );
            if ( $is_match && $on( $device, 'opponent' ) && ( $row['home_away'] ?? '' ) === 'home' ) $eyebrow[] = __( 'Home', 'talenttrack' );
            if ( $is_match && $on( $device, 'opponent' ) && ( $row['home_away'] ?? '' ) === 'away' ) $eyebrow[] = __( 'Away', 'talenttrack' );
            if ( $when === 'today' ) $eyebrow[] = __( 'Today', 'talenttrack' );
            $eyebrow = array_filter( $eyebrow, static fn( $p ) => $p !== '' );

            $what = ( $is_match && $on( $device, 'opponent' ) ) ? (string) ( $row['opponent'] ?? '' ) : '';
            if ( $what === '' ) $what = (string) ( $row['title'] ?? '' );
            if ( $what === '' ) $what = (string) ( $row['type_label'] ?? '' );

            $inner = ( $eyebrow !== [] ? '<span class="tt-week-type">' . esc_html( implode( ' · ', $eyebrow ) ) . '</span>' : '' ) . esc_html( $what );
            // A row that is a block of buttons, on a device where none of them show: its title is the link.
            if ( ! $as_link && $detail !== '' && $buttons['devices'][ $device ] === [] ) {
                return '<a class="' . esc_attr( trim( 'tt-week-what tt-week-what-link ' . $class ) ) . '" href="' . esc_url( $detail ) . '">' . $inner . '</a>';
            }
            return '<span class="' . esc_attr( trim( 'tt-week-what ' . $class ) ) . '">' . $inner . '</span>';
        } );

        $meta_html = self::perDevice( static function ( string $device, string $class ) use ( $row, $is_match, $multi, $on ): string {
            $meta = [];
            if ( $multi && $on( $device, 'team_name' ) && (string) ( $row['team_name'] ?? '' ) !== '' ) $meta[] = (string) $row['team_name'];
            if ( $on( $device, 'time' ) ) {
                if ( $is_match ) {
                    /* translators: %s: arrival/presence time, e.g. "13:15" */
                    if ( (string) ( $row['meet_time'] ?? '' ) !== '' ) $meta[] = sprintf( __( 'Present %s', 'talenttrack' ), (string) $row['meet_time'] );
                    /* translators: %s: kick-off time, e.g. "10:00" */
                    if ( (string) ( $row['start_time'] ?? '' ) !== '' ) $meta[] = sprintf( __( 'Kick-off %s', 'talenttrack' ), (string) $row['start_time'] );
                } elseif ( (string) ( $row['start_time'] ?? '' ) !== '' ) {
                    $meta[] = (string) $row['start_time'];
                }
            }
            if ( $on( $device, 'location' ) && (string) ( $row['location'] ?? '' ) !== '' ) $meta[] = (string) $row['location'];
            if ( $meta === [] ) return '';
            return '<span class="' . esc_attr( trim( 'tt-week-meta ' . $class ) ) . '">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
        } );

        $chips = [];
        foreach ( self::weekChips( $row ) as [ $tone, $label, $block ] ) {
            $class = self::weekDeviceClass( $week, $block );
            if ( $class !== null ) $chips[] = [ $tone, $label, $class ];
        }

        ob_start();
        ?>
        <span class="tt-week-day">
            <small><?php echo esc_html( $ts ? date_i18n( 'D', $ts ) : '' ); ?></small>
            <b><?php echo esc_html( $ts ? date_i18n( 'j', $ts ) : '' ); ?></b>
        </span>
        <span class="tt-week-body">
            <?php echo $what_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the builder. ?>
            <?php echo $meta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the builder. ?>
            <?php if ( $chips !== [] ) : ?>
                <span class="tt-week-chips">
                    <?php foreach ( $chips as [ $tone, $label, $class ] ) : ?>
                        <span class="<?php echo esc_attr( trim( 'tt-week-chip tt-week-chip--' . $tone . ' ' . $class ) ); ?>"><?php echo esc_html( $label ); ?></span>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
            <?php if ( $buttons['html'] !== '' ) : ?>
                <span class="tt-week-actions"><?php echo $buttons['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in weekButtons(). ?></span>
            <?php endif; ?>
        </span>
        <?php
        $inner = (string) ob_get_clean();

        if ( ! $as_link ) {
            echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . $inner . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — every part of $inner is escaped above.
        } else {
            echo '<a class="' . esc_attr( implode( ' ', $classes ) ) . '" href="' . esc_url( $detail ) . '">' . $inner . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — every part of $inner is escaped above.
        }
    }

    /**
     * What the row still needs, as [tone, label, block key] triples. Tone is
     * `ok`, `todo` or `bad`. Availability is counts only.
     *
     * @param array<string, mixed> $row
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function weekChips( array $row ): array {
        $chips = [];
        if ( ( $row['attendance_recorded'] ?? null ) === true ) {
            $chips[] = [ 'ok', __( 'Attendance recorded', 'talenttrack' ), 'attendance' ];
        } elseif ( ( $row['attendance_recorded'] ?? null ) === false ) {
            $chips[] = [ 'bad', __( 'Attendance not recorded', 'talenttrack' ), 'attendance' ];
        }
        if ( is_array( $row['planned'] ?? null ) ) {
            $chips[] = [ 'ok', sprintf(
                /* translators: 1: players expected, 2: players on the roster */
                __( '%1$d of %2$d expected', 'talenttrack' ),
                (int) ( $row['planned']['expected'] ?? 0 ),
                (int) ( $row['planned']['roster'] ?? 0 )
            ), 'expected' ];
        }
        if ( ( $row['training_plan'] ?? null ) === true ) {
            $chips[] = [ 'ok', __( 'Training plan ready', 'talenttrack' ), 'training_plan' ];
        } elseif ( ( $row['training_plan'] ?? null ) === false ) {
            $chips[] = [ 'todo', __( 'No training plan yet', 'talenttrack' ), 'training_plan' ];
        }
        if ( is_array( $row['availability'] ?? null ) ) {
            $available   = (int) ( $row['availability']['available'] ?? 0 );
            $unavailable = (int) ( $row['availability']['unavailable'] ?? 0 );
            /* translators: %d: number of players who can play */
            $chips[] = [ 'ok', sprintf( _n( '%d available', '%d available', $available, 'talenttrack' ), $available ), 'availability' ];
            if ( $unavailable > 0 ) {
                /* translators: %d: number of players who cannot play */
                $chips[] = [ 'bad', sprintf( _n( '%d unavailable', '%d unavailable', $unavailable, 'talenttrack' ), $unavailable ), 'availability' ];
            }
        }
        if ( is_array( $row['lineup'] ?? null ) ) {
            $placed = (int) ( $row['lineup']['placed'] ?? 0 );
            $target = (int) ( $row['lineup']['target'] ?? 0 );
            $chips[] = $placed >= $target
                ? [ 'ok', __( 'Line-up complete', 'talenttrack' ), 'lineup' ]
                /* translators: 1: starters placed, 2: starters the formation needs */
                : [ 'todo', sprintf( __( 'Line-up %1$d of %2$d', 'talenttrack' ), $placed, $target ), 'lineup' ];
        }
        return $chips;
    }

    /**
     * The row's buttons, from the action keys the service granted. Per
     * device at most `ActionBlock::MAX_PER_ROW` show, and the first one that
     * applies is the main button; a button switched off for a device is
     * hidden there. `devices` lists, per device, the actions that show.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $week
     * @return array{html: string, devices: array{desktop: list<string>, phone: list<string>}}
     */
    private static function weekButtons( array $row, int $aid, array $week ): array {
        $actions = is_array( $row['actions'] ?? null ) ? $row['actions'] : [];
        $base    = RecordLink::dashboardUrl();
        $buttons = [];
        foreach ( $actions as $action ) {
            $url = '';
            switch ( $action ) {
                case CoachWeek::ACTION_MARK_ATTENDANCE:
                    $url = WizardEntryPoint::urlFor( 'mark-attendance', BackLink::appendTo( ActivityGridLink::attendanceUrl( $aid ) ), [ 'activity_id' => $aid ] );
                    if ( $url !== '' ) $buttons[ $action ] = [ __( 'Mark attendance', 'talenttrack' ), $url ];
                    break;
                case CoachWeek::ACTION_MATCH_PREP:
                    $url = CrossViewLink::allows( 'match-prep' ) ? add_query_arg( [ 'tt_view' => 'match-prep', 'activity_id' => $aid ], $base ) : '';
                    if ( $url !== '' ) $buttons[ $action ] = [ __( 'Match preparation', 'talenttrack' ), BackLink::appendTo( $url ) ];
                    break;
                case CoachWeek::ACTION_START_MATCH:
                    $url = CrossViewLink::allows( 'match-execution' ) ? add_query_arg( [ 'tt_view' => 'match-execution', 'activity_id' => $aid ], $base ) : '';
                    if ( $url !== '' ) $buttons[ $action ] = [ __( 'Start match', 'talenttrack' ), BackLink::appendTo( $url ) ];
                    break;
                case CoachWeek::ACTION_OPEN_ACTIVITY:
                    $url = CrossViewLink::allows( 'activities' ) ? RecordLink::detailUrlForWithBack( 'activities', $aid ) : '';
                    if ( $url !== '' ) $buttons[ $action ] = [ __( 'Activity', 'talenttrack' ), $url ];
                    break;
            }
        }

        $map     = CoachWeek::actionBlocks();
        $blocks  = is_array( $week['blocks'] ?? null ) ? $week['blocks'] : [];
        $devices = [ 'desktop' => [], 'phone' => [] ];
        foreach ( array_keys( $devices ) as $device ) {
            $shown = array_filter(
                array_keys( $buttons ),
                static fn( string $action ): bool => in_array( $map[ $action ] ?? '', (array) ( $blocks[ $device ] ?? [] ), true )
            );
            $devices[ $device ] = array_slice( array_values( $shown ), 0, ActionBlock::MAX_PER_ROW );
        }

        $html = '';
        foreach ( $buttons as $action => [ $label, $url ] ) {
            $class = self::deviceClass( in_array( $action, $devices['desktop'], true ), in_array( $action, $devices['phone'], true ) );
            if ( $class === null ) continue;
            $main_desktop = ( $devices['desktop'][0] ?? null ) === $action;
            $main_phone   = ( $devices['phone'][0] ?? null ) === $action;
            $variant      = $main_desktop && $main_phone ? 'tt-week-btn--primary' : 'tt-week-btn--secondary';
            if ( $main_desktop && ! $main_phone ) $variant .= ' tt-week-btn--main-desktop';
            if ( $main_phone && ! $main_desktop ) $variant .= ' tt-week-btn--main-phone';
            $html .= '<a class="' . esc_attr( trim( 'tt-week-btn ' . $variant . ' ' . $class ) ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        return [ 'html' => $html, 'devices' => $devices ];
    }

    /**
     * Render the section heading + tile grid for each visible group.
     * Module-enabled + capability + persona filtering already happened
     * inside `TileRegistry::tilesForUserGrouped()`; this method only
     * paints the markup.
     *
     * @param array<int, array{label:string, tiles:array}> $groups
     */
    private static function renderGroups( array $groups ): void {
        // #1598 — academy-wide tile layout: 'row' (icon left, title+desc
        // stacked beside it) or 'stacked' (icon + title share line 1,
        // description full width beneath).
        $stacked = TileGridStandard::activeLayout() === 'stacked';
        foreach ( $groups as $group ) {
            $tiles = $group['tiles'];
            if ( empty( $tiles ) ) continue;
            ?>
            <div class="tt-ftile-section-label">
                <span><?php echo esc_html( (string) $group['label'] ); ?></span>
            </div>
            <div class="tt-ftile-grid">
                <?php foreach ( $tiles as $tile ) :
                    // #1553 — Phosphor duotone glyph in an accent chip.
                    $chip  = TileIconChip::render(
                        (string) ( $tile['icon'] ?? '' ),
                        (string) ( $tile['color'] ?? '#0b3d2e' )
                    );
                    $label = esc_html( (string) ( $tile['label'] ?? '' ) );
                    $desc  = esc_html( (string) ( $tile['desc'] ?? '' ) );
                    $url   = esc_url( (string) ( $tile['url'] ?? '' ) );
                    // #2409 — "Under development" marker, from the tile's own
                    // feature or its owning module. '' for the common case.
                    $dev   = \TT\Shared\Frontend\Components\DevelopmentPill::badgeForViewSlug(
                        (string) ( $tile['view_slug'] ?? '' )
                    );
                    ?>
                    <a class="tt-ftile<?php echo $dev !== '' ? ' is-under-development' : ''; ?>" href="<?php echo $url; ?>">
                        <?php if ( $stacked ) : ?>
                            <div class="tt-ftile-head">
                                <?php echo $chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TileIconChip escapes its own attrs and IconRenderer returns trusted SVG. ?>
                                <div class="tt-ftile-label"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — esc_html'd above. ?></div>
                            </div>
                            <?php echo $dev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — DevelopmentPill escapes its own label + title. ?>
                            <p class="tt-ftile-desc"><?php echo $desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — esc_html'd above. ?></p>
                        <?php else : ?>
                            <?php echo $chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TileIconChip escapes its own attrs and IconRenderer returns trusted SVG. ?>
                            <div class="tt-ftile-body">
                                <div class="tt-ftile-label"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — esc_html'd above. ?></div>
                                <?php echo $dev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — DevelopmentPill escapes its own label + title. ?>
                                <p class="tt-ftile-desc"><?php echo $desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — esc_html'd above. ?></p>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }


    /**
     * #1992 / #2081 — the parent's child-scoped landing. Anchored on the
     * child's name + photo (§1), it MIRRORS the player's own Me-group tile
     * grid (#2081) — child-framed "`<FirstName>'s <noun>`" labels, each
     * carrying the child's ?player_id — plus a child switcher when the
     * parent has more than one linked child. The legacy "work of today"
     * column is intentionally absent — the parent's screen is the child's
     * record, not a task list.
     *
     * Active-child resolution + tile-set selection (which mirrored Me-tiles,
     * gated by module + `player_*` features) are domain decisions
     * (ParentChildResolver / ParentDashboardTiles, §4); this method only
     * resolves the child-scoped URL, frames the label, and paints.
     */
    private static function renderParentGrid( int $user_id ): void {
        $children = \TT\Infrastructure\Players\ParentChildResolver::children( $user_id );
        if ( empty( $children ) ) {
            // Defensive — isParentViewer() already guaranteed ≥1, but never
            // render a broken anchor.
            return;
        }

        // Active child: explicit ?tt_child when it's one of THIS parent's
        // children, else the most-recent (children[0]).
        $requested = isset( $_GET['tt_child'] ) ? absint( $_GET['tt_child'] ) : 0;
        $active    = $children[0];
        if ( $requested > 0 ) {
            foreach ( $children as $c ) {
                if ( (int) $c->id === $requested ) { $active = $c; break; }
            }
        }
        $child_id   = (int) $active->id;
        $child_name = trim( QueryHelpers::player_display_name( $active ) );
        $photo      = (string) ( $active->photo_url ?? '' );

        // #2081 — labels read "<Child first name>'s <noun>" (Anglo
        // possessive). Prefer the child's first name; fall back to the
        // full display name so a record without a parsed first name still
        // reads sensibly.
        $first_name = trim( (string) ( $active->first_name ?? '' ) );
        if ( $first_name === '' ) {
            $first_name = $child_name !== '' ? $child_name : __( 'Player', 'talenttrack' );
        }

        $base  = self::shortcodeBaseUrl();
        $tiles = \TT\Infrastructure\Players\ParentDashboardTiles::tiles( $user_id, $child_id );

        echo TileIconChip::styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static trusted CSS.
        echo '<div class="tt-parent-dash">';

        // Child anchor — name + photo (§1).
        echo '<div class="tt-parent-dash-anchor">';
        if ( $photo !== '' ) {
            echo '<img class="tt-parent-dash-photo" src="' . esc_url( $photo ) . '" alt="" loading="lazy" width="56" height="56" />';
        } else {
            echo '<span class="tt-parent-dash-photo tt-parent-dash-photo--blank" aria-hidden="true">'
                . esc_html( self::childInitials( $active ) ) . '</span>';
        }
        echo '<div class="tt-parent-dash-anchor-text">';
        echo '<div class="tt-parent-dash-eyebrow">' . esc_html__( 'My child', 'talenttrack' ) . '</div>';
        echo '<h2 class="tt-parent-dash-name">' . esc_html( $child_name !== '' ? $child_name : __( 'Player', 'talenttrack' ) ) . '</h2>';
        echo '</div>';
        echo '</div>';

        // #4089 — during a trial the rail holds only the schedule; say why,
        // so the family is not left wondering where the rest went.
        if ( \TT\Infrastructure\Players\ParentChildResolver::accessOf( $active )
            === \TT\Infrastructure\Players\ParentChildResolver::ACCESS_TRIAL ) {
            echo '<p class="tt-notice">' . esc_html( sprintf(
                /* translators: %s: the child's name. */
                __( '%s is on trial. During the trial you can see their trainings, matches and attendance. The rest of their record opens once they have signed with the academy.', 'talenttrack' ),
                $child_name !== '' ? $child_name : __( 'Your child', 'talenttrack' )
            ) ) . '</p>';
        }

        // Child switcher (only when >1 linked child).
        \TT\Shared\Frontend\Components\ParentChildSwitcher::renderInlineSwitcher( $children, $child_id, $base );

        // #2081 — child-scoped tile rail mirroring the player's Me-tiles.
        echo '<div class="tt-parent-dash-grid">';
        foreach ( $tiles as $tile ) {
            $slug = (string) ( $tile['view_slug'] ?? '' );
            if ( $slug === '' ) continue;
            $url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
                [ 'tt_view' => $slug, 'player_id' => $child_id ],
                $base
            ) );
            $chip  = TileIconChip::render(
                (string) ( $tile['icon'] ?? '' ),
                (string) ( $tile['color'] ?? '#0b3d2e' )
            );
            // #2081 — child-frame the mirrored Me-tile noun as an Anglo
            // possessive, e.g. "Sven's development" / "Sven's ontwikkeling".
            $noun  = (string) ( $tile['child_noun'] ?? '' );
            $label = esc_html( sprintf(
                /* translators: 1: child's first name, 2: tile noun (e.g. development, card, evaluations). */
                __( "%1\$s's %2\$s", 'talenttrack' ),
                $first_name,
                $noun
            ) );
            echo '<a class="tt-parent-dash-tile" href="' . esc_url( $url ) . '">';
            echo $chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TileIconChip escapes its own attrs; IconRenderer returns trusted SVG.
            echo '<span class="tt-parent-dash-tile-label">' . $label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — esc_html'd above.
            // #2409 — a parent sees the same marker as everyone else.
            echo \TT\Shared\Frontend\Components\DevelopmentPill::badgeForViewSlug( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — DevelopmentPill escapes its own label + title.
            echo '</a>';
        }
        echo '</div>';

        // #3472 — the parent's OWN surfaces, unframed and unscoped. Their
        // inbox is the one that mattered: under the default `classic` shell
        // this grid is the whole navigation, and `my-messages` had no tile
        // here and no entry in the user menu, so a parent could not reach it
        // at all. Rendered as a second section so the child's record stays
        // the anchor of the screen (§1) and these read as what they are.
        $own = \TT\Infrastructure\Players\ParentDashboardTiles::ownTiles( $user_id );
        if ( $own !== [] ) {
            echo '<h3 class="tt-parent-dash-own-heading">' . esc_html__( 'Your account', 'talenttrack' ) . '</h3>';
            echo '<div class="tt-parent-dash-grid">';
            foreach ( $own as $tile ) {
                $slug = $tile['view_slug'];
                if ( $slug === '' ) continue;
                // tt-xview-ok: the slug came out of
                // TileRegistry::tilesForUserGrouped( $user_id ), which has
                // already applied the per-user capability, module and feature
                // gates — the same question CrossViewLink would ask. The child
                // rail above resolves its URLs the same way.
                $url  = \TT\Shared\Frontend\Components\BackLink::appendTo(
                    add_query_arg( [ 'tt_view' => $slug ], $base ) /* tt-xview-ok */
                );
                $chip = TileIconChip::render( $tile['icon'], $tile['color'] );
                echo '<a class="tt-parent-dash-tile" href="' . esc_url( $url ) . '">';
                echo $chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TileIconChip escapes its own attrs; IconRenderer returns trusted SVG.
                echo '<span class="tt-parent-dash-tile-label">' . esc_html( $tile['label'] ) . '</span>';
                echo \TT\Shared\Frontend\Components\DevelopmentPill::badgeForViewSlug( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — DevelopmentPill escapes its own label + title.
                echo '</a>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    private static function childInitials( object $child ): string {
        $first = (string) ( $child->first_name ?? '' );
        $last  = (string) ( $child->last_name ?? '' );
        $a = $first !== '' ? mb_substr( $first, 0, 1 ) : '';
        $b = $last !== ''  ? mb_substr( $last, 0, 1 )  : '';
        $out = strtoupper( $a . $b );
        return $out !== '' ? $out : '?';
    }

    private static function greeting( int $user_id ): string {
        $user = get_userdata( $user_id );
        $name = $user ? $user->display_name : '';
        return $name !== ''
            ? sprintf(
                /* translators: %s is user display name */
                __( 'Welcome, %s', 'talenttrack' ),
                $name
            )
            : __( 'Welcome', 'talenttrack' );
    }
    /**
     * Base URL of the current page without tt_view or any drill-down
     * params. Tiles append ?tt_view=<slug> to this.
     */
    private static function shortcodeBaseUrl(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab' ],
            $current ?: home_url( '/' )
        );
    }
}
