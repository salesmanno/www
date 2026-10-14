<?php

/** Public error codes never contain credentials, schema names or driver messages. */
final class PortalLoginFailure {
    public static function code(Throwable $error): string {
        if ($error instanceof MysqlConfigurationException) return 'database_configuration';
        if ($error instanceof PDOException) return 'database_query';
        if ($error->getPrevious() instanceof PDOException) return 'database_connection';
        return 'system_error';
    }
}
