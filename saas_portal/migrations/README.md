# Abonnementsdatoer og betalingsadministrasjon

Ingen migrering kjøres automatisk ved innlogging, sidevisning eller lagring.
Den eksisterende prisinstallereren og SQL-filen klargjør nå også nullable
`saas_subscription_plans.subscription_start` og `due_date`. Bare datoene kan
klargjøres eksplisitt med:

```sh
php saas_portal/migrations/install_subscription_dates.php --apply
```

Dette legger ikke til tabeller, endrer ikke eksisterende datoer og utfyller
ikke gamle abonnementer. Første bekreftede modullagring setter manglende start
til kalenderdatoen i Europe/Oslo og manglende forfall til én kalendermåned etter
start, avkortet til siste gyldige dag i måneden. Senere lagring beholder datoene.
Eksisterende betalingsperioder og faktisk betalingsstatus endres ikke.

Abonnementsfanen viser lagret netto månedsbeløp. Brutto/rabattbeløp hentes fra
lagrede modulpriser og vises bare når disse stemmer med den lagrede avtalen;
gjeldende katalogpriser brukes ikke til å rekonstruere gamle avtaler.

Betalingsredigering og datoendringer krever en aktiv SaaS-adminrolle
(`admin`, `super_admin` eller `saas_admin`), CSRF og en bedriftsbundet
femminutters redigeringstillatelse. `global_admin` gir ikke automatisk denne
administrasjonsrettigheten. Eksisterende innlogginger og roller endres ikke.

**Lagringsmåte for betalingsnøkler:** Kolonnenavn som slutter på `_encrypted`
er ikke bevis på hvilket format betalingsmodulen bruker. Som standard
bevares eksisterende verdier og erstatning i slike felt bruker kun en kjent
encoder. Prosjektet inneholder ikke betalingsmodulens encoder/decoder.
Ingen ny krypteringsstandard gjettes ut fra kolonnenavn.

Dersom betalingsmodulen faktisk leser klartekst, kan SaaS-admin velge
`credential_storage=plaintext` og bekrefte `credential_storage_confirmed=true`
i tillegg til vanlig opplåsing og lagringsbekreftelse. Da lagres kun nye,
uttrykkelig innskrevne hemmelige verdier direkte, også i felt med `_encrypted`
i navnet. Dette gir **ikke kryptering i applikasjonen**, og må ikke brukes med
en betalingsmodul som forventer dekryptering. Valget er ikke et automatisk
standardvalg, og konverterer ikke andre eksisterende nøkler. Tomme felt
bevarer eksisterende verdi. Uttrykkelig fjerning krever ingen encoder.
Lagringsvalget logges uten noen nøkkelverdier.

Ved faktisk kryptert lagring: Koble den verifiserte eksisterende encoderen inn med
`PaymentCredentialCipher::configure($encoder)` i en felles bootstrap etter at
helperen er lastet; tjenester kan også få samme verifiserte closure eksplisitt.
Denne koblingen er foreløpig ikke konfigurert. Ved standard lagringsvalg
bevares eksisterende ciphertext, og erstatning av slike verdier avvises
atomisk til kontrakten er kjent.
Ikke bruk en testencoder i applikasjonen. Nøkler returneres aldri til klienten.

E-postoppsett følger samme SaaS-adminbeskyttelse, CSRF, femminutters opplåsing,
versjonskontroll og eksplisitte lagringsbekreftelse. Nye kontoer kan opprettes
direkte i portalen. Hvis flere kontoer finnes, må en konto velges uttrykkelig;
`configuration_id` kontrolleres mot bedriften. `create_new=true` oppretter en
ny konto og må ikke kombineres med en eksisterende konto-ID. Tomme passord
bevarer verdiene; `clear_secrets` kan eksplisitt fjerne `smtp_password` eller
`imap_password`. Passord returneres aldri. Klartekstvalg gjelder samme
lagringskontrakt og advarsel som for betalingsnøkler.

