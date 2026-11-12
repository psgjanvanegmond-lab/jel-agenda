# JEL Agenda

Toont de agenda van het Jan van Egmond Lyceum op de website, rechtstreeks uit de Outlook-agenda **"JEL agenda website"**.

- Een automatische taak (GitHub Actions) haalt **elk half uur** de agenda op uit Outlook.
  Microsoft blokkeert verkeer van GitHub naar Outlook; daarom loopt het ophalen via een klein
  tussenstation op **Render** (map `proxy/`), dat met een geheime sleutel is afgeschermd.
- Het resultaat (`docs/agenda.json`) wordt gepubliceerd op GitHub Pages.
- De website laat de agenda zien met een klein stukje code: **2 maanden tegelijk met pijltjes, tijden, datumbereik en icoontjes**.
- Werkt GitHub of Outlook een keer niet, dan blijft de laatst bekende agenda staan.

## Agenda bijhouden (in Outlook)

| Situatie | Zo maak je het in Outlook | Op de website |
|---|---|---|
| Activiteit met tijd | Gewone afspraak | "14:45–16:15 uur" |
| Hele dag | "Hele dag" aan | geen tijd |
| Periode (toetsweek, vakantie, CE) | **Eén** afspraak "hele dag", van eerste t/m laatste dag | "vr 14 mei t/m di 1 jun" |
| Meerdere dagen, elke dag zelfde tijd (POP-gesprekken) | Herhalen: elke dag, tot de laatste dag | één regel: "ma 23 t/m do 26 nov · 17:00–20:00 uur" |
| Niet op de website | Afspraak op **Privé** zetten | wordt overgeslagen |

Locatie en notitie uit Outlook worden ook getoond. Het icoon wordt gekozen op basis van de titel. Zelf kiezen kan met een Outlook-categorie met een van deze namen: `vakantie`, `vrij`, `toets`, `uitslag`, `gesprek`, `groep8`, `activiteit`, `rooster`, `agenda`.

## Eenmalig instellen

**Render (tussenstation)**

1. Render → *New* → *Blueprint* → kies de opslagplaats `jel-agenda`.
2. Vul bij `ICS_URL` de ICS-link uit Outlook in. `TOKEN` (de geheime sleutel) maakt Render zelf.
3. Na het aanmaken: noteer het adres, bijv. `https://jel-agenda-proxy.onrender.com`, en kopieer
   de waarde van `TOKEN` (service → *Environment*).

**GitHub** (*Settings → Secrets and variables → Actions*):

1. `JEL_PROXY_URL` = het Render-adres
   `JEL_PROXY_TOKEN` = dezelfde sleutel als `TOKEN` op Render
2. **Pages aanzetten:** *Settings → Pages → Build and deployment → Source:* **GitHub Actions**
3. **Eerste keer draaien:** *Actions → Agenda bijwerken → Run workflow*
4. Controleer: <https://psgjanvanegmond-lab.github.io/jel-agenda/>

Gaat er iets mis, dan stuurt GitHub een e-mail en staat de fout bij *Actions*.

## Insluiten op de website (WordPress)

Voeg op de pagina een blok **Aangepaste HTML** toe met:

```html
<div data-jel-agenda-embed data-maanden="2">Agenda laden…</div>
<link rel="stylesheet" href="https://psgjanvanegmond-lab.github.io/jel-agenda/agenda.css">
<script src="https://psgjanvanegmond-lab.github.io/jel-agenda/agenda.js" defer></script>
```

Opties op de eerste regel:

- `data-maanden="3"`: 3 maanden tegelijk (`0` = alles onder elkaar)
- `data-aantal="5"`: alleen de eerstvolgende 5, zonder pijltjes (bijv. voor de homepage)
- `data-verleden="ja"`: ook afgelopen activiteiten

**Terugdraaien:** haal het blok weg, of zet het oude Agenda-blok weer aan.

## Technisch

- `build/class-jel-ics.php`: leest de ICS van Outlook (tijdzones, hele dagen, herhalingen, uitzonderingen) en voegt aaneengesloten dagen samen.
- `build/build.php`: maakt `docs/agenda.json`. Stopt met een fout als de link geen agenda is, of als Outlook alleen "bezet/vrij" doorgeeft.
- `docs/agenda.js` en `docs/agenda.css`: de weergave op de website.
- `.github/workflows/agenda.yml`: de automatische taak.
- `proxy/server.py` en `render.yaml`: het tussenstation op Render (alleen Python-standaardbibliotheek).

Lokaal testen: `php build/build.php agenda.ics docs/agenda.json`
