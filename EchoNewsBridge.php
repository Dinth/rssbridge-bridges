<?php
class EchoNewsBridge extends BridgeAbstract {
  const NAME          = 'Echo News';
  const URI           = 'https://www.echo-news.co.uk/news/';
  const DESCRIPTION   = 'Echo-News articles with keyword filters, images, and excerpts';
  const MAINTAINER    = 'your_github_username';
  const CACHE_TIMEOUT = 3600;
  private $conditionalOverrides = [];
  const PARAMETERS = [
    [
      'keywords' => [
        'name'         => 'Keywords',
        'type'         => 'text',
        'required'     => false,
        'title'        => 'Advanced filtering: Use quotes for phrases, - prefix to exclude. Support conditional overrides with except(). Examples: flood,"traffic jam",-advertisement,-"canvey island",except("museum","country park") will exclude canvey island articles unless they contain both museum OR country park.',
        'exampleValue' => 'flood,"traffic jam",-"canvey island",-chelmsford,except("museum","country park")'
      ],
      'maxpages' => [
        'name'         => 'Max pages',
        'type'         => 'number',
        'required'     => false,
        'defaultValue'=> 1,
        'title'        => 'Number of pages to crawl (1–5)'
      ],
      'excerpt_length' => [
        'name'         => 'Excerpt length',
        'type'         => 'number',
        'required'     => false,
        'defaultValue' => 200,
        'title'        => 'Maximum length of article excerpt in characters'
      ],
      'debug' => [
        'name'         => 'Debug mode',
        'type'         => 'checkbox',
        'required'     => false,
        'title'        => 'Enable debug output to see HTML structure'
      ]
    ]
  ];
  public function collectData() {
    $uri      = self::URI;
    $maxPages = intval($this->getInput('maxpages'));
    $excerptLength = intval($this->getInput('excerpt_length')) ?: 200;
    $debug = $this->getInput('debug');
    list($include, $exclude, $conditionalOverrides) = $this->parseKeywords($this->getInput('keywords'));
    $this->conditionalOverrides = $conditionalOverrides;
    $seenUrls = [];
    for ($page = 1; $page <= $maxPages; $page++) {
      try {
        $html = getSimpleHTMLDOM($uri);
      } catch (\HttpException $e) {
        break;
      }
      $nodes = $html->find('ul.listing li');
      if (empty($nodes)) {
        $nodes = $html->find('article');
      }
      if (empty($nodes)) {
        $nodes = $html->find('main#content a');
      }
      if ($page === 1 && empty($nodes)) {
        returnServerError('No listing nodes found. Update selectors.');
      }
      foreach ($nodes as $node) {
        $linkElem = ($node->tag === 'a') ? $node : $node->find('a', 0);
        if (!$linkElem || empty($linkElem->href)) {
          continue;
        }
        $url = $this->absoluteUrl($linkElem->href);
        if (isset($seenUrls[$url])) {
          continue;
        }
        $seenUrls[$url] = true;
        if (stripos($url, 'advertisement') !== false) {
          continue;
        }
        // Fetch article page for true title/content
        try {
          $art = getSimpleHTMLDOM($url);
        } catch (\HttpException $e) {
          continue;
        }
        $h1 = $art->find('h1', 0);
        $title = $h1 ? trim($h1->plaintext) : trim($linkElem->plaintext);
        if (!$title) {
          continue;
        }

        // Clean up HTML entities and tags in title
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = strip_tags($title);
        $title = trim($title);

        // Check if this is a live article and modify title accordingly
        $liveContent = $art->find('#liveArticleContent', 0);
        $isLiveArticle = false;
        if ($liveContent && strlen(trim(strip_tags($liveContent->innertext))) > 50) {
          $title .= ' - LIVE FEED';
          $isLiveArticle = true;
        }

        // Skip advertisement articles based on title and content indicators
        if ($this->isAdvertisement($title, $art)) {
          continue;
        }
        // Debug: Show HTML structure
        $debugInfo = '';
        if ($debug) {
          $debugInfo = $this->getDebugInfo($art);
        }
        // Try to find article body
        $body = $this->findArticleBody($art);
        $contentHtml = $body ? $body->innertext : '';
        // Extract main article image
        $imageUrl = $this->extractMainImage($art);
        // Extract article categories
        $categories = $this->extractCategories($art);
        // Create excerpt from article content
        $excerpt = $this->createExcerpt($body, $excerptLength);

        // If no content found, try fallback
        if (!$contentHtml && !$excerpt) {
          $excerpt = $this->getFallbackExcerpt($art, $excerptLength);
        }

        // Decode HTML entities in excerpt
        if ($excerpt) {
          $excerpt = html_entity_decode($excerpt, ENT_QUOTES, 'UTF-8');
        }

        // If no content found, try fallback
        if (!$contentHtml && !$excerpt) {
          $excerpt = $this->getFallbackExcerpt($art, $excerptLength);
        }
        $timestamp = time();
        $time = $art->find('time', 0);
        if ($time && !empty($time->datetime)) {
          // Parse the datetime and adjust for UK timezone (BST/GMT)
          $dateTime = new DateTime($time->datetime);
          // Set timezone to UK (handles BST/GMT automatically)
          $dateTime->setTimezone(new DateTimeZone('Europe/London'));
          $timestamp = $dateTime->getTimestamp();
        }
        // Check keywords against title and excerpt
        if (!$this->matchesKeywords($title, $excerpt, $include, $exclude)) {
          continue;
        }
        // Clean and format the content
        $cleanContent = $this->cleanContent($contentHtml);
        $cleanContent = html_entity_decode($cleanContent, ENT_QUOTES, 'UTF-8');

        // Build enhanced content with debug info if enabled
        $enhancedContent = '';
        if ($debug) {
          $enhancedContent .= '<div style="background:#f0f0f0;padding:10px;margin-bottom:10px;font-size:12px;"><strong>DEBUG INFO:</strong><br>' . $debugInfo . '</div>';
        }
        if ($imageUrl) {
          $enhancedContent .= '<figure style="margin: 0 0 20px 0;"><img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($title) . '" style="max-width:100%; height:auto; border-radius: 4px;"></figure>';
        }
        if ($excerpt) {
          $enhancedContent .= '<div style="background:#f8f9fa;padding:15px;margin-bottom:20px;border-left:4px solid #007bff;border-radius:4px;"><strong>Summary:</strong> ' . htmlspecialchars($excerpt) . '</div>';
        }
        $enhancedContent .= $cleanContent;
        $this->items[] = [
          'title'         => $title,
          'uri'           => $url,
          'content'       => strip_tags($cleanContent),
          'content_html'  => $enhancedContent,
          'timestamp'     => $timestamp,
          'enclosures'    => $imageUrl ? [$imageUrl] : [],
          'summary'       => $excerpt,
          'categories'    => $categories
        ];
      }
      $next = $html->find('a.pagination__next', 0);
      if (!$next || empty($next->href)) {
        break;
      }
      $uri = $this->absoluteUrl($next->href);
    }
  }
  private function parseKeywords($input) {
    $include = $exclude = $conditionalOverrides = [];
    if (!$input) {
      return [$include, $exclude, $conditionalOverrides];
    }
    // First, extract quoted phrases (both include and exclude)
    $quotedPhrases = [];
    preg_match_all('/-?"[^"]+"|\'[^\']+\'/', $input, $matches);
    foreach ($matches[0] as $match) {
      $quotedPhrases[] = $match;
      // Remove the quoted phrase from input to process remaining keywords
      $input = str_replace($match, '', $input);
    }
    // Look for conditional override syntax: except("keyword","keyword")
    preg_match_all('/except\s\(\s([^)]+)\s*\)/', $input, $exceptMatches);
    if (!empty($exceptMatches[0])) {
      foreach ($exceptMatches[1] as $exceptContent) {
        // Parse the content inside except()
        $exceptTerms = [];
        preg_match_all('/"([^"]+)"|\'([^\']+)\'|([^,\s]+)/', $exceptContent, $termMatches);
        foreach ($termMatches[0] as $term) {
          $term = trim($term, '"\'');
          if (!empty($term)) {
            $exceptTerms[] = strtolower($term);
          }
        }
        if (!empty($exceptTerms)) {
          $conditionalOverrides[] = $exceptTerms;
        }
      }
      // Remove except() clauses from input
      $input = preg_replace('/except\s*\([^)]+\)/', '', $input);
    }
    // Process quoted phrases
    foreach ($quotedPhrases as $phrase) {
      $phrase = trim($phrase);
      $isExclude = (strpos($phrase, '-') === 0);
      // Remove the leading - and quotes
      if ($isExclude) {
        $phrase = substr($phrase, 1);
      }
      $phrase = trim($phrase, '"\'');
      $phrase = strtolower($phrase);
      if ($phrase !== '') {
        if ($isExclude) {
          $exclude[] = $phrase;
        } else {
          $include[] = $phrase;
        }
      }
    }
    // Process remaining comma-separated keywords
    foreach (explode(',', strtolower($input)) as $term) {
      $term = trim($term);
      if ($term === '') {
        continue;
      } elseif (strpos($term, '-') === 0) {
        $exclude[] = substr($term, 1);
      } else {
        $include[] = $term;
      }
    }
    return [$include, $exclude, $conditionalOverrides];
  }
  private function extractCategories($articleDom) {
    $categories = [];
    $categoriesContainer = $articleDom->find('#article > div.mar-article > div > div.mar-article__article-tags', 0);

    if ($categoriesContainer) {
      $tags = $categoriesContainer->find('div.article-tags');
      foreach ($tags as $tag) {
        $tagText = trim($tag->plaintext);
        if (!empty($tagText)) {
          $categories[] = $tagText;
        }
      }
    }

    return $categories;
  }
  private function matchesKeywords($title, $summary, $include, $exclude) {
    $text = strtolower($title . ' ' . $summary);
    // Check exclude terms/phrases first
    $shouldExclude = false;
    $excludeMatches = [];
    foreach ($exclude as $kw) {
      if (strpos($text, $kw) !== false) {
        $shouldExclude = true;
        $excludeMatches[] = $kw;
      }
    }
    // If article would be excluded, check for conditional overrides
    if ($shouldExclude && !empty($this->conditionalOverrides)) {
      foreach ($this->conditionalOverrides as $overrideTerms) {
        $anyOverrideTermFound = false;
        foreach ($overrideTerms as $overrideTerm) {
          if (strpos($text, $overrideTerm) !== false) {
            $anyOverrideTermFound = true;
            break;
          }
        }
        // If all override terms are found, don't exclude this article
        if ($allOverrideTermsFound) {
          $shouldExclude = false;
          break;
        }
      }
    }
    if ($shouldExclude) {
      return false;
    }
    // If no include terms specified, allow all (that aren't excluded)
    if (empty($include)) {
      return true;
    }
    // Check if any include term/phrase matches
    foreach ($include as $kw) {
      if (strpos($text, $kw) !== false) {
        return true;
      }
    }
    return false;
  }
  private function isAdvertisement($title, $articleDom) {
    $lowerTitle = strtolower($title);
    $content = $articleDom->plaintext;
    $lowerContent = strtolower($content);
    // STRONGER subscription detection - must match multiple criteria
    $subscriptionTerms = [
      'subscription', 'subscribe', 'digital subscription',
      'reader rewards', 'special offer', 'exclusive access',
      'unlimited access', 'monthly subscription'
    ];
    $titleMatches = 0;
    $contentMatches = 0;
    foreach ($subscriptionTerms as $term) {
      if (strpos($lowerTitle, $term) !== false) {
        $titleMatches++;
      }
      if (strpos($lowerContent, $term) !== false) {
        $contentMatches++;
      }
    }
    // Strong indicators: subscription in title + multiple content matches
    if ($titleMatches >= 1 && $contentMatches >= 3) {
      return true;
    }
    // Very specific subscription article patterns
    if (strpos($lowerTitle, 'subscription') !== false &&
      (strpos($lowerTitle, 'months') !== false ||
      strpos($lowerTitle, 'offer') !== false ||
      strpos($lowerTitle, 'deal') !== false)) {
      return true;
      }
      // Content-heavy subscription indicators
      $strongContentIndicators = [
        'terms and conditions apply',
        'subscription auto-renews',
        'newsquest media group',
        'digital subscription is the best way',
        'click here to subscribe'
      ];
      $strongMatches = 0;
      foreach ($strongContentIndicators as $indicator) {
        if (strpos($lowerContent, $indicator) !== false) {
          $strongMatches++;
        }
      }
      return $strongMatches >= 2;
  }
  private function cleanContent($html) {
    if (!$html) return '';
    // Enhanced JavaScript and script removal
    $cleaningPatterns = [
      // Remove all script tags and their content (most comprehensive)
      '/<script[^>]*>[\s\S]*?<\/script>/is',
      // Remove inline JavaScript handlers
      '/\son[a-z]+\s=\s["\'][^"\']["\']?/i',
      // Remove JavaScript function calls and variable assignments
      '/\(function\s\([^)]*\)\s{[^}]}[^}]}\)\s*\([^)]*\);?/is',
      '/var\s+[^;]+;/is',
      '/const\s+[^;]+;/is',
      '/let\s+[^;]+;/is',
      // Remove specific video player JavaScript (ExCo player)
      '/ExCoPlayer\.[^;]+;?/is',
      '/PARSELY\.[^;]+;?/is',
      // Remove JavaScript object definitions
      '/\{\s["\']?[a-zA-Z_$][a-zA-Z0-9_$]["\']?\s:\s[^}]+\}/is',
      // Remove standalone JavaScript statements
      '/\s[a-zA-Z_$][a-zA-Z0-9_$]*\s=\s[^;]+;?\s/is',
      // Remove div containers for video players and embeds
      '/<div[^>]class="exco-embed"[^>]>.*?<\/div>/is',
      '/<div[^>]id="[0-9a-f-]+"[^>]class="exco-embed"[^>]*><\/div>/is',
      '/<div[^>]*class="[^"]*Embed[^"]*"[^>]*>.*?<\/div>/is',
      // Remove script class for embeds
       '/<script[^>]*class="[^"]*exco-player[^"]*"[^>]*>.*?<\/script>/is',
      // Existing advertisement and unwanted content patterns
      '/<div[^>]advert-container[^>]>.*?<\/div>/is',
      '/<div[^>]DFP_[^>]>.*?<\/div>/is',
      '/<aside[^>]OUTBRAIN[^>]>.*?<\/aside>/is',
      '/<div[^>]piano-container[^>]>.*?<\/div>/is',
      '/<div[^>]link-builder-block[^>]>.*?<\/div>/is',
      '/<span[^>]inline-image-caption[^>]>.*?<\/span>/is',
      // Remove "Read more posts" from live articles
      '/<p[^>]*>\s*Read more posts\s*<\/p>/is',
      '/<div[^>]*>\s*Read more posts\s*<\/div>/is',
      '/<span[^>]*>\s*Read more posts\s*<\/span>/is',
      '/Read more posts/is',
      // Enhanced WhatsApp advertisement removal
      '/<hr>\s<p[^>]>\sWe\'re now on\s&nbsp;\sWhatsApp![^<]<\/p>\s*<hr>/is',
      '/<hr[^>]>\s<p[^>]>.?WhatsApp.?<\/p>\s<hr[^>]*>/is',
      '/<p[^>]>\sWe\'re now on WhatsApp![^<]*<\/p>/is',
      '/<p[^>]>We\'re now on WhatsApp![^<]?bit\.ly\/[^<]*<\/p>/is',
      '/<p[^>]>.?We\'re now on WhatsApp.?bit\.ly\/[^<].?<\/p>/is',
      // Remove conditional script execution
      '/if\s*\([^)]+\)\s*\{[^}]*\}/is',
      // Remove jQuery/$ statements
      '/\$\([^)]*\)\.[^;]+;?/is',
      // Remove window object assignments
      '/window\.[^;]+;?/is',
      // remove internal linking
      '/<div[^>]*class="[^"]*link-builder-block[^"]*"[^>]*>.*?<\/div>/is'
    ];
    foreach ($cleaningPatterns as $pattern) {
      $html = preg_replace($pattern, '', $html);
    }
    // Additional cleanup: Remove any remaining JavaScript-like content
    // Remove lines that look like JavaScript (contain common JS keywords)
    $jsKeywords = ['function', 'var ', 'const ', 'let ', '= {', '}.', 'addEventListener', 'onload', 'getElementById'];
    $lines = explode("\n", $html);
    $cleanedLines = [];
    foreach ($lines as $line) {
      $line = trim($line);
      $containsJs = false;
      foreach ($jsKeywords as $keyword) {
        if (stripos($line, $keyword) !== false) {
          $containsJs = true;
          break;
        }
      }
      if (!$containsJs && !empty($line)) {
        $cleanedLines[] = $line;
      }
    }
    $html = implode("\n", $cleanedLines);
    // Remove any remaining stray script-related tags and content
    $html = preg_replace('/<\/script>/', '', $html);
    $html = preg_replace('/<script[^>]*>/', '', $html);
    // Remove any remaining HR tags (advertisement leftovers)
    $html = preg_replace('/<hr[^>]>\s/', '', $html);
    $html = preg_replace('/\s<hr[^>]>/', '', $html);
    // Clean up excessive whitespace and empty paragraphs
    $html = preg_replace('/<p>\s*<\/p>/', '', $html);
    $html = preg_replace('/\s+/', ' ', $html);
    $html = preg_replace('/>\s+</', '><', $html);
    // Fix malformed HTML
    $html = preg_replace('/<\/div>\s<\/div>\s<p>/', '<p>', $html);
    return trim($html);
  }
  private function getDebugInfo($articleDom) {
    $info = [];
    // Check for content containers
    $contentSelectors = [
      '#liveArticleContent' => 'Live article content',
      '#live-article-content__livefeed-summary' => 'Live feed summary',
      '#subscription-replace-entire-article' => 'Main content area',
      '.article-body' => 'Article body',
      '.article-first-paragraph' => 'First paragraph',
      '.block-article-content' => 'Block article content',
      'main article' => 'Main article tag'
    ];
    foreach ($contentSelectors as $selector => $description) {
      $element = $articleDom->find($selector, 0);
      if ($element) {
        $textLength = strlen(trim($element->plaintext));
        $info[] = "✓ Found {$description} ({$selector}): {$textLength} chars";
      } else {
        $info[] = "✗ NOT FOUND: {$description} ({$selector})";
      }
    }
    // Enhanced image debugging
    $imagesWithSrcset = $articleDom->find('img[srcset]');
    $imagesWithSrc = $articleDom->find('img[src]');
    $info[] = "📷 Images with srcset found: " . count($imagesWithSrcset);
    $info[] = "🖼️ Images with src found: " . count($imagesWithSrc);
    // Show specific image candidates with actual URLs
    $contentImages = $articleDom->find('#subscription-content img, .article-body img, .mar-article-image img');
    $info[] = "🎯 Content area images found: " . count($contentImages);
    // Debug actual image URLs being found
    foreach ($contentImages as $i => $img) {
      if ($i < 3) { // Show first 3
        $src = $img->src ? $img->src : ($img->srcset ? 'HAS SRCSET' : 'NO SRC');
        $alt = $img->alt ? substr($img->alt, 0, 30) . '...' : 'No alt';
        $info[] = "IMG #{$i}: src=\"{$src}\" alt=\"{$alt}\"";
      }
    }
    return implode('<br>', $info);
  }
  private function findArticleBody($articleDom) {
    // Check if this is a live article first
    $liveContent = $articleDom->find('#liveArticleContent', 0);
    if ($liveContent && strlen(trim(strip_tags($liveContent->innertext))) > 50) {
      // For live articles, use the live feed summary
      $liveSummary = $articleDom->find('#live-article-content__livefeed-summary', 0);
      if ($liveSummary) {
        $html = $liveSummary->innertext;
        $textLength = strlen(trim(strip_tags($html)));
        if ($textLength > 50) {
          $cleanElement = str_get_html($html);
          if ($cleanElement) {
            return $cleanElement;
          }
        }
      }
      // Fallback to full live content if summary not found
      $html = $liveContent->innertext;
      $textLength = strlen(trim(strip_tags($html)));
      if ($textLength > 100) {
        $cleanElement = str_get_html($html);
        if ($cleanElement) {
          return $cleanElement;
        }
      }
    }

    // Regular article selectors
    $selectors = [
      '#subscription-replace-entire-article'
    ];
    foreach ($selectors as $selector) {
      $element = $articleDom->find($selector, 0);
      if ($element) {
        $html = $element->innertext;
        $textLength = strlen(trim(strip_tags($html)));
        if ($textLength > 100) {
          $cleanElement = str_get_html($html);
          if ($cleanElement) {
            return $cleanElement;
          }
        }
      }
    }

    // Ultimate fallback - try to get ANY paragraphs
    $allParagraphs = $articleDom->find('p');
    if (!empty($allParagraphs)) {
      $content = '';
      foreach ($allParagraphs as $p) {
        $text = trim($p->plaintext);
        if (strlen($text) > 30 &&
          !preg_match('/cookie|subscribe|newsletter|advert|whatsapp/i', $text)) {
          $content .= $p->outertext;
          }
      }
      if (strlen(strip_tags($content)) > 100) {
        return str_get_html($content);
      }
    }
    return null;
  }

