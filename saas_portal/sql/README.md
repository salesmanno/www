# SafeDeleteCompany Stored Procedure

## Problem som ble løst

Den gamle slettefunksjonen hadde en kritisk feil:
- Prøvde å sette `created_by = NULL` i `tenant_website_config`
- Men `created_by` er **NOT NULL** → UPDATE feilet
- Dette forårsaket FK constraint error ved sletting av bedrifter med admins

## Løsning

Den oppdaterte prosedyren:
1. ✅ Fjerner UPDATE-statements som prøver å nullstille NOT NULL kolonner
2. ✅ Sletter data direkte i riktig rekkefølge
3. ✅ Bruker `SET FOREIGN_KEY_CHECKS = 0` midlertidig for å tillate sletting
4. ✅ Garantert atomisk transaksjon - alt slettes eller ingenting slettes

## Installasjon

Når databasen er tilgjengelig, kjør:

```bash
cd saas_portal/sql
php install_procedure.php
```

Dette vil:
- Droppe eksisterende `SafeDeleteCompany` prosedyre
- Opprette ny versjon med fiksen
- Bekrefte at prosedyren er installert

## Testing

Etter installasjon, test ved å:
1. Logge inn på SaaS-portalen
2. Prøve å slette en bedrift med admins/staff
3. Verifisere at sletting fungerer uten feil

## Hva prosedyren gjør

1. **FASE 1**: Sletter barn-tabeller uten direkte `company_id`
   - events_checkin, sales_transactions, sales_orders_items, etc.
   - tenant_admin_sessions, tenant_admin_modules, tenant_staff_modules

2. **FASE 2**: Sletter hovedtabeller med `company_id`
   - tenant_company_modules, tenant_announcements
   - tenant_website_config, tenant_website_general, tenant_settings
   - Events, membership, reservations, sales, rental data
   - unified_ledger, service_bookings
   - tenant_customers, tenant_admins, tenant_staff

3. **FASE 3**: Sletter bedriften selv
   - tenant_companies

Alt skjer i én atomisk transaksjon med automatisk rollback ved feil.
