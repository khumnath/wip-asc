<?php

// Helper to check if node is in generic area (header, menu, footer)
function isInGenericArea($node) {
    if (!$node) return false;
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

// Parse JSON feed
function parseJSONFeed($content, $url, $sourceName) {
    $data = json_decode($content, true);
    if (!$data) return [];

    $articles = [];
    if (isset($data['articles'])) {
        $articles = $data['articles'];
    } elseif (is_array($data)) {
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

        // Expanded image extraction including _embedded
        $image = $article['jetpack_featured_media_url'] ??
                 $article['urlToImage'] ??
                 $article['image'] ??
                 $article['thumbnailUrl'] ??
                 ($article['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '') ??
                 '';

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

// Parse HTML feed (Scraper)
function parseHTMLFeed($content, $url, $sourceName) {
    $items = [];
    $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $content);
    $xpath = new DOMXPath($doc);

    if (strpos($url, 'onlinekhabar.com') !== false) {
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
        $nodes = $xpath->query('//div[contains(@class, "content-listing")]//div[contains(@class, "columnnews")] | //div[contains(@class, "col-sm-8")]//div[contains(@class, "columnnews")]');
        if ($nodes->length === 0) $nodes = $xpath->query('//div[contains(@class, "columnnews")] | //div[contains(@class, "raw-story")] | //div[contains(@class, "item")]');
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
        $nodes = $xpath->query('//div[contains(@class, "news-cat-list")]//div[contains(@class, "items")]');
        if ($nodes->length === 0) $nodes = $xpath->query('//div[contains(@class, "breaking-news-item")] | //div[contains(@class, "items")]');
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

    // Generic fallback
    if (empty($items)) {
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
    $content = $item->children($namespaces['content'] ?? '');
    $html = (string)$item->description . (string)$content->encoded;
    if (preg_match('/<img[^>]+(?:src|data-src)=["\']([^"\']+\.(?:jpg|jpeg|png|webp|gif)[^"\']*)["\']/i', $html, $matches)) {
        return $matches[1];
    }
    return '';
}

function extractDirectXMLImage($item) {
    if (isset($item->image)) return (string)$item->image;
    return '';
}

// Fetch single feed (using Helper)
function fetchFeed($url, $type, $sourceName, $providedContent = null) {
    $content = $providedContent ?? fetchUrl($url);
    if (!$content) return [];

    if ($type === 'json') {
        return parseJSONFeed($content, $url, $sourceName);
    } elseif ($type === 'html') {
        return parseHTMLFeed($content, $url, $sourceName);
    } else {
        return parseXMLFeed($content, $sourceName);
    }
}
