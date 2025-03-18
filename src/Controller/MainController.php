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

    public function updateNodes(): Response {
        if (count($this->config) === 0) {
            $this->getLogger('arche_core_gui_static')->error('config file is not existing');
            return new Response("Config file is not exitsing", 404, ['Content-Type' => 'application/json']);
        }

        //fetch the html and from the github files and combine them to one text.
        $this->fetchGithubContent();

        //fetch the nodes from drupal
        $this->fetchNodes();

        return new Response("Update done", 200);
    }

    private function fetchNodes() {


        foreach ($this->config as $lang => $values) {
            foreach ($values as $pathAlias) {
                // Convert alias to system path
                $path = \Drupal::service('path_alias.manager')->getPathByAlias('/' . $pathAlias);

                // Check if it's a node path
                if (preg_match('/^\/node\/(\d+)$/', $path, $matches)) {
                    $nid = $matches[1];
                    $node = Node::load($nid);

                    if ($node) {
                        $nLang = $node->language()->getId();
                        if (isset($this->content[$nLang][$pathAlias])) {
                            $node->set('body', [
                                'value' => $this->content[$nLang][$pathAlias],
                                'format' => 'full_html', // Ensure it's set to 'full_html' or another appropriate format
                            ]);
                            $node->save();
                        }
                    }
                }
            }
        }
    }

    /**
     * Fetch github raw urls and build up $content array with the final html content
     */
    private function fetchGithubContent() {
        $httpClient = new \GuzzleHttp\Client();
        //https://github.com/acdh-oeaw/arche-static-text/blob/main/arche2/en/deposition-process_en.html
        foreach ($this->config as $lang => $url) {
            $htmlUrl = $this->githubUrl . $lang . '/' . $url[0] . '.html';
            $jsonlUrl = $this->githubUrl . $lang . '/' . $url[0] . '.json';
            $htmlContent = "";
            $jsonContent = "";
            $htmlContent = $this->fetchContent($htmlUrl);
            $jsonContent = $this->fetchContent($jsonlUrl);

            if (!empty($jsonContent) && !empty($htmlContent)) {
                $this->content[$lang][$url[0]] = $this->generateHtmlContent($htmlContent, $jsonContent);
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
