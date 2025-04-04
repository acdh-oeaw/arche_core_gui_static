<?php

namespace Drupal\arche_core_gui_static\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Component\Serialization\Yaml;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

/**
 * Description of MetadataController
 *
 * @author nczirjak
 */
class MainController extends ControllerBase {

    private $config = "";
    private $content = [];
    private $githubUrl = "https://raw.githubusercontent.com/acdh-oeaw/arche-static-text/refs/heads/main/arche2/";

    public function __construct() {
        $this->initConfig();
    }

    private function initConfig() {
        $cfgFile = \Drupal::service('extension.list.module')->getPath('arche_core_gui_static') . '/config.yaml';
        if (file_exists($cfgFile)) {
            $yaml_content = file_get_contents($cfgFile);
            $this->config = Yaml::decode($yaml_content);
        }
    }

    public function updateNodes(): JsonResponse {
        if (count($this->config) === 0) {
            $this->getLogger('arche_core_gui_static')->error('config file is not existing');
            return new Response("Config file is not exitsing", 404, ['Content-Type' => 'application/json']);
        }

        //fetch the html and from the github files and combine them to one text.
        $this->fetchGithubContent();

        //fetch the nodes from drupal
        $result = $this->fetchNodes();

        return new JsonResponse($result, 200);
    }

    private function fetchNodes(): array {
        $result = [];
        foreach ($this->config as $langcode => $values) {
            foreach ($values as $alias) {
                $alias_manager = \Drupal::service('path_alias.manager');
                // Step 1: Resolve alias to internal path
                $system_path = $alias_manager->getPathByAlias('/' . $alias, $langcode);

                // Step 2: Check if it's a node path like "/node/123"
                if (preg_match('#^/node/(\d+)$#', $system_path, $matches)) {
                    $nid = $matches[1];

                    // Step 3: Load the node
                    $node = Node::load($nid);

                    if ($node) {
                        //echo "🎉 Loaded node: " . $node->label() . " (ID: $nid)\n";
                        //echo "<br>";
                        $nLang = $node->language()->getId();
                        if (isset($this->content[$langcode][$alias])) {

                            $node->set('body', [
                                'value' => $this->content[$langcode][$alias],
                                'format' => 'full_html', // Ensure it's set to 'full_html' or another appropriate format
                            ]);
                            try {
                                $status = $node->save();

                                if ($status === SAVED_NEW) {
                                    $result['new'][] = $alias;
                                    \Drupal::logger('custom')->info('New node created: ' . $node->id());
                                } elseif ($status === SAVED_UPDATED) {
                                    $result['updated'][] = $alias;
                                    \Drupal::logger('custom')->info('Node updated: ' . $node->id());
                                }
                            } catch (EntityStorageException $e) {
                                $result['error'][] = $alias;
                                \Drupal::logger('custom')->error('Node save failed: ' . $e->getMessage());
                            }
                        }
                    }
                } else {
                    $result['notFound'][] = $alias;
                }
            }
        }

        return $result;
    }

    /**
     * Fetch github raw urls and build up $content array with the final html content
     */
    private function fetchGithubContent() {
        $httpClient = new \GuzzleHttp\Client();
        //https://github.com/acdh-oeaw/arche-static-text/blob/main/arche2/en/deposition-process_en.html

        foreach ($this->config as $lang => $urls) {
            foreach ($urls as $url) {
                $htmlUrl = $this->githubUrl . $lang . '/' . $url . '.html';
                $jsonlUrl = $this->githubUrl . $lang . '/' . $url . '.json';
                $htmlContent = "";
                $jsonContent = "";
                $htmlContent = $this->fetchContent($htmlUrl);
                $jsonContent = $this->fetchContent($jsonlUrl);

                if (!empty($jsonContent) && !empty($htmlContent)) {
                    $this->content[$lang][$url] = $this->generateHtmlContent($htmlContent, $jsonContent);
                }
            }
        }
    }

    /**
     * Replace the params in the html
     * @param string $htmlContent
     * @param string $jsonData
     * @return type
     */
    private function generateHtmlContent(string $htmlContent, string $jsonData) {
        $jsonData = json_decode($jsonData, true);
        return preg_replace_callback('/\{\{\s*([\w\.]+)\s*\}\}/', function ($matches) use ($jsonData) {
            $keys = explode('.', $matches[1]); // Split nested keys
            $value = $jsonData;

            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key]; // Go deeper into the array
                } else {
                    //   return "MISSING_KEY({$matches[1]})"; // Placeholder for missing keys
                }
            }

            return is_array($value) ? implode("", $value) : $value; // Handle array values
        }, $htmlContent);
    }

    /**
     * do the guzzle fetch
     * @param string $url
     * @return string
     */
    private function fetchContent(string $url): string {
        $httpClient = new \GuzzleHttp\Client();
        try {
            $response = $httpClient->get($url, [
                'headers' => [
                    'User-Agent' => 'Drupal/10 my_module',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return $response->getBody()->getContents();
            }

            $this->logger->error("Unexpected status code: {code}", ['code' => $statusCode]);
            return "";
        } catch (RequestException $e) {
            $this->logger->error("HTTP request failed: {message}", ['message' => $e->getMessage()]);
            return "";
        }
    }
}
