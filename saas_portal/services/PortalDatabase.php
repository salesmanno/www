<?php

class PortalDatabaseUnavailableException extends RuntimeException {}

/** No retries of writes, no connection credentials or SQL values in diagnostics. */
class PortalDatabase {
    public static function connect(): PDO {
        try {
            $db = getDBConnection();
            if (!$db instanceof PDO) throw new RuntimeException('Connection did not return PDO.');
            return $db;
        } catch (Throwable $cause) {
            throw new PortalDatabaseUnavailableException('Database connection unavailable.', 0, $cause);
        }
    }

    public static function unavailable(Throwable $error): bool {
        if ($error instanceof PortalDatabaseUnavailableException) return true;
        if (!$error instanceof PDOException) return false;
        $state = (string)($error->errorInfo[0] ?? $error->getCode());
        $driver = (int)($error->errorInfo[1] ?? 0);
        return str_starts_with($state, '08') ||
            in_array($driver, [1040, 1203, 2002, 2003, 2005, 2006, 2013, 2055], true);
    }

    public static function failure(Throwable $error, string $operation, string $fallback, string $code = 'request_failed'): array {
        $reference = bin2hex(random_bytes(6));
        $unavailable = self::unavailable($error);
        $cause = $error instanceof PortalDatabaseUnavailableException ? ($error->getPrevious() ?? $error) : $error;
        $state = (string)($cause instanceof PDOException ? ($cause->errorInfo[0] ?? $cause->getCode()) : '');
        $driver = $cause instanceof PDOException ? ($cause->errorInfo[1] ?? null) : null;
        $diagnostic = [
            'reference' => $reference,
            'operation' => preg_match('/^[a-z_]{1,40}$/D', $operation) ? $operation : 'request',
            'stage' => $error instanceof PortalDatabaseUnavailableException ? 'connect' : 'request',
            'type' => get_class($cause),
            'sqlstate' => preg_match('/^[A-Z0-9]{5}$/D', $state) ? $state : null,
            'driver_code' => is_numeric($driver) ? (int)$driver : null,
        ];
        error_log('Portal request failed: ' . json_encode($diagnostic));
        // Connection loss during a commit can leave the outcome uncertain.
        // Never claim rollback or automatically replay a mutation in that case.
        $message = $unavailable
            ? 'Databasen er midlertidig utilgjengelig. '
                . (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                    ? 'Lagringen kunne ikke bekreftes. Kontroller lagret oppsett når forbindelsen er tilbake før du prøver igjen.'
                    : 'Prøv igjen når forbindelsen er tilbake.')
            : $fallback;
        return [$unavailable ? 503 : 500, [
            'success' => false,
            'error_code' => $unavailable ? 'database_unavailable' : $code,
            'request_id' => $reference,
            'message' => $message . ' Feilreferanse: ' . $reference,
        ]];
    }
}
