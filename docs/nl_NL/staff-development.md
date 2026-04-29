<!-- audience: user -->

# Stafontwikkeling

De plugin volgt spelers tot in detail. Vanaf v3.58.0 volgt hij **de mensen die die spelers coachen** met dezelfde primitieven — doelen, evaluaties, een persoonlijk ontwikkelingsplan — plus een certificeringsregister dat aan spelerskant geen tegenhanger heeft. De module is opt-in (uit te zetten via wp-admin → Configuratie → Functieschakelaars), maar staat standaard aan voor nieuwe installaties.

## Wat je krijgt

Een nieuwe tegelgroep "Stafontwikkeling" op het dashboard, met vijf tegels:

- **Mijn POP** — je persoonlijke ontwikkelingsplan. Vier velden: sterke punten, ontwikkelpunten, acties komend kwartaal, en een vrije narratief voor context. Eén rij per (jij, seizoen). Opslaan overschrijft de vorige inhoud — gebruik het narratief voor historie.
- **Mijn stafdoelen** — persoonlijke ontwikkelingsdoelen. Elk doel heeft een titel, prioriteit, status, optionele einddatum, en een **optionele koppeling aan een certificering** (bv. "UEFA-B halen"). Bij koppeling verschijnt het doel ook op de certificeringentegel zodat het pad van "ik wil dit" → "ik heb dit" zichtbaar blijft.
- **Mijn stafevaluaties** — log van zelf-evaluaties en (als je rechten hebt) top-down evaluaties van het hoofd ontwikkeling. De evaluatieboom voor staf staat los van die van spelers; hij bevat standaard vijf hoofdcategorieën: *Coaching-vakmanschap / Communicatie / Methodische beheersing / Mentorschap / Betrouwbaarheid*. Subcategorieën voeg je toe via wp-admin → Configuratie → Evaluatiecategorieën, net als bij spelers.
- **Mijn certificeringen** — het badge-register. Elke rij heeft een uitgever, een "uitgegeven op"-datum, een optionele "verloopt op"-datum, en een optionele document-URL (Google Drive, OneDrive, intranet — de plugin host het bestand niet). Rijen die binnen 90 dagen verlopen krijgen een oranje pil, binnen 30 dagen rood, verlopen grijs. Dezelfde drempels sturen het workflow-template voor certificering-verloop.
- **Stafoverzicht** — de roll-up voor het hoofd ontwikkeling. Drie kaarten: open stafdoelen door de hele academie, top-down reviews die te oud zijn (geen review in de afgelopen 365 dagen), en certificeringen die binnen 90 dagen verlopen. Elke rij linkt naar het detailscherm. Alleen zichtbaar voor de rollen hoofd-ontwikkeling en clubbeheerder.

## Functionele rol: Mentor

De migratie voegt een **Mentor**-rol toe aan de bestaande lijst (Hoofdcoach / Assistent-coach / Manager / Fysio / Anders). Mentors worden aan een mentee gekoppeld via de tabel `tt_staff_mentorships` — toewijzen door de admin via de Mensen-pagina, zelfde flow als andere functionele rollen. Mentors krijgen beheerrechten op de stafontwikkelings-records van hun mentee (POP / doelen / evaluaties / certificeringen). Ze krijgen geen rechten op de hele staf.

## Workflow-templates

Vier templates registreren bij het workflow-systeem bij het opstarten van de module:

- **Jaarlijkse stafzelfevaluatie** — vuurt op 1 september om 00:00, één taak per niet-gearchiveerd stafmedewerker, deadline 30 dagen.
- **Top-down staf review** — dezelfde 1-september-cron, toegewezen aan het hoofd ontwikkeling, deadline 60 dagen. Eén taak per stafmedewerker.
- **Stafcertificering verloopt** — dagelijkse cron om 06:00 loopt `tt_staff_certifications.expires_on` na tegen vier drempels (90 / 60 / 30 / 0 dagen). De engine ontdubbelt op (certificering, drempel) zodat dezelfde rij niet twee keer afgaat. Toegewezen aan de staflid; het hoofd ontwikkeling krijgt CC via het bestaande meldingenkanaal.
- **POP-review nieuw seizoen** — vuurt wanneer een seizoen op "huidig" wordt gezet (de bestaande actie `tt_pdp_season_set_current` uit #0044). Fan-out: één taak per stafmedewerker, "Werk je POP bij voor het nieuwe seizoen".

Alle vier gebruiken voorlopig de gedeelde placeholder `StaffStubForm` — de taak afronden brengt je naar de relevante tegel waar je de data via de gewone UI invult. Speciale taakformulieren (rijker dan de placeholder) komen in een vervolg-PR als gebruik daarom vraagt.

## Wat dit *niet* is

- **Een setup-wizard voor nieuwe staf.** Dat is #0024. Deze module is persoonlijke ontwikkeling voor staf die al een `tt_people`-rij heeft.
- **Anonieme evaluaties.** De reviewer wordt op elke evaluatierij vastgelegd.
- **Documentopslag voor certificeringen.** v1 bewaart een URL naar het document; het document zelf staat op de plek waar je academie zoiets al bewaart.
- **Vergelijken tussen academies.** Per-club only.
- **Peer-evaluaties.** v1 ondersteunt `self` en `top_down`. Peer-reviews komen later als de vraag duidelijk wordt.

## Rechten

| Rechten-key | Toegewezen aan | Wat het toestaat |
| --- | --- | --- |
| `tt_view_staff_development` | Administrator, Hoofd Ontwikkeling, Clubbeheerder, Coach, Scout, Staf | Eigen stafontwikkelings-records zien op het dashboard. |
| `tt_manage_staff_development` | Administrator, Hoofd Ontwikkeling, Clubbeheerder | Records van iedere stafmedewerker bewerken. Mentors krijgen dit gescopet op hun mentee(s) via `tt_staff_mentorships`. |
| `tt_view_staff_certifications_expiry` | Administrator, Hoofd Ontwikkeling, Clubbeheerder | De club-brede roll-up van verlopende certificeringen zien. |

De auth-matrix legt nog een laag toe: een gewone gebruiker kan alleen schrijven naar records op zijn eigen `tt_people`-rij (afgedwongen in `StaffDevelopmentRestController::can_manage_target`).

## REST-eindpunten

Onder `talenttrack/v1`:

```
GET    /staff/{person_id}/goals           POST   /staff/{person_id}/goals
PUT    /staff-goals/{id}                   DELETE /staff-goals/{id}

GET    /staff/{person_id}/evaluations     POST   /staff/{person_id}/evaluations
PUT    /staff-evaluations/{id}             DELETE /staff-evaluations/{id}

GET    /staff/{person_id}/certifications  POST   /staff/{person_id}/certifications
PUT    /staff-certifications/{id}          DELETE /staff-certifications/{id}

GET    /staff/{person_id}/pdp             PUT    /staff/{person_id}/pdp     (upsert)

GET    /staff/expiring-certifications     (alleen voor managers)

GET    /staff/{person_id}/mentorships     POST   /staff/{person_id}/mentorships
                                          DELETE /staff-mentorships/{id}
```

Alle eindpunten gebruiken `permission_callback` op basis van de capability-laag (geen role-string-vergelijkingen). De PHP-views en de REST-controller delen dezelfde repositories, dus een toekomstige SaaS-frontend krijgt dezelfde antwoorden als de gerenderde HTML.
