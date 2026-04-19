# TalentTrack v2.5.1 — Delivery Changes

## What this ZIP does

Two bundled fixes/additions:

1. **BUGFIX — non-admin logout**. v2.5.0 accidentally blocked `admin-post.php` for non-admins, which silently killed the logout button. Fixed by whitelisting `admin-post.php` in `FrontendAccessControl`.

2. **Dashboard user menu dropdown**. Replaces the bare Log out button with a proper dropdown. Click your name → "Edit profile" + "Log out" menu.

## How to install

1. Extract this ZIP.
2. Open `talenttrack-v2.5.1/`.
3. Copy contents into your local `talenttrack/` repo folder. Allow overwrites.
4. GitHub Desktop shows the changed files.
5. Commit: `v2.5.1 — fix non-admin logout + add user menu dropdown`.
6. Push.
7. GitHub → Releases → tag `v2.5.1`.
8. WordPress auto-updates.

## Files in this delivery

### Modified
- `talenttrack.php` — v2.5.1.
- `readme.txt` — stable tag + changelog.
- `src/Shared/Frontend/FrontendAccessControl.php` — whitelists `admin-post.php` for non-admin users.
- `src/Shared/Frontend/DashboardShortcode.php` — new user menu dropdown (replaces bare logout button).
- `assets/css/public.css` — styles for dropdown menu; old `.tt-dash-user` / `.tt-logout-btn` selectors replaced.
- `languages/talenttrack-nl_NL.po` — adds "Edit profile" Dutch translation.
- `languages/talenttrack-nl_NL.mo` — recompiled (251 messages).

### Unchanged
- Everything else.

## After install — clear cache

Because CSS changed, **hard-refresh** your browser once (Ctrl/Cmd+Shift+R) to pick up the new styles. If you use a caching plugin (W3TC, WP Super Cache, etc.) purge it first.

## Verification

1. Log out (via wp-login.php or deactivate-and-reactivate the plugin so you're logged out cleanly).
2. Log in as a **non-admin** user (player, coach, etc.).
3. Top-right of the dashboard: click your name.
4. Dropdown opens with "Edit profile" and "Log out".
5. Click "Log out" — you land on the homepage with the login form. ✓
6. Log in again, click your name, click "Edit profile".
7. WP profile page loads (it's under wp-admin but whitelisted). Change your display name, save.
8. Click browser back. You're back on the dashboard, name updated.
9. Press Esc with the dropdown open — it closes. ✓
10. Click anywhere outside the dropdown — it closes. ✓

## UX note — the profile page experience

Clicking "Edit profile" takes users to `/wp-admin/profile.php`, WordPress's native profile page. It's functional but looks like WP admin — plain gray, no TalentTrack branding. For non-admins there's no admin bar, so there's no obvious "back to dashboard" link. Users navigate back via the browser back button.

If this turns out to be a problem in practice (users get confused, or want a TT-styled profile form), that's a future enhancement — a custom TT-styled profile tab inside the dashboard, adding about 150 lines. For now, the simpler approach keeps the scope tight.

## Sprint 1a — now complete

With v2.5.0 + v2.5.1 together, Sprint 1a is done:

- ✅ Homepage shows login when logged out
- ✅ Homepage shows dashboard when logged in
- ✅ No user (except admin) can access wp-admin
- ✅ Logout works for all roles, returns to homepage
- ✅ Profile access via dashboard user menu
- ✅ No redirects to wp-login or wp-admin after login
- ✅ Admin users retain full backend access
- ✅ No broken AJAX/REST/cron (admin-ajax, admin-post, and cron all whitelisted)
