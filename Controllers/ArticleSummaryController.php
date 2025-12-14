<?php

class FreshExtension_ArticleSummary_Controller extends Minz_ActionController
{
  public function summarizeAction()
  {
    // Set response header to JSON first, before any output
    header('Content-Type: application/json');
    $this->view->_layout(false);

    $oai_url = FreshRSS_Context::$user_conf->oai_url ?? '';
    $oai_key = FreshRSS_Context::$user_conf->oai_key ?? '';
    $oai_model = FreshRSS_Context::$user_conf->oai_model ?? '';
    $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt ?? '';
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider ?? '';

    if (
      $this->isEmpty($oai_url)
      || $this->isEmpty($oai_key)
      || $this->isEmpty($oai_model)
      || $this->isEmpty($oai_prompt)
    ) {
      echo json_encode(array(
        'response' => array(
          'data' => 'missing config',
          'error' => 'configuration'
        ),
        'status' => 200
      ));
      return;
    }

    $entry_id = Minz_Request::param('id');
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      echo json_encode(array('status' => 404));
      return;
    }

    $content = $entry->content(); // Replace with article content

    // Process $oai_url
    // Open AI Input
    $successResponse = array(
      'response' => array(
        'data' => array(
          "oai_url" => rtrim($oai_url, '/') . '/chat/completions',
          "oai_key" => $oai_key,
          "model" => $oai_model,
          "messages" => [
            [
              "role" => "system",
              "content" => $oai_prompt
            ],
            [
              "role" => "user",
              "content" => "input: \n" . $this->htmlToMarkdown($content),
            ]
          ],
          "max_completion_tokens" => 2048, // You can adjust the length of the summary as needed
          "temperature" => 1, // You can adjust the randomness/temperature of the generated text as needed
          "n" => 1 // Generate summary
        ),
        'provider' => 'openai',
        'error' => null
      ),
      'status' => 200
    );

    // Ollama API Input
    if ($oai_provider === "ollama") {
      $successResponse = array(
        'response' => array(
          'data' => array(
            "oai_url" => rtrim($oai_url, '/') . '/api/generate',
            "oai_key" => $oai_key,
            "model" => $oai_model,
            "system" => $oai_prompt,
            "prompt" =>  $this->htmlToMarkdown($content),
            "stream" => true,
          ),
          'provider' => 'ollama',
          'error' => null
        ),
        'status' => 200
      );
    }
    echo json_encode($successResponse);
    return;
  }

  public function saveSummaryAction()
  {
    // Set response header to JSON first, before any output
    header('Content-Type: application/json');
    $this->view->_layout(false);

    $entry_id = Minz_Request::param('id');
    $summary = Minz_Request::param('summary');

    if (!$entry_id || !$summary) {
      echo json_encode(array('status' => 400, 'error' => 'Missing parameters'));
      return;
    }

    try {
      $entry_dao = FreshRSS_Factory::createEntryDao();
      $entry = $entry_dao->searchById($entry_id);

      if ($entry === null) {
        echo json_encode(array('status' => 404, 'error' => 'Entry not found'));
        return;
      }

      // Get current content
      $current_content = $entry->content();
      
      // Check if summary already exists
      if (strpos($current_content, '<!-- AI_SUMMARY_START -->') !== false) {
        echo json_encode(array('status' => 200, 'message' => 'Summary already exists'));
        return;
      }

      // Decode HTML entities if they exist in the summary
      $decoded_summary = html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      
      // Create summary HTML block using CSS classes
      $summary_html = '<div class="ai-summary-block">'
        . '<!-- AI_SUMMARY_START -->'
        . '<h3>✨ AI Summary</h3>'
        . '<div class="ai-summary-content">' . $decoded_summary . '</div>'
        . '<!-- AI_SUMMARY_END -->'
        . '</div>';

      // Add summary only at top of content
      $new_content = $summary_html . $current_content;

      // Update entry content
      $entry->_content($new_content);
      $entry_dao->updateEntry($entry->toArray());

      echo json_encode(array(
        'status' => 200, 
        'message' => 'Summary saved successfully',
        'inserted' => true
      ));
    } catch (Exception $e) {
      echo json_encode(array('status' => 500, 'error' => $e->getMessage()));
    }
    return;
  }

  private function isEmpty($item)
  {
    return $item === null || trim($item) === '';
  }

  private function htmlToMarkdown($content)
  {
    // Create DOMDocument object
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Ignore HTML parsing errors
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // Create XPath object
    $xpath = new DOMXPath($dom);

    // Define an anonymous function to process the node
    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      // Process text nodes
      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      // Process element nodes
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
            // $markdown .= "[";
            // $markdown .= $processNode($node->firstChild);
            // $markdown .= "](" . $node->getAttribute('href') . ")";
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
            // Tags not considered, only the text inside is kept
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    // Get all nodes
    $nodes = $xpath->query('//body/*');

    // Process all nodes
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    // Remove extra line breaks
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);
    
    return $markdown;
  }

}
