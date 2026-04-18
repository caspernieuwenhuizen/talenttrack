<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abstract Migration base.
 *
 * Each migration file under /database/migrations/ returns an anonymous
 * class extending this.
 *
 *   - getName() must return the filename (without .php). The runner
 *     uses this as the unique key stored in tt_migrations.
 *   - up()     must be idempotent — use CREATE TABLE IF NOT EXISTS,
 *              empty-row checks before seeding, etc.
 */
abstract class Migration {

    abstract public function getName(): string;

    abstract public function up(): void;
}
