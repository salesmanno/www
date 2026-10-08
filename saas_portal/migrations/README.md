# Abonnementsdatoer og betalingsadministrasjon

Ingen migrering kjøres automatisk ved innlogging, sidevisning eller lagring.
Den eksisterende prisinstallereren og SQL-filen klargjør nå også nullable
`tenant_subscriptions.subscription_start` og `due_date`. Bare datoene kan
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

# Abonnementspriser

Kodearbeid og tester krever ikke tilgang til den faktiske databasen.

## Eksplisitt installasjon

Når skjemaendringen skal tas i bruk på appens eksisterende MySQL-database:

```sh
php saas_portal/migrations/install_subscription_pricing.php --apply
```

Alternativt kan `subscription_pricing.sql` kjøres direkte i en MySQL-klient.
SQL-filen tilpasser selv fremmednøkkelkolonnene til eksisterende metadata;
den trenger ingen tilgang til databasen mens koden utvikles i Replit.

Migreringen klargjør:

- `saas_subscription_plan_modules`: bedriftens lagrede modulprisgrunnlag på planen.
- `saas_subscription_changes`: varig historikk over bekreftet månedspris,
  engangsetablering og operasjonsnøkkel.
- Manglende `max_users` og rabattkolonner på planen, samt tidligere/nye grenser
  og rabatter i endringshistorikken. Eksisterende kolonner og verdier beholdes.

Eksisterende planer, abonnementer, kontoer og modultilganger masseendres ikke.
Migreringen kjøres ikke ved serverstart eller fra nettforespørsler.
ID-kolonnenes tegnsett og kollasjon hentes programmatisk fra eksisterende
referansetabeller, slik at fremmednøkler blir kompatible.

`system_modules` må allerede ha `price_monthly`, `price_setup` og `price_currency`.
Manglende pris er ikke gratis; gratis settes eksplisitt til `0.00`.

## Lagringsflyt

Forhåndsvisning er fortsatt skrivebeskyttet. Etter modulvalg må administratoren
krysse av for bekreftelse og bruke «Bekreft og lagre». Serveren låser bedriftene,
beregner på nytt og sammenligner med forhåndsvisningens hash.

- En delt plan kopieres uten å endre de andre bedriftenes vilkår.
- Dagens moduler forhåndsvelges. Det bekreftede utvalget erstatter features,
  inkludert fravalg og tomt utvalg. Ingen ansatte, kontoer eller moduldata slettes.
- Sluttpris er summen av de valgte modulenes månedspriser. Tidligere avtalepris
  vises separat og erstattes bare etter uttrykkelig bekreftelse.
- Samlet etableringsverdi og etablering som tilkommer nå vises separat.
- `max_users` lagres på den bedriftsspesifikke planen. Null er ukjent og sperrer
  nye kontoer frem til oppsett; 0 tillater ingen aktive SuperUsers. Grensen kan
  ikke senkes under dagens aktive firmakontoer. Kontoopprettelse og planendring
  låser samme bedrift for å hindre samtidighetsfeil.
- Etablering registreres bare for nye moduler og trekkes ikke automatisk.
- Abonnementsperioder, betalingsreferanser og øvrige eksisterende vilkår beholdes.
- Alle valgte bedrifter lagres i én transaksjon.
- Ny innsending av samme bekreftelse returnerer tidligere lagringsresultat.

Planens samlede månedspris er autoritativ. Modulprisgrunnlaget kan derfor
avvike fra summen av en rabattert bedriftsavtale.

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
