# Tests can switch commercial mode and the plan tier per case (#4336)

The PHPUnit suite can now put one test on a commercial free-tier install and the next on a paid one, so the free-tier team cap and the locked plan states are covered by tests instead of checked by hand. `LicenseMode::overrideForTests()` and `LicenseGate::overrideTierForTests()` are read only while the test bootstrap's `TT_PHPUNIT` constant is defined, so they have no effect on a live install, and there is deliberately no filter. Usage is in `docs/testing.md`. No behaviour change for academies.
