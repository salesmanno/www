<?php

/** Reuse the owning module's verified codec; never guess an encrypted column's format. */
final class PaymentCredentialCipher {
    private static ?Closure $encoder=null;
    public static function configure(Closure $encoder): void { self::$encoder=$encoder; }
    public static function available(): bool { return self::$encoder!==null; }
    public static function encrypt(string $plain): string {
        if (!self::$encoder) throw new InvalidArgumentException('Betalingsmodulens krypteringsformat er ikke koblet til. Eksisterende nøkler er beholdt; ingen endringer er lagret.');
        $value=(self::$encoder)($plain);
        if (!is_string($value) || $value==='' || hash_equals($plain,$value)) throw new RuntimeException('Betalingsmodulens kryptering returnerte en ugyldig verdi.');
        return $value;
    }
}
