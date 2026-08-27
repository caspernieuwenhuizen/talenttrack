<?php
/**
 * Routable surfaces that own the thumb zone themselves (#2933).
 *
 * The shell renders one primary navigation (CLAUDE.md §5b), and below
 * 768px its presentation is a fixed bar across the bottom of the screen.
 * A handful of surfaces put their own controls in that same space,
 * because the work they carry is one-handed and time-critical: a live
 * match on a touchline, a training session in progress. Stacking the
 * shell bar under those controls costs roughly 190px of chrome on a
 * 640px screen — enough to halve the content the surface can show.
 *
 * Listing a slug here suppresses the shell's bottom bar on that surface
 * only.
 *
 * **This is a §5b exception with a contract, not a view emitting its own
 * navigation.** The view still emits none: it declares that it needs the
 * space, and the shell honours the declaration. Nothing is duplicated,
 * moved or reimplemented.
 *
 * **The user is never stranded.** Two things have to hold before a slug
 * belongs here, and both are on the surface, not on this file:
 *
 *   1. The view renders the §5a breadcrumb chain on every code path,
 *      including permission-denied early returns. That chain is the way
 *      out, and it is what makes suppressing the bar safe.
 *   2. Its own controls genuinely occupy the bottom of the viewport. A
 *      surface that merely looks busy does not qualify — it would just
 *      lose its navigation for nothing.
 *
 * Above 768px this file has no effect: the bar is already `display:none`
 * there and the sidebar or drawer is the presentation. Under the
 * `classic` shell it has no effect either, because there is no bar.
 *
 * @return array<string, string> view slug => why it claims the space
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    'match-execution' => 'Live on the touchline, one-handed, with the clock running. The view pins the score and the state action to the screen and puts its section switcher in the thumb zone; the shell bar underneath would sit below the switcher and halve the panel the coach is actually reading.',
    'training-run'    => 'The same shape one surface over: a session being run, with the commit controls pinned to the bottom so the next block is one thumb away.',
];
