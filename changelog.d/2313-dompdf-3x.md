# Upgrade dompdf to 3.x (security) (#2313)

Bump: patch

Bumped the `dompdf/dompdf` dependency from `^2.0` to `^3.0`. Every 2.0.x
release is now flagged by published security advisories, which blocked
`composer install` in CI. dompdf 3.x carries no advisories and still supports
PHP 7.4, so the plugin's minimum PHP is unchanged. PDF export behaviour is
unaffected — the renderer uses only the stable dompdf API.
