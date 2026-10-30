# Declared minimum PHP corrected to 8.1 (#4277)

Bump: patch

The plugin header, `readme.txt` and `composer.json` declared PHP 7.4 as the minimum, while the code uses readonly properties, promoted constructor properties and other PHP 8.1 features, so the plugin could not load on PHP 7.4 or 8.0 although WordPress would let it install there. All three now declare PHP 8.1, so WordPress refuses to install or update on an older host instead of letting the site break. This corrects a false declaration rather than raising a requirement: every known install runs PHP 8.2 or 8.4. The setup wizard topic names the minimum.
