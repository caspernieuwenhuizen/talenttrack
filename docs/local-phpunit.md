---
title: Running PHPUnit locally
group: developer
summary: Run the PHP test suite against a local MySQL or MariaDB without wp-env, and read the result against a base run.
audience: [dev]
order: 96
---

# Running PHPUnit locally

CI runs `tests/php` inside wp-env. When wp-env is not available (no Docker),
or a branch cannot use CI, the suite also runs against a local MySQL or
MariaDB, such as the one XAMPP ships. Everything below lives **outside the
repo**: the committed `vendor/` is runtime-only, and nothing here gets
committed.

## What you need

- PHP 8.x and Composer on the PATH.
- A local MySQL or MariaDB, reachable on `127.0.0.1`.
- A **separate, empty database** for the tests, for example `tt_phpunit`.
  The WordPress test suite drops and recreates its tables on every run, so
  never point it at a database you use for anything else.

## One-time setup

Make a runner folder anywhere outside the repo, for example a scratch
directory, and put four things in it.

**`composer.json`**, then run `composer install` in that folder:

```json
{
    "name": "local/tt-phpunit-runner",
    "require": {
        "phpunit/phpunit": "^9.6",
        "yoast/phpunit-polyfills": "^2.0",
        "wp-phpunit/wp-phpunit": "7.1.3"
    },
    "config": { "allow-plugins": false }
}
```

Match `wp-phpunit/wp-phpunit` to the WordPress version you test against.

**`wordpress/`**: a plain WordPress download of that version, unzipped. It is
never installed through the browser; the test suite installs it into the
test database.

**`wp-tests-config.php`**:

```php
<?php
define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_MULTISITE', false );
define( 'WP_DEBUG', true );

define( 'DB_NAME', 'tt_phpunit' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', __DIR__ . '/vendor/yoast/phpunit-polyfills' );
```

**`bootstrap.php`**, a wrapper around the repo's own
`tests/php/bootstrap.php`:

```php
<?php
// The repo under test is the current working directory.
$repo = getcwd();

require __DIR__ . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__DIR=' . __DIR__ . '/vendor/wp-phpunit/wp-phpunit' );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

// The committed vendor/ has no autoload entry for the test classes.
spl_autoload_register( static function ( string $class ) use ( $repo ): void {
    $prefix = 'TT\\Tests\\Php\\';
    if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) return;
    $file = $repo . '/tests/php/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
    if ( is_file( $file ) ) require $file;
} );

// In CI, wp-env activates the plugin in the test database before PHPUnit
// starts, so the base tables exist when the repo bootstrap runs the
// migrations. Do the same here.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';
tests_add_filter( 'init', static function () use ( $repo ): void {
    if ( ! defined( 'TT_VERSION' ) ) {
        require $repo . '/talenttrack.php';
    }
    if ( class_exists( '\\TT\\Core\\Activator' ) ) {
        \TT\Core\Activator::activate();
    }
}, 1 );

require $repo . '/tests/php/bootstrap.php';
```

Without the activation step the migrations fail on missing base tables
(`tt_people` and others), because locally nothing has activated the plugin.

## Running

From the root of the checkout or worktree you want to test, with `$W` the
runner folder:

```
php -d memory_limit=2G "$W/vendor/bin/phpunit" -c phpunit.xml.dist --bootstrap "$W/bootstrap.php" --filter TrialCaseArchiveRestTest
```

Drop `--filter` for the whole suite, and add `--log-junit <file>.xml` to keep
the result for a comparison.

**Recreate the database before every run** you want to trust:

```
mysql -u root -h 127.0.0.1 -e "DROP DATABASE IF EXISTS tt_phpunit; CREATE DATABASE tt_phpunit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

A rerun on a used database fails on leftovers (duplicate keys on
`tt_players`, for instance), and those failures look like real ones.

## Reading the result

A full local run does **not** come out green, even on `main`. Several hundred
tests fail locally that pass in CI: differences in the database server and
PHP setup, and state that leaks from one test into the next in a long run.
The bootstrap also prints database warnings from older migrations; those are
noise.

So never read a full local run on its own. Compare it with a base run:

1. Run the full suite on the base commit (`main`, or the branch's
   merge-base) on a fresh database, with `--log-junit base.xml`.
2. Run it on the branch, again on a fresh database, with
   `--log-junit branch.xml`.
3. Look only at tests that fail on the branch and not on the base.

A small script does step 3:

```php
<?php
// php junit-diff.php base.xml branch.xml
function failing( string $file ): array {
    $out = [];
    foreach ( simplexml_load_file( $file )->xpath( '//testcase' ) as $case ) {
        $id = (string) $case['class'] . '::' . (string) $case['name'];
        foreach ( [ 'failure', 'error' ] as $kind ) {
            foreach ( $case->{$kind} as $node ) {
                // A test that printed output: local noise, not an outcome.
                if ( (string) $node['type'] === 'PHPUnit\\Framework\\OutputError' ) continue;
                $out[ $id ] = (string) $node['type'];
            }
        }
    }
    return $out;
}
$base   = failing( $argv[1] );
$branch = failing( $argv[2] );
echo "Fail on branch only:\n";
foreach ( array_diff_key( $branch, $base ) as $id => $type ) echo "  {$id} ({$type})\n";
echo "Fail on base only:\n";
foreach ( array_diff_key( $base, $branch ) as $id => $type ) echo "  {$id}\n";
```

Then run each test that fails on the branch only **on its own**, on a fresh
database. A test that passes alone and fails only in the full run is leakage
from an earlier test, not the branch.

Two more things that look like regressions and are not:

- **Date windows.** Tests that reason about "today" fail when local time and
  UTC fall on different dates, such as just after midnight in Europe. If a
  date test fails on the branch only, check the time the two runs started.
- **Test preconditions.** A new test can be wrong about the authorization
  matrix (which persona holds which capability). Check its
  precondition before you blame the code.

A local run is evidence, not CI. When the branch can use CI, CI decides.
