<?php
declare(strict_types=1);

require_once __DIR__ . '/SitemapRepository.php';
require_once __DIR__ . '/SitemapVerifier.php';
require_once __DIR__ . '/SitemapCache.php';

function publicSitemapCache(): SitemapCache
{
    return new SitemapCache(SitemapCache::directory(), static function (float $deadline): array {
        // Load the existing connection lazily: warm-cache requests need no DB.
        require_once dirname(__DIR__, 2) . '/config/database.php';
        $repository = new SitemapRepository(getDBConnection());
        $candidates = $repository->candidates();
        foreach (array_unique($repository->warnings) as $warning) error_log($warning);
        $verifier = new SitemapVerifier();
        $entries = $verifier->verify($candidates, $deadline);
        error_log('Sitemap verification: ' . json_encode($verifier->counts));
        return $entries;
    });
}
