# Sentralt malverksted

Åpnes fra **E-postmaler** i SaaS-oversikten, eller via lenken under Epost på en bedrift. Bare aktive SaaS-administratorer med rollen `admin`, `super_admin` eller `saas_admin` har tilgang til data/handlinger. En firmakonto eller en modulubegrenset global firmabruker får ikke dermed SaaS-administratorrettigheter.

## Data og klargjøring

Ingen mellomtabell eller ny mal-tabell opprettes. Eksisterende `email_templates` brukes:

- `company_id = NULL`: sentral bibliotekmal; brukes ikke direkte ved firmaspesifikk utsending.
- `company_id = firma-ID`: selvstendig firmamal.
- `source_template_id`: valgfri referanse til kopiens kilde; sletting av kilden bevarer kopien.
- `library_template_key`: generert hjelpekolonne med unik indeks for bibliotekets malnøkler. MySQL tillater ellers flere like nøkler når `company_id` er NULL.

Etter kontroll og sikkerhetskopi av faktisk installert skjema kan den eksplisitte CLI-migreringen kjøres på riktig server:

```
php saas_portal/migrations/install_email_templates.php --apply
```

Dette er ikke utført automatisk. Migreringen endrer ikke eksisterende malinnhold, sender ingen e-post og tildeler ingen moduler. Den beholder identifikatorkolonnenes eksisterende typer og kollasjon, gjør firmaeierskap valgfritt og legger til kildekobling og nøkkelbeskyttelse. Eksisterende mal- og loggtabeller må finnes og bruke InnoDB. MySQL DDL er ikke en samlet transaksjon; ved avbrudd må skjemaet kontrolleres før trygg omkjøring. Eksisterende SQL-dokumenter er referanser, ikke bevis på produksjonsskjema.

## Arbeidsflyt

Lagre en mal i biblioteket eller hos et firma. Ved kopiering kan flere firmaer velges. Henting fra firma til biblioteket er vanlig kopiering til biblioteket. Flytting har nøyaktig én mottaker.

Alle kopier er selvstendige. Redigering av kilden endrer ingen utdelte kopier. **Oppdater kopier** erstatter bare uttrykkelig valgte, koblede kopier. Ved nøkkelkollisjon må administrator velge avbryt, erstatning eller en ny nøkkel. Eksisterende nøkkel låses ved vanlig redigering fordi utsendingskoden bruker denne nøkkelen; nye nøkler blir ikke automatisk koblet til en utsendingshendelse.

Planlegging av kopiering, flytting, oppdatering og sletting viser navn, nøkkel og mottakere før bekreftelse. Endrede kilder/mottakere avviser en gammel bekreftelse. Hele fordelingen er transaksjonell. Flytting oppretter eller erstatter målmalen før originalen fjernes. Historiske loggrader beholdes med opprinnelig firma, mottaker og emne. Begge dokumenterte loggoppsett støttes: en ID-referanse i `template_id` tømmes ved fjerning, mens historisk nøkkel i `template_used` og lagret innhold bevares urørt. Brukstellingen for nøkkelbaserte logger avgrenses også på firma. Migreringen kontrollerer loggoppsettet og legger ikke til en oppdiktet historisk referanse. Andre kopier blir ikke slettet. Aktive maler kan være i bruk selv uten tidligere utsendinger, derfor vises varsel ved fjerning.

## Redigering og sikkerhet

Støttet innhold er enkel HTML for e-post, med begrensede inline-stiler, trygge lenker og HTTPS-bilder. Ustøttet/utrygg HTML avvises, ikke stille omskrevet. Forhåndsvisning bruker eksempeldata i en sandboxet iframe med restriktiv CSP. Eksterne bilder er blokkert i forhåndsvisningen for å unngå sporing og eksterne forespørsler. Innholdet vises aldri som HTML i selve administratorgrensesnittet.

Variabelvelgeren viser variablene for valgt, kjent maltype. Eksisterende familier for salg, arrangement, medlemskap, utleie og service støttes, inkludert variablene i de dokumenterte standardmalene. Hver kjent familie valideres mot sine egne variabler; en medlemskapsvariabel blir ikke dermed gyldig i salgskvitteringen. Andre nøkler kan brukes som malutkast, men krever en eksisterende eller senere eksplisitt utsendingskobling. Verktøyet sender ikke test-e-post og endrer ikke SMTP eller betalingskonfigurasjon.

## Lokal verifikasjon

```
php saas_portal/tests/email_templates_test.php
node --check saas_portal/assets/email-templates.js
python3 saas_portal/tests/run_email_template_mysql_test.py
```

PHP-testene bruker isolert SQLite i minnet, ikke applikasjonens databaseinnstillinger. Python-kommandoen oppretter en midlertidig, nettverksisolert MySQL-fixture og kontrollerer den idempotente migreringen, bibliotekets unike nøkler og bevaring av logger. Den virtuelle hjelpekolonnen unngår MySQLs begrensning for lagrede genererte kolonner kombinert med eksisterende fremmednøkler med kaskaderegler.

For lokal nettlesertest startes `php saas_portal/tests/email_templates_browser_fixture.php` separat og deretter `node saas_portal/tests/email_templates_browser_test.js`. Gjenta med `php saas_portal/tests/email_templates_browser_fixture.php key` for nøkkelbaserte logger. Dette bruker port 5001 og en midlertidig SQLite-database; applikasjonens innlogging eller databaseinnstillinger endres ikke. Stopp testserveren etterpå. Fixturefilen kan ikke kjøres som vanlig web-rute. Alle fem standardmalfamilier inngår. Testen dekker ikke produksjonsinnlogging eller reell utsending.
