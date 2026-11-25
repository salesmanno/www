---
name: Samlet offentlig nettstedskart
description: Brukerens valg om ett cross-site-sitemap for Salesman og de offentlige firmaprosjektene.
---

Brukeren vil ha ett samlet nettstedskart på salesman.no som lister hovedside, offentlige firmaer og tilbud direkte. Ikke lag separate kart eller en indeks per bedrift. Del bare dersom protokollens størrelse- eller antallsgrenser krever det.

**Why:** Brukeren ønsker å slippe å opprette og sende inn hundre separate nettstedskart.

**How to apply:** Hent tilbudenes eksisterende delingskoder fra det delte datagrunnlaget. Ikke anta at offentlige delingsruter i firmaprosjektet må opprettes i SaaS-prosjektet. Kontroller at URL-typene samsvarer med firmaprosjektets offentlige kontrakt. Skill kodepublisering fra domeneverifisering og innsending i Google Search Console; ingen Google-innsending er bekreftet uten faktisk kontotilgang.

Finn tilbydere med utgangspunkt i `tenant_companies.subdomain`. Brukeren presiserte: «Eksisterer bedriften skal det være synlig», og ønsker alle offentlige delingskodesider, inkludert `tenant_announcements.share_code`.

**Why:** Brukeren ba uttrykkelig om dette da produksjonskartet bare fikk én kandidat. Nettsteds- og moduloppsett må ikke hindre oppdagelse av eksisterende offentlige forsider.

**How to apply:** Ikke bruk administrative aktiv-/modulflagg eller obligatoriske katalogkoblinger som bevis for at en offentlig nettside mangler. Oppdag delingskoder direkte; respekter eksplisitt privat/upublisert innhold og kontroller offentlig HTTP-tilgjengelighet og indekserbarhet før URL-er tas inn i kartet.

Kundesidens delingsruter er autoritative for URL-typen: timebestilling bruker `type=reservation`, ikke katalogtypen `service`.

**Why:** Brukeren delte et nettstedskart fra kundesideprosjektet som viste den faktiske kontrakten; intern produktkatalog hadde ført til feil offentlig URL-type.

**How to apply:** Ikke utled offentlige ruter fra interne modul-/produktnavn. Kontroller mot kundesidens delingslenker når nye typer legges til.
