# Databaseoppsett etter GitHub-eksport

Prosjektet bruker samme MySQL-database som før. Databasepassord følger ikke med GitHub. Ingen nye tabeller eller brukere er nødvendige.

## Domeneshop

1. Kopier malen `config/saas-database.example.json` til en lokal fil kalt **`.saas-database.json`**.
2. Fyll inn dine eksisterende databaseinnstillinger på egen maskin. Behold JSON-formatet. Ikke send passord i chat eller legg filen i Git.
3. Last opp filen med SFTP til katalogen **ett nivå over den offentlige webroten**. Hvis webroten heter `www`, skal filen ligge ved siden av `www`, ikke inni den. Skjulte filer må kanskje slås på i SFTP-klienten.
4. Sett filrettighetene til `600` hvis PHP kjører med samme bruker, eller den mest begrensede tilgangen som gjør at PHP kan lese filen. Ikke gi andre skrivetilgang.
5. Last opp oppdatert prosjektkode og åpne SaaS-innloggingen.

Koden finner filen automatisk basert på PHPs `DOCUMENT_ROOT`. Hvis denne verdien ikke finnes (for eksempel ved CLI), brukes katalogen over prosjektroten. En eksplisitt absolutt filsti kan settes med miljøvariabelen `MYSQL_CONFIG_FILE`. Filen må fortsatt ligge utenfor både offentlig webrot og prosjektrot, også når symlinker brukes.

Hvis PHP ikke får lese utenfor webroten, må webhotellets tilgang eller sikre miljøvariabler konfigureres. **Ikke flytt JSON-filen inn i `www` for å omgå dette.**

## Replit og andre miljøer med miljøvariabler

Eksisterende `MYSQL_HOST`, `MYSQL_DATABASE`, `MYSQL_USER` og `MYSQL_PASSWORD` brukes fortsatt. `MYSQL_PORT` er valgfri, med standard `3306`. Verten kan være et vertsnavn eller en URL til verten.

Miljøvariablene har prioritet. Hvis noen av de fire nødvendige innstillingene er satt, må alle fire være satt; koden blander aldri hemmeligheter fra forskjellige konfigurasjonskilder eller prøver en annen database etter tilkoblingsfeil. En `.env`-fil blir ikke automatisk lastet.

## Kontroll

- «Databaseoppsettet … mangler eller er ugyldig»: sjekk plassering, filrettigheter, JSON-format og at alle feltene er fylt inn. Ikke publiser filinnholdet.
- Generell systemfeil: kontroller serverens PHP-logg. «MySQL connection unavailable» betyr at tilkoblingen feilet; kontroller vert, port og databasekonto uten å dele hemmeligheter.
- Hvis det står at PDO-driveren mangler, må `pdo_mysql` være aktivert på serveren.

Et Git-klon uten `vendor/` må også få avhengighetene installert med Composer for funksjonene som bruker disse. Composer-avhengigheter er ikke nødvendig for selve denne konfigurasjonsleseren.

Innlogging skriver vanligvis `last_login` og oppretter en økt. Ingen slik innlogging eller dataendring utføres av konfigurasjonsoppsettet alene.
