<?php
class MakerworldBridge extends BridgeAbstract {
  const NAME = 'MakerWorld 3D Models';
  const URI = 'https://makerworld.com';
  const DESCRIPTION = 'Latest 3D models from MakerWorld filtered by category, days, and sorting';
  const MAINTAINER = 'your_username';
  const PARAMETERS = [
    'General' => [
      'category' => [
        'name'        => 'Category',
        'type'        => 'list',
        'values'      => [
          'All Categories' => '',
          'Hobby & DIY' => '300',
          'Miniatures' => '301',
          'Toys & Games' => '302',
          'Home & Garden' => '303',
          'Fashion' => '304',
          'Tools & Gadgets' => '305',
          'Art & Design' => '306',
          'Educational' => '307',
          'Automotive' => '308',
          'Electronics' => '309',
          'Jewelry' => '310',
          'Sports & Outdoors' => '311',
          'Medical & Science' => '312',
          'Architecture' => '313',
          'Food & Kitchen' => '314',
          'Cosplay' => '315',
          'Replacement Parts' => '316',
          'Business & Industrial' => '317'
        ],
        'defaultValue' => '300',
      ],
      'days' => [
        'name'        => 'Days since created',
        'type'        => 'list',
        'values'      => [
          '1 day' => '1',
          '7 days' => '7',
          '30 days' => '30',
          '90 days' => '90',
          'All time' => ''
        ],
        'defaultValue' => '7',
      ],
      'orderBy' => [
        'name' => 'Sort by',
        'type' => 'list',
        'values' => [
          'Hot Score' => 'hotScore',
          'Latest' => 'latest',
          'Most Liked' => 'mostLiked',
          'Most Downloaded' => 'mostDownloaded',
          'Most Collected' => 'mostCollected'
        ],
        'defaultValue' => 'hotScore',
      ],
      'limit' => [
        'name'        => 'Number of items',
        'type'        => 'number',
        'defaultValue' => 20,
        'title'       => 'Maximum number of items to return (1-50)'
      ]
    ],
  ];

  public function collectData() {
    $category = $this->getInput('category');
    $days     = $this->getInput('days');
    $orderBy  = $this->getInput('orderBy');
    $limit    = min(max(intval($this->getInput('limit')), 1), 50);

    // Build URL based on category selection
    if ($category) {
      $url = self::URI . "/en/3d-models/{$category}";
    } else {
      $url = self::URI . "/en/3d-models";
    }

    // Add query parameters
    $params = [];
    if ($days) {
      $params['designCreateSince'] = $days;
    }
    if ($orderBy) {
      $params['orderBy'] = $orderBy;
    }

    if (!empty($params)) {
      $url .= '?' . http_build_query($params);
    }

    $html = getSimpleHTMLDOMCached($url, 3600);
    if (!$html) {
      returnServerError('Could not fetch MakerWorld page');
    }

    $itemCount = 0;
    foreach ($html->find('h3 a, h2 a') as $link) {
      if ($itemCount >= $limit) {
        break;
      }

      $title = trim($link->plaintext);
      if (!$title) {
        continue;
      }

      $item = [
        'title'      => $title,
        'uri'        => html_entity_decode(
          strpos($link->href, '//') === 0 ? 'https:'.$link->href :
          (strpos($link->href, 'http') === 0 ? $link->href : self::URI.$link->href)
        ),
        'author'     => '',
        'categories' => [],
        'enclosures' => [],
        'content'    => '',
        'timestamp'  => time(),
      ];

      // Fetch detail page
      $detail = getSimpleHTMLDOMCached($item['uri'], 3600);
      if ($detail) {
        // Image
        if ($m = $detail->find('meta[property=og:image]', 0)) {
          $img = $m->content;
        } elseif ($imgTag = $detail->find('.model-media img, .gallery img', 0)) {
          $img = $imgTag->src ?: $imgTag->getAttribute('data-src');
        }
        if (!empty($img)) {
          $img = preg_match('#^//#', $img) ? 'https:'.$img : (preg_match('#^https?://#', $img) ? $img : self::URI.$img);
          $item['enclosures'][] = $img;
          $item['content'] .= '<img src="'.$img.'" style="max-width:300px;"><br>';
        }

        // Description: better HTML processing
        if ($descEl = $detail->find('.model-description, .description', 0)) {
          $raw = $descEl->innertext;
        } elseif ($metaDesc = $detail->find('meta[name=description]', 0)) {
          $raw = '<p>'.$metaDesc->content.'</p>';
        } else {
          $raw = '';
        }

        if ($raw) {
          // First, preserve paragraph structure by replacing closing </p> with double newlines
          $withParagraphs = preg_replace('#</p>#i', "\n\n", $raw);
          // Replace other block elements with single newlines
          $withLines = preg_replace('#</?(div|br|h[1-6]|li|ul|ol|blockquote)[^>]*>#i', "\n", $withParagraphs);
          // Strip all remaining HTML tags
          $stripped = strip_tags($withLines);
          // Clean up whitespace but preserve paragraph breaks
          $cleaned = preg_replace("/[ \t]+/", " ", $stripped); // Multiple spaces to single space
          $cleaned = preg_replace("/\n[ \t]+/", "\n", $cleaned); // Remove spaces after newlines
          $cleaned = preg_replace("/[ \t]+\n/", "\n", $cleaned); // Remove spaces before newlines
          $cleaned = preg_replace("/\n{3,}/", "\n\n", trim($cleaned)); // Max 2 consecutive newlines
          // Convert double newlines to paragraphs, single newlines to breaks
          $paragraphed = preg_replace("/\n\n+/", "</p><p>", $cleaned);
          $paragraphed = preg_replace("/\n/", "<br>", $paragraphed);
          // Wrap in paragraph tags if there's content
          if (trim($paragraphed)) {
            $item['content'] .= '<p>' . $paragraphed . '</p>';
          }
        }

        // Author
        if ($authorEl = $detail->find('.author-name a, .username a', 0)) {
          $item['author'] = trim($authorEl->plaintext);
        }

        // Tags/Categories
        foreach ($detail->find('.tag-list a, .categories a, .model-tags a') as $tagEl) {
          $tag = trim($tagEl->plaintext);
          if ($tag) {
            $item['categories'][] = $tag;
          }
        }

        // Try to get creation date if available
        if ($dateEl = $detail->find('.creation-date, .publish-date, time', 0)) {
          $dateText = $dateEl->getAttribute('datetime') ?: $dateEl->plaintext;
          if ($dateText) {
            $timestamp = strtotime($dateText);
            if ($timestamp) {
              $item['timestamp'] = $timestamp;
            }
          }
        }
      }

      $item['content'] .= '<p><a href="'.$item['uri'].'">View on MakerWorld</a></p>';
      $this->items[] = $item;
      $itemCount++;
    }
  }

  public function getName() {
    $category = $this->getInput('category');
    $days = $this->getInput('days');
    $orderBy = $this->getInput('orderBy');

    $name = 'MakerWorld';

    if ($category) {
      $categoryNames = array_flip($this->getParameters()['General']['category']['values']);
      if (isset($categoryNames[$category])) {
        $name .= ' - ' . $categoryNames[$category];
      }
    } else {
      $name .= ' - All Categories';
    }

    if ($days) {
      $name .= " (Last {$days} days)";
    }

    return $name;
  }
}
?>
