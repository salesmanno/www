---
name: Samlet offentlig nettstedskart
description: Brukerens valg om ett cross-site-sitemap for Salesman og de offentlige firmaprosjektene.
---

Brukeren vil ha ett samlet nettstedskart på salesman.no som lister hovedside, aktive offentlige firmaer og tilbud direkte. Ikke lag separate kart eller en indeks per bedrift. Del bare dersom protokollens størrelse- eller antallsgrenser krever det.

**Why:** Brukeren ønsker å slippe å opprette og sende inn hundre separate nettstedskart.

**How to apply:** Hent tilbudenes eksisterende delingskoder fra det delte datagrunnlaget. Ikke anta at offentlige delingsruter i firmaprosjektet må opprettes i SaaS-prosjektet. Kontroller at URL-typene samsvarer med firmaprosjektets offentlige kontrakt. Skill kodepublisering fra domeneverifisering og innsending i Google Search Console; ingen Google-innsending er bekreftet uten faktisk kontotilgang.

Finn tilbydere med utgangspunkt i `tenant_companies.subdomain`, ikke krav om ferdig moduloppsett for firmaets forside.

**Why:** Brukeren ba uttrykkelig om dette da produksjonskartet bare fikk én kandidat. Nettsteds- og moduloppsett må ikke hindre oppdagelse av eksisterende offentlige forsider.

**How to apply:** Skill oppdagelse av firmaforsider fra filtrering av tilbud. Behold kontroll av offentlig HTTP-tilgjengelighet og indekserbarhet før URL-er tas inn i kartet.
