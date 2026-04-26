<!-- audience: admin, dev -->

# Workflow-motor — cron-instellingen

De workflow-motor gebruikt de ingebouwde cron van WordPress (WP-cron) om geplande triggers af te vuren. WP-cron is op de meeste gehoste sites "goed genoeg", maar het is geen echte cron — het draait alleen wanneer iemand de site bezoekt. Op een site met weinig verkeer of een host met agressieve caching kunnen geplande taken volledig stoppen met afvuren. Deze pagina legt uit hoe je dat detecteert en oplost.

## Hoe weet je dat er een probleem is

De TalentTrack admin-pagina's tonen een banner wanneer de motor detecteert dat geplande taken niet betrouwbaar afgevuurd zijn:

> **TalentTrack workflow:** Geplande taken lijken niet betrouwbaar te draaien op deze installatie. De WP-cron van je host heeft mogelijk aandacht nodig.

De detectie is simpel — er is ten minste één open of in behandeling-zijnde taak met een deadline van meer dan 24 uur in het verleden. Als de uurlijkse tik zou zijn afgevuurd, was de taak verplaatst naar "te laat" en zou de herinneringsmail vóór deadline zijn verstuurd. Beide hangen af van WP-cron.

De banner is per gebruiker 7 dagen weg te klikken. Als de onderliggende conditie blijft bestaan, komt hij automatisch terug.

## Snelle diagnose

In een terminal met shell-toegang:

```bash
wp cron event list --next_run_relative
```

Zoek naar `tt_workflow_cron_tick`. Die zou binnen `1 hour` (1 uur) moeten staan ingepland. Staat hij uren over tijd, dan draait WP-cron niet.

Geen shell-toegang? De WP-Crontrol plugin (gratis, op de WordPress.org plugin-directory) toont dezelfde informatie in wp-admin.

## Oplossing — optie 1: echte cron (aanbevolen)

Schakel het lazy-laden van WP-cron uit en draai het via een echte cronjob. Dit is de productie-set-up.

### 1. Schakel WP-cron op page-load uit

Voeg dit toe aan `wp-config.php`, boven de regel `/* That's all, stop editing! */`:

```php
define( 'DISABLE_WP_CRON', true );
```

Hiermee stopt WordPress met geplande taken uitvoeren tijdens pagina-bezoeken. Zonder vervanging (stap 2) zal *niets* meer geplands draaien — doe deze stappen dus in deze volgorde, niet apart.

### 2. Plan een echte cron

#### Linux / managed hosting cPanel

Voeg een cronjob toe die elke 5 minuten `wp-cron.php` aanroept:

```
*/5 * * * * curl -sS https://JOUW-SITE.com/wp-cron.php >/dev/null 2>&1
```

Of, als `curl` niet beschikbaar is:

```
*/5 * * * * wget -q -O - https://JOUW-SITE.com/wp-cron.php >/dev/null 2>&1
```

#### Hosts met WP-CLI geïnstalleerd

```
*/5 * * * * cd /path/to/wp && /usr/local/bin/wp cron event run --due-now >/dev/null 2>&1
```

Dit is sneller (geen HTTP-overhead) en betrouwbaarder.

### 3. Verifieer

Na 10 minuten draai je `wp cron event list --next_run_relative` opnieuw. De `tt_workflow_cron_tick` zou gestaag vooruit moeten gaan. De TalentTrack-banner verdwijnt binnen een dag wanneer late taken zijn verwerkt.

## Oplossing — optie 2: externe monitoringdienst

Kun je geen echte cronjob toevoegen (sommige shared hosts), gebruik dan een externe dienst om `/wp-cron.php` op een rooster te pingen:

- **EasyCron** — gratis tier dekt 10-minuten-intervallen.
- **Cron-job.org** — gratis, 1-minuut-intervallen beschikbaar.
- **Uptime Robot** — primair een monitor, maar pingt standaard elke 5 minuten.

Configureer de dienst om een GET te doen op `https://JOUW-SITE.com/wp-cron.php?doing_wp_cron` elke 5 minuten. Schakel WP-cron *niet* uit in `wp-config.php` bij deze aanpak — laat de page-load fallback gewoon werken, met de externe pinger als vangnet voor stille periodes.

## Wat de motor daadwerkelijk plant

De uurlijkse cron-tik (`tt_workflow_cron_tick`) doet het volgende:

1. Loop door elke ingeschakelde `cron`-rij in `tt_workflow_triggers`.
2. Evalueer voor elk de `cron_expression` tegen `last_fired_at`.
3. Is een afvuurbeurt verschuldigd (en binnen het laatste uur, om dubbele afvuren na lange downtime te voorkomen), dispatch het template via `TaskEngine::dispatch()`.
4. Schrijf `last_fired_at` zodat de volgende tik hetzelfde venster niet opnieuw afvuurt.

Templates van Phase 1 die hierop leunen:

- **Wekelijkse zelfevaluatie speler** — `0 18 * * 0` (zondag 18:00).
- **Kwartaaldoelen** en **Kwartaalreview HoD** — `0 0 1 */3 *` (00:00 op de 1e van elke 3e maand).

Het admin-scherm in Sprint 5 laat je deze per installatie aanpassen. Tot die tijd staan de geseede standaardwaarden actief.

## Wat de motor expliciet niet doet

- **Geen retry bij mislukte dispatch.** Crasht de motor tijdens een tik, dan wordt `last_fired_at` toch geschreven (om eindeloze loops op dezelfde kapotte staat te voorkomen). Houd je foutlogs in de gaten op `[TalentTrack workflow]`-regels.
- **Geen drift-inhaalslag.** Als WP-cron 3 dagen plat heeft gelegen en je het herstelt, vuurt de motor géén 3 weekly self-evals tegelijk af — alleen de meest recente. Dat is bewust.
- **Geen volledige cron-expressie-evaluator.** De vocabulaire is bewust beperkt (zondag op HH:MM, "elke Nde maand om 00:00 op de 1e"). Alles daarbuiten is een code-uitbreiding.

## Zie ook

- [Workflow- en takenmotor](workflow-engine.md) — het overzicht.
- [WordPress cron documentatie](https://developer.wordpress.org/plugins/cron/) — algemene WP-cron-werking.
