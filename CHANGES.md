# TalentTrack v2.7.1 — Fix PeopleModule silent-skip

## What was wrong

In v2.7.0, the People menu never appeared despite the tables being created and all files being in place. Diagnosis:

Your `ModuleInterface` requires this signature:

```php
public function register( Container $container ): void;
public function boot( Container $container ): void;
public function getName(): string;
```

My v2.7.0 PeopleModule had:
- `public function register(): void` (missing `Container $container`)
- `public function boot(): void` (missing `Container $container`)
- No `getName()` method at all
- **Didn't declare `implements ModuleInterface`**

`ModuleRegistry::load()` checks `$module instanceof ModuleInterface` before registering. My PeopleModule wasn't an instance, so it got silently skipped — same shape of silent-skip bug we've fought before.

This is entirely my fault. I should have inspected `ModuleInterface` before writing the module.

## What v2.7.1 does

One file change: a corrected `src/Modules/People/PeopleModule.php` that:
- Declares `implements ModuleInterface`
- Uses the correct signatures with `Container $container`
- Implements `getName()` returning `'people'`
- Moves `add_action( 'admin_menu', ... )` from `register()` to `boot()` (the interface docblock says boot() is for "admin menus, hooks, shortcodes" — register() is for earlier registration)

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting.
2. Commit, push, tag `v2.7.1`, create release.
3. **No deactivate/reactivate needed** — just refresh wp-admin.
4. TalentTrack → **People** should appear in the menu.

## Verify

- TalentTrack → People renders an empty people list with an "Add New" button
- TalentTrack → Teams → edit any team → scroll below the form → Staff section appears
- Everything from v2.7.0's verification checklist should now work

## Files in this release

Only one file changed:
- `src/Modules/People/PeopleModule.php` — properly implements ModuleInterface

Plus version bumps:
- `talenttrack.php` — 2.7.1
- `readme.txt` — 2.7.1 stable tag

Everything else unchanged from v2.7.0.
