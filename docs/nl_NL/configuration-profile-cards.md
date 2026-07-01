<!-- audience: admin -->

# Configuratie — Profielkaarten

**Dashboard → Configuratie → Profielkaarten** (`?config_sub=profile-cards`)

Het tabblad **Profiel** van het spelersprofiel toont een set kaarten over de speler. Niet elke academie gebruikt elke kaart — een club die niet scout heeft bijvoorbeeld niets aan de kaart **Ontdekking**. Met deze instelling kies je academiebreed welke van die kaarten het tabblad Profiel toont.

De instellingen staan per club in `tt_config`, zodat een toekomstige multi-tenant-installatie de keuze van elke academie apart houdt. Opslaan gaat via `POST /wp-json/talenttrack/v1/config`, net als de andere inline-configuratieformulieren; de pagina is alleen voor beheerders / clubbeheerders.

## Welke kaarten je kunt verbergen

| Kaart | Wat het toont | Te verbergen? |
| --- | --- | --- |
| **Identiteit** | Geboortedatum, positie(s), voorkeursvoet, rugnummer en status van de speler. | Nee — altijd zichtbaar. Dit verankert wie de speler is. |
| **Academie** | Het team, de leeftijdscategorie en de datum van aansluiting van de speler. | Ja |
| **Ouders · Verzorgers** | Gekoppelde contactgegevens van ouders / verzorgers. Alleen voor staf. | Ja |
| **Ontdekking** | Hoe de speler is ontdekt — de scout, het ontdekkingsevenement, de club en de datum — voor spelers die via de scouting-trechter (Prospects) binnenkwamen. Alleen voor staf. | Ja |

Het paneel **PHV / VCT** staat hier niet bij: dat wordt bepaald door de VCT-moduleschakelaar, dus het uitzetten van de VCT-module verwijdert het al.

## Hoe het werkt

Elke kaart die je wilt tonen blijft **aangevinkt**; vink een kaart uit om die overal te verbergen. Standaard blijft elke nu getoonde kaart zichtbaar — je moet een kaart uitvinken om die te verbergen. Identiteit heeft geen selectievakje omdat die altijd wordt getoond.

Een kaart verbergen is alleen een **weergavekeuze**. Er worden geen gegevens verwijderd: als je de kaart weer aanvinkt, komt die meteen terug, met de inhoud intact. De kaart is verborgen voor iedereen in de academie.

De staf-only kaarten (**Ouders · Verzorgers** en **Ontdekking**) houden hun bestaande regel bovenop deze instelling: een speler die het eigen profiel bekijkt, of een ouder die het profiel van het kind bekijkt, ziet die kaarten nooit — ook niet wanneer ze academiebreed aanstaan. Ze hier verbergen verwijdert ze ook voor staf.

## Zie ook

- [Configuratie — Algemeen](configuration-general.md)
- [Configuratie en branding](configuration-branding.md)
