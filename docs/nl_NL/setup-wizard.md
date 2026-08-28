---
title: Installatiewizard
group: basics
summary: De begeleide eerste-installatie die je TalentTrack inleidt.
audience: [admin]
views: [setup]
module: TT\Modules\Onboarding\OnboardingModule
capability: tt_view_setup_wizard
order: 40
---

# Installatiewizard

De installatiewizard is het eerste wat een verse TalentTrack-installatie toont. De wizard maakt het minimum aan dat een club nodig heeft om met de plugin te beginnen: een academienaam, een eerste team, je beheerprofiel en een frontend-dashboardpagina die als homepage van de site wordt ingesteld.

## Waar je hem vindt

De wizard is bereikbaar vanuit vier plekken — kies welke je het eerst tegenkomt.

- **Eerste installatie**: een banner verschijnt op het wp-admin TalentTrack-dashboard met een knop "Installatie starten".
- **Terugkomen**: zolang de wizard nog niet voltooid is, staat er een menu-item `TalentTrack → Welkom` direct onder Dashboard.
- **Configuratietab**: `Configuratie → Installatiewizard` toont de huidige status (bezig / afgerond) met knoppen **Hervatten** en **Opnieuw beginnen**.
- **Accountpagina**: zolang de wizard niet is afgerond toont `TalentTrack → Account` een kleine melding "Maak het opzetten van TalentTrack af." met een Hervatten-knop.
- **Na voltooiing**: de banner en het `Welkom`-menu-item verdwijnen, maar de Configuratietab en de melding op de Accountpagina blijven "Wizard opnieuw uitvoeren" / "Opnieuw beginnen" aanbieden. Opnieuw starten verwijdert **geen** gegevens die je al hebt ingevuld — je doorloopt alleen weer de stappen.

## Wat de stappen doen

1. **Welkom** — korte uitleg van de plugin en twee knoppen: *Mijn academie instellen* (gaat verder met de wizard) of *Probeer met voorbeeldgegevens* (verwijst naar de demogegevensgenerator onder TalentTrack → Demogegevens zodat je kunt verkennen voordat je commit).
2. **Basisgegevens academie** — naam, primaire kleur, seizoenlabel, standaard datumnotatie. Opgeslagen in `tt_config`.
3. **Je selectie importeren** — haal teams, spelers en staf uit een spreadsheet in plaats van ze opnieuw te typen. Uploaden toont eerst wat het bestand bevat; er wordt niets opgeslagen tot je bevestigt. Je kunt deze stap overslaan.
4. **Eerste team** — naam + leeftijdscategorie. Maakt één rij aan in `tt_teams`. Je kunt deze stap overslaan en teams later toevoegen via de Teams-weergave (spelers — geen teams — ondersteunen bulk-CSV-import).
5. **Eerste beheerder** — bevestigt je WP-account, maakt een `tt_people`-stafrecord gekoppeld aan dat account, en (optioneel) kent je de rol *Clubbeheerder* toe.
6. **Je staf toevoegen** — de coaches en staf die TalentTrack gaan gebruiken. Voor ieder van hen wordt een uitnodiging klaargezet en vastgehouden; er wordt niemand gemaild tot jij ze verstuurt.
7. **Dashboardpagina** — maakt een WordPress-pagina met de `[talenttrack_dashboard]` shortcode en stelt deze in als de homepage van de site, zodat je na inloggen direct op het dashboard landt. Bestaat er al een pagina met de shortcode, dan wordt die hergebruikt (en gepubliceerd als het een concept was), nooit gedupliceerd. Je kunt deze stap overslaan en de homepage later wijzigen onder Instellingen → Lezen.
8. **Klaar** — overzicht van wat is ingesteld plus kaarten met "Aanbevolen vervolgstappen" (spelers toevoegen, eerste coach uitnodigen, branding aanpassen, back-ups instellen). De knop **Naar dashboard** opent de in stap 7 aangemaakte frontend-dashboardpagina (of het wp-admin-dashboard als je de stap hebt overgeslagen).

Het Klaar-scherm zie je één keer, als je afrondt. Open je de wizard daarna opnieuw, dan krijg je een korte regel "Installatie is voltooid" met de resetlink, niet het overzicht.

## Overslaan vs afsluiten

- **Sla nu over** (banner): verbergt de banner maar laat het menu-item staan. Handig als je het later wilt instellen.
- **Probeer met voorbeeldgegevens** (Welkom-stap): sluit de wizard volledig af en stuurt je door naar de demogegevensgenerator. Het menu-item blijft beschikbaar; klikken brengt je terug naar stap 1.

## Opnieuw starten

Onder elke stap (en op het voltooiingsscherm) staat een kleine "Wizard opnieuw starten"-link. Die wist de status en brengt je terug naar stap 1. Nuttig om de installatie op een staging-site te testen voordat je live gaat.

## Hooks voor uitbreidingen

De wizard vuurt drie acties af waar andere modules op kunnen aanhaken:

```php
do_action( 'tt_onboarding_step_completed', string $step, array $payload );
do_action( 'tt_onboarding_completed' );
do_action( 'tt_onboarding_reset' );
```

Toekomstige epics zoals de monetisatie-trial-CTA of de back-up-instelwizard haken aan deze acties in plaats van de wizard zelf aan te passen.

## Opslag van status

- `tt_onboarding_state` (optie) — JSON `{ step, dismissed, payload }`. Formulierwaarden per stap blijven in `payload` zodat een pagina-refresh halverwege geen invoer verliest.
- `tt_onboarding_completed_at` (optie) — UNIX-timestamp die wordt geschreven wanneer de dashboardstap wordt voltooid of overgeslagen.

Bij het resetten van de wizard worden beide opties verwijderd.
