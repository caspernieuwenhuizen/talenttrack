# Debugging PUC (Plugin Update Checker) on the running WordPress install

If updates don't appear in wp-admin → Plugins despite a new GitHub release existing, work through this list:

## 1. Force a check

Add `?puc_check_now=1&puc_slug=talenttrack` to any wp-admin URL. If an update appears after reloading Plugins, PUC was just cached. Default cache is 12 hours. Nothing to fix.

## 2. Check what PUC sees

Drop this into a plugin or theme's functions.php temporarily:

```php
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::getLatestClassVersion( 'Plugin\\UpdateChecker' );
    // Find the TT update checker instance (PUC v5 stores them in a global registry)
    $info = get_site_transient( 'puc_request_info_talenttrack' );
    echo '<div class="notice notice-info"><pre>' . esc_html( print_r( $info, true ) ) . '</pre></div>';
});
```

Reload wp-admin. The notice shows what PUC fetched from GitHub. Look for:
- The `version` field — does it match your latest release tag?
- The `download_url` field — does it point to talenttrack.zip?
- Any error messages

## 3. Verify the release structure

Latest release at github.com/caspernieuwenhuizen/talenttrack/releases/latest should have:
- Tag in format `vX.Y.Z` (NOT `v.X.Y.Z`)
- talenttrack.zip attached as an asset (not just "Source code (zip)")

If the tag is prerelease-formatted (`v3.0.0-alpha`), PUC skips it by default. Use proper release tags for updates you want delivered.

## 4. Verify the plugin headers

In talenttrack.php the `Version:` header must equal the tag (minus the `v` prefix). So tag `v3.0.0` requires `Version: 3.0.0` in the header. A common slip is updating the constant but forgetting the header, or vice versa. The release workflow does not enforce this; it's a manual discipline until we add a check.

## 5. Clear the cache

If everything looks right but PUC still won't update:
- Visit wp-admin. Open Plugins page.
- In URL bar append `&force-check=1` (sometimes works)
- Or deactivate + reactivate the plugin (triggers cache clear)

## 6. Increase PUC verbosity (last resort)

In talenttrack.php, right after the `buildUpdateChecker()` call:

```php
$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/caspernieuwenhuizen/talenttrack/',
    __FILE__,
    TT_PLUGIN_SLUG
);
$update_checker->getVcsApi()->enableReleaseAssets();
$update_checker->setAuthentication( '' ); // no token for public repo
```

`enableReleaseAssets()` is the key call — it tells PUC to prefer release assets (your talenttrack.zip) over the auto-generated source zipball. Without it, PUC may try to use the source zipball which has the wrong folder name (`caspernieuwenhuizen-talenttrack-<sha>`) and silently fail the update.

**This is the most likely cause of your current PUC problem.**
