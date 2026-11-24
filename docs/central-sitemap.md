# Samlet dynamisk nettstedskart

## Offentlige adresser

- `https://salesman.no/sitemap.xml` er ett vanlig XML-`urlset` så lenge
  protokollgrensene tillater det. Det lister hovedsiden, firmaenes kanoniske
  subdomener og tilbudenes delingslenker direkte.
- `https://salesman.no/robots.txt` peker til dette ene kartet. Portalen blokkeres
  ikke i robots.txt: søkemotorer må kunne lese portalens eksisterende `noindex`.
- `www.salesman.no` videresendes permanent til `salesman.no`, med samme sti og
  spørringsparametre. Det lages ingen separat www-oppføring.
- Bare dersom en grense nås, blir hovedkartet en `sitemapindex` med nødvendige
  deler på `/sitemap-1.xml`, `/sitemap-2.xml` osv. Delene er ikke firmabaserte.
  Grensene er 50 000 URL-er og 52 428 800 ukomprimerte byte per dokument.

## Datagrunnlag og offentlighet

Ingen nye tabeller eller migreringer. Generatoren leser bare:

- `tenant_companies`: aktivt firma, gyldig subdomene; `subdomain_active` må være
  aktiv dersom denne kolonnen finnes.
- `tenant_website_general`: én aktiv innstillingsrad uten vedlikeholdsmodus
  eller noindex. `tenant_website_config`: minst én aktivert, offentlig og
  publisert modul.
- `output_products`: bare synlige og offentlige oppføringer for publiserte
  moduler. Inaktive firmamoduler og systemmoduler utelates når disse
  tilgangstabellene finnes.
- `events_eventdata.share_code`: aktivt arrangement som ikke er utløpt
  (sluttdato, ellers startdato), med `?type=event`.
- `membership_plans.share_code`: aktivt, offentlig medlemskap til salgs,
  med `?type=membership`. Eksisterende `is_for_sale`/`for_sale` respekteres.
- `rental_object.share_code`, eller `rental_objects.share_code`: aktivt og
  tilgjengelig objekt, med `?type=rental`.
- `reservation_services.share_code`: aktiv timebestilling, med `?type=service`.
  `service` er typen dette prosjektet bruker i tilbudskatalogen. Firmaprosjektets
  delingsruter må bruke samme type; generatoren oppretter ikke disse rutene.

Tabellnavn oppdages uten hensyn til bokstavstørrelse, men spørringene bruker
faktisk installert navn. Tilbud uten `share_code` eller nødvendige
offentlighetsfelt utelates og meldes i serverloggen. Det brukes ikke produkt-ID,
`plan_code` eller oppdiktede koder som reserve. Ikke opprett nye tabeller eller
share_code-kolonner bare for å tilfredsstille denne generatoren: avklar
firmaprosjektets eksisterende datakontrakt.

Delingskodene tillater bare bokstaver, tall, `_` og `-`, maksimalt 128 tegn.
Private innholdsrader, slettede tilbud og medlems-eksklusive arrangementer
utelates. Reelle `updated_at`, ellers `created_at`, brukes som dato i `lastmod`;
feltet utelates når en gyldig dato ikke finnes. Genereringstid brukes aldri.

Før en ny versjon av kartet legges ut, kontrolleres robots.txt og hver kandidat
med offentlig HTTPS-GET uten innlogging eller cookies:

- Krever HTTP 200 og HTML; ingen videresendinger.
- Respekterer robots.txt, HTTP- og HTML-noindex, canonical og passordskjemaer.
- Tilbud utelates dersom firmaets offentlige hovedside ikke består kontrollen.
- Tillater bare salesman.no og ett gyldig firmasubdomene; DNS-adressen bindes
  til en offentlig IP. Private adresser, andre domener, HTTP, proxy og
  videresendinger er ikke tillatt.
- Ti samtidige forespørsler, maksimalt seks sekunder og 2 MiB per svar.

Dette kan ikke tolke JavaScript-innlogging eller garantere mot feilmerkede
«soft 404»-sider i et annet prosjekt. Firmaprosjektet må selv svare med riktig
status og noindex for privat, fjernet eller utilgjengelig innhold.

## Drift på webhotellet

PHP 8.2 eller nyere trenger PDO-driveren for eksisterende database, cURL, DOM/libxml og et
skrivbart midlertidig område. `.htaccess` krever Apache mod_rewrite.
Replits enkle PHP-utviklingsserver leser ikke Apache-reglene; bruk
`/sitemap.php` for å teste selve endepunktet der.

Ferdige XML-filer mellomlagres privat i PHPs midlertidige område, med fem
minutters levetid, filrettigheter 0600 og katalogrettigheter 0700.
Første forespørsel kan generere kartet. En kald/utløpt forespørsel har 20
sekunders arbeidsbudsjett. Ved stor datamengde bør webhotellets cron oppdatere
kartet hvert fjerde minutt, som **samme bruker og med samme PHP-konfigurasjon**
som nettstedet:

```sh
php /absolutt/sti/til/nettstedet/bin/build_sitemap.php
```

Bruk nettstedets faktiske serversti, ikke eksemplet over. CLI-jobben har ikke
webforespørselens tidsgrense. Det er ikke satt opp noen ekstern cron-jobb
automatisk. Ingen databaseinnstillinger, nøkler eller passord skal kopieres
til kommandolinjen.

Atomisk bytte og eksklusiv oppdateringslås hindrer halvferdig XML og parallelle
genereringer. Feil ved database eller generering gir HTTP 503 med Retry-After,
ikke et falskt vellykket/tomt kart eller ødelagt XML. Siste gode snapshot
overskrives ikke ved feil, men serveres heller ikke som om det fortsatt var
ferskt. Se serverloggen ved 503. Nettverkskontroller kan legitimt utelate
utilgjengelige enkeltsider; antall inkluderte/utelatte kandidater logges.

## Google Search Console

Kodeendringen verifiserer ikke Google-eierskap og sender ikke kartet til Google.
Dette må gjøres separat av en eier:

1. Verifiser en **domeneegenskap for `salesman.no`** med Googles DNS-TXT-post.
   Den dekker hoveddomenet og underdomener. Dersom det i stedet brukes
   URL-prefiksegenskaper, må alle verter som inkluderes, være verifisert.
2. Etter publisering og vellykket servergenerering: åpne
   `https://salesman.no/sitemap.xml`; kontroller at det svarer 200 uten
   innlogging eller videresending og inneholder faktiske sider.
3. Send inn **bare `https://salesman.no/sitemap.xml`** i Search Console.
   Google støtter samlet cross-site-kart gjennom Search Console når eierskapet
   til alle nettsteder er verifisert. Henvisning i robots.txt alene oppfyller
   ikke denne verifiseringen.
4. Kontroller rapporten etter at Google har behandlet kartet. Et sitemap er et
   oppdagelseshjelpemiddel, ikke en garanti for indeksering.

Offisiell veiledning:
https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap#cross-submit

## Lokale tester (ingen produksjonsdatabase eller eksterne HTTP-kall)

```sh
php tests/sitemap_test.php
python3 tests/sitemap_http_test.py
python3 saas_portal/tests/apache_routing_test.py
```
