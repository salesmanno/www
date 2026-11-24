<?php
declare(strict_types=1);

/** Public diagnostics: only known messages and aggregate counts, never exceptions or row data. */
final class SitemapDiagnostics
{
    private static array $lines = [];

    public static function reset(): void { self::$lines = []; }
    public static function lines(): array { return self::$lines; }

    public static function warning(string $message): void
    {
        if (!preg_match('/^Sitemap: (?:optional source [a-z_]+ is absent; excluded\.|[a-z_]+ lacks required [a-z_]+; excluded\.)$/D', $message)) return;
        self::record($message);
    }

    public static function stage(string $stage): void
    {
        if (in_array($stage, ['database', 'candidates', 'verification', 'complete'], true)) {
            self::record('Sitemap: stage=' . $stage);
        }
    }

    public static function counts(array $counts): void
    {
        $safe = [];
        foreach (['candidates', 'included', 'excluded'] as $key) {
            if (isset($counts[$key]) && is_int($counts[$key])) $safe[$key] = max(0, $counts[$key]);
        }
        self::record('Sitemap verification: ' . json_encode($safe));
    }

    private static function record(string $line): void
    {
        error_log($line);
        if (count(self::$lines) < 100 && !in_array($line, self::$lines, true)) self::$lines[] = $line;
    }

    public static function comment(array $lines): string
    {
        if (!$lines) return '';
        $text = htmlspecialchars(implode("\n", $lines), ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        // XML comments cannot contain "--", even in escaped text.
        while (str_contains($text, '--')) $text = str_replace('--', '- -', $text);
        return "\n<!--\n{$text}\n-->\n";
    }
}
