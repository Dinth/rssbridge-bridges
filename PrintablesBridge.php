<?php
class PrintablesBridge extends BridgeAbstract {
  const MAINTAINER = 'Your Name';
  const NAME = 'Printables.com';
  const URI = 'https://www.printables.com';
  const DESCRIPTION = 'Returns trending 3D models from Printables.com';
  const CACHE_TIMEOUT = 1800; // 30 minutes

  const PARAMETERS = [
    '' => [
      'category' => [
        'name' => 'Category',
        'type' => 'list',
        'required' => false,
        'defaultValue' => null,
        'values' => [
          'All Categories' => null,
          '3D Printer Accessories' => '31',
          'Art' => '88',
          'Fashion' => '97',
          'Functional' => '20',
          'Games' => '19',
          'Gadgets' => '11',
          'Hobby' => '14',
          'Household' => '3',
          'Learning' => '84',
          'Miniatures' => '10',
          'Models' => '18',
          'RC Vehicles' => '52',
          'Signs & Logos' => '94',
          'Spare Parts' => '16',
          'Sport & Outdoors' => '25',
          'Tools' => '15',
          'Toys' => '12'
        ]
      ],
      'days' => [
        'name' => 'Trending Period',
        'type' => 'list',
        'required' => false,
        'defaultValue' => '7',
        'values' => [
          'Last 7 days' => '7',
          'Last 30 days' => '30',
          'Last 3 months' => '90'
        ]
      ],
      'sort' => [
        'name' => 'Sort By',
        'type' => 'list',
        'required' => false,
        'defaultValue' => 'trending',
        'values' => [
          'Trending' => 'trending',
          'Most Liked' => 'likes',
          'Most Downloaded' => 'downloads',
          'Most Recent' => 'newest'
        ]
      ],
      'limit' => [
        'name' => 'Number of items',
        'type' => 'number',
        'required' => false,
        'defaultValue' => 20,
        'title' => 'Maximum number of items to return (1-50)'
      ]
    ]
  ];

  private function getApiEndpoint() {
    return 'https://api.printables.com/graphql/';
  }

