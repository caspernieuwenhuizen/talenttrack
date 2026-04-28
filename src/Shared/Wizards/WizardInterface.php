<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A wizard is a slug + capability gate + ordered list of steps.
 *
 * Steps are looked up by slug. The first step's slug is the start
 * of the wizard; subsequent steps are reached by `WizardStepInterface::nextStep()`.
 */
interface WizardInterface {

    /** Stable slug used in URLs (?tt_view=wizard&slug=<this>). */
    public function slug(): string;

    /** Human label for the entry-point button + page heading. */
    public function label(): string;

    /** Capability required to start this wizard. */
    public function requiredCap(): string;

    /**
     * @return WizardStepInterface[]  Ordered. Steps are addressed by
     *                                their `slug()` though, so the
     *                                order here matters only for the
     *                                "first step" lookup.
     */
    public function steps(): array;

    /**
     * Slug of the very first step (the entry).
     */
    public function firstStepSlug(): string;
}
