# EBIT OLX eXchange

**WooCommerce → OLX.ba sinhronizacijski plugin** koji radi putem EBIT Sync servisa.

Sinhronizujte WooCommerce proizvode na [OLX.ba](https://olx.ba) direktno iz WordPress admina — kategorije, brendovi, atributi, slike, cijene i opisi — uz napredne funkcije poput zakazane sinhronizacije, masovnog BUMP-a, sponzoriranja i AI naslova.

---

## Funkcionalnosti

- **Sinhronizacija proizvoda** — objavljujte, ažurirajte, skrivajte/prikazujte i brišite WooCommerce proizvode na OLX.ba, pojedinačno ili masovno
- **Masovna sinhronizacija** — red čekanja s batch obradom i ponovnim pokušajima
- **Auto sinhronizacija (cron)** — pozadinski worker koji automatski održava OLX oglase usklađenim s WooCommerceom
- **Mapiranje kategorija, brendova i atributa** — povežite WooCommerce taksonomiju s OLX.ba kategorijama, brendovima i atributima
- **Cjenovni pravila** — fleksibilni obračun cijena između cijene u shopu i OLX cijene (marža/prilagodba)
- **Smart Image Cache** — obrađene slike se kešuju za brzu ponovnu sinhronizaciju
- **Dinamički bedž i okvir na slikama** — automatsko postavljanje promotivnih bedževa/okvira na slike proizvoda
- **Builder opisa** — globalni prefiks/sufiks i predlošci OLX opisa
- **AI naslovi** — generisanje naslova oglasa pomoću vještačke inteligencije
- **VIP artikli** — upravljanje OLX VIP oglasima
- **Sponzoriranje** — sponzorirajte oglase direktno iz WordPress admina
- **Masovni BUMP** — osvježite oglase masovno uz poštovanje OLX dnevnih limita
- **Provjera duplikata i dnevni populator** — opcione automatizacije za održavanje kataloga
- **Integracija u listu proizvoda** — OLX statusni stupci, filteri i bulk akcije na WooCommerce *Proizvodi* ekranu, plus metabox na ekranu za uređivanje pojedinog proizvoda
- **Sigurnost** — OLX lozinka se nikad ne čuva lokalno; sesije koriste tokene, kredencijali su enkriptovani, svi AJAX zahtjevi su zaštićeni nonce-om i provjerom korisničkih ovlaštenja

## Sistemski zahtjevi

| Zahtjev | Verzija |
|---|---|
| WordPress | 5.8 ili noviji |
| WooCommerce | 5.0 ili noviji (mora biti aktivan) |
| PHP | 7.4 ili noviji (8.x podržan) |
| PHP ekstenzije | `gd` (renderovanje bedževa/okvira), `openssl`, `json` |
| EBIT Sync server | Aktivan licencni ključ i URL servera (obezbeđuje EBIT Marketing) |
| OLX.ba nalog | Validan prodavački nalog |

Plugin je **SaaS klijent**: sva komunikacija s OLX.ba ide preko vašeg EBIT Sync server endpointa. WordPress host mora imati dozvoljene odlazne HTTPS konekcije.

## Instalacija

### A) Upload putem WordPress admina (preporučeno)

1. Preuzmite najnoviji release ZIP fajl plugina (`ebit-olx-exchange.zip`).
2. U WordPress adminu idite na **Dodaci → Dodaj novi → Upload dodatka**.
3. Odaberite ZIP fajl i kliknite **Instaliraj odmah**.
4. Kliknite **Aktiviraj**. WooCommerce mora biti instaliran i aktivan.

### B) Ručna instalacija putem FTP/SSH

1. Raspakirajte ZIP tako da dobijete folder `ebit-olx-exchange/` s fajlom `ebit-olx-exchange.php`.
2. Uploadujte folder u `wp-content/plugins/` na vašem serveru.
3. U WordPress adminu idite na **Dodaci** i aktivirajte **EBIT OLX eXchange**.

### C) Iz izvornog koda (za programere)

```bash
cd wp-content/plugins
git clone https://github.com/ebitmarketing/ebit-olx-exchange.git
cd ebit-olx-exchange
composer install   # opcionalno — plugin ima ugrađen rezervni PSR-4 autoloader
```

Zatim aktivirajte plugin iz WordPress admina. Composer je potreban samo za dev zavisnosti (PHPUnit, Brain Monkey, Mockery); u produkciji plugin sam učitava `src/`.

## Podešavanje i konfiguracija

Nakon aktivacije, u WordPress admin sidebaru pojavljuje se nova stavka menija **OLX eXchange**.

1. **Povežite se s EBIT Sync serverom** — otvorite **OLX eXchange → Postavke** i unesite:
   - **URL servera** — vaš EBIT Sync endpoint
   - **Licencni ključ** — vaš EBIT licencni ključ, zatim osvježite/validujte licencu
2. **Prijavite se na OLX.ba** — na tabu Postavke unesite vaš OLX korisničko ime i lozinku. Lozinka se šalje sync serveru samo radi autentifikacije i **nikad se ne čuva** u WordPressu; plugin čuva isključivo sesijski token. Kada sesija istekne, bit ćete upitani da se ponovo prijavite.
3. **Mapirajte kategorije** — na tabu *Mapiranje* povežite WooCommerce kategorije s OLX.ba kategorijama i podesite zemlju/grad.
4. **Mapirajte brendove i atribute** — uskladite WooCommerce brendove/atribute s OLX ekvivalentima i podesite podrazumijevane vrijednosti atributa po potrebi.
5. **Cijene i opisi** — podesite cjenovna pravila, globalni prefiks naslova i predložak opisa po potrebi.
6. **Postavke slika** — opciono omogućite dinamički bedž/okvir i opcije keširanja slika.
7. **Omogućite automatizaciju (opciono)** — uključite auto sinhronizaciju, podesite veličinu cron batcha i po potrebi omogućite skrivanje/prikazivanje, provjeru duplikata ili dnevni populator.

### Sinhronizacija proizvoda

- **Pojedinačni proizvod**: otvorite proizvod u WooCommerceu i koristite **OLX** metabox za sinhronizaciju, skrivanje ili uklanjanje oglasa.
- **Masovno**: odaberite proizvode na listi *Proizvodi* i koristite OLX bulk akcije, ili pokrenite **Masovnu sinhronizaciju** s plugin stranice.
- **BUMP / Sponzor / VIP**: koristite odgovarajuće tabove na OLX eXchange admin stranici (dostupnost ovisi o vašem licencnom paketu).

## Deinstalacija

Deaktivacija plugina briše zakazane cron događaje. Brisanje plugina putem **Dodaci → Obriši** pokreće `uninstall.php`, koji uklanja sve opcije plugina i baze podataka.

## Razvoj

```bash
composer install
vendor/bin/phpunit
```

- PSR-4 namespace `EbitOlx\` mapira na [src/](src/); testovi su pod `EbitOlx\Tests\` u `tests/`.
- Legacy admin stranica i tab predlošci nalaze se u [includes/](includes/); legacy shell je u [ebit-olx-exchange.php](ebit-olx-exchange.php).
- Unit testovi koriste PHPUnit 9.6 s Brain Monkey i Mockery za mockovanje WordPress funkcija.

### Struktura projekta

```
ebit-olx-exchange.php   Bootstrap plugina + legacy shell (meni, postavke, server proxy)
src/
  Plugin.php            Servisni kontejner / redoslijed pokretanja
  Admin/                Asset manager, stupci liste proizvoda, bulk akcije, metabox
  Ajax/                 AJAX handleri (sync, masovni sync, BUMP, sponzor, AI naslovi, licenca…)
  Api/                  OLX API klijent + EBIT Sync server klijent
  Cron/                 Pozadinski sync radnici (batch worker, dnevni populator, sponzor)
  Database/             Migracije + repozitorij queue-a proizvoda
  Image/                Procesor slika, rendereri bedževa i okvira
  License/              Klijent za licencu + ograničavanje funkcionalnosti
  Security/             Enkripcija kredencijala, nonce, validacija inputa
  Sync/                 Servis za sinhronizaciju, builderi payloada/opisa, kalkulator cijena
includes/               Admin stranica + predlošci tabova postavki
assets/                 CSS/JS (Select2, admin UI)
```

## Licenca

GPL — pogledajte [LICENSE.txt](LICENSE.txt).

---

**Autor:** Denis Nurboja · EBIT Marketing
kontakt 063-710-710