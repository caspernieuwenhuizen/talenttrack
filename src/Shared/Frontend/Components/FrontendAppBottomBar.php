<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Authorization\PersonaResolver;

/**
 * FrontendAppBottomBar (#2459) — thumb-zone navigation below 768px.
 *
 * A second presentation of the app shell's one primary navigation
 * (CLAUDE.md §5b), not a second navigation: it consumes
 * `FrontendAppNav::groups()` unchanged, so it inherits the same
 * capability filtering, per-persona labels and ordering. The drawer stays
 * available alongside it and carries the full set — the bar is an
 * accelerator for the four things a persona does most, never the only
 * path to anything.
 *
 * **Which four is deliberately not decided in code.** The slots are
 * club-scoped config; absent config means a derived default computed from
 * the registry. That is what let this ship before the product question
 * ("which five destinations per persona?") was answered: a suboptimal
 * default costs a tap, and correcting it later is a config edit rather
 * than a release.
 *
 * When the answer arrives it comes from `tt_usage_events`, which already
 * records `frontend_view` with the view slug per user — see
 * `docs/frontend-shell.md`.
 */
final class FrontendAppBottomBar {

    /**
     * Club-scoped config key. Shape: JSON object of
     * `persona key => [ slug, slug, slug, slug ]`.
     * Absent or empty for a persona means "use the derived default".
     */
    public const CONFIG_KEY = 'tt_shell_mobile_slots';

    /** Navigation slots before the trailing "More" entry. */
    private const SLOT_COUNT = 4;

    /**
     * The four destinations for this user, in order.
     *
     * Configured slugs are honoured first; anything stale (a slug that no
     * longer exists, is hidden for this persona, or fails the capability
     * check) is skipped rather than rendered dead, and the derived
     * default backfills the gap. A bad config can therefore never produce
     * an empty or broken bar.
     *
     * @return list<array<string,mixed>>
     */
    public static function slots( int $user_id ): array {
        $available = self::availableTiles( $user_id );
        if ( $available === [] ) return [];

        $by_slug = [];
        foreach ( $available as $tile ) {
            $by_slug[ self::slugFor( $tile ) ] = $tile;
        }

        $picked = [];
        foreach ( self::configuredSlugs( $user_id ) as $slug ) {
            if ( count( $picked ) >= self::SLOT_COUNT ) break;
            if ( ! isset( $by_slug[ $slug ] ) ) continue;      // stale / not permitted
            if ( isset( $picked[ $slug ] ) ) continue;          // duplicate in config
            $picked[ $slug ] = $by_slug[ $slug ];
        }

        // Backfill from the derived default so the bar is always full
        // when there are enough tiles to fill it.
        foreach ( $available as $tile ) {
            if ( count( $picked ) >= self::SLOT_COUNT ) break;
            $slug = self::slugFor( $tile );
            if ( isset( $picked[ $slug ] ) ) continue;
            $picked[ $slug ] = $tile;
        }

        return array_values( $picked );
    }

    /**
     * The derived default's candidate pool: every work tile the user can
     * see, in `groupOrder()` sequence.
     *
     * Setup tiles are excluded on purpose — Configuration, Migrations and
     * friends are not what anyone reaches for one-handed at the side of a
     * pitch, and putting them a tap away from the thumb is how a bar stops
     * being useful.
     *
     * @return list<array<string,mixed>>
     */
    private static function availableTiles( int $user_id ): array {
        $out = [];
        foreach ( FrontendAppNav::groups( $user_id ) as $group ) {
            foreach ( $group['tiles'] as $tile ) {
                if ( (string) ( $tile['kind'] ?? 'work' ) !== 'work' ) continue;
                if ( self::slugFor( $tile ) === '' ) continue;
                $out[] = $tile;
            }
        }
        return $out;
    }

