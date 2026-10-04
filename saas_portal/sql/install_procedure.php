<?php
/**
 * The company API no longer needs a stored procedure.
 * Do not reinstall the old fixed-table deletion routine.
 */

header('Content-Type: text/plain; charset=utf-8');
echo "SafeDeleteCompany er ikke lenger nødvendig.\n";
echo "Bedriftssletting bruker CompanyDeletionService og gjeldende databaseskjema.\n";
echo "Ingen databaseendringer er utført.\n";
