---
name: Portal administration workflow
description: User's intended relationship between the company overview, employees and login accounts.
---

Keep global activity and logged-in users together on the company overview, rather than separating them into different screens. Company detail is centered on its activity, with employees at top-right and company settings (including SEO, excluding separate users/admins tabs) below.

**Why:** The user explicitly requested this administration flow rather than the previous split views.

**How to apply:** Preserve this hierarchy when extending the portal. New “Opprett SuperUser” actions belong to the selected employee and create a tenant login, not a SaaS-wide administrator. Existing unlinked accounts must remain manageable; do not delete or silently attach them during UI restructuring.

Modulvelgeren skal vise tilgjengelige moduler til venstre og bedriftens valgte moduler til høyre. Klikk flytter modulen mellom listene, og også aktive moduler skal kunne fjernes.

**Why:** Brukeren utvidet den tidligere additive tildelingen til redigering av hele bedriftens modulutvalg.

**How to apply:** Last inn dagens moduler som valgte. Vis tillegg og fjerninger før bekreftelse, og bevar separate utvalg ved redigering av flere bedrifter.

Bedriftens modultilgang skal bestemmes av `saas_subscription_plans.features`; SuperUsers skal bare kunne tildeles moduler fra disse features.

**Why:** Brukeren presiserte at dette er den tiltenkte tilgangsmodellen, ikke bedriftens separate modulrader som autoritativ kilde.

**How to apply:** Bruk bedriftens abonnementstilknytning til å finne features. Brukeren valgte individuell modultilgang per bedrift: endringer i en bedrifts features skal ikke endre andre bedrifters tilgang, selv om de tidligere delte plan.

Abonnementsutsteder for bedriftene skal være Salesman fra `self_company`, ikke en utsteder hentet fra `tenant_companies`.

**Why:** Brukeren valgte Salesman og presiserte «fra self_company ja».

**How to apply:** Bruk denne utstederen ved klargjøring av abonnementstilknytninger. Selve valget er ikke en godkjenning av endringer i faktiske data.

Abonnementsoppsett skal vises som en kolonne på skjermen ved modultildeling. Beregningen skal omfatte månedspris, ikke bare etablering eller én modulpris.

**Why:** Brukeren valgte «På skjermen ved modultildeling» og presiserte behovet for månedspris.

**How to apply:** Vis løpende månedsbeløp og engangsetablering separat i modultildelingsflyten.

Modulvelgeren skal vise sluttsum for månedlig pris og etablering, og la operatøren sette `saas_subscription_plans.max_users` som grense for bedriftens SuperUsers.

**Why:** Brukeren ba uttrykkelig om begge totalsummene og om å bestemme hvor mange SuperUsers bedriften kan ha.

**How to apply:** Oppdater summene ved valg og fravalg. Brukergrensen må håndheves på serveren, ikke bare vises i skjemaet; globale SaaS-identiteter er ikke firmakontoer.

Prisene i modulkatalogen er standardpriser, ikke en tillatelse til å endre eksisterende avtalte abonnementspriser.

**Why:** Bedriftsspesifikke avtaler kan ha rabatter eller andre vilkår. En ny katalogberegning må ikke umerkelig erstatte disse.

**How to apply:** Skill katalogestimat fra gjeldende avtale i visningen. Krev en uttrykkelig avklaring før eksisterende avtalte priser erstattes ved senere lagring.

Utvikling av abonnementsoppsett skal utføres programmatisk uten å være avhengig av tilgang til den faktiske databasen.

**Why:** Brukeren presiserte «du trenger IKKE databasetilkobling for å utføre arbeidet, gjør det programmatisk uten å være avhengig av databasens tilgang».

**How to apply:** Klargjør kode og eksplisitte migreringer, og verifiser mot isolerte testdatabaser. Ikke gjør manglende tilgang til faktiske data til en blokkering for kodearbeidet.

Adminbrukere uten ansattilknytning er ment som globale brukere utenfor firmaet og skal ikke ha firmaets modulrestriksjoner.

**Why:** Brukeren presiserte dette som unntak fra features-grensen for firma-SuperUsers.

**How to apply:** Brukeren valgte tilgang til alle bedrifter. Bruk en eksplisitt, serverkontrollert global rolle gjennom eksisterende SaaS-innlogging, uten ny SuperUser-innlogging i portalen. Sletting eller frakobling av en ansatt må ikke automatisk oppgradere en tidligere firmakonto til global tilgang.

Eksisterende SaaS-innlogging og eldre SaaS-roller skal fortsette å fungere uten at den nye globale kontomigreringen er kjørt.

**Why:** Nye rollebegrensninger og krav om globale sesjonstabeller kunne blokkere den etablerte SaaS-innloggingen. Brukeren presiserte at det var SaaS-innloggingen i dette prosjektet som måtte bevares.

**How to apply:** Ikke legg nye migrerings- eller tilgangskrav på gamle SaaS-operatører som del av kontoflyttingen. Obligatorisk ny sesjonssporing og løpende global rollekontroll gjelder uttrykkelig konverterte identiteter; nye kontoflyttinger skal fortsatt verifisere operatøren på serveren.

Aktivitetsfeeder skal vise lesbare setninger om aktør, handling og arrangement eller betaling, med detaljer ved hover og gamle/nye verdier ved endringer. Alle feeder og øktbokser skal oppdateres løpende; aktive økter skal ha sanntidsnedtelling, og påloggede ansatte skal ha grønt lys på bedriftssiden.

**Why:** Brukeren beskrev feeden som uleselig og ønsket å se hva kunder og SuperUsers faktisk gjør, uten manuell oppdatering.

**How to apply:** Bevar denne presentasjonen på både oversikten og bedriftssiden. Detaljer må også være tilgjengelige med tastatur og berøring, og begrenses til relevante tillatte data, ikke rå audit- eller autentiseringsdata.

Betalingsløsninger skal kunne opprettes og administreres i SaaS-portalen uavhengig av bedriftens moduler, bare av SaaS-admins. Redigering skal beskyttes mot utilsiktede endringer.

**Why:** Brukeren presiserte at dette er høyeste administrasjonsnivå og at oppsett ikke skal henvises til økonomimodulen.

**How to apply:** Skill rett til betalingsadministrasjon fra global tilgang til bedrifter/moduler. Behold maskering av hemmeligheter, men ikke bruk maskering som grunn til å nekte autorisert opprettelse eller erstatning.

Bedriftssiden skal ha egen fane ved SEO for månedlig beløp og månedsrabatt, med forfallsnedtelling og abonnementsstart ved lagring av modultildeling.

**Why:** Brukeren ba om samlet oversikt over disse abonnementsvilkårene i bedriftens oppsettsområde.

**How to apply:** Gjenbruk eksisterende abonnementsinfrastruktur og skill lagrede avtalevilkår fra prisforhåndsvisning. Videreføring av globale SuperUsers skal foretrekke eksisterende roller, rettigheter og innlogging fremfor flere nye tabeller.