Felter som ikke finnes i installert e-postskjema, rapporteres med
`supported_fields` og hindrer ikke opprettelse av SMTP-oppsett med feltene som
finnes. Ingen DDL kjøres automatisk for valgfrie IMAP- eller sikkerhetsfelter.
Tilkoblingstesten er SaaS-admin- og CSRF-beskyttet og kontrollerer
nettverkstilkobling, ikke SMTP-/IMAP-passordautentisering eller faktisk sending.

Manglende koblet betalingskonfigurasjon kan repareres ved uttrykkelig lagring
av leverandøroppsettet, uten å endre en annen bedrifts rad eller opprette en
duplikatleverandør. SaaS-adminens ID settes ikke i `created_by`-felt som kan
referere til tenant-administratorer; SaaS-handlingen registreres separat i
eksisterende aktivitetslogg.

# Abonnementspriser

Kodearbeid og tester krever ikke tilgang til den faktiske databasen.

## Eksplisitt installasjon

Når skjemaendringen skal tas i bruk på appens eksisterende MySQL-database:

```sh
php saas_portal/migrations/install_subscription_pricing.php --apply
```

Alternativt kan `subscription_pricing.sql` kjøres direkte i en MySQL-klient.
SQL-filen klargjør bare manglende kolonner på eksisterende plantabell.
Ingen nye tabeller opprettes.

Migreringen klargjør:

- Manglende `max_users`, rabattkolonner, `subscription_start` og `due_date`
  på `saas_subscription_plans`. Eksisterende kolonner og verdier beholdes.

Eksisterende planer, abonnementer, kontoer og modultilganger masseendres ikke.
Migreringen kjøres ikke ved serverstart eller fra nettforespørsler.
Eksisterende historikktabeller beholdes urørt, men kreves ikke for lagring.

`system_modules` må allerede ha `price_monthly`, `price_setup` og `price_currency`.
Manglende pris er ikke gratis; gratis settes eksplisitt til `0.00`.

## Lagringsflyt

Forhåndsvisning er fortsatt skrivebeskyttet. Etter modulvalg må administratoren
krysse av for bekreftelse og bruke «Bekreft og lagre». Serveren låser bedriftene,
beregner på nytt og sammenligner med forhåndsvisningens hash.

- En delt plan kopieres uten å endre de andre bedriftenes vilkår.
- Dagens moduler forhåndsvelges. Det bekreftede utvalget erstatter features,
  inkludert fravalg og tomt utvalg. Ingen ansatte, kontoer eller moduldata slettes.
- Sluttpris er summen av de valgte modulenes månedspriser etter rabatt.
  Tidligere avtalepris erstattes bare etter uttrykkelig bekreftelse.
- Prisoversikten har tre linjer: Etablering (nye moduler etter rabatt),
  Månedlig (etter rabatt), og Total (etablering pluss én måned).
- `max_users` lagres på den bedriftsspesifikke planen. Null er ukjent og sperrer
  nye kontoer frem til oppsett; 0 tillater ingen aktive SuperUsers. Grensen kan
  ikke senkes under dagens aktive firmakontoer. Kontoopprettelse og planendring
  låser samme bedrift for å hindre samtidighetsfeil.
- Etablering beregnes bare for nye moduler og trekkes ikke automatisk.
- Abonnementsperioder, betalingsreferanser og øvrige eksisterende vilkår beholdes.
- Alle valgte bedrifter lagres i én transaksjon.
- Gamle prisforhåndsvisninger avvises etter en endring. Låsing og transaksjoner
  hindrer doble abonnementer ved samtidig lagring, uten en egen historikktabell.

Planens lagrede månedspris er den avtalte nettoprisen. Katalogprisene kan senere
endres uten at dette endrer avtalen. Katalogestimat, katalograbatt og katalognetto
vises derfor separat fra avtalt månedspris. Historisk brutto/rabattbeløp vises som
ukjent når grunnlaget ikke er bevart; gjeldende katalogpriser brukes ikke til å
fabrikere et historisk avtalegrunnlag, heller ikke ved 100 prosent rabatt.

## Tabelloversikt og opprydding

