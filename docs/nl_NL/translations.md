<!-- audience: admin -->

# Auto-vertaling

UI-tekst van de plugin wordt vertaald via het standaard `.po` / `.mo` proces. **Vrije tekst die gebruikers invoeren** — doelen, evaluatienotities, sessiebeschrijvingen, aanwezigheidsnotities — werd dat historisch niet. Een coach die in het Nederlands schrijft en een ouder die in het Frans leest zagen ruwe Nederlandse tekst.

De Translations-laag (#0025) sluit dat gat met een opt-in vertalingscache die op render-time draait.

## Standaard UIT

Tot je deze functie expliciet aanzet verandert er niets. Geen API-calls, geen verzending van brontekst, geen extra kosten. Brontekst wordt getoond zoals ingevoerd. Brondetectie draait ook niet bij opslaan.

Twee voorwaarden om aan te zetten:

1. Het selectievakje **GDPR Artikel 28 sub-processor** is aangevinkt. De gekozen aanbieder treedt op als sub-processor namens je club; je bevestigt die relatie door de functie aan te zetten.
2. De **primary engine** heeft geldige credentials.

Als één van beide ontbreekt weigert het formulier de schakelaar op AAN te zetten en toont een inline foutmelding.

## Waar je hem vindt

`wp-admin → TalentTrack → Configuration → Translations`.

## Engines

Twee engines worden meegeleverd; de laag is engine-agnostisch zodat een derde via het filter `tt_translation_engine_factory` kan worden ingehaakt.

| Engine | API | Free tier | Opmerkingen |
| --- | --- | --- | --- |
| DeepL (primary) | api-free.deepl.com (free keys) / api.deepl.com (betaald) | 500.000 tekens/maand | Auth via API-sleutel. Kwaliteit op NL ↔ EN/FR/DE/ES wordt over het algemeen beter geacht dan alternatieven. |
| Google Translate | Cloud Translation v3 | (geen free tier; ~€20 per miljoen tekens) | Auth via service-account JSON. Het project moet Cloud Translation API hebben ingeschakeld. |

Je kan een fallback-engine instellen die alleen wordt gebruikt bij een herstelbare fout in de primary (rate limit, 5xx, netwerk). Als beide falen geeft de laag de brontekst onveranderd terug.

## Soft kostenlimiet

Je stelt in:

- Een maandelijkse tekenlimiet. Standaard 200.000 — voldoende voor de meeste single-club installaties binnen de DeepL free tier.
- Een notify-at-threshold percentage (standaard 80%).

Wat er gebeurt op elk niveau:

- Onder de drempel: niets zichtbaars. Vertalingen lopen, cache vult, gebruiksteller telt op.
- Bij de drempel: een persistente admin-notice op het wp-admin dashboard. Vuurt eenmaal per maand (vastgelegd op de usage-row).
- Op 100% van de limiet: engine-calls stoppen voor de rest van de maand. Lezers zien brontekst. De notice escaleert naar errortoon met een "Raise the cap"-link. Geen save-time errors, geen request-blocking.

De volgende maand rolt automatisch over (period_start verandert); tellers worden impliciet gereset omdat ze per periode zijn opgesplitst.

## Brondetectie

Detectie draait bij opslaan, niet bij weergave. De eerste opslag van een vrij-tekstveld roept het detect-endpoint van de engine aan, slaat het resultaat op in `tt_translation_source_meta`, en dat wordt de brontaal voor elke weergave totdat het veld verandert.

Als de detectie-confidence onder 0.6 ligt valt de laag terug op de geconfigureerde site default content language. Die staat standaard op de short code van je WP locale (`nl` voor `nl_NL`, etc.) en is instelbaar in de Configuration-tab.

Bij een opslag van ongewijzigde tekst wordt niet opnieuw gedetecteerd — de source hash wordt naast de gedetecteerde taal opgeslagen.

## Cache-invalidatie

De cache wordt automatisch ongeldig wanneer een brontekst verandert. Een doel-titel aanpassen van "Aanname onder druk" naar "Aanname onder druk verbeteren" verwijdert elke gecachte vertaling van de oude tekst; de volgende lezer in elke doeltaal betaalt voor een verse vertaling.

Een "Clear cache now"-knop op de Configuration-tab wist de hele cache plus de source-meta tabel. Gebruik bij engine-wissel voor een schone start, of bij opt-out (gebeurt dan automatisch).

## Wat wordt vertaald

Vandaag: titels + beschrijvingen van doelen, titels + notities van sessies, aanwezigheidsnotities, en de player-dashboard schermen die deze rijen tonen.

Bewust niet:

- `tt_lookups.translations` (al een admin-beheerd vertaalsysteem).
- Bestandsnamen + media-metadata.
- De UI-strings van de plugin zelf (die gaan via het standaard `.po` / `.mo` proces).

Een nieuwe vrij-tekst call site toevoegen aan de inventaris betekent: de render wrappen met `TranslationLayer::render( $value )` en op de save path `TranslationLayer::detectAndCache( $entity_type, $entity_id, $field_name, $value )` aanroepen. Zie `docs/architecture.md` voor de conventies.

## Gebruikersvoorkeuren

Elke WP-gebruiker kiest hoe vertaalde inhoud wordt getoond, op het standaard wp-admin profielscherm:

- **Translated** (standaard) — toon vertaald; verberg bron.
- **Original** — nooit vertalen; altijd brontekst tonen.
- **Side-by-side** — toon `[vertaalde tekst] (origineel: [brontekst])`. Handig voor HoD of scouts die accuraatheid willen controleren.

Opgeslagen als `user_meta` key `tt_translation_pref`. Geldt site-breed voor die gebruiker.

## Privacy-positie

Wanneer je vertalen aanzet, voegt de plugin een paragraaf toe aan de WP **privacy policy editor** (Settings → Privacy → Privacy Policy Guide) over de sub-processor relatie. Kopieer die naar je gepubliceerde beleid waar nodig.

Vertalen uitzetten wist de volledige `tt_translations_cache` en `tt_translation_source_meta` tabellen. Broncontent (doelen, sessies, evaluaties) blijft ongemoeid — alleen de vertalings-derivatieven worden gewist.

## Sub-processor disclosure (DPA-links)

- **DeepL SE** — [www.deepl.com/privacy](https://www.deepl.com/privacy/)
- **Google LLC** — [Cloud DPA](https://cloud.google.com/terms/data-processing-addendum)

Verifieer de huidige links bij de aanbieder voordat je hier op steunt. De plugin volgt geen DPA-versies.

## Troubleshooting

- **Vertalingen verschijnen niet**: controleer dat de laag aan staat in Configuration. Controleer dat de gedetecteerde brontaal van de tekst klopt met wat je verwacht (`tt_translation_source_meta` rij voor die entiteit). Controleer dat het maandgebruik onder de cap zit (Configuration → Translations → Usage this month).
- **Engine auth-errors**: de laag logt naar de standaard TalentTrack-logger via `Logger::error( 'translations.engine.failed', … )`. Check `tt_logs` (of de dashboard log-viewer als die landt) voor de engine-naam + reason code.
- **Token-errors met Google**: de laag cachet het OAuth2 access-token in een transient voor ~1u. Het transient wordt gewist bij 401/403 en de volgende call haalt opnieuw. Handmatig resetten: clear alle transients via WP-CLI (`wp transient delete --all`) of via je cache-plugin.
- **Verkeerde brontaal gedetecteerd**: meest gangbare oorzaak is dat het veld korter is dan ~10 tekens. Detectie-drempel staat op 0.6; daaronder valt de site default in. Het veld uitbreiden en opslaan triggert opnieuw detectie.
