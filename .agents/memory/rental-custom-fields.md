---
name: Rental custom fields
description: User's required storage model for configurable rental and customer form fields.
---

Utleie- og kundefelt skal følge den eksisterende løsningen for dynamiske skjemafelt. Ikke opprett en separat utleiespesifikk innstillingstabell for slike felt.

**Why:** Brukeren presiserte at alle slike felt skal inn under dynamiske skjemafelt, og at vedkommende ikke hadde opprettet `rental_customer_field_settings`.

**How to apply:** Før en endring, finn den faktiske eksisterende dynamiske skjemamodellen og felt-/svarskjemaet. Ikke anta at arrangementsfelter er generiske, og ikke opprett tabeller eller endre faktiske data uten uttrykkelig godkjenning.
