<?php
namespace TT\Modules\SeedReview;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\SeedReview\Admin\SeedReviewPage;
use TT\Shared\Admin\AdminMenuRegistry;

/**
 * SeedReviewModule — Excel-based bulk review and edit of seeded
 * lookup / category / role rows.
 *
 * Operator workflow:
 *   1. Configuration → Seed review → "Download review template"
 *      → .xlsx with one sheet per seed table (tt_lookups, tt_eval_
 *      categories, tt_roles, tt_functional_roles). Each row carries
 *      the row id, the canonical English `name`/`label`, the current
 *      Dutch translation pulled via `__()` under `switch_to_locale
 *      ('nl_NL')`, a `language` column flagging which language the
 *      stored value is in, and notes / sort_order / etc.
 *   2. Operator reviews offline, edits labels / translations / notes.
 *   3. Operator uploads the edited .xlsx back via "Apply edits".
 *   4. Importer diffs the upload against the live rows by primary
 *      key and applies in-place updates with audit-log entries.
 *
 * Cap-gated on `tt_edit_settings`. Lives at
 * `?page=tt-seed-review` under the TalentTrack admin menu. Live-DB
 * updates only — does NOT rewrite the shipped seed PHP files.
 * Operators who want the change to ship to other installs as code
 * work it back into `config/authorization_seed.php` / migrations
 * manually.
 */
class SeedReviewModule implements ModuleInterface {

    public function getName(): string { return 'seed_review'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        AdminMenuRegistry::register( [
            'module_class' => self::class,
            'parent'       => 'talenttrack',
            'title'        => __( 'Seed review', 'talenttrack' ),
            'label'        => __( 'Seed review', 'talenttrack' ),
            'cap'          => 'tt_edit_settings',
            'slug'         => 'tt-seed-review',
            'callback'     => [ SeedReviewPage::class, 'render' ],
            'group'        => 'configuration',
            'order'        => 92,
        ] );

        add_action( 'admin_post_tt_seed_review_export', [ SeedReviewPage::class, 'handleExport' ] );
        add_action( 'admin_post_tt_seed_review_import', [ SeedReviewPage::class, 'handleImport' ] );
    }
}
