<?php
class ArticleSummaryExtension extends Minz_Extension
{
  protected array $csp_policies = [
    'default-src' => '*',
  ];

  public function init()
  {
    $this->registerHook('entry_before_display', array($this, 'addSummaryButtons'));
    $this->registerHook('entry_before_insert', array($this, 'autoGenerateSummary'));
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

  public function autoGenerateSummary($entry)
  {
    // Check if auto-generation is enabled
    $auto_enabled = FreshRSS_Context::$user_conf->oai_auto_enabled ?? '';
    if ($auto_enabled !== '1') {
      return $entry;
    }

    // Check if summary already exists
    if (strpos($entry->content(), '<!-- AI_SUMMARY_START -->') !== false) {
      return $entry;
    }

    // Check if this feed is in the allowed list
    $auto_feeds = FreshRSS_Context::$user_conf->oai_auto_feeds ?? '';
    $allowed_feeds = array_filter(array_map('trim', explode(',', $auto_feeds)));
    
    if (!empty($allowed_feeds) && !in_array($entry->feed(false), $allowed_feeds)) {
      return $entry;
    }

    // Calculate reading time
    $reading_time = $this->calculateReadingTime($entry->content());
    $min_reading_time = (int)(FreshRSS_Context::$user_conf->oai_auto_min_time ?? '5');

    if ($reading_time < $min_reading_time) {
      return $entry;
    }

    // Generate summary
    try {
      $summary = $this->generateSummarySync($entry);
      if ($summary) {
        $summary_html = '<div class="ai-summary-block">'
          . '<!-- AI_SUMMARY_START -->'
          . '<h3>✨ AI Summary</h3>'
          . '<div class="ai-summary-content">' . $summary . '</div>'
          . '<!-- AI_SUMMARY_END -->'
          . '</div>';
        
        $entry->_content($summary_html . $entry->content());
      }
    } catch (Exception $e) {
      // Silently fail - don't block article insertion
      error_log('ArticleSummary auto-generation failed: ' . $e->getMessage());
    }

    return $entry;
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

  private function generateSummarySync($entry)
  {
    $oai_url = FreshRSS_Context::$user_conf->oai_url ?? '';
    $oai_key = FreshRSS_Context::$user_conf->oai_key ?? '';
    $oai_model = FreshRSS_Context::$user_conf->oai_model ?? '';
    $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt ?? '';
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider ?? 'openai';

    if (empty($oai_url) || empty($oai_key) || empty($oai_model)) {
      return null;
    }

    // Get article content as markdown
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
      return null;
    }

    $json = json_decode($response, true);
    
    if ($oai_provider === 'openai') {
      return $json['choices'][0]['message']['content'] ?? null;
    } else {
      return $json['response'] ?? null;
    }
  }

  private function htmlToMarkdown($html)
  {
    // Simple HTML to markdown conversion
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $text;
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
