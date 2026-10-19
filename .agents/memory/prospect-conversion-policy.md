---
name: Interessent til firma
description: Krav til interessestatus ved opprettelse av firma fra interessentoversikten.
---

Bedriften må være markert som interessert i tabellen når den opprettes fra interessentoversikten.

**Why:** Brukeren presiserte: «Men bedriften må være markert som interessert i tabellen».

**How to apply:** Bruk den lagrede interessestatusen som vilkår i opprettelsesflyten, ikke bare et ulagret felt i nettleseren.

Bare notater og interesse skal kunne redigeres i kontaktoversikten. Firmaopplysninger, kontaktperson og øvrige kontaktdata skal være skrivebeskyttet. Interesse skal være en av/på-bryter.

**Why:** Brukeren korrigerte den generelle redigeringsflaten: «Eneste som skal kunne redigeres er notater eller interesse».

**How to apply:** Begrens både skjermen og serverens oppdateringer til disse to opplysningene. Ikke behold fritekstredigering av status eller andre handlinger som endrer kontaktopplysninger.

SaaS-kontoer med `view_only = 1` skal bare ha tilgang til interessentoversikten og kunne endre notater og interesse. Navnet «view_only» betyr ikke at notater og interesse skal være låst.

**Why:** Brukeren spesifiserte «saas_admins.view_only = 1 tilgang bare til interesser(notat,statusendring på bedrifter)».

**How to apply:** Les begrensningen fra den aktive kontoen, også for eksisterende økter. Sperr andre skjermbilder og API-handlinger; ikke la en globalrolle overstyre denne begrensningen.