  private function buildModelListQuery() {
    $category = $this->getInput('category');
    $days = $this->getInput('days');
    $sort = $this->getInput('sort');
    $limit = min(50, max(1, (int)$this->getInput('limit')));

    // Map sort options to Printables ordering
    $ordering = '-likes_count_7_days'; // default
    switch($sort) {
      case 'trending':
        $ordering = '-likes_count_7_days';
        break;
      case 'likes':
        $ordering = '-likes_count';
        break;
      case 'downloads':
        $ordering = '-download_count';
        break;
      case 'newest':
        $ordering = '-published';
        break;
    }

    $graphqlQuery = 'query ModelList($limit: Int!, $cursor: String, $categoryId: ID, $materialIds: [Int], $userId: ID, $printerIds: [Int], $licenses: [ID], $ordering: String, $hasModel: Boolean, $filesType: [FilterPrintFilesTypeEnum], $modelingApps: [ID], $includeUserGcodes: Boolean, $nozzleDiameters: [Float], $weight: IntervalObject, $printDuration: IntervalObject, $publishedDateLimitDays: Int, $featured: Boolean, $featuredNow: Boolean, $usedMaterial: IntervalObject, $hasMake: Boolean, $competitionAwarded: Boolean, $onlyFollowing: Boolean, $collectedByMe: Boolean, $madeByMe: Boolean, $likedByMe: Boolean, $aiGenerated: Boolean, $paid: PaidEnum, $price: IntervalObject, $sale: Boolean, $downloadable: Boolean, $excludedIds: [ID]) { models: morePrints( limit: $limit cursor: $cursor categoryId: $categoryId materialIds: $materialIds printerIds: $printerIds licenses: $licenses userId: $userId ordering: $ordering hasModel: $hasModel filesType: $filesType modelingApps: $modelingApps nozzleDiameters: $nozzleDiameters includeUserGcodes: $includeUserGcodes weight: $weight printDuration: $printDuration publishedDateLimitDays: $publishedDateLimitDays featured: $featured featuredNow: $featuredNow usedMaterial: $usedMaterial hasMake: $hasMake onlyFollowing: $onlyFollowing competitionAwarded: $competitionAwarded collectedByMe: $collectedByMe madeByMe: $madeByMe liked: $likedByMe aiGenerated: $aiGenerated paid: $paid price: $price sale: $sale downloadablePremium: $downloadable excludedIds: $excludedIds ) { cursor items { ...Model __typename } __typename } } fragment AvatarUser on UserType { id handle verified dateVerified publicUsername avatarFilePath badgesProfileLevel { profileLevel __typename } __typename } fragment LatestContestResult on PrintType { latestContestResult: latestCompetitionResult { ranking: placement competitionId __typename } __typename } fragment Model on PrintType { id name slug ratingAvg likesCount liked datePublished dateFeatured firstPublish downloadCount mmu category { id path { id name nameEn __typename } __typename } modified image { ...SimpleImage __typename } nsfw aiGenerated club: premium price priceBeforeSale user { ...AvatarUser isHiddenForMe __typename } ...LatestContestResult __typename } fragment SimpleImage on PrintImageType { id filePath rotation imageHash imageWidth imageHeight __typename }';

    $variables = [
      'categoryId' => $category,
      'competitionAwarded' => false,
      'cursor' => null,
      'featured' => false,
      'hasMake' => false,
      'limit' => $limit,
      'ordering' => $ordering,
      'publishedDateLimitDays' => intval($days)
    ];

    $query = [
      'operationName' => 'ModelList',
      'query' => $graphqlQuery,
      'variables' => $variables
    ];

    return json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  private function buildModelDetailQuery($modelId) {
    $graphqlQuery = 'query ModelDetail($id: ID!, $loadPurchase: Boolean!) { model: print(id: $id) { ...ModelDetailEditable priceBeforeSale purchaseDate @include(if: $loadPurchase) paidPrice @include(if: $loadPurchase) giveawayDate @include(if: $loadPurchase) mmu user { ...UserDetail __typename } contests: competitions { id name slug description isOpen __typename } contestsResults: competitionResults { ranking: placement contest: competition { id name slug modelsCount: printsCount openFrom openTo __typename } __typename } prusameterPoints ...LatestContestResult __typename } } fragment AvatarUser on UserType { id handle verified dateVerified publicUsername avatarFilePath badgesProfileLevel { profileLevel __typename } __typename } fragment LatestContestResult on PrintType { latestContestResult: latestCompetitionResult { ranking: placement competitionId __typename } __typename } fragment Model on PrintType { id name slug ratingAvg likesCount liked datePublished dateFeatured firstPublish downloadCount mmu category { id path { id name nameEn __typename } __typename } modified image { ...SimpleImage __typename } nsfw aiGenerated club: premium price priceBeforeSale user { ...AvatarUser isHiddenForMe __typename } ...LatestContestResult __typename } fragment ModelDetailEditable on PrintType { id slug name authorship club: premium excludeCommercialUsage price eduProject { id __typename } ratingAvg myRating ratingCount description category { id path { id name nameEn description __typename } __typename } modified firstPublish datePublished dateCreatedThingiverse nsfw aiGenerated summary likesCount makesCount liked printDuration numPieces weight nozzleDiameters usedMaterial layerHeights mmu materials { id name __typename } dateFeatured downloadCount displayCount filesCount privateCollectionsCount publicCollectionsCount pdfFilePath commentCount userGcodeCount remixCount canBeRated addMakePermission printer { id name __typename } image { ...SimpleImage __typename } images { ...SimpleImage __typename } tags { name id __typename } thingiverseLink filesType previewFile { ...PreviewFile __typename } license { id disallowRemixing __typename } remixParents { ...RemixParent __typename } remixDescription __typename } fragment PreviewFile on PreviewFileUnionType { ... on STLType { id filePreviewPath __typename } ... on SLAType { id filePreviewPath __typename } ... on GCodeType { id filePreviewPath __typename } __typename } fragment RemixParent on PrintRemixType { id modelId: parentPrintId modelName: parentPrintName modelAuthor: parentPrintAuthor { id handle verified publicUsername __typename } model: parentPrint { ...RemixParentModel __typename } url urlAuthor urlImage urlTitle urlLicense { id name disallowRemixing __typename } urlLicenseText __typename } fragment RemixParentModel on PrintType { id name slug datePublished image { ...SimpleImage __typename } club: premium authorship license { id name disallowRemixing __typename } eduProject { id __typename } __typename } fragment SimpleImage on PrintImageType { id filePath rotation imageHash imageWidth imageHeight __typename } fragment UserDetail on UserType { ...AvatarUser isFollowedByMe isHiddenForMe canBeFollowed billingAccountType lowestTierPrice highlightedModels { models { ...Model __typename } featured __typename } designer stripeAccountActive membership { id currentTier { id name benefits { id title benefitType description __typename } __typename } __typename } __typename }';

    $variables = [
      'id' => $modelId,
      'loadPurchase' => false
    ];

    $query = [
      'operationName' => 'ModelDetail',
      'query' => $graphqlQuery,
      'variables' => $variables
    ];

    return json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  private function makeGraphQLRequest($query) {
    $clientUid = '00f8ec70-6487-458d-bd08-5d9fa9c578a6';

    $headers = [
      'Content-Type: application/json',
      'Accept: application/json',
      'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
      'Origin: https://www.printables.com',
      'Referer: https://www.printables.com/',
      'Client-Uid: ' . $clientUid,
      'GraphQL-Client-Version: v1.6.8'
    ];

    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
                                     'content' => $query,
                                     'timeout' => 30,
                                     'ignore_errors' => true
      ]
    ]);

