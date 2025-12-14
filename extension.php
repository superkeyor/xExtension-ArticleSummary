<?php
class ArticleSummaryExtension extends Minz_Extension
{
  protected array $csp_policies = [
    'default-src' => '*',
  ];

  public function init()
  {
    $this->registerHook('entry_before_display', array($this, 'addSummaryButtons'));
    $this->registerHook('freshrss_user_maintenance', array($this, 'handleUserMaintenance'));
    $this->registerController('ArticleSummary');
    Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
    Minz_View::appendScript($this->getFileUrl('axios.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('marked.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
  }

  public function addSummaryButtons($entry)
  {
    $url_summary = Minz_Url::display(array(
      'c' => 'ArticleSummary',
      'a' => 'summarize',
      'params' => array(
        'id' => $entry->id()
      )
    ));
    
    // Check if summary already exists in content
    if (strpos($entry->content(), '<!-- AI_SUMMARY_START -->') !== false) {
      // Summary already exists, don't add buttons
      return $entry;
    }
    
    // Create top button and content div
    $topButton = '<div class="oai-summary-wrap">'
      . '<button data-request="' . $url_summary . '" data-entry-id="' . $entry->id() . '" class="oai-summary-btn"></button>'
      . '<div class="oai-summary-content"></div>'
      . '</div>';
    
    // Create spacer and bottom button
    $bottomButton = '<div>&nbsp;</div>'
      . '<div class="oai-summary-wrap">'
      . '<button data-request="' . $url_summary . '" data-entry-id="' . $entry->id() . '" class="oai-summary-btn"></button>'
      . '<div class="oai-summary-content"></div>'
      . '</div>';
    
    // Add both buttons to the content
    $entry->_content(
      $topButton
      . $entry->content()
      . $bottomButton
    );
    
    return $entry;
  }

  public function handleUserMaintenance()
  {
    try {
      // Check if auto-generation is enabled
      $auto_enabled = FreshRSS_Context::$user_conf->oai_auto_enabled ?? '';
      if ($auto_enabled !== '1') {
        return;
      }

      // Get configuration
      $oai_url = FreshRSS_Context::$user_conf->oai_url ?? '';
      $oai_key = FreshRSS_Context::$user_conf->oai_key ?? '';
      $oai_model = FreshRSS_Context::$user_conf->oai_model ?? '';
      $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt ?? '';
      $oai_provider = FreshRSS_Context::$user_conf->oai_provider ?? 'openai';

      // Skip if API not configured
      if (empty($oai_url) || empty($oai_key) || empty($oai_model)) {
        Minz_Log::warning('ArticleSummary: Auto-generation skipped - API not configured');
        return;
      }

      $min_reading_time = (int)(FreshRSS_Context::$user_conf->oai_auto_min_time ?? '5');
      $auto_feeds = FreshRSS_Context::$user_conf->oai_auto_feeds ?? '';
      $allowed_feeds = array_filter(array_map('trim', explode(',', $auto_feeds)));

      // Get entry DAO
      $entryDAO = FreshRSS_Factory::createEntryDao();

      // Process each allowed feed (or all feeds if none specified)
      $feedDAO = FreshRSS_Factory::createFeedDao();
      $feeds = $feedDAO->listFeeds();

      $totalProcessed = 0;
      $totalSkipped = 0;
      $skipReasons = [];
      
      foreach ($feeds as $feed) {
        // Check if this feed is in the allowed list
        if (!empty($allowed_feeds) && !in_array($feed->id(), $allowed_feeds)) {
          continue;
        }

        // Get unread articles for this feed (limit to 50 per feed per run)
        $entries = iterator_to_array(
          $entryDAO->listWhere('f', $feed->id(), FreshRSS_Entry::STATE_NOT_READ, order: 'DESC', limit: 50)
        );
        
        Minz_Log::notice('ArticleSummary: Checking feed ' . $feed->id() . ' (' . $feed->name() . '), found ' . count($entries) . ' unread articles');

        foreach ($entries as $entry) {
          // Skip if summary already exists
          if (strpos($entry->content(), '<!-- AI_SUMMARY_START -->') !== false) {
            $totalSkipped++;
            $skipReasons[] = 'Entry ' . $entry->id() . ': Summary already exists';
            continue;
          }

          // Calculate reading time
          $reading_time = $this->calculateReadingTime($entry->content());
          if ($reading_time < $min_reading_time) {
            $totalSkipped++;
            $skipReasons[] = 'Entry ' . $entry->id() . ': Reading time ' . $reading_time . ' min < ' . $min_reading_time . ' min';
            continue;
          }
          
          Minz_Log::notice('ArticleSummary: Processing entry ' . $entry->id() . ' (reading time: ' . $reading_time . ' min)');

          // Generate summary
          try {
            $summary = $this->generateSummarySync($entry, $oai_url, $oai_key, $oai_model, $oai_prompt, $oai_provider);
            
            if ($summary) {
              // Save raw summary (will be parsed by marked.js on frontend display)
              $summary_html = '<div class="ai-summary-block">'
                . '<!-- AI_SUMMARY_START -->'
                . '<h3>✨ AI Summary</h3>'
                . '<div class="ai-summary-content">' . $summary . '</div>'
                . '<!-- AI_SUMMARY_END -->'
                . '</div>';
              
              // Update entry content
              $new_content = $summary_html . $entry->content();
              $entry->_content($new_content);
              $entry->_hash(md5($new_content));
              $entryDAO->updateEntry($entry->toArray());
              
              $totalProcessed++;
              Minz_Log::notice('ArticleSummary: Generated summary for entry ' . $entry->id());
            }
          } catch (Exception $e) {
            Minz_Log::error('ArticleSummary: Failed to generate summary for entry ' . $entry->id() . ': ' . $e->getMessage());
            // Continue with next article
          }

          // Limit processing to prevent timeouts (max n articles per maintenance run)
          if ($totalProcessed >= 100) {
            break 2;
          }
        }
      }

      if ($totalProcessed > 0) {
        Minz_Log::notice('ArticleSummary: Auto-generated ' . $totalProcessed . ' summaries');
      }
      
      if ($totalSkipped > 0) {
        Minz_Log::notice('ArticleSummary: Skipped ' . $totalSkipped . ' articles:');
        foreach ($skipReasons as $reason) {
          Minz_Log::notice('  - ' . $reason);
        }
      }
      
      if ($totalProcessed === 0 && $totalSkipped === 0) {
        Minz_Log::notice('ArticleSummary: No articles needed summarization (checked ' . count($feeds) . ' feeds)');
      }

    } catch (Exception $e) {
      Minz_Log::error('ArticleSummary maintenance error: ' . $e->getMessage());
    }
  }

  private function generateSummarySync($entry, $oai_url, $oai_key, $oai_model, $oai_prompt, $oai_provider)
  {
    // Use htmlToMarkdown to convert article content (same as manual processing)
    $content = $this->htmlToMarkdown($entry->content());

    // Prepare API request
    if ($oai_provider === 'openai') {
      $url = rtrim($oai_url, '/') . '/chat/completions';
      $data = [
        'model' => $oai_model,
        'messages' => [
          ['role' => 'system', 'content' => $oai_prompt],
          ['role' => 'user', 'content' => 'input: \n' . $content]
        ],
        'max_completion_tokens' => 2048,
        'temperature' => 1,
        'stream' => false
      ];
    } else {
      $url = rtrim($oai_url, '/') . '/api/generate';
      $data = [
        'model' => $oai_model,
        'system' => $oai_prompt,
        'prompt' => $content,
        'stream' => false
      ];
    }

    // Make API request
    $ch = curl_init($url);
    if ($ch === false) {
      throw new Exception('Failed to initialize cURL');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $oai_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
      throw new Exception('API request failed with HTTP ' . $http_code);
    }

    $json = json_decode($response, true);
    
    if ($oai_provider === 'openai') {
      return $json['choices'][0]['message']['content'] ?? null;
    } else {
      return $json['response'] ?? null;
    }
  }

  private function calculateReadingTime($content)
  {
    // Strip HTML tags
    $text = strip_tags($content);
    
    // Remove extra spaces and newlines
    $text = preg_replace('/(^\s*)|(\s*$)/u', '', $text);
    $text = preg_replace('/[ ]{2,}/u', ' ', $text);
    $text = preg_replace('/\n /u', '\n', $text);

    // Count mixed Chinese characters and English words
    $wordCount = 0;
    $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($tokens as $token) {
      if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $token)) {
        // Chinese characters - count each character
        $wordCount += mb_strlen($token, 'UTF-8');
      } else {
        // English or other - count as one word
        $wordCount += 1;
      }
    }

    // Calculate reading time (300 words per minute)
    $reading_time = round($wordCount / 300);
    return $reading_time;
  }

