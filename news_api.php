<?php
/**
 * Server-Side News Fetcher & Caching System
 *
 * This script fetches news from multiple RSS/JSON sources and caches them on the server.
 * - Automatic background refresh every 5 minutes
 * - Serves cached news instantly to clients (no waiting)
 * - Supports multiple categories
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Configuration
$CACHE_DIR = __DIR__ . '/news_cache';
$CACHE_DURATION = 5 * 60; // 5 minutes (Fresh data)
$STALE_DURATION = 30 * 60; // 30 minutes (Serve stale while background refreshing)
$CONFIG_FILE = __DIR__ . '/config/overrides.json';

// Ensure cache directory exists
if (!is_dir($CACHE_DIR)) {
    mkdir($CACHE_DIR, 0755, true);
}

// Global configuration storage
$GLOBALS['OVERRIDES'] = null;

// Load full configuration
function loadOverrides() {
    if ($GLOBALS['OVERRIDES'] !== null) return $GLOBALS['OVERRIDES'];
    global $CONFIG_FILE;
    if (!file_exists($CONFIG_FILE)) return [];
    $config = json_decode(file_get_contents($CONFIG_FILE), true);
    $GLOBALS['OVERRIDES'] = $config ?: [];
    return $GLOBALS['OVERRIDES'];
}

// Load news feeds configuration
function loadNewsConfig() {
    $config = loadOverrides();
    return $config['newsFeeds'] ?? [];
}

// Fetch content with proxy fallback
function fetchContent($url) {
    $config = loadOverrides();
    $proxies = $config['corsProxies'] ?? [];

    // Headers to mimic a browser
    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
        "Accept-Language: en-US,en;q=0.9",
        "Referer: https://www.google.com/",
        "Connection: close"
    ];

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 5, // Reduced for faster feedback
            'follow_location' => 1,
            'ignore_errors' => true
        ],
        'ssl' => ["verify_peer" => false, "verify_peer_name" => false]
    ];

    // 1. Try Direct Fetch
    $context = stream_context_create($opts);
    $content = @file_get_contents($url, false, $context);

    // Check if direct fetch worked (heuristically based on size/403)
    if ($content !== false && strlen($content) > 500) {
        return $content;
    }

    // 2. Try Proxies
    foreach ($proxies as $proxy) {
        // Skip local relative proxies if we are already on server
        if (strpos($proxy, 'http') !== 0) continue;

        $proxyUrl = $proxy . urlencode($url);
        $content = @file_get_contents($proxyUrl, false, $context);

        if ($content !== false && strlen($content) > 500) {
            return $content;
        }
    }

    return false;
}

// Fetch single feed (RSS, Atom, or JSON)
function fetchFeed($url, $type, $sourceName) {
    $content = fetchContent($url);
    if (!$content) return [];

    // Parse based on type
    if ($type === 'json') {
        return parseJSONFeed($content, $url, $sourceName);
    } elseif ($type === 'html') {
        return parseHTMLFeed($content, $url, $sourceName);
    } else {
        return parseXMLFeed($content, $sourceName);
    }
}

// Parse JSON feed
function parseJSONFeed($content, $url, $sourceName) {
    $data = json_decode($content, true);
    if (!$data) return [];

    $articles = [];
    if (isset($data['articles'])) {
        $articles = $data['articles'];
    } elseif (is_array($data)) {
        // Some APIs return array directly
        $articles = isset($data[0]['title']) ? $data : [];
    }

    $items = [];
    foreach ($articles as $article) {
        $link = $article['url'] ?? $article['link'] ?? $article['newsUrl'] ?? '';
        if (!$link) continue;

        $title = $article['title'];
        if (is_array($title) && isset($title['rendered'])) {
            $title = $article['title']['rendered'];
        }

        $excerpt = $article['excerpt'] ?? $article['description'] ?? $article['newsOverView'] ?? '';
        if (is_array($excerpt) && isset($excerpt['rendered'])) {
            $excerpt = $article['excerpt']['rendered'];
        }

        $image = $article['jetpack_featured_media_url'] ?? $article['urlToImage'] ?? $article['image'] ?? $article['thumbnailUrl'] ?? '';

        $items[] = [
            'id' => md5($link),
            'title' => is_string($title) ? strip_tags($title) : '',
            'link' => $link,
            'description' => substr(strip_tags(is_string($excerpt) ? $excerpt : ''), 0, 200),
            'pubDate' => $article['date'] ?? $article['publishedAt'] ?? $article['pubDate'] ?? $article['publishedDate'] ?? date('c'),
            'source' => $sourceName ?: 'News',
            'image' => $image
        ];
    }

    return $items;
}

// Helper to check if node is in generic area (header, menu, footer)
function isInGenericArea($node) {
    $tempNode = $node->parentNode;
    while ($tempNode && $tempNode instanceof DOMElement) {
        $tag = strtolower($tempNode->tagName);
        if ($tag === 'header' || $tag === 'footer' || $tag === 'nav') return true;

        $pClass = strtolower($tempNode->getAttribute('class'));
        $pId = strtolower($tempNode->getAttribute('id'));

        $genericTerms = ['header', 'footer', 'menu', 'sidebar', 'trending', 'related', 'megamenu', 'popular', 'recommend'];
        foreach ($genericTerms as $term) {
            if (strpos($pClass, $term) !== false || strpos($pId, $term) !== false) {
                return true;
            }
        }
        $tempNode = $tempNode->parentNode;
    }
    return false;
}

// Parse HTML feed (Scraper)
function parseHTMLFeed($content, $url, $sourceName) {
    $items = [];
    $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $content);
    $xpath = new DOMXPath($doc);

    // Site-specific logic
    if (strpos($url, 'onlinekhabar.com') !== false) {
        // Target main listing area only to avoid header/megamenu mixups
        $nodes = $xpath->query('//div[contains(@class, "ok-listing-posts")]//div[contains(@class, "ok-news-post")] | //div[contains(@class, "ok-post-parent-wrapper")]//div[contains(@class, "okv4-post")]');
        if ($nodes->length === 0) {
            $nodes = $xpath->query('//div[contains(@class, "ok-news-post")] | //div[contains(@class, "okv4-post")]');
        }
        foreach ($nodes as $node) {
            if (isInGenericArea($node)) continue;

            $linkEl = $xpath->query('.//a', $node)->item(0);
            $imgEl = $xpath->query('.//img', $node)->item(0);
            $titleEl = $xpath->query('.//h2|.//h3|.//h1|.//span[contains(@class, "title")]', $node)->item(0);

            if ($linkEl && $titleEl) {
                $link = $linkEl->getAttribute('href');
                if ($link && strpos($link, 'http') !== 0) $link = $baseUrl . $link;
                if (!$link || $link === '#' || strpos($link, 'javascript') !== false) continue;

                $items[] = [
                    'id' => md5($link),
                    'title' => trim($titleEl->textContent),
                    'link' => $link,
                    'description' => '',
                    'pubDate' => date('c'),
                    'source' => $sourceName ?: 'OnlineKhabar',
                    'image' => $imgEl ? ($imgEl->getAttribute('src') ?: $imgEl->getAttribute('data-src') ?: $imgEl->getAttribute('data-lazy-src')) : ''
                ];
            }
        }
    } elseif (strpos($url, 'swasthyakhabar.com') !== false) {
        $nodes = $xpath->query('//div[contains(@class, "samachar-box")] | //div[contains(@class, "news-break")]');
        foreach ($nodes as $node) {
            if (isInGenericArea($node)) continue;
            $linkEl = $xpath->query('.//a', $node)->item(0);
            $titleEl = $xpath->query('.//span[contains(@class, "main-title")] | .//h3 | .//h2', $node)->item(0);
            $imgEl = $xpath->query('.//img', $node)->item(0);

            if ($linkEl && $titleEl) {
                $link = $linkEl->getAttribute('href');
                if (strpos($link, 'http') !== 0) $link = $baseUrl . $link;
                $items[] = [
                    'id' => md5($link),
                    'title' => trim($titleEl->textContent),
                    'link' => $link,
                    'description' => '',
                    'pubDate' => date('c'),
                    'source' => $sourceName ?: 'Swasthyakhabar',
                    'image' => $imgEl ? ($imgEl->getAttribute('src') ?: $imgEl->getAttribute('data-src')) : ''
                ];
            }
        }
    } elseif (strpos($url, 'ratopati.com') !== false) {
        // Target main content only
        $nodes = $xpath->query('//div[contains(@class, "content-listing")]//div[contains(@class, "columnnews")] | //div[contains(@class, "col-sm-8")]//div[contains(@class, "columnnews")]');
        if ($nodes->length === 0) {
            $nodes = $xpath->query('//div[contains(@class, "columnnews")] | //div[contains(@class, "raw-story")] | //div[contains(@class, "item")]');
        }
        foreach ($nodes as $node) {
            if (isInGenericArea($node)) continue;
            $linkEl = $xpath->query('.//a', $node)->item(0);
            $titleEl = $xpath->query('.//h3|.//h2|.//h4|.//span[contains(@class, "title")]', $node)->item(0);
            $imgEl = $xpath->query('.//img', $node)->item(0);

            if ($linkEl && $titleEl) {
                $link = $linkEl->getAttribute('href');
                if (strpos($link, 'http') !== 0) $link = $baseUrl . $link;
                $items[] = [
                    'id' => md5($link),
                    'title' => trim($titleEl->textContent),
                    'link' => $link,
                    'description' => '',
                    'pubDate' => date('c'),
                    'source' => $sourceName ?: 'Ratopati',
                    'image' => $imgEl ? ($imgEl->getAttribute('src') ?: $imgEl->getAttribute('data-src')) : ''
                ];
            }
        }
    } elseif (strpos($url, 'setopati.com') !== false) {
        // Target main listing only
        $nodes = $xpath->query('//div[contains(@class, "news-cat-list")]//div[contains(@class, "items")]');
        if ($nodes->length === 0) {
            $nodes = $xpath->query('//div[contains(@class, "breaking-news-item")] | //div[contains(@class, "items")]');
        }
        foreach ($nodes as $node) {
            if (isInGenericArea($node)) continue;
            $linkEl = $xpath->query('.//a', $node)->item(0);
            $titleEl = $xpath->query('.//span[contains(@class, "main-title")] | .//h3 | .//h2', $node)->item(0);
            $imgEl = $xpath->query('.//img', $node)->item(0);

            if ($linkEl && $titleEl) {
                $link = $linkEl->getAttribute('href');
                if (strpos($link, 'http') !== 0) $link = $baseUrl . $link;
                $items[] = [
                    'id' => md5($link),
                    'title' => trim($titleEl->textContent),
                    'link' => $link,
                    'description' => '',
                    'pubDate' => date('c'),
                    'source' => $sourceName ?: 'Setopati',
                    'image' => $imgEl ? ($imgEl->getAttribute('data-src') ?: $imgEl->getAttribute('src')) : ''
                ];
            }
        }
    } elseif (strpos($url, 'kathmandupost.com') !== false || strpos($url, 'myrepublica') !== false || strpos($url, 'annapurnapost.com') !== false) {
        $nodes = $xpath->query('//article | //div[contains(@class, "grid__card")] | //div[contains(@class, "category-box")] | //div[contains(@class, "news-card")]');
        foreach ($nodes as $node) {
            if (isInGenericArea($node)) continue;
            $linkEl = $xpath->query('.//a', $node)->item(0);
            $titleEl = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//span[contains(@class, "main-title")]|.//div[contains(@class, "news__title")]', $node)->item(0);
            $imgEl = $xpath->query('.//img', $node)->item(0);

            if ($linkEl && $titleEl) {
                $link = $linkEl->getAttribute('href');
                if (strpos($link, 'http') !== 0) $link = $baseUrl . $link;
                $items[] = [
                    'id' => md5($link),
                    'title' => trim($titleEl->textContent),
                    'link' => $link,
                    'description' => '',
                    'pubDate' => date('c'),
                    'source' => $sourceName ?: 'News',
                    'image' => $imgEl ? ($imgEl->getAttribute('src') ?: $imgEl->getAttribute('data-src') ?: $imgEl->getAttribute('data-lazy-src')) : ''
                ];
            }
        }
    }

    // Generic fallback if no items found
    if (empty($items)) {
        // Look for common patterns
        $nodes = $xpath->query('//article | //div[contains(@class, "post")] | //div[contains(@class, "article")] | //div[contains(@class, "news-card")]');
        foreach ($nodes as $node) {
            if (isInGenericArea($node)) continue;
            $linkEl = $xpath->query('.//a', $node)->item(0);
            $titleEl = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//span[contains(@class, "title")]', $node)->item(0);
            $imgEl = $xpath->query('.//img', $node)->item(0);

            if ($linkEl && $titleEl) {
                $link = $linkEl->getAttribute('href');
                if ($link && $link !== '#' && strpos($link, 'http') !== 0) $link = $baseUrl . $link;

                if ($link && $link !== '#') {
                    $items[] = [
                        'id' => md5($link . $titleEl->textContent),
                        'title' => trim($titleEl->textContent),
                        'link' => $link,
                        'description' => '',
                        'pubDate' => date('c'),
                        'source' => $sourceName ?: 'News',
                        'image' => $imgEl ? ($imgEl->getAttribute('src') ?: $imgEl->getAttribute('data-src') ?: $imgEl->getAttribute('data-lazy-src')) : ''
                    ];
                }
            }
            if (count($items) >= 25) break;
        }
    }

    return array_slice($items, 0, 30);
}

// Parse XML feed (RSS/Atom)
function parseXMLFeed($content, $sourceName) {
    libxml_use_internal_errors(true);
    $start = strpos($content, '<?xml');
    if ($start !== false) $content = substr($content, $start);

    $xml = @simplexml_load_string($content);
    if (!$xml) return [];

    $items = [];
    $namespaces = $xml->getNamespaces(true);

    if (isset($xml->entry) && count($xml->entry) > 0) {
        foreach ($xml->entry as $entry) {
            $items[] = parseAtomEntry($entry, $sourceName, $namespaces);
        }
    } elseif (isset($xml->channel->item) && count($xml->channel->item) > 0) {
        foreach ($xml->channel->item as $item) {
            $items[] = parseRSSItem($item, $sourceName, $namespaces);
        }
    } elseif (isset($xml->item) && count($xml->item) > 0) {
        foreach ($xml->item as $item) {
            $items[] = parseRSSItem($item, $sourceName, $namespaces);
        }
    }

    return array_filter($items);
}

// Parse Atom entry
function parseAtomEntry($entry, $sourceName, $namespaces) {
    $link = '';
    if (isset($entry->link)) {
        if (count($entry->link) > 1) {
            foreach ($entry->link as $l) {
                if ((string)$l['rel'] === 'alternate' || !(string)$l['rel']) {
                    $link = (string)$l['href'];
                    break;
                }
            }
        } else {
            $link = (string)$entry->link['href'];
        }
    }

    if (!$link) return null;

    $content = '';
    if (isset($entry->content)) {
        $content = strip_tags((string)$entry->content);
    } elseif (isset($entry->summary)) {
        $content = strip_tags((string)$entry->summary);
    }

    return [
        'id' => md5($link),
        'title' => (string)$entry->title,
        'link' => $link,
        'description' => substr($content, 0, 200),
        'pubDate' => (string)($entry->updated ?? $entry->published ?? date('c')),
        'source' => $sourceName,
        'image' => extractAtomImage($entry, $namespaces)
    ];
}

// Parse RSS item
function parseRSSItem($item, $sourceName, $namespaces) {
    if (!isset($item->link) && !isset($item->guid)) return null;
    $link = (string)($item->link ?? $item->guid);

    $media = $item->children($namespaces['media'] ?? '');
    $content = $item->children($namespaces['content'] ?? '');

    $description = '';
    if (isset($content->encoded)) {
        $description = strip_tags((string)$content->encoded);
    } elseif (isset($item->description)) {
        $description = strip_tags((string)$item->description);
    }

    return [
        'id' => md5($link),
        'title' => (string)$item->title,
        'link' => $link,
        'description' => substr($description, 0, 200),
        'pubDate' => (string)($item->pubDate ?? date('r')),
        'source' => $sourceName,
        'image' => (string)($item->image ?? extractRSSImage($item, $media, $namespaces))
    ];
}

// Extract image from Atom entry
function extractAtomImage($entry, $namespaces) {
    if (isset($namespaces['media'])) {
        $media = $entry->children($namespaces['media']);
        if (isset($media->content)) return (string)$media->content['url'];
        if (isset($media->thumbnail)) return (string)$media->thumbnail['url'];
    }
    if (isset($entry->link)) {
        foreach ($entry->link as $link) {
            if ((string)$link['rel'] === 'enclosure' && strpos((string)$link['type'], 'image') !== false) {
                return (string)$link['href'];
            }
        }
    }

    // Fallback: Parse from content/summary HTML
    $html = (string)$entry->content . (string)$entry->summary;
    if (preg_match('/<img[^>]+(?:src|data-src)=["\']([^"\']+\.(?:jpg|jpeg|png|webp|gif)[^"\']*)["\']/i', $html, $matches)) {
        return $matches[1];
    }

    return '';
}

// Extract image from RSS item
function extractRSSImage($item, $media, $namespaces) {
    if (isset($media->content)) {
        if (isset($media->content['url'])) return (string)$media->content['url'];
        if (isset($media->content->attributes()->url)) return (string)$media->content->attributes()->url;
    }
    if (isset($media->thumbnail)) return (string)$media->thumbnail['url'];
    if (isset($item->enclosure) && strpos((string)$item->enclosure['type'], 'image') !== false) {
        return (string)$item->enclosure['url'];
    }

    // Fallback: Parse from description or content:encoded HTML
    $content = $item->children($namespaces['content'] ?? '');
    $html = (string)$item->description . (string)$content->encoded;
    if (preg_match('/<img[^>]+(?:src|data-src)=["\']([^"\']+\.(?:jpg|jpeg|png|webp|gif)[^"\']*)["\']/i', $html, $matches)) {
        return $matches[1];
    }

    return '';
}

// Extract direct image tag if simplexml missed it
function extractDirectXMLImage($item) {
    if (isset($item->image)) return (string)$item->image;
    return '';
}

// Fetch all news for a category
function fetchCategoryNews($category, $feeds) {
    $allItems = [];
    $seenLinks = [];

    foreach ($feeds as $feed) {
        try {
            $items = fetchFeed($feed['url'], $feed['type'] ?? 'rss', $feed['sourceName'] ?? '');
            if (!is_array($items)) $items = [];

            foreach ($items as $item) {
                if ($item && !empty($item['title']) && !empty($item['link']) && !in_array($item['link'], $seenLinks)) {
                    $allItems[] = $item;
                    $seenLinks[] = $item['link'];
                }
            }
        } catch (Exception $e) {}
    }

    // Round Robin: Interleave sources
    $sourceGroups = [];
    foreach ($allItems as $item) {
        $sourceGroups[$item['source']][] = $item;
    }

    // Sort each group date DESC
    foreach ($sourceGroups as &$group) {
        usort($group, function($a, $b) {
            return strtotime($b['pubDate']) - strtotime($a['pubDate']);
        });
    }

    $mixedItems = [];
    $sources = array_keys($sourceGroups);
    $maxLen = 0;
    foreach ($sourceGroups as $group) {
        $maxLen = max($maxLen, count($group));
    }

    for ($i = 0; $i < $maxLen; $i++) {
        foreach ($sources as $source) {
            if (isset($sourceGroups[$source][$i])) {
                $mixedItems[] = $sourceGroups[$source][$i];
            }
        }
    }

    return $mixedItems;
}

// Get news with extreme caching
function getNews($category, $canFetch = true, $forceRefresh = false) {
    global $CACHE_DIR, $CACHE_DURATION, $STALE_DURATION;
    $cacheFile = "$CACHE_DIR/{$category}.json";

    // 1. Force Refresh? Clear it.
    if ($forceRefresh && file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

    // 2. Try Cache First
    if (file_exists($cacheFile)) {
        $cacheAge = time() - filemtime($cacheFile);

        // Fresh enough? Return raw string for speed.
        if ($cacheAge < $CACHE_DURATION) {
            return file_get_contents($cacheFile);
        }

        // Stale but usable? Return raw string and we'll refresh later if $canFetch is false.
        // If we are in "check only" mode ($canFetch=false), ALWAYS return cache if it exists,
        // even if it's super old. Better to show old news than nothing while we update.
        if (!$canFetch) {
            return file_get_contents($cacheFile);
        }
    }

    if (!$canFetch && !isset($_GET['refresh'])) return json_encode([]);

    // 3. Fetch fresh data
    $newsConfig = loadNewsConfig();
    $feeds = $newsConfig[$category] ?? [];
    if (empty($feeds)) return json_encode([]);

    $news = fetchCategoryNews($category, $feeds);
    if (!is_array($news)) $news = [];

    // Save to cache
    $json = json_encode($news, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json) file_put_contents($cacheFile, $json);

    return $json;
}

// Helper to send response and keep running in background if possible
function sendResponse($json) {
    // Prevent script from stopping if client disconnects
    ignore_user_abort(true);
    set_time_limit(0);

    if (!$json) $json = json_encode(['error' => 'No data']);

    $etag = md5($json);
    header("ETag: \"$etag\"");
    header("Cache-Control: public, max-age=60");

    // Check for Not Modified
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        header('HTTP/1.1 304 Not Modified');
        header('Content-Length: 0');
        header('Connection: close');
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
        // Even if 304, we might want to refresh cache if it's old,
        // but typically 304 means client has latest, so maybe cache is fine.
        // But if cache is stale on server, we should refresh it for next time.
        return;
    }

    // Standard Response
    $length = strlen($json);
    header('Content-Length: ' . $length);
    header('Connection: close');

    // Output and Flush
    ob_start();
    echo $json;
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();

    // End request for FPM
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// Main handler
if (php_sapi_name() === 'cli') {
    $category = $argv[1] ?? 'fresh';
    $forceRefresh = isset($argv[2]) && $argv[2] === 'refresh';
} else {
    $category = $_GET['category'] ?? 'fresh';
    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
}

if ($category === 'all') {
    // Return all categories
    $newsConfig = loadNewsConfig();
    $allNews = [];
    $toRefresh = [];

    foreach ($newsConfig as $cat => $feeds) {
        $cacheFile = "$CACHE_DIR/{$cat}.json";

        if ($forceRefresh) {
            // [HOT RELOAD FIX] If client requests refresh, do it NOW synchronously
            // This ensures the response contains the FRESH data, not the old data
            $content = getNews($cat, true, true);
            $allNews[] = "\"$cat\":" . ($content ?: "[]");
        } else {
            // Standard: Return fast (stale) and mark for background refresh
            $isStale = !file_exists($cacheFile) || (time() - filemtime($cacheFile) > $CACHE_DURATION);
            $content = getNews($cat, false);
            $allNews[] = "\"$cat\":" . ($content ?: "[]");
            if ($isStale) $toRefresh[] = $cat;
        }
    }

    $finalJson = "{\"mode\":\"batch\",\"categories\":{" . implode(",", $allNews) . "},\"timestamp\":" . time() . "}";
    sendResponse($finalJson);

    // Refresh stale categories in the background (only for non-forced requests)
    if (!$forceRefresh && !empty($toRefresh)) {
        foreach ($toRefresh as $cat) {
            getNews($cat, true, false); // canFetch=true, force=false (logic handles needed fetch)
        }
    }
} else {
    // Single category
    $itemsJson = getNews($category, true, $forceRefresh);
    $outputString = "{\"category\":\"$category\",\"items\":$itemsJson,\"timestamp\":" . time() . "}";
    sendResponse($outputString);
}
