# Configurable email sender — name + address for plugin email (#2157)

Bump: minor

Configuration → General gains an **Email sender** group: set the name and
address every TalentTrack email is sent from, instead of inheriting the
WordPress default "WordPress <wordpress@…>". The values are applied to all
plugin email — account invitations and notifications as well as Comms
messages — via the wp_mail_from / wp_mail_from_name filters. Blank or invalid
values fall back cleanly to the WordPress default, so the From header is never
broken. Stored per club in tt_config, so a future multi-tenant install keeps
each academy's sender separate.
