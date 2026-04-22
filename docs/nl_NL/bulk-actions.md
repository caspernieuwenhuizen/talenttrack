# Bulkacties (archiveren & verwijderen)

De meeste lijstpagina's in TalentTrack ondersteunen bulkacties — handig als je veel rijen tegelijk wilt archiveren of verwijderen.

## Hoe het werkt

1. Vink het selectievakje linksboven aan elke rij die je wilt bewerken (of het selectievakje in de kop om alles op de pagina te selecteren).
2. Kies een actie in de dropdown bulkacties bovenaan de tabel.
3. Klik op **Toepassen**.
4. Een bevestigingspagina toont wat er zal gebeuren. Bevestig of annuleer.

## Archiveren versus permanent verwijderen

### Archiveren

- Zet een `archived_at`-tijdstempel en `archived_by`-gebruiker op het record
- De rij verdwijnt uit actieve lijsten (de standaardweergave verbergt gearchiveerd)
- **Behoudt alle relaties** — evaluaties verwijzen nog steeds naar de speler, rapporten bevatten nog historische data, aggregaties blijven werken
- Omkeerbaar via de actie "Dearchiveren" op het tabblad Gearchiveerd
- **Dit is de standaardactie, aanbevolen voor de meeste gevallen**

### Permanent verwijderen

- Verwijdert de rij daadwerkelijk uit de database
- **Geblokkeerd** als het record afhankelijke data heeft (bijv. je kunt een speler niet verwijderen die evaluaties heeft)
- De controle op afhankelijke data voorkomt dat er per ongeluk evaluaties, doelen, sessies, enz. wees achterblijven
- **Onomkeerbaar**

## Best practice

Archiveer eerst. Verwijder alleen permanent als je zeker weet dat de data voor altijd weg moet zijn (bijv. een AVG-verzoek, opruimen van testdata).

## Filteren

Het tabblad Gearchiveerd op elke lijstpagina toont gearchiveerde records. Het standaardtabblad Actief verbergt ze.
