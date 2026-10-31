---
title: Testing plan-gated behaviour
group: developer
summary: How a PHPUnit test switches commercial mode and the plan tier for one case, and why the switch has no effect outside the test suite.
audience: [dev]
order: 97
---

# Testing plan-gated behaviour

Commercial mode is the `TT_COMMERCIAL_MODE` constant, and a constant holds
for the whole PHPUnit process. Without help, a test run is either all
commercial or all non-commercial, and a non-commercial install answers the
Pro tier with no caps. So the free-tier caps and the locked plan states could
not be put under test.

Two overrides fix that, for tests only.

## Switching per case

```php
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;

LicenseMode::overrideForTests( true );                      // commercial on
LicenseGate::overrideTierForTests( FeatureMap::TIER_FREE ); // on the free tier
```

- `LicenseMode::overrideForTests( ?bool )` decides what
  `LicenseMode::isCommercial()` answers.
- `LicenseGate::overrideTierForTests( ?string )` puts a commercial install
  on `free`, `standard` or `pro`. It acts as a pinned tier, ahead of the
  developer override and the stored entitlement. Anything other than the
  three tier slugs is ignored.

Set both. On a non-commercial install the tier override changes nothing,
because a non-commercial install answers Pro.

## Always reset in `tear_down()`

The overrides are static and outlive the test that set them. Pass `null` to
both in `tear_down()`, or the next test in the run inherits the plan:

```php
public function tear_down(): void {
    LicenseMode::overrideForTests( null );
    LicenseGate::overrideTierForTests( null );
    parent::tear_down();
}
```

`tests/php/LicenseTestOverrideTest.php` is the worked example: two teams on
the free tier, the team-cap notice for the administrator and none for a head
coach.

## Why it cannot reach a live install

Both overrides are read only while the `TT_PHPUNIT` constant is defined and
true. Only `tests/php/bootstrap.php` defines it. On any other install the
values are never read, whatever code sets them.

There is deliberately no filter. A non-commercial install answers Pro, so a
filter on the commercial check would let any plugin switch licensing off.
