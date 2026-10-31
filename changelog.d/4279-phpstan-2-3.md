# Static analysis moves to PHPStan 2.3 (#4279)

The development toolchain now runs PHPStan 2.3, and the code its stricter rules flagged has been fixed rather than added to the baseline. The fixes are a validator return type that now says what it returns, three null-coalescing defaults that could never apply, and a Spond paging loop whose first check was always true. No behaviour change for academies. This is tooling and code hygiene only.