| Tabell | Ansvar |
|---|---|
| `system_modules` | Eneste katalog for modulnavn, månedspris, etableringspris og valuta. |
| `saas_subscription_plans` | Bedriftens autoritative modulnøkler i `features`, avtalte månedspris, prosent-rabatter, `max_users`, abonnementsstart og forfall. En delt plan kopieres ved bekreftet bedriftsendring eller individuell datoendring. |
| `tenant_subscriptions` | Bedriftens kobling til planen og utstederen, status og betalingssyklus. |
| `tenant_admin_modules` | Firmabrukerens egne tildelinger, begrenset av bedriftens features. |
| `tenant_company_modules` | Eldre kompatibilitetsrader som speiles ved bekreftet lagring. De gir aldri tilgang utover features og brukes ikke til å forhåndsvelge ekstra moduler. Historiske rader kan brukes til å unngå ny etablering på tidligere tildelte moduler. |
| `saas_subscription_changes` | Eventuell eldre historikk beholdes urørt. Portalen oppretter, leser eller skriver ikke denne tabellen og krever den ikke for lagring. |

`saas_subscription_plan_modules` er ikke lenger nødvendig. Ingen portalforespørsel
leser, skriver eller krever den, og hverken SQL- eller PHP-migreringen oppretter den.
En allerede installert tabell beholdes **helt urørt**. Ikke slett den som del av
denne migreringen: senere fysisk opprydding krever backup, kontroll av andre
applikasjoners avhengigheter og uttrykkelig godkjenning. Historiske priser i den
kan eksporteres før eventuell separat sletting.

Gamle bulk-endepunkter bruker samme bekreftede abonnementsflyt; enkelt-toggling
av bedriftstilganger avvises. Den uavhengige additive tildelingsmetoden er fjernet.
Ingen migrering eller endring i faktiske data skjer ved oppstart eller lesing.

## Rabatter

`plan_setup_discount` og `plan_monthly_discount` er prosent mellom 0 og 100,
med maksimalt to desimaler. De redigeres per bedrift sammen med modulvalg og
brukergrense. Priser beregnes i heltalls-øre med avrunding til nærmeste øre.
10 prosent av 1750 gir 1575 per måned. Grunnpris, rabatt og nettopris vises
hver for seg. Etablering gjelder bare nye moduler; hele utvalgets etableringsverdi
vises separat og registreres ikke som en ny belastning.

## Globale SaaS-brukere

Klargjør eksplisitt, uten å konvertere kontoer:

```sh
php saas_portal/migrations/install_global_saas.php --apply
```

Dette gjør SaaS-ansattlenken valgfri og klargjør koblings- og sesjonstabeller.
Globale identiteter får rollen `global_admin`, bruker eksisterende SaaS-innlogging
med e-post og har alle aktive moduler for alle bedrifter. Firmakontoer oppgraderes
aldri bare fordi ansattlenken mangler eller slettes. Migreringen gir ikke noen
eksisterende konto nye rettigheter.

På oversikten må en konkret firmauavhengig konto først forhåndsvises og deretter
bekreftes. Passord beholdes uten å sendes til klienten. E-post-/brukernavnkollisjoner,
uklart kontoeierskap og MFA-kontoer blokkeres for separat avklaring. Ved konvertering
deaktiveres den gamle firmainnloggingen og dens økter; den globale identiteten
forblir uavhengig hvis firmaet eller den gamle kontoen senere slettes.

Serveren leser aktiv SaaS-rolle på hver forespørsel fra en konvertert global identitet.
Eksisterende SaaS-innlogging og dens tidligere sesjonsregler beholdes; vanlige
SaaS-operatører trenger ikke den nye migreringen for å logge inn. Globale sesjoner vises
i den samlede øktoversikten og kan avsluttes der. Deaktivering og tilbakekalling
slår igjennom uten å stole på en gammel rolle lagret i PHP-sesjonen.

## Isolert verifisering

Tester godtar bare en egen MySQL-socket under `/tmp`, aldri appens tilkobling:

```sh
php saas_portal/tests/subscription_pricing_test.php
php saas_portal/tests/subscription_preview_test.php /tmp/isolert/mysql.sock
php saas_portal/tests/subscription_save_test.php /tmp/isolert/mysql.sock
node saas_portal/tests/subscription_preview_ui_test.js
```
