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

Migreringen lager bare:

- `saas_subscription_plan_modules`: bedriftens lagrede modulprisgrunnlag på planen.
- `saas_subscription_changes`: varig historikk over bekreftet månedspris,
  engangsetablering og operasjonsnøkkel.

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
- En eksisterende avtalt månedspris beholdes som grunnbeløp; bare nye modulers
  månedspriser legges til. Katalogpriser erstatter ikke gamle avtalepriser.
- Etablering registreres bare for nye moduler og trekkes ikke automatisk.
- Abonnementsperioder, betalingsreferanser og øvrige eksisterende vilkår beholdes.
- Alle valgte bedrifter lagres i én transaksjon.
- Ny innsending av samme bekreftelse returnerer tidligere lagringsresultat.

Planens samlede månedspris er autoritativ. Modulprisgrunnlaget kan derfor
avvike fra summen av en rabattert bedriftsavtale.

## Isolert verifisering

Tester godtar bare en egen MySQL-socket under `/tmp`, aldri appens tilkobling:

```sh
php saas_portal/tests/subscription_pricing_test.php
php saas_portal/tests/subscription_preview_test.php /tmp/isolert/mysql.sock
php saas_portal/tests/subscription_save_test.php /tmp/isolert/mysql.sock
node saas_portal/tests/subscription_preview_ui_test.js
```
