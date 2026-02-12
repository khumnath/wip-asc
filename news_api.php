<?php
/**
 * Server-Side News Fetcher (Proxy Mode)
 *
 * Replaces complex background caching with direct, parallel fetching.
 * Mirrors Cloudflare Worker behavior using api_helper.php
 */

require_once __DIR__ . '/api_helper.php';
require_once __DIR__ . '/news_parser.php';

// Cache control for client/CDN (5 minutes)
header('Cache-Control: public, max-age=300');

$category = $_GET['category'] ?? 'fresh';

// Helper to get all feeds if category is 'all'
function getAllNewsFeeds() {
    $json = @file_get_contents(__DIR__ . '/config/overrides.json');
    $config = json_decode($json, true);
    return $config['newsFeeds'] ?? [];
}

$feedsToFetch = [];

if ($category === 'all') {
    $allFeeds = getAllNewsFeeds();
    // Flatten all categories into a single list of unique feeds?
    // Actually, 'all' mode usually returns categorized data.
    // We will fetch ALL feeds for ALL categories efficiently.
    $feedsToFetch = $allFeeds; // ['cat' => [feeds...], 'cat2' => [feeds...]]
} else {
    $feeds = getCategoryFeeds($category);
    if ($feeds) {
        $feedsToFetch[$category] = $feeds;
    }
}

// 1. Collect all unique URLs to fetch
$uniqueUrls = [];
$urlMap = []; // URL -> Content

foreach ($feedsToFetch as $cat => $catFeeds) {
    foreach ($catFeeds as $feed) {
        if (!empty($feed['url'])) {
            $uniqueUrls[$feed['url']] = $feed['url'];
        }
    }
}

// 2. Fetch in Parallel
$contents = fetchUrlsParallel(array_values($uniqueUrls));
// Map back to easy lookup
foreach ($uniqueUrls as $originalUrl) {
    // curl_multi preserves keys? fetchUrlsParallel returns indexed array orkeyed?
    // api_helper uses $handles[$key] where key is from input array.
    // array_values reindexes 0..N.
    // So $contents is 0..N.
    // We need to map back.
    // Let's rely on mapping by index.
    $indexedUrls = array_values($uniqueUrls);
    foreach ($indexedUrls as $index => $url) {
        if (isset($contents[$index])) {
            $urlMap[$url] = $contents[$index];
        }
    }
}

// 3. Process Feeds per Category
$response = [];
$isBatch = ($category === 'all');

foreach ($feedsToFetch as $cat => $catFeeds) {
    $allItems = [];
    $seenLinks = [];

    foreach ($catFeeds as $feed) {
        $url = $feed['url'];
        $content = $urlMap[$url] ?? null;

        if ($content) {
            $items = fetchFeed($url, $feed['type'] ?? 'rss', $feed['sourceName'] ?? '', $content);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if ($item && !empty($item['title']) && !empty($item['link'])) {
                        $itemLink = (string)$item['link'];
                        if (!isset($seenLinks[$itemLink])) {
                            $allItems[] = $item;
                            $seenLinks[$itemLink] = true;
                        }
                    }
                }
            }
        }
    }

    // Round Robin Sort
    $sourceGroups = [];
    foreach ($allItems as $item) {
        $sourceGroups[$item['source']][] = $item;
    }

    foreach ($sourceGroups as &$group) {
        usort($group, function($a, $b) {
            $ta = strtotime($a['pubDate']) ?: (time() - 86400);
            $tb = strtotime($b['pubDate']) ?: (time() - 86400);
            return $tb - $ta;
        });
    }

    $mixedItems = [];
    $sources = array_keys($sourceGroups);
    $maxLen = 0;
    foreach ($sourceGroups as $group) $maxLen = max($maxLen, count($group));

    for ($i = 0; $i < $maxLen; $i++) {
        foreach ($sources as $source) {
            if (isset($sourceGroups[$source][$i])) {
                $mixedItems[] = $sourceGroups[$source][$i];
            }
        }
    }

    if ($isBatch) {
        $response[$cat] = $mixedItems;
    } else {
        $response = $mixedItems; // Single category returns list directly
    }
}

// 4. Return
if ($isBatch) {
    echo json_encode([
        'mode' => 'batch',
        'categories' => $response,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} else {
    // Single category wrapper to match old API if needed?
    // Old API returned: {category, items, ...} OR just items?
    // Step 4803 'else' block: {category, items, ...}.
    // But Client likely handles both?
    // Let's stick to {category, items} to be safe/compatible.
    echo json_encode([
        'category' => $category,
        'items' => $response,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
