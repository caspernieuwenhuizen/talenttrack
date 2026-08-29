<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Template\TemplateCatalog;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;

/**
 * #3111 — a fresh install starts with messaging off; an upgrade is
 * untouched.
 *
 * The whole difficulty of this slice is that three situations store the
 * same value today: a pre-#3111 install, an install whose operator ticked
 * every box, and a brand-new install. The first two must keep meaning
 * "everything on"; only the third changes. Seeding at activation is what
 * gives the third case a stored value of its own, and these tests pin
 * that the other two are untouched by it.
 *
 * The upgrade guarantee is asserted, not reasoned about, because it is
 * the property most easily broken by a later "small" change to
 * `isEnabled()`.
 */
final class CommsFreshInstallDefaultsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        TemplateRegistry::clear();
        foreach ( TemplateCatalog::shipped() as $template ) {
            TemplateRegistry::register( $template );
        }
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        parent::tear_down();
    }

    // -- the fresh-install seed -------------------------------------------

    public function test_seeding_disables_every_switchable_template(): void {
        $this->assertTrue( TemplateSwitch::seedFreshInstallDefault() );

        $disabled = TemplateSwitch::disabledKeys();
        $this->assertNotEmpty( $disabled );

        foreach ( array_keys( TemplateSwitch::switchableTemplates() ) as $key ) {
            $this->assertFalse(
                TemplateSwitch::isEnabled( $key ),
                sprintf( 'A fresh install must not send "%s" until somebody chooses it.', $key )
            );
        }
    }

    /**
     * The property #3110 exists to protect: a new academy that sends
     * nothing must still be able to give people logins.
     */
    public function test_account_mail_survives_the_seed(): void {
        TemplateSwitch::seedFreshInstallDefault();

        $this->assertNotContains( 'invitation_email', TemplateSwitch::disabledKeys() );
        $this->assertTrue( TemplateSwitch::isEnabled( 'invitation_email' ) );
    }

    /**
     * Activation can run more than once — a deactivate/reactivate cycle,
     * or a re-install over an existing database. Neither may overwrite
     * what the academy has chosen since.
     */
    public function test_seeding_never_overwrites_a_stored_choice(): void {
        TemplateSwitch::setDisabled( [ 'goal_nudge' ] );

        $this->assertFalse( TemplateSwitch::seedFreshInstallDefault() );
        $this->assertSame( [ 'goal_nudge' ], TemplateSwitch::disabledKeys() );
        $this->assertTrue( TemplateSwitch::isEnabled( 'training_cancelled' ) );
    }

    // -- the upgrade guarantee --------------------------------------------

    /**
     * An install that existed before #3111 has an empty stored value and
     * means "everything on". Nothing about this change may alter that,
     * and the seed is never called for it.
     */
    public function test_an_upgrading_install_still_sends_everything(): void {
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

        foreach ( array_keys( TemplateRegistry::all() ) as $key ) {
            $this->assertTrue(
                TemplateSwitch::isEnabled( $key ),
                sprintf( 'An upgrade must not silently switch "%s" off.', $key )
            );
        }
    }

    /**
     * The reason the stored set is the DISABLED one, still holding.
     *
     * A template shipped in a later release is in nobody's stored set, so
     * it lands enabled on an install that already existed. A fresh
     * install activated on that later release gets it disabled instead,
     * because its seed is computed from the catalogue as it stands at
     * activation.
     */
    public function test_a_future_template_lands_enabled_on_an_existing_install(): void {
        TemplateSwitch::seedFreshInstallDefault();

        $this->assertTrue(
            TemplateSwitch::isEnabled( 'a_template_shipped_next_release' ),
            'A key absent from the stored disabled set is enabled, by construction.'
        );
    }

    /**
     * `isEnabled()` reads the stored set and nothing else — no install
     * date, no "has the operator seen this" flag, no read-time default.
     * Written as a test because a read-time rule is exactly the shortcut
     * that would quietly break the upgrade guarantee above.
     */
    public function test_is_enabled_reads_only_the_stored_set(): void {
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '["goal_nudge"]' );

        $this->assertFalse( TemplateSwitch::isEnabled( 'goal_nudge' ) );
        $this->assertTrue( TemplateSwitch::isEnabled( 'training_cancelled' ) );
    }

    // -- the catalogue -----------------------------------------------------

    /**
     * The seed reads `TemplateCatalog`, not `TemplateRegistry`, because
     * activation runs long after `init` and the registry is empty there.
     * If the two ever diverge, a fresh install silently seeds a partial
     * set and sends messages nobody chose — so pin that boot registers
     * exactly the catalogue.
     */
    public function test_the_registry_boots_from_the_catalogue(): void {
        $catalogue = [];
        foreach ( TemplateCatalog::shipped() as $template ) {
            $catalogue[] = $template->key();
        }

        sort( $catalogue );
        $registered = array_keys( TemplateRegistry::all() );
        sort( $registered );

        $this->assertSame( $catalogue, $registered );
        $this->assertSame(
            count( $catalogue ),
            count( array_unique( $catalogue ) ),
            'Two templates share a key — one would silently replace the other in the registry.'
        );
    }

    public function test_the_seed_covers_every_switchable_shipped_template(): void {
        TemplateSwitch::seedFreshInstallDefault();

        $this->assertSame(
            TemplateCatalog::shippedSwitchableKeys(),
            TemplateSwitch::disabledKeys()
        );
    }
}
