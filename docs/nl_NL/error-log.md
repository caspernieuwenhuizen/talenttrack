<!-- audience: admin, dev -->

# Foutenlog

Als er iets misgaat binnen TalentTrack — een opslag die niet doorkomt, een import die halverwege stopt — logt de plug-in de technische oorzaak. Historisch gingen die meldingen alleen naar het PHP-foutenlog van de server, waar de meeste beheerders niet bij kunnen zonder hostingpaneel- of SSH-toegang.

Vanaf v4.20.119 houdt de plug-in ook een eigen begrensd foutenlog bij in de database, met een viewer in wp-admin.

## Waar vind je het

**TalentTrack → Error Log** in wp-admin (in de Configuratie-groep, naast Migraties).

Toegang vereist het leesrecht voor het *auditlog* (`tt_view_audit_log`) — dezelfde beheerdersgroep die het auditlog kan lezen. Administrators hebben altijd toegang.

## Wat het toont

Elke `error` en `warning` die de plug-in tijdens runtime logt, nieuwste eerst:

- **Datum** — wanneer het gebeurde (tijdzone van de site).
- **Niveau** — `error` (iets is mislukt) of `warning` (iets is gedegradeerd maar doorgegaan).
- **Bericht** — de technische gebeurtenissleutel, bijv. `admin.activity.save.failed`.
- **Context** — uitklapbare details: de databasefouttekst, het betrokken record-id en vergelijkbare diagnostische waarden.

Filter bovenaan op niveau en datumbereik. De viewer toont de nieuwste 100 passende regels; verklein het datumbereik om oudere te zien.

## Bewaartermijn

Het log is een rollende buffer: alleen de **nieuwste 500 regels** blijven bewaard. Oudere rijen worden bij elke schrijfactie automatisch opgeschoond — geen cronjob, geen handmatig opruimen, geen onbegrensde tabelgroei.

Het log is diagnostisch, geen audittrail. Voor "wie heeft wat gewijzigd" gebruik je het auditlog; voor de schemastatus de Migraties-pagina.

## Als de pagina meldt dat de tabel ontbreekt

De foutenlogtabel wordt aangemaakt door databasemigratie `0155_error_log`. Voer openstaande migraties uit via **TalentTrack → Migraties** en herlaad.

## Voor ontwikkelaars

- Regels worden geschreven door `Logger::error()` / `Logger::warning()` — geen wijzigingen op aanroeplocaties nodig; elke bestaande Logger-aanroep wordt automatisch vastgelegd.
- Persistentie kan het verzoek nooit breken: een ontbrekende tabel, een onbereikbare database of een coderingsfout degradeert stil naar de bestaande `error_log()`-schrijfactie.
- Dezelfde data is via REST beschikbaar op `GET /wp-json/talenttrack/v1/system/errors` (zelfde rechtencontrole) — zie [rest-api.md](../rest-api.md).
