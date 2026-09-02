<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3276 — a position the academy added itself must read as its own label,
 * and the eleven seeded ones must keep reading as their long form.
 *
 * `positionLabel()` was a hard-coded switch over the Activator's eleven
 * codes, so anything the operator added fell through and printed its
 * database key on twelve read surfaces at once: a profile line reading
 * `Verdedigende middenvelder · rechter_middenvelder`, half translated and
 * half not, on the screen a coach reads most.
 *
 * The fix resolves the lookup row first and keeps the switch as the step
 * behind it. That order has one hazard, which is what most of this class is
 * about: on a stock install the seeded rows carry NO `tt_translations`
 * entry and their `tt_lookups.name` IS the code, so a naive "resolve the
 * row first" would downgrade every seeded position from *Defensive
 * midfielder* to *CDM* on every install. The guard is that a resolver
 * handing the code straight back has told us nothing.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT COVER
 *
 * The happy path — an operator label winning — is not asserted here, and
 * that is a limitation of the harness rather than an oversight.
 * `LookupTranslator::rowByTypeAndName()` memoises rows per lookup type in a
 * process-lifetime `static` with no reset hook, built on the first call of
 * the run. Each test's inserts are rolled back and re-made with fresh ids,
 * so from the second test onward the cached row carries an id that no
 * longer matches the translation rows — any translation-backed assertion
 * would pass or fail on test ordering. `LookupTranslatorCaseFallbackTest`
 * sidesteps the same trap by asserting only row names.
 *
 * That path was verified against the seeded local install instead, where
 * `rechter_middenvelder` with a Dutch label resolves to *Rechter
 * middenvelder* while `CDM` still resolves to *Verdedigende middenvelder*.
 * Giving that a home in CI needs the static to become resettable, which is
 * a change to shared code this fix does not need.
 */
final class PositionLabelResolutionTest extends WP_UnitTestCase {

    private const TYPE = 'position';

    /**
     * Rows are inserted here rather than in a test body on purpose — see the
     * cache note in the class docblock. Seeding the whole set up front keeps
     * whichever test runs first consistent with the rest.
     */
    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $club = (int) CurrentClub::id();
        $rows = [
            // A seeded-style row: its name IS the code, no translation.
            'CDM'                  => 1,
            // Operator-added rows, no label — the humanise path.
            'linker_middenvelder'  => 2,
        ];

        foreach ( $rows as $name => $order ) {
            $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
                'club_id'     => $club,
                'lookup_type' => self::TYPE,
                'name'        => (string) $name,
                'sort_order'  => $order,
            ] );
        }
    }

    /**
     * The regression the new resolution order could cause, and the reason
     * the "did the resolver actually tell us anything" guard exists.
     *
     * A seeded row's name is its code and it has no translation, so the
     * lookup step answers `CDM`. If that answer were taken, every install
     * would silently lose eleven long forms.
     */
    public function test_a_seeded_code_still_reads_as_its_long_form(): void {
        $this->assertSame( 'Defensive midfielder', LabelTranslator::positionLabel( 'CDM' ) );
        $this->assertSame( 'Goalkeeper',           LabelTranslator::positionLabel( 'GK' ) );
        $this->assertSame( 'Centre forward',       LabelTranslator::positionLabel( 'CF' ) );
    }

    /**
     * The defect's visible half: an operator key must never reach a screen,
     * even when nothing anywhere carries a label for it.
     */
    public function test_a_lookup_row_without_a_label_is_humanised(): void {
        $label = LabelTranslator::positionLabel( 'linker_middenvelder' );
        $this->assertSame( 'Linker Middenvelder', $label );
        $this->assertNotSame( 'linker_middenvelder', $label, 'the raw key must never reach a screen' );
    }

    /** A stored value with no lookup row at all still never prints raw. */
    public function test_an_orphaned_code_is_humanised(): void {
        $this->assertSame( 'Vleugel Verdediger', LabelTranslator::positionLabel( 'vleugel_verdediger' ) );
    }

    /** Empty in, empty out — callers guard on the empty string. */
    public function test_empty_input_yields_empty_output(): void {
        $this->assertSame( '', LabelTranslator::positionLabel( '' ) );
        $this->assertSame( '', LabelTranslator::positionLabel( '   ' ) );
    }

    /**
     * The label is display-only. The stored code stays the matching key.
     *
     * Chemistry buckets, formation slots and squad-step matching all key on
     * the stored value; if any started keying on the label, renaming a
     * position in Configuration would silently move players between
     * buckets. `positionLongForm()` is the canonical-English side of that
     * contract — migration 0141 drives `switch_to_locale() → __()` off it —
     * so it must keep answering from the switch alone, never from operator
     * strings.
     */
    public function test_the_canonical_long_form_is_independent_of_operator_labels(): void {
        $this->assertSame( 'Defensive midfielder', LabelTranslator::positionLongForm( 'CDM' ) );
        $this->assertSame( 'Goalkeeper',           LabelTranslator::positionLongForm( 'GK' ) );

        // An unseeded code has no canonical long form and must say so by
        // handing the code back — the humanising happens one level up, in
        // positionLabel(), so migration 0141 never sees a prettified key.
        $this->assertSame(
            'rechter_middenvelder',
            LabelTranslator::positionLongForm( 'rechter_middenvelder' )
        );
    }
}
