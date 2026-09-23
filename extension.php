<?php
class ArticleSummaryExtension extends Minz_Extension
{
  const SUMMARY_START = '<!-- AI_SUMMARY_START -->';
  const SUMMARY_END = '<!-- AI_SUMMARY_END -->';

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

    [$summary_markdown, $content] = self::splitSummary($entry->content());

    $existing_summary = '';
    if ($summary_markdown !== null) {
      $existing_summary = '<div class="oai-summary-block">'
        . '<h3 class="oai-summary-header">✨ AI Summary</h3>'
        . '<div class="oai-summary-content">' . htmlspecialchars($summary_markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>'
        . '</div>';
    }

    $topWrapper = '<div class="oai-summary-wrap">'
      . '<button data-request="' . $url_summary . '" data-entry-id="' . $entry->id() . '" class="oai-summary-btn">✨Summarize</button>'
      . '<div class="oai-summary-content"></div>'
      . $existing_summary
      . '</div>';

    $bottomWrapper = '<div class="oai-summary-wrap">'
      . '<button data-request="' . $url_summary . '" data-entry-id="' . $entry->id() . '" class="oai-summary-btn">✨Summarize</button>'
      . '<div class="oai-summary-content"></div>'
      . '</div>';

    $entry->_content($topWrapper . $content . $bottomWrapper);
    return $entry;
  }

  /**
   * Split stored content into [summary markdown or null, article content without any summary markup].
   * Also understands legacy formats (div/h3 wrapped summaries, persisted button UI).
   */
  public static function splitSummary($content)
  {
    if (!is_string($content)) {
      return [null, ''];
    }

    $summary = null;
    $pattern = '/(?:<div class="oai-summary-block"[^>]*>\s*)?<!-- AI_SUMMARY_START -->(.*?)<!-- AI_SUMMARY_END -->(?:\s*<\/div>)?\s*/s';
    if (preg_match($pattern, $content, $matches)) {
      $summary = preg_replace('/^\s*<h3[^>]*>\s*✨ AI Summary\s*<\/h3>\s*/u', '', $matches[1]);
      if (preg_match('/^\s*<div class="oai-summary-content"[^>]*>(.*)<\/div>\s*$/s', $summary, $inner)) {
        $summary = $inner[1];
      }
      $summary = trim(str_replace('--&gt;', '-->', $summary));
      if ($summary === '') {
        $summary = null;
      }
    }

    $content = preg_replace($pattern, '', $content);
    $content = preg_replace('/<div class="oai-summary-wrap"[^>]*>\s*<button[^>]*>.*?<\/button>\s*<div class="oai-summary-content"[^>]*>\s*<\/div>\s*<\/div>\s*/s', '', $content);

    return [$summary, $content];
  }

  /** Build the content to persist: bare summary markers at the top, then the untouched article content. */
  public static function joinSummary($summary, $content)
  {
    // A literal "-->" would terminate the HTML comment early.
    $summary = str_replace('-->', '--&gt;', trim($summary));
    return self::SUMMARY_START . $summary . self::SUMMARY_END . $content;
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
      $oai_max_tokens = (int)(FreshRSS_Context::$user_conf->oai_max_tokens ?? '4096');

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

        // Get unread articles for this feed (limit to x per feed per run)
        $entries = iterator_to_array(
          // $entryDAO->listWhere('f', $feed->id(), FreshRSS_Entry::STATE_NOT_READ, order: 'DESC', limit: 50)
          $entryDAO->listWhere('f', $feed->id(), FreshRSS_Entry::STATE_NOT_READ, order: 'DESC', limit: -1)  // no limit
        );
        
        Minz_Log::notice('ArticleSummary: Checking feed ' . $feed->id() . ' (' . $feed->name() . '), found ' . count($entries) . ' unread articles');

        foreach ($entries as $entry) {
          // content(false): raw DB content, without enclosures FreshRSS appends for display
          [$existing, $body] = self::splitSummary($entry->content(false));
          if ($existing !== null) {
            $totalSkipped++;
            $skipReasons[] = 'Entry ' . $entry->id() . ': Summary already exists';
            continue;
          }

          // Calculate reading time
          $reading_time = $this->calculateReadingTime($body);
          if ($reading_time < $min_reading_time) {
            $totalSkipped++;
            $skipReasons[] = 'Entry ' . $entry->id() . ': Reading time ' . $reading_time . ' min < ' . $min_reading_time . ' min';
            continue;
          }
          
          Minz_Log::notice('ArticleSummary: Processing entry ' . $entry->id() . ' (reading time: ' . $reading_time . ' min)');

          // Generate summary
          try {
            $summary = $this->generateSummarySync($body, $oai_url, $oai_key, $oai_model, $oai_prompt, $oai_provider, $oai_max_tokens);
            
            if (is_string($summary) && trim($summary) !== '') {
              // Keep the original hash so the next feed refresh does not treat the entry as changed and overwrite it.
              $entry->_content(self::joinSummary($summary, $body));
              $entryDAO->updateEntry($entry->toArray());
              
              $totalProcessed++;
              Minz_Log::notice('ArticleSummary: Generated summary for entry ' . $entry->id());
            }
          } catch (Exception $e) {
            Minz_Log::error('ArticleSummary: Failed to generate summary for entry ' . $entry->id() . ': ' . $e->getMessage());
            // Continue with next article
          }

          // Limit processing to prevent timeouts (max n articles per maintenance run)
          if ($totalProcessed >= 50) {
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

  private function generateSummarySync($body, $oai_url, $oai_key, $oai_model, $oai_prompt, $oai_provider, $oai_max_tokens = 4096)
  {
    $content = $this->htmlToMarkdown($body);

    // Prepare API request
    if ($oai_provider === 'openai') {
      $url = rtrim($oai_url, '/') . '/chat/completions';
      $data = [
        'model' => $oai_model,
        'messages' => [
          ['role' => 'system', 'content' => $oai_prompt],
          ['role' => 'user', 'content' => "input: \n" . $content]
        ],
        'max_completion_tokens' => $oai_max_tokens,
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
      FreshRSS_Context::$user_conf->oai_max_tokens = Minz_Request::param('oai_max_tokens', '4096');
      FreshRSS_Context::$user_conf->oai_auto_enabled = Minz_Request::param('oai_auto_enabled', '');
      FreshRSS_Context::$user_conf->oai_auto_min_time = Minz_Request::param('oai_auto_min_time', '5');
      FreshRSS_Context::$user_conf->oai_auto_feeds = Minz_Request::param('oai_auto_feeds', '');
      FreshRSS_Context::$user_conf->save();
    }
  }
}
