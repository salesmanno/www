---
name: Sentralt malverksted
description: Brukerens ønskede arbeidsflyt for e-postmaler på tvers av firmaer.
---

E-postmaler skal kunne bearbeides ett sentralt sted og derfra delegeres til firmaer. Maler må kunne flyttes eller kopieres frem og tilbake mellom firmaer, og slettes/fjernes.

**Why:** Brukeren ønsker én samlet arbeidsflate, ikke å måtte redigere maler separat inne på hvert firma.

**How to apply:** Legg administrasjonen på SaaS-nivå under eksisterende innlogging og ivareta begge retninger ved overføring; ikke begrens løsningen til enveis distribusjon.

Bruk selvstendige firmakopier, ikke automatisk synkronisering, og bygg videre på eksisterende malsystem uten en mellomtabell.

**Why:** Ett sentralt redigeringssted skal ikke gjøre at bearbeiding av en original utilsiktet endrer e-poster hos flere firmaer.

**How to apply:** Fordel og oppdater bare uttrykkelig valgte mottakere etter en bekreftet oversikt; bevar andre kopier og utsendingshistorikk ved fjerning.

MySQL kan avvise en lagret generert eier-/nøkkelkolonne når basiskolonnen har eksisterende fremmednøkler med kaskaderegler.

**Why:** MySQL begrenser kombinasjonen av lagrede genererte kolonner og kaskaderegler på basiskolonnen. Hjelpenøkler skal ikke kreve at eksisterende relasjoner svekkes.

**How to apply:** Test genererte nøkler sammen med de faktiske fremmednøkkelreglene; ikke fjern kaskaderegler for å få en hjelpekolonne installert.
