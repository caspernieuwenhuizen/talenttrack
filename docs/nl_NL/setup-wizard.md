# Installatiewizard

De installatiewizard is het eerste wat een verse TalentTrack-installatie toont. De wizard maakt het minimum aan dat een club nodig heeft om met de plugin te beginnen: een academienaam, een eerste team en je beheerprofiel.

## Waar je hem vindt

- **Eerste installatie**: een banner verschijnt op het wp-admin TalentTrack-dashboard met een knop "Installatie starten".
- **Terugkomen**: zolang de wizard nog niet voltooid is, staat er een menu-item `TalentTrack → Welkom` direct onder Dashboard.
- **Na voltooiing**: het menu-item en de banner verdwijnen. Om opnieuw in te stappen klik je op "Wizard opnieuw starten" — dat brengt je terug naar stap 1 en wist eventueel opgeslagen voortgang.

## Wat de vijf stappen doen

1. **Welkom** — korte uitleg van de plugin en twee knoppen: *Mijn academie instellen* (gaat verder met de wizard) of *Probeer met voorbeeldgegevens* (verwijst naar de demogegevensgenerator onder Tools zodat je kunt verkennen voordat je commit).
2. **Basisgegevens academie** — naam, primaire kleur, seizoenlabel, standaard datumnotatie. Opgeslagen in `tt_config`.
3. **Eerste team** — naam + leeftijdscategorie. Maakt één rij aan in `tt_teams`. Je kunt deze stap overslaan als je teams later via CSV in bulk wilt toevoegen.
4. **Eerste beheerder** — bevestigt je WP-account, maakt een `tt_people`-stafrecord gekoppeld aan dat account, en (optioneel) kent je de rol *Clubbeheerder* toe.
5. **Klaar** — overzicht van wat is ingesteld plus vier kaarten met "Aanbevolen vervolgstappen": spelers toevoegen, eerste coach uitnodigen, branding aanpassen, frontend-dashboardpagina aanmaken.

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

Toekomstige epics zoals de monetisatie-trial-CTA (#0011) of de back-up-instelwizard (#0013) haken aan deze acties in plaats van de wizard zelf aan te passen.

## Opslag van status

- `tt_onboarding_state` (optie) — JSON `{ step, dismissed, payload }`. Formulierwaarden per stap blijven in `payload` zodat een pagina-refresh halverwege geen invoer verliest.
- `tt_onboarding_completed_at` (optie) — UNIX-timestamp die wordt geschreven wanneer stap 5 wordt bereikt.

Bij het resetten van de wizard worden beide opties verwijderd.