  private function htmlToMarkdown($content)
  {
    // Reused from ArticleSummaryController - converts HTML to markdown for API input
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      if ($node->nodeType === XML_ELEMENT_NODE) {
        switch ($node->nodeName) {
          case 'p':
          case 'div':
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h1':
            $markdown .= "# ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h2':
            $markdown .= "## ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h3':
            $markdown .= "### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h4':
            $markdown .= "#### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h5':
            $markdown .= "##### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h6':
            $markdown .= "###### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'a':
            $markdown .= "`";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "`";
            break;
          case 'img':
            $alt = $node->getAttribute('alt');
            $markdown .= "img: `" . $alt . "`";
            break;
          case 'strong':
          case 'b':
            $markdown .= "**";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "**";
            break;
          case 'em':
          case 'i':
            $markdown .= "*";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "*";
            break;
          case 'ul':
          case 'ol':
            $markdown .= "\n";
            foreach ($node->childNodes as $child) {
              if ($child->nodeName === 'li') {
                $markdown .= str_repeat("  ", $indentLevel) . "- ";
                $markdown .= $processNode($child, $indentLevel + 1);
                $markdown .= "\n";
              }
            }
            $markdown .= "\n";
            break;
          case 'li':
            $markdown .= str_repeat("  ", $indentLevel) . "- ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child, $indentLevel + 1);
            }
            $markdown .= "\n";
            break;
          case 'br':
            $markdown .= "\n";
            break;
          case 'audio':
          case 'video':
            $alt = $node->getAttribute('alt');
            $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
            break;
          default:
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    $nodes = $xpath->query('//body/*');

    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);
    
    return $markdown;
  }

  public function handleConfigureAction()
  {
    if (Minz_Request::isPost()) {
      FreshRSS_Context::$user_conf->oai_url = Minz_Request::param('oai_url', '');
      FreshRSS_Context::$user_conf->oai_key = Minz_Request::param('oai_key', '');
      FreshRSS_Context::$user_conf->oai_model = Minz_Request::param('oai_model', '');
      FreshRSS_Context::$user_conf->oai_prompt = Minz_Request::param('oai_prompt', '');
      FreshRSS_Context::$user_conf->oai_provider = Minz_Request::param('oai_provider', '');
      FreshRSS_Context::$user_conf->oai_auto_enabled = Minz_Request::param('oai_auto_enabled', '');
      FreshRSS_Context::$user_conf->oai_auto_min_time = Minz_Request::param('oai_auto_min_time', '5');
      FreshRSS_Context::$user_conf->oai_auto_feeds = Minz_Request::param('oai_auto_feeds', '');
      FreshRSS_Context::$user_conf->save();
    }
  }
}