    /**
     * Configured slugs for the user's persona, or `[]` when nothing is
     * configured — which is the ship state and means "derive".
     *
     * @return list<string>
     */
    private static function configuredSlugs( int $user_id ): array {
        $raw = ( new ConfigService() )->get( self::CONFIG_KEY, '' );
        if ( $raw === '' ) return [];

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];

        $persona = '';
        if ( class_exists( PersonaResolver::class ) ) {
            $persona = (string) ( PersonaResolver::activePersona( $user_id ) ?? '' );
        }

        $slugs = $decoded[ $persona ] ?? ( $decoded['*'] ?? [] );
        if ( ! is_array( $slugs ) ) return [];

        return array_values( array_filter( array_map(
            static fn( $s ) => is_string( $s ) ? sanitize_key( $s ) : '',
            $slugs
        ) ) );
    }

    private static function slugFor( array $tile ): string {
        $slug = (string) ( $tile['view_slug'] ?? '' );
        return $slug !== '' ? $slug : (string) ( $tile['slug'] ?? '' );
    }

    /**
     * Whether a slot should read as current. True for the slot's own view
     * and for views that belong to it — a player detail page lights up
     * Players, which is the difference between a bar that orients you and
     * one that goes blank as soon as you open a record.
     */
    private static function isActive( string $slot_slug, string $view ): bool {
        if ( $slot_slug === '' || $view === '' ) return false;
        if ( $slot_slug === $view ) return true;

        // `players` owns `player`, `teams` owns `team`, and so on: the
        // detail slug is the list slug without its plural 's'.
        $singular = rtrim( $slot_slug, 's' );
        return $singular !== '' && $singular !== $slot_slug && $view === $singular;
    }

    public static function render( int $user_id, string $active_view = '', string $hub_url = '' ): void {
        $slots = self::slots( $user_id );
        if ( $slots === [] ) return;

        echo '<nav class="tt-shell-bar" aria-label="' . esc_attr__( 'Quick navigation', 'talenttrack' ) . '">';
        echo '<ul class="tt-shell-bar__list">';

        foreach ( $slots as $tile ) {
            $slug   = self::slugFor( $tile );
            $active = self::isActive( $slug, $active_view );

            echo '<li class="tt-shell-bar__item">';
            echo '<a class="tt-shell-bar__link' . ( $active ? ' is-active' : '' ) . '" '
                . 'href="' . esc_url( (string) $tile['url'] ) . '"'
                . ( $active ? ' aria-current="page"' : '' ) . '>';
            echo '<span class="tt-shell-bar__icon" aria-hidden="true">';
            $icon = (string) ( $tile['icon'] ?? '' );
            if ( $icon !== '' ) {
                echo \TT\Shared\Icons\IconRenderer::render( $icon, [ 'width' => 20, 'height' => 20 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            }
            echo '</span>';
            echo '<span class="tt-shell-bar__label">' . esc_html( (string) $tile['label'] ) . '</span>';
            echo '</a>';
            echo '</li>';
        }

        // Fifth slot — the full tile hub. Always present, so everything
        // outside the four slots stays one tap away.
        $active_hub = ( $active_view === '' );
        echo '<li class="tt-shell-bar__item">';
        echo '<a class="tt-shell-bar__link' . ( $active_hub ? ' is-active' : '' ) . '" '
            . 'href="' . esc_url( $hub_url !== '' ? $hub_url : home_url( '/' ) ) . '"'
            . ( $active_hub ? ' aria-current="page"' : '' ) . '>';
        echo '<span class="tt-shell-bar__icon" aria-hidden="true">'
            . \TT\Shared\Icons\IconRenderer::render( 'menu', [ 'width' => 20, 'height' => 20 ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            . '</span>';
        echo '<span class="tt-shell-bar__label">' . esc_html__( 'More', 'talenttrack' ) . '</span>';
        echo '</a>';
        echo '</li>';

        echo '</ul>';
        echo '</nav>';
    }
}
