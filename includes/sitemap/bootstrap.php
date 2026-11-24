<?php
declare(strict_types=1);

require_once __DIR__ . '/SitemapRepository.php';
require_once __DIR__ . '/SitemapVerifier.php';
require_once __DIR__ . '/SitemapCache.php';

function publicSitemapCache(): SitemapCache
{
    return new SitemapCache(SitemapCache::directory(), static function (float $deadline): array {
        // Load the existing connection lazily: warm-cache requests need no DB.
        SitemapDiagnostics::stage('database');
        require_once dirname(__DIR__, 2) . '/config/database.php';
        $repository = new SitemapRepository(getDBConnection());
        SitemapDiagnostics::stage('candidates');
        try {
            $candidates = $repository->candidates();
        } finally {
            foreach (array_unique($repository->warnings) as $warning) SitemapDiagnostics::warning($warning);
            SitemapDiagnostics::discovery($repository->counts);
        }
        SitemapDiagnostics::counts(['candidates' => count($candidates)]);
        SitemapDiagnostics::stage('verification');
        $verifier = new SitemapVerifier();
        $entries = $verifier->verify($candidates, $deadline);
        SitemapDiagnostics::counts($verifier->counts);
        SitemapDiagnostics::stage('complete');
        return $entries;
    });
}
