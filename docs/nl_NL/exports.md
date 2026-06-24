<!-- audience: coach, admin -->

# Bulk-exports

Het scherm **Exports** (`?tt_view=exports`) is de centrale plek voor de bulk-exporteurs van de academie — de downloads van hele tabellen / hele seizoenen, in tegenstelling tot de exports per record (een spelersfiche, een scoutingrapport-PDF, een POP) die op het detailscherm van elk record zelf blijven staan waar de bijbehorende id in context is.

## Indeling (v4.26.20+)

De exporteurs zijn gegroepeerd in doelgerichte secties, en elke exporteur is een ingeklapt accordeonblok zodat de pagina overzichtelijk blijft:

- **Selectie & spelers** — Spelerslijst, Teamselectie + seizoensstatistieken, Bondsregistratie (JSON).
- **Activiteiten & aanwezigheid** — Aanwezigheidsregister, Teamactiviteitenhistorie, Teamkalender (iCal).
- **Evaluaties** — Evaluatie-export, Spelerevaluaties (plat).
- **Doelen** — Doelenlijst.
- **Rapporten & mensen** — KPI-momentopname, Coach-/stafgids.
- **Beheer & compliance** — Auditlog, Volledige clubgegevens-back-up, Demo-data-rondrit.

De ingeklapte kop van elk blok toont de exporttitel plus een format-badge per ondersteunde uitvoer (CSV / XLSX / PDF / ICS / JSON / ZIP), zodat je ziet wat een export oplevert zonder hem te openen. Klap een blok uit om de filters in te stellen, een format te kiezen (als er meer dan één is), kolommen te kiezen (voor tabel-exports) en hem uit te voeren.

Elk blok is afgeschermd op rechten: je ziet alleen de exporteurs die je rol toestaat, en een sectie zonder toegestane exporteur toont geen kop. Een export uitvoeren is ongewijzigd — hij post naar de export-handler met een nonce en streamt het bestand.

## Losse export-tegels uitschakelen (beheer)

Een academiebeheerder kan **losse export-tegels** uitschakelen — bijvoorbeeld om het Auditlog, de Volledige clubgegevens-back-up of de Bondsregistratie te verbergen — zonder bestandsformaten of het hele Exports-scherm uit te zetten. De schakelaars staan op de beheerpagina **Modules**, onder de module **Export**: één schakelaar per tegel (`Export: Spelerslijst`, `Export: Auditlog`, …), gegroepeerd bij de overige per-academie functieschakelaars.

Alle tegels staan **standaard aan**, dus er verandert niets totdat je er één uitzet. Een tegel uitschakelen:

- verbergt hem van het Exports-scherm voor iedereen in de academie (inclusief beheerders — zo kan een academie die haar eigen back-ups niet wil blootstellen die verbergen), en
- weigert die export bij het eindpunt, zodat hij ook niet via een opgeslagen of zelfgemaakte link kan worden uitgevoerd.

De schakelaar **beperkt** de toegang alleen — een gebruiker heeft nog steeds de onderliggende rechten nodig om een ingeschakelde tegel te zien. Schakelaars zijn per academie (club-scoped) en worden in het auditlog vastgelegd.

De blokken zijn native `<details>`-elementen: toegankelijk met toetsenbord en schermlezer, en bruikbaar tot 360px breed waar ze in één kolom stapelen.