  private function getFallbackExcerpt($articleDom, $maxLength = 200) {
    $paragraphs = $articleDom->find('p');
    $text = '';
    foreach ($paragraphs as $p) {
      $pText = trim($p->plaintext);
      if (strlen($pText) > 30 &&
        stripos($pText, 'cookie') === false &&
        stripos($pText, 'subscribe') === false &&
        stripos($pText, 'newsletter') === false &&
        stripos($pText, 'whatsapp') === false &&
        stripos($pText, 'we\'re now on whatsapp') === false &&
        stripos($pText, 'join our new channel') === false &&
        stripos($pText, 'bit.ly/') === false &&
        stripos($pText, 'terms and conditions') === false) {
        $text .= $pText . ' ';
      if (strlen($text) > $maxLength) {
        break;
      }
        }
    }
    return $this->truncateText($text, $maxLength);
  }
  private function truncateText($text, $maxLength) {
    $text = trim($text);
    if (strlen($text) <= $maxLength) {
      return $text;
    }
    $excerpt = substr($text, 0, $maxLength);
    $lastSpace = strrpos($excerpt, ' ');
    if ($lastSpace !== false) {
      $excerpt = substr($excerpt, 0, $lastSpace);
    }
    return $excerpt . '...';
  }
  private function extractMainImage($articleDom) {
    // Priority 1: Look for hero/featured images first
    $heroSelectors = [
      '.mar-article-image img[srcset]',
      '.mar-article-image img[src]',
      '.article-hero img[srcset]',
      '.article-hero img[src]',
      '.featured-image img[srcset]',
      '.featured-image img[src]'
    ];
    foreach ($heroSelectors as $selector) {
      $img = $articleDom->find($selector, 0);
      if ($img) {
        $imgUrl = $this->getImageUrl($img);
        if ($imgUrl && $this->isValidArticleImage($imgUrl)) {
          return $this->absoluteUrl($imgUrl);
        }
      }
    }
    // Priority 2: Find images within the article content area (both srcset and src)
    $contentSelectors = [
      '#subscription-replace-entire-article img[srcset]',
      '#subscription-replace-entire-article img[src]'
    ];
    foreach ($contentSelectors as $selector) {
      $images = $articleDom->find($selector);
      foreach ($images as $img) {
        $imgUrl = $this->getImageUrl($img);
        if ($imgUrl && $this->isValidArticleImage($imgUrl)) {
          // Skip if it's within an ad container
          $parent = $img->parent();
          $skipImage = false;
          while ($parent && !$skipImage) {
            if (stripos($parent->class, 'advert') !== false ||
              stripos($parent->class, 'ad') !== false) {
              $skipImage = true;
              }
              $parent = $parent->parent();
          }
          if (!$skipImage) {
            return $this->absoluteUrl($imgUrl);
          }
        }
      }
    }
    return null;
  }
  private function getImageUrl($img) {
    // Try srcset first (get highest quality), then fallback to src
    if ($img->srcset) {
      return $this->getBestImageFromSrcset($img->srcset);
    } elseif ($img->src) {
      return $img->src;
    }
    return null;
  }
  private function getBestImageFromSrcset($srcset) {
    preg_match_all('/(\S+)\s+(\d+)w/', $srcset, $matches, PREG_SET_ORDER);
    if (empty($matches)) return null;
    // Sort by width and get largest
    usort($matches, function($a, $b) { return intval($b[2]) - intval($a[2]); });
    return $matches[0][1];
  }
  private function isValidArticleImage($url) {
    $lowerUrl = strtolower($url);
    // Skip known non-article images
    $skipPatterns = [
      'love-local', 'logo', 'icon', 'banner', 'small_site_logo',
      'social', 'ipso', 'advertisement', 'tracker', 'pixel',
      '1x1', 'facebook', 'twitter', 'regulated', 'footer', 'header',
      'usa-today-logo'
    ];
    foreach ($skipPatterns as $pattern) {
      if (stripos($lowerUrl, $pattern) !== false) {
        return false;
      }
    }
    return true;
  }
  private function createExcerpt($bodyDom, $maxLength = 200) {
    if (!$bodyDom) {
      return '';
    }
    $paragraphs = $bodyDom->find('p');
    $text = '';
    foreach ($paragraphs as $p) {
      $pText = trim($p->plaintext);
      // Skip paragraphs that contain JavaScript or unwanted content
      if (strlen($pText) < 20 ||
        stripos($pText, 'advertisement') !== false ||
        stripos($pText, 'subscribe') !== false ||
        stripos($pText, 'newsletter') !== false ||
        stripos($pText, 'cookie') !== false ||
        stripos($pText, 'whatsapp') !== false ||
        stripos($pText, 'we\'re now on whatsapp') !== false ||
        stripos($pText, 'join our new channel') !== false ||
        stripos($pText, 'bit.ly/') !== false ||
        // JavaScript content detection
        stripos($pText, 'function') !== false ||
        stripos($pText, 'var ') !== false ||
        stripos($pText, 'const ') !== false ||
        stripos($pText, 'ExCoPlayer') !== false ||
        stripos($pText, 'PARSELY') !== false ||
        stripos($pText, 'getElementById') !== false ||
        stripos($pText, 'addEventListener') !== false ||
        preg_match('/we\'re now on.*?whatsapp/i', $pText) ||
        preg_match('/whatsapp.*?bit\.ly/i', $pText) ||
        preg_match('/\(function|\{.\}|var\s+\w+\s=/', $pText) ||
        stripos($pText, 'terms and conditions') !== false) {
        continue;
        }
        $text .= $pText . ' ';
        if (strlen($text) > $maxLength * 1.5) {
          break;
        }
    }
    // If no good paragraphs found, try the article-first-paragraph class
    $firstPara = $bodyDom->find('.article-first-paragraph', 0);
    if ($firstPara) {
      $pText = trim($firstPara->plaintext);
      // Check if first paragraph is clean
      if (stripos($pText, 'whatsapp') === false &&
        stripos($pText, 'function') === false &&
        stripos($pText, 'var ') === false &&
        stripos($pText, 'ExCoPlayer') === false &&
        !preg_match('/\\(function|\\{.*\\}/', $pText)) {
        // If we already have text, prepend the first paragraph
        if (strlen(trim($text)) > 0) {
          $text = $pText . ' ' . $text;
        } else {
          $text = $pText;
        }
        }
    }
    return $this->truncateText($text, $maxLength);
  }
  private function absoluteUrl($path) {
    if (strpos($path, 'http') === 0) {
      return $path;
    }
    // Handle relative URLs that start with /resources/
    if (strpos($path, '/resources/') === 0) {
      return 'https://www.echo-news.co.uk' . $path;
    }
    // Handle other relative URLs
    if (strpos($path, '/') === 0) {
      return 'https://www.echo-news.co.uk' . $path;
    }
    // Fallback for relative paths
    return rtrim(self::URI, '/') . '/' . ltrim($path, '/');
  }
}
