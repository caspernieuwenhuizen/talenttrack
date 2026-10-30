# A developer test bench can pin its tier so it does not lapse to free (#4301)

A test bench without a control plane lost its recorded plan after a month and fell back to free, which locked features and showed limit notices nobody expected. On an install where the developer override secret is configured, `define( 'TT_DEV_TIER', 'pro' )` in `wp-config.php` now pins the tier until it is removed. Customer installs, which never define the secret, are unaffected. `wp tt entitlement show` names the pin.