    $response = file_get_contents($this->getApiEndpoint(), false, $context);

    if ($response === false) {
      throw new Exception('Failed to fetch data from Printables API');
    }

    // Handle potential compression
    if (function_exists('gzdecode') && substr($response, 0, 3) === "\x1f\x8b\x08") {
      $response = gzdecode($response);
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new Exception('Invalid JSON response from Printables API: ' . json_last_error_msg());
    }

    if (isset($data['errors'])) {
      throw new Exception('GraphQL errors: ' . json_encode($data['errors']));
    }

    return $data;
  }

  private function fetchModelDetail($modelId) {
    $query = $this->buildModelDetailQuery($modelId);
    $response = $this->makeGraphQLRequest($query);

    if (isset($response['data']['model'])) {
      return $response['data']['model'];
    }

    return null;
  }

  public function collectData() {
    try {
      // First, get the list of models
      $query = $this->buildModelListQuery();
      $response = $this->makeGraphQLRequest($query);

      if (isset($response['data']['models']['items']) && is_array($response['data']['models']['items'])) {
        $models = $response['data']['models']['items'];

        // Clear items array explicitly
        $this->items = [];

        // Fetch detailed information for each model
        foreach ($models as $model) {
          $detailedModel = $this->fetchModelDetail($model['id']);
          if ($detailedModel) {
            // Merge basic model info with detailed info
            $enrichedModel = array_merge($model, $detailedModel);
            $this->processModel($enrichedModel);
          } else {
            // Fallback to basic model info if detail fetch fails
            $this->processModel($model);
          }
        }
      } else {
        throw new Exception('GraphQL response format unexpected or empty results');
      }

    } catch (Exception $e) {
      throw new Exception('API request failed: ' . $e->getMessage());
    }
  }

  private function processModel($model) {
    $item = [];

    $item['uri'] = 'https://www.printables.com/model/' . $model['id'] . '-' . $model['slug'];
    $item['title'] = $model['name'];
    $item['content'] = $this->buildContent($model);
    $item['author'] = $model['user']['publicUsername'] ?? 'Unknown';
    $item['timestamp'] = strtotime($model['datePublished']);
    $item['uid'] = 'printables_' . $model['id'];

    // Add categories
    $categories = [];
    if (isset($model['category']['path'])) {
      foreach ($model['category']['path'] as $cat) {
        $categories[] = $cat['name'];
      }
    }
    $item['categories'] = $categories;

    $this->items[] = $item;
  }

  private function buildContent($model) {
    $content = '<div class="printables-model">';

    // Add main image
    if (!empty($model['image']['filePath'])) {
      $imageUrl = 'https://media.printables.com/' . $model['image']['filePath'];
      $content .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($model['name']) . '" style="max-width: 100%; height: auto; margin-bottom: 15px;">';
    }

    // Build unified description content with consistent formatting
    $description = $this->buildUnifiedDescription($model);
    if (!empty($description)) {
      $content .= $description;
    }

    $content .= '</div>';

    return $content;
  }

  private function buildUnifiedDescription($model) {
    $parts = [];

    // Add summary as regular paragraph
    if (!empty($model['summary'])) {
      $summary = trim(strip_tags($model['summary']));
      if (!empty($summary)) {
        $parts[] = '<p>' . htmlspecialchars($summary) . '</p>';
      }
    }

    // Add description content
    if (!empty($model['description'])) {
      $description = $this->cleanDescription($model['description']);
      if (!empty($description)) {
        $parts[] = $description;
      }
    }

    // Add stats as regular text paragraphs
    $stats = [];
    if (isset($model['likesCount']) && $model['likesCount'] > 0) {
      $stats[] = number_format($model['likesCount']) . ' likes';
    }
    if (isset($model['downloadCount']) && $model['downloadCount'] > 0) {
      $stats[] = number_format($model['downloadCount']) . ' downloads';
    }
    if (!empty($model['ratingAvg']) && $model['ratingAvg'] > 0) {
      $stats[] = number_format($model['ratingAvg'], 1) . '/5.0 rating';
    }

    if (!empty($stats)) {
      $parts[] = '<p>Stats: ' . implode(', ', $stats) . '</p>';
    }

    // Add metadata as regular paragraphs
    $metadata = [];

    // Published date
    if (!empty($model['datePublished'])) {
      $publishedDate = date('M j, Y', strtotime($model['datePublished']));
      $metadata[] = 'Published: ' . $publishedDate;
    }

    // Category
    if (!empty($model['category']['path'])) {
      $categoryPath = [];
      foreach ($model['category']['path'] as $cat) {
        $categoryPath[] = $cat['name'];
      }
      $metadata[] = 'Category: ' . implode(' > ', $categoryPath);
    }

    // Tags
    if (!empty($model['tags'])) {
      $tags = [];
      foreach ($model['tags'] as $tag) {
        $tags[] = $tag['name'];
      }
      if (!empty($tags)) {
        $metadata[] = 'Tags: ' . implode(', ', $tags);
      }
    }

    // Features
    $features = [];
    if (!empty($model['mmu'])) {
      $features[] = 'Multi-Material';
    }
    if (!empty($model['aiGenerated'])) {
      $features[] = 'AI Generated';
    }
    if (!empty($model['club'])) {
      $features[] = 'Premium';
    }
    if (!empty($model['nsfw'])) {
      $features[] = 'NSFW';
    }

    if (!empty($features)) {
      $metadata[] = 'Features: ' . implode(', ', $features);
    }

    // Creator
    if (!empty($model['user'])) {
      $creator = $model['user']['publicUsername'];
      if (!empty($model['user']['verified'])) {
        $creator .= ' (Verified)';
      }
      if (!empty($model['user']['badgesProfileLevel']['profileLevel'])) {
        $creator .= ' - Level ' . $model['user']['badgesProfileLevel']['profileLevel'];
      }
      $metadata[] = 'Creator: ' . $creator;
    }

    // Add all metadata as a single paragraph
    if (!empty($metadata)) {
      $parts[] = '<p>' . implode(' | ', $metadata) . '</p>';
    }

    // If no content, create basic fallback
    if (empty($parts)) {
      $parts[] = '<p>3D model available for download from Printables.com</p>';
    }

    return implode('', $parts);
  }

  private function cleanDescription($description) {
    // Decode HTML entities
    $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Remove script and style tags completely
    $description = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $description);

    // Clean up specific formatting
    $description = preg_replace('/&nbsp;/i', ' ', $description);
    $description = preg_replace('/\s+/', ' ', $description);

    // Convert to plain text but preserve paragraph breaks
    $description = strip_tags($description, '<p><br>');

    // Remove any remaining attributes and convert to simple paragraphs
    $description = preg_replace('/<p[^>]*>/', '<p>', $description);

    // Clean up empty paragraphs and excessive breaks
    $description = preg_replace('/<p>\s*<\/p>/', '', $description);
    $description = preg_replace('/(<br\s*\/?>){3,}/', '<br><br>', $description);

    // Ensure reasonable length
    $plainText = strip_tags($description);
    if (strlen($plainText) > 800) {
      $truncated = substr($plainText, 0, 800);
      $lastSpace = strrpos($truncated, ' ');
      if ($lastSpace !== false) {
        $truncated = substr($truncated, 0, $lastSpace);
      }
      $description = '<p>' . htmlspecialchars($truncated) . '...</p>';
    }

    return trim($description);
  }

  public function getName() {
    $category = $this->getInput('category');
    $days = $this->getInput('days');

    // Get category name from the values array
    $categoryName = 'All Categories';
    if ($category) {
      $categoryValues = self::PARAMETERS['']['category']['values'];
      $categoryName = array_search($category, $categoryValues);
    }

    return "Printables.com - Trending in {$categoryName} (Last {$days} days)";
  }

  public function getURI() {
    $category = $this->getInput('category');
    $uri = self::URI . '/search/models';

    if ($category) {
      $uri .= '?category=' . $category;
    }

    return $uri;
  }
}
?>
