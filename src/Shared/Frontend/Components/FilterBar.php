<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FilterBar — reusable, data-driven filter bar (#2026, epic #2017 Phase 1).
 *
 * One component for every list surface's filter row. The calling view
 * supplies an ordered list of filter groups (options + active state);
 * FilterBar renders the chrome. It holds NO view-specific logic — it
 * never decides what the options are, what is active, or how a filter
 * maps to a query (CLAUDE.md §4: the component composes, the view
 * decides). That keeps it portable across views and SaaS-front-ends.
 *
 * Two layouts, one markup tree (responsive treatment in
 * `assets/css/frontend-filter-bar.css`):
 *   - <1024px: collapses to a "Filters" button (+ active-count badge)
 *     and scrollable summary chips; tapping opens a bottom sheet holding
 *     the same groups.
 *   - >=1024px: a single inline row, each group under a small-caps
 *     label with dividers between groups.
 *
 * The bar is a wrapper around the view's own GET <form>. The view passes
 * the hidden fields it needs to preserve (tt_view, tt_back, etc.) and
 * the per-group controls; FilterBar renders the form, the inline row,
 * the mobile trigger + chips, and the bottom sheet (a second copy of the
 * groups). Both copies submit the same form, so behaviour is identical
 * whichever layout is active.
 *
 * Group types:
 *   - `select`  — labelled <select> (auto-submits on change).
 *   - `text`    — a free-text / search box bound to a form field
 *                 (#2082). No auto-submit on keystroke; the noscript
 *                 Apply button (or a JS hydrator) commits the value.
 *   - `date_range` — a paired from/to date range (#2082). Two date
 *                 inputs, each its own form field.
 *   - `period`  — a pill-dropdown trigger (`tt-perdrop`) that, in the
 *                 inline bar, reveals a popover of period links; in the
 *                 sheet, expands to a one-tap segmented track. Options are
 *                 links (no JS dependency for navigation).
 *   - `status`  — one-tap status pills (`tt-statpill`), link-based.
 *   - `toggle`  — a boolean switch (`tt-switch`) backed by a checkbox
 *                 that auto-submits.
 *
 * Each group is an array:
 *   [
 *     'type'  => 'select'|'text'|'date_range'|'period'|'status'|'toggle',
 *     'label' => 'Team',            // small-caps group label
 *     'key'   => 'team',            // stable id for the group (CSS hook)
 *     // type-specific keys, see below
 *   ]
 *
 * select: 'name' (form field), 'options' => [value => label], 'selected',
 *         'placeholder' (optional first option label).
 * text:   'name' (form field), 'value', 'placeholder' (optional),
 *         'input_type' ('text'|'search', default 'text'),
 *         'inputmode' (optional, e.g. 'search'),
 *         'autocomplete' (optional).
 * date_range: 'from' => ['name','value'], 'to' => ['name','value'],
 *         'label_from', 'label_to' (per-input labels).
 * period/status: 'options' => [ ['value','label','url','active', 'dot'?] ],
 *         'active_label' (text shown on the period pill trigger).
 * toggle: 'name', 'on' (bool), 'on_label' (the "Tonen" text),
 *         'value' (submitted value when checked, default '1').
 *
 * The wrapping <form> can carry extra attributes (e.g.
 * `data-tt-list-form="1"` so FrontendListTable's hydrator binds to it)
 * via the top-level `form_attrs` arg, and extra raw controls (already
 * escaped) can be injected into both the inline row and the sheet body
 * via `extra_controls` — used by FrontendListTable for its card-mode
 * sort dropdown.
 */
final class FilterBar {

	/** Ensure the shared stylesheet is enqueued exactly once. */
	private static bool $css_enqueued = false;
	/** Ensure the shared sheet script is enqueued exactly once. */
	private static bool $js_enqueued = false;

	/**
	 * Enqueue the FilterBar stylesheet + sheet script. Safe to call
	 * repeatedly (guards prevent double-enqueue). Strings the JS needs
	 * are localized on `TT.i18n` via the `TT_FILTER_BAR` payload.
	 */
	public static function enqueueAssets(): void {
		if ( ! self::$css_enqueued ) {
			wp_enqueue_style(
				'tt-frontend-filter-bar',
				TT_PLUGIN_URL . 'assets/css/frontend-filter-bar.css',
				[ 'tt-frontend-app-chrome' ],
				TT_VERSION
			);
			self::$css_enqueued = true;
		}
		if ( ! self::$js_enqueued ) {
			wp_enqueue_script(
				'tt-filter-bar',
				TT_PLUGIN_URL . 'assets/js/components/filter-bar.js',
				[],
				TT_VERSION,
				true
			);
			wp_localize_script( 'tt-filter-bar', 'TT_FILTER_BAR', [
				'i18n' => [
					'open'  => __( 'Open filters', 'talenttrack' ),
					'close' => __( 'Close', 'talenttrack' ),
				],
			] );
			self::$js_enqueued = true;
		}
	}

	/**
	 * Render the filter bar and echo it.
	 *
	 * @param array{
	 *   form_action?:string,
	 *   hidden?:array<string,string>,
	 *   groups:array<int,array<string,mixed>>,
	 *   active_count?:int,
	 *   chips?:array<int,string>,
	 *   title?:string,
	 *   filters_label?:string,
	 *   reset_url?:string,
	 *   noscript_label?:string
	 * } $args
	 */
	public static function render( array $args ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes internally.
		echo self::html( $args );
	}

	/**
	 * Build the filter-bar HTML.
	 *
	 * @param array<string,mixed> $args see render().
	 */
	public static function html( array $args ): string {
		self::enqueueAssets();

		$groups   = isset( $args['groups'] ) && is_array( $args['groups'] ) ? $args['groups'] : [];
		$hidden   = isset( $args['hidden'] ) && is_array( $args['hidden'] ) ? $args['hidden'] : [];
		$action   = (string) ( $args['form_action'] ?? '' );
		$active   = (int) ( $args['active_count'] ?? 0 );
		$chips    = isset( $args['chips'] ) && is_array( $args['chips'] ) ? $args['chips'] : [];
		$title    = (string) ( $args['title'] ?? __( 'Filters', 'talenttrack' ) );
		$ftrigger = (string) ( $args['filters_label'] ?? __( 'Filters', 'talenttrack' ) );
		$reset    = (string) ( $args['reset_url'] ?? '' );
		$ns_label = (string) ( $args['noscript_label'] ?? __( 'Apply', 'talenttrack' ) );
		// #2082 — extra form attributes (e.g. data-tt-list-form so the
		// FrontendListTable hydrator binds to the bar's own form) and an
		// extra raw control block (card-mode sort dropdown) echoed into
		// both the inline row and the sheet body. Caller pre-escapes the
		// raw HTML.
		$form_attrs = isset( $args['form_attrs'] ) && is_array( $args['form_attrs'] ) ? $args['form_attrs'] : [];
		$extra      = (string) ( $args['extra_controls'] ?? '' );

		$form_attr_html = '';
		foreach ( $form_attrs as $name => $value ) {
			$form_attr_html .= ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
		}

		$out  = '<div class="tt-filterbar" data-tt-filterbar>';
		$out .= '<form method="get" class="tt-filterbar__form" data-tt-filterbar-form'
			. ( $action !== '' ? ' action="' . esc_url( $action ) . '"' : '' )
			. $form_attr_html . '>';

		foreach ( $hidden as $name => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( (string) $name )
				. '" value="' . esc_attr( (string) $value ) . '" />';
		}

		// ---- Inline single-line row (desktop >=1024px) --------------
		$out .= '<div class="tt-filterbar__row">';
		$last = count( $groups ) - 1;
		foreach ( $groups as $i => $group ) {
			$out .= self::renderGroup( $group, false );
			if ( $i < $last ) {
				$out .= '<div class="tt-filterbar__div" aria-hidden="true"></div>';
			}
		}
		if ( $extra !== '' ) {
			$out .= $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller pre-escapes.
		}
		$out .= '</div>'; // .tt-filterbar__row

		// ---- Mobile collapsed trigger + summary chips (<1024px) -----
		$out .= '<div class="tt-filterbar__mobile">';
		$out .= '<div class="tt-filtertrigger">';
		$out .= '<button type="button" class="tt-btn tt-filterbtn" data-tt-filter-open'
			. ' aria-haspopup="dialog" aria-expanded="false">';
		$out .= '<span class="tt-filterbtn__icon" aria-hidden="true">&#9776;</span>';
		$out .= '<span>' . esc_html( $ftrigger ) . '</span>';
		if ( $active > 0 ) {
			$out .= '<span class="tt-filterbtn__badge">' . esc_html( (string) $active ) . '</span>';
		}
		$out .= '</button>';
		if ( $chips !== [] ) {
			$out .= '<div class="tt-chips" aria-hidden="true">';
			foreach ( $chips as $chip ) {
				$out .= '<span class="tt-chip">' . esc_html( (string) $chip ) . '</span>';
			}
			$out .= '</div>';
		}
		$out .= '</div>'; // .tt-filtertrigger
		$out .= '</div>'; // .tt-filterbar__mobile

		// ---- Bottom sheet (holds the same groups, sheet variant) ----
		$out .= '<div class="tt-filter-sheet-scrim" data-tt-filter-scrim hidden></div>';
		$out .= '<div class="tt-filter-sheet" role="dialog" aria-modal="true"'
			. ' aria-label="' . esc_attr( $title ) . '" data-tt-filter-sheet hidden>';
		$out .= '<div class="tt-filter-sheet__grip" aria-hidden="true"></div>';
		$out .= '<div class="tt-filter-sheet__head">';
		$out .= '<h3 class="tt-filter-sheet__title">' . esc_html( $title ) . '</h3>';
		$out .= '<button type="button" class="tt-filter-sheet__close" data-tt-filter-close'
			. ' aria-label="' . esc_attr__( 'Close', 'talenttrack' ) . '">&#10005;</button>';
		$out .= '</div>';
		$out .= '<div class="tt-filter-sheet__body">';
		foreach ( $groups as $group ) {
			$out .= self::renderGroup( $group, true );
		}
		if ( $extra !== '' ) {
			$out .= $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller pre-escapes.
		}
		$out .= '</div>'; // .tt-filter-sheet__body
		$out .= '<div class="tt-filter-sheet__foot">';
		if ( $reset !== '' ) {
			$out .= '<a class="tt-btn tt-btn-secondary tt-filter-sheet__reset" href="' . esc_url( $reset ) . '">'
				. esc_html__( 'Clear', 'talenttrack' ) . '</a>';
		}
		$out .= '<button type="button" class="tt-btn tt-btn-primary tt-filter-sheet__apply"'
			. ' data-tt-filter-close>' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
		$out .= '</div>'; // .tt-filter-sheet__foot
		$out .= '</div>'; // .tt-filter-sheet

		// noscript fallback — a real submit button for JS-off browsers.
		$out .= '<noscript><button type="submit" class="tt-btn tt-btn-secondary">'
			. esc_html( $ns_label ) . '</button></noscript>';

		$out .= '</form>';
		$out .= '</div>'; // .tt-filterbar

		return $out;
	}

	/**
	 * Render one filter group (label + control). The control markup is
	 * shared between the inline bar and the sheet; `$in_sheet` only
	 * changes the period control (pill-dropdown inline, segmented track
	 * in the sheet).
	 *
	 * @param array<string,mixed> $group
	 */
	private static function renderGroup( array $group, bool $in_sheet ): string {
		$type  = (string) ( $group['type'] ?? '' );
		$label = (string) ( $group['label'] ?? '' );
		$key   = sanitize_key( (string) ( $group['key'] ?? $type ) );

		$out  = '<div class="tt-filterbar__group tt-filterbar__group--' . esc_attr( $key ) . '">';
		if ( $label !== '' ) {
			$out .= '<span class="tt-filter__glabel">' . esc_html( $label ) . '</span>';
		}

		switch ( $type ) {
			case 'select':
				$out .= self::renderSelect( $group );
				break;
			case 'text':
				$out .= self::renderText( $group );
				break;
			case 'date_range':
				$out .= self::renderDateRange( $group, $in_sheet );
				break;
			case 'period':
				$out .= self::renderPeriod( $group, $in_sheet );
				break;
			case 'status':
				$out .= self::renderStatus( $group );
				break;
			case 'toggle':
				$out .= self::renderToggle( $group );
				break;
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 * `select` — chevron box. Auto-submits the wrapping form on change.
	 *
	 * @param array<string,mixed> $group
	 */
	private static function renderSelect( array $group ): string {
		$name        = (string) ( $group['name'] ?? '' );
		$selected    = (string) ( $group['selected'] ?? '' );
		$placeholder = isset( $group['placeholder'] ) ? (string) $group['placeholder'] : null;
		$options     = isset( $group['options'] ) && is_array( $group['options'] ) ? $group['options'] : [];
		// #2082 — `auto_submit` (default true) controls the
		// data-tt-filter-submit hook that filter-bar.js auto-submits on.
		// FrontendListTable opts OUT (its own JS hydrator handles the
		// change and live-filters without a reload), so the two scripts
		// don't both fire on one change.
		$auto_submit = ! isset( $group['auto_submit'] ) || ! empty( $group['auto_submit'] );

		$out  = '<div class="tt-filsel">';
		$out .= '<select class="tt-filsel__select" name="' . esc_attr( $name ) . '"'
			. ( $auto_submit ? ' data-tt-filter-submit' : '' ) . '>';
		if ( $placeholder !== null ) {
			$out .= '<option value=""' . ( $selected === '' ? ' selected' : '' ) . '>'
				. esc_html( $placeholder ) . '</option>';
		}
		foreach ( $options as $value => $opt_label ) {
			$v = (string) $value;
			$out .= '<option value="' . esc_attr( $v ) . '"'
				. ( $v === $selected ? ' selected' : '' ) . '>'
				. esc_html( (string) $opt_label ) . '</option>';
		}
		$out .= '</select>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * `text` — a free-text / search box. Unlike `select`/`toggle` it does
	 * NOT auto-submit on change (a keystroke shouldn't reload the page);
	 * the noscript Apply button commits the value, and a JS hydrator
	 * (FrontendListTable) live-filters as the user types. Honours
	 * `input_type` ('text'|'search'), `inputmode`, and `autocomplete`.
	 *
	 * @param array<string,mixed> $group
	 */
	private static function renderText( array $group ): string {
		$name        = (string) ( $group['name'] ?? '' );
		$value       = (string) ( $group['value'] ?? '' );
		$placeholder = (string) ( $group['placeholder'] ?? '' );
		$itype       = (string) ( $group['input_type'] ?? 'text' );
		$itype       = in_array( $itype, [ 'text', 'search' ], true ) ? $itype : 'text';
		$inputmode   = isset( $group['inputmode'] ) ? (string) $group['inputmode'] : '';
		$autocomp    = isset( $group['autocomplete'] ) ? (string) $group['autocomplete'] : '';

		$out  = '<div class="tt-filtext">';
		$out .= '<input type="' . esc_attr( $itype ) . '" class="tt-filtext__input"'
			. ' name="' . esc_attr( $name ) . '"'
			. ' value="' . esc_attr( $value ) . '"';
		if ( $placeholder !== '' ) {
			$out .= ' placeholder="' . esc_attr( $placeholder ) . '"';
		}
		if ( $inputmode !== '' ) {
			$out .= ' inputmode="' . esc_attr( $inputmode ) . '"';
		}
		if ( $autocomp !== '' ) {
			$out .= ' autocomplete="' . esc_attr( $autocomp ) . '"';
		}
		$out .= ' />';
		$out .= '</div>';
		return $out;
	}

	/**
	 * `date_range` — a paired from/to date range. Each bound to its own
	 * form field (`from.name` / `to.name`) so the surrounding view keeps
	 * full control of the param names (e.g. `filter[date_from]`). No
	 * auto-submit on change (a keystroke / date pick shouldn't reload).
	 *
	 * On the INLINE bar the range gets an explicit **Apply** submit
	 * button (#2184) so a desktop user has a clear, keyboard-reachable
	 * way to commit a changed from/to — the inline bar otherwise has no
	 * visible commit action. It's a plain `type="submit"`: for a bare
	 * FilterBar it navigates (GET reload); for a FrontendListTable
	 * adopter the hydrator intercepts the form's submit and live-filters
	 * instead, so there's no double submit. The sheet variant keeps its
	 * single footer Apply (`$in_sheet` suppresses the per-group button)
	 * — no duplicate.
	 *
	 * @param array<string,mixed> $group
	 */
	private static function renderDateRange( array $group, bool $in_sheet = false ): string {
		$from = isset( $group['from'] ) && is_array( $group['from'] ) ? $group['from'] : [];
		$to   = isset( $group['to'] )   && is_array( $group['to'] )   ? $group['to']   : [];
		$label_from = (string) ( $group['label_from'] ?? __( 'From', 'talenttrack' ) );
		$label_to   = (string) ( $group['label_to']   ?? __( 'To', 'talenttrack' ) );

		$field = static function ( array $cfg, string $sublabel ): string {
			$name  = (string) ( $cfg['name'] ?? '' );
			$value = (string) ( $cfg['value'] ?? '' );
			$o  = '<label class="tt-fildate">';
			if ( $sublabel !== '' ) {
				$o .= '<span class="tt-fildate__label">' . esc_html( $sublabel ) . '</span>';
			}
			$o .= '<input type="date" class="tt-fildate__input"'
				. ' name="' . esc_attr( $name ) . '"'
				. ' value="' . esc_attr( $value ) . '" />';
			$o .= '</label>';
			return $o;
		};

		$out  = '<div class="tt-fildaterange">';
		$out .= $field( $from, $label_from );
		$out .= $field( $to, $label_to );
		if ( ! $in_sheet ) {
			$out .= '<button type="submit" class="tt-btn tt-btn-primary tt-fildate__apply">'
				. esc_html__( 'Apply', 'talenttrack' ) . '</button>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * `period` — compact pill-dropdown in the inline bar; a one-tap
	 * segmented track in the sheet. Options are link-based (each carries
	 * a `url`) so navigation works without JS; the inline popover is a
	 * progressive enhancement toggled by the sheet/popover script.
	 *
	 * @param array<string,mixed> $group
	 */
	private static function renderPeriod( array $group, bool $in_sheet ): string {
		$options      = isset( $group['options'] ) && is_array( $group['options'] ) ? $group['options'] : [];
		$active_label = (string) ( $group['active_label'] ?? '' );

		if ( $in_sheet ) {
			$out = '<div class="tt-segtrack" role="group">';
			foreach ( $options as $opt ) {
				$url    = (string) ( $opt['url'] ?? '' );
				$lbl    = (string) ( $opt['label'] ?? '' );
				$is_on  = ! empty( $opt['active'] );
				$out   .= '<a class="tt-seg' . ( $is_on ? ' tt-seg--on' : '' ) . '" href="' . esc_url( $url ) . '"'
					. ( $is_on ? ' aria-current="true"' : '' ) . '>' . esc_html( $lbl ) . '</a>';
			}
			$out .= '</div>';
			return $out;
		}

		// Inline: a native <details> pill-dropdown — keyboard-operable and
		// fully functional with JS off. The script (data-tt-perdrop) only
		// adds outside-click-to-close as an enhancement.
		$out  = '<details class="tt-perdrop-wrap" data-tt-perdrop>';
		$out .= '<summary class="tt-perdrop">'
			. '<span class="tt-perdrop__label">' . esc_html( $active_label ) . '</span>'
			. '<span class="tt-perdrop__chev" aria-hidden="true"></span></summary>';
		$out .= '<div class="tt-perdrop__menu" role="menu">';
		foreach ( $options as $opt ) {
			$url   = (string) ( $opt['url'] ?? '' );
			$lbl   = (string) ( $opt['label'] ?? '' );
			$is_on = ! empty( $opt['active'] );
			$out  .= '<a class="tt-perdrop__opt' . ( $is_on ? ' tt-perdrop__opt--on' : '' ) . '"'
				. ' role="menuitem" href="' . esc_url( $url ) . '"'
				. ( $is_on ? ' aria-current="true"' : '' ) . '>'
				. esc_html( $lbl ) . '</a>';
		}
		$out .= '</div>';
		$out .= '</details>';
		return $out;
	}

	/**
	 * `status` — one-tap pills with a status dot, link-based.
	 *
	 * @param array<string,mixed> $group
	 */
	private static function renderStatus( array $group ): string {
		$options = isset( $group['options'] ) && is_array( $group['options'] ) ? $group['options'] : [];

		$out = '<div class="tt-statset" role="group">';
		foreach ( $options as $opt ) {
			$url   = (string) ( $opt['url'] ?? '' );
			$lbl   = (string) ( $opt['label'] ?? '' );
			$dot   = sanitize_key( (string) ( $opt['dot'] ?? ( $opt['value'] ?? '' ) ) );
			$is_on = ! empty( $opt['active'] );
			$out  .= '<a class="tt-statpill' . ( $is_on ? ' tt-statpill--on' : '' ) . '"'
				. ' href="' . esc_url( $url ) . '" data-k="' . esc_attr( $dot ) . '"'
				. ( $is_on ? ' aria-current="true"' : '' ) . '>'
				. '<span class="tt-statpill__dot" aria-hidden="true"></span>'
				. esc_html( $lbl ) . '</a>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * `toggle` — a boolean switch backed by a checkbox that auto-submits.
	 * The checkbox is the accessible control (label + :focus-visible);
	 * the track/knob is decorative. JS reflects the checked state onto
	 * the visual switch.
	 *
	 * @param array<string,mixed> $group
	 */
	private static function renderToggle( array $group ): string {
		$name     = (string) ( $group['name'] ?? '' );
		$on       = ! empty( $group['on'] );
		$on_label = (string) ( $group['on_label'] ?? '' );
		$value    = (string) ( $group['value'] ?? '1' );

		$out  = '<label class="tt-switch' . ( $on ? ' tt-switch--on' : '' ) . '" data-tt-switch>';
		$out .= '<input type="checkbox" class="tt-switch__input" name="' . esc_attr( $name )
			. '" value="' . esc_attr( $value ) . '"' . ( $on ? ' checked' : '' )
			. ' data-tt-filter-submit />';
		$out .= '<span class="tt-switch__track" aria-hidden="true"></span>';
		if ( $on_label !== '' ) {
			$out .= '<span class="tt-switch__label">' . esc_html( $on_label ) . '</span>';
		}
		$out .= '</label>';
		return $out;
	}
}
