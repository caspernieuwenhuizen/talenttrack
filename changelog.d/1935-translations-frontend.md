# Translations config moved to the frontend (#1935)

Bump: minor

The auto-translation engine configuration is now a frontend view at
`?tt_view=translations` instead of bouncing to wp-admin. The Configuration
"Translations" tile opens it directly. The view covers everything the old
wp-admin tab did — enable toggle, primary/fallback engine, DeepL key and
Google service-account JSON (both kept masked with a "(set)" indicator),
site default language, monthly character cap, notify threshold, the GDPR
sub-processor confirmation, the read-only usage table, and the Clear cache
action. Settings save through a new REST surface
(`POST /translations/settings`, `POST /translations/clear-cache`) gated on
`tt_view_translations` / `tt_edit_translations`; the validation,
keep-on-blank credential handling, and GDPR opt-out cache purge all run in
the domain layer, shared with the wp-admin tab. The wp-admin tab stays as a
power-user fallback.
