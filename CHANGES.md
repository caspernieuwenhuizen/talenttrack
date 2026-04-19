# TalentTrack v2.5.0 — Delivery Changes

## What this ZIP does

Completes **Sprint 1a — frontend-first application**. Makes TalentTrack the primary interface: users log in via the frontend, land on the dashboard, and never need wp-admin. Administrators still have full wp-admin access via the admin bar or direct URL.

## How to install

1. Extract this ZIP somewhere.
2. Open `talenttrack-v2.5.0/`.
3. Copy contents into your local `talenttrack/` repo folder. Allow overwrites.
4. GitHub Desktop shows the changed files.
5. Commit: `v2.5.0 — Sprint 1a (frontend-first)`.
6. Push.
7. GitHub → Releases → tag `v2.5.0`.
8. WordPress auto-updates.

## Files in this delivery

### Modified
- `talenttrack.php` — v2.5.0.
- `readme.txt` — stable tag + changelog.
- `src/Core/Kernel.php` — registers `FrontendAccessControl` service.
- `src/Modules/Auth/AuthModule.php` — binds and boots `LogoutHandler`.
- `src/Modules/Auth/LoginHandler.php` — successful login redirects to dashboard page instead of wp-admin.
- `src/Shared/Frontend/DashboardShortcode.php` — explicit logged-out guard + logout button in header.
- `assets/css/public.css` — new styles for dashboard header + logout button.
- `languages/talenttrack-nl_NL.po` — adds Dutch translations for new strings ("Log out", "Check your email...").
- `languages/talenttrack-nl_NL.mo` — recompiled.

### Added
- `src/Shared/Frontend/FrontendAccessControl.php` — central service for:
  - Redirecting non-admins away from wp-admin.
  - Hiding admin bar for non-admins.
  - Gating wp-login.php (except logout / password reset).
  - Logout redirect.
  - Login-redirect override.
- `src/Modules/Auth/LogoutHandler.php` — handles `admin-post.php?action=tt_logout`.

### Unchanged
- Every module, every data table, every REST endpoint, every admin CRUD page, every role/capability. This is a pure access-control layer addition.

## REQUIRED: Set the WP homepage

For the "homepage shows the app" behavior to work, you must configure WP to use a page containing the shortcode as the homepage:

1. Create a WordPress Page (Pages → Add New), title it "Dashboard" (or anything).
2. Add the shortcode `[talenttrack_dashboard]` as the only content.
3. Publish it.
4. **Settings → Reading** → "Your homepage displays" → pick **"A static page"** → Homepage = your new Dashboard page.
5. Save.

Now `yoursite.com/` shows the TT login when logged out and the TT dashboard when logged in.

## Optional: Configure the dashboard page explicitly

By default, the plugin redirects to `home_url('/')` (your WP homepage, as configured above). If you want redirects to target a **different** page than the homepage (e.g., you have a separate `/dashboard` page and want to keep the homepage as something else), you can set the page ID in the `tt_config` table:

```sql
UPDATE wp_tt_config SET config_value = '42' WHERE config_key = 'dashboard_page_id';
```

(Replace `42` with your actual Page ID, and `wp_` with your table prefix if different.) If the config value is `0` (the default), the plugin falls back to `home_url('/')` — which is the most common setup.

## Behavior matrix

| Action | Non-admin user | Administrator |
|---|---|---|
| Visit homepage logged out | Login form | Login form |
| Visit homepage logged in | TT dashboard | TT dashboard |
| Visit `/wp-admin/*` logged out | Redirected to WP login (standard) | Redirected to WP login (standard) |
| Visit `/wp-admin/*` logged in | Redirected to homepage | Full wp-admin access |
| Visit `/wp-login.php` logged out | Redirected to homepage | Redirected to homepage |
| Visit `/wp-login.php?action=logout` | Standard WP logout → homepage | Standard WP logout → homepage |
| Visit `/wp-login.php?action=lostpassword` | Standard reset form (allowed) | Standard reset form (allowed) |
| Submit login form (success) | → Homepage (dashboard) | → Homepage (dashboard) |
| Click "Log out" in header | → Homepage (login form) | → Homepage (login form) |
| See admin bar on frontend | Hidden | Visible |
| Call admin-ajax.php | Works (not gated) | Works (not gated) |
| Call REST API | Works (not gated) | Works (not gated) |
| wp-cron runs | Works (not gated) | Works (not gated) |

## Post-install verification

1. Log out. Visit `yoursite.com/`. See the TT login form. ✓
2. Try `yoursite.com/wp-admin/` logged out — WP shows its own login page. ✓
3. Log in as a player or coach. End up on the homepage (dashboard). ✓
4. Try to visit `yoursite.com/wp-admin/` as that player/coach — get redirected back to the homepage. ✓
5. Admin bar absent on the frontend. ✓
6. Click Log out. Back to homepage, login form showing. ✓
7. Log in as an administrator. Admin bar visible; can click the site-name menu to jump to wp-admin. ✓
8. Admin can still reach `yoursite.com/wp-admin/` directly. ✓
9. Forgotten password flow: from login form → "Wachtwoord vergeten?" → email sent → click link → reset → logged in → land on dashboard. ✓
10. Existing AJAX features (submit evaluation, add goal, change goal status, delete goal) still work. ✓

## Recovery — if you lock yourself out

Unlikely but possible if something goes wrong. Connect via FTP and rename `wp-content/plugins/talenttrack` to something else (e.g. `talenttrack-disabled`). WordPress auto-deactivates the plugin. Log in as admin, restore the folder name, reactivate.
