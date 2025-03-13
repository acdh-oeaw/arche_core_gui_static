<?php

namespace Drupal\arche_core_gui_static\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Description of MetadataController
 *
 * @author nczirjak
 */
class MainController extends ControllerBase {

    public function __construct() {
    
    }

    /**
     * Fetches content from GitHub and updates nodes.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   A JSON response with update status.
     */
    public function updateNodes(): Response {
        // Define the GitHub API URLs for English and German directories.
        $directories = [
            'en' => 'https://api.github.com/repos/acdh-oeaw/arche-static-text/contents/arche2/en',
            'de' => 'https://api.github.com/repos/acdh-oeaw/arche-static-text/contents/arche2/de',
        ];
        
        $httpClient = new \GuzzleHttp\Client();

        // Loop through each directory.
        foreach ($directories as $lang => $dir_url) {
            try {
                // GitHub API requires a User-Agent header.
                $response = $httpClient->get($dir_url, [
                    'headers' => [
                        'User-Agent' => 'Drupal/10 my_module',
                    ],
                ]);
                $files = json_decode($response->getBody()->getContents(), TRUE);
                echo "<pre>";
                var_dump($files);
                echo "</pre>";

                die();
                if (!empty($files) && is_array($files)) {
                    foreach ($files as $file) {
                        // Process only if it's a file.
                        if ($file['type'] === 'file' && !empty($file['name'])) {
                            // Use the GitHub provided download URL for raw content.
                            $download_url = $file['download_url'];
                            $file_response = $httpClient->get($download_url, [
                                'headers' => [
                                    'User-Agent' => 'Drupal/10 my_module',
                                ],
                            ]);
                            $content = $file_response->getBody()->getContents();

                            // Create an identifier from the file name.
                            // E.g., for "deposition-process_en.html", the identifier might be "deposition-process_en"
                            $identifier = pathinfo($file['name'], PATHINFO_FILENAME);

                            // Find a node based on a field (for example, field_identifier) matching the identifier.
                            // Adjust 'your_content_type' and 'field_identifier' to your actual values.
                            $nids = \Drupal::entityQuery('node')
                                    ->condition('type', 'your_content_type')
                                    ->condition('field_identifier', $identifier)
                                    ->execute();

                            if (!empty($nids)) {
                                $nid = reset($nids);
                                $node = Node::load($nid);
                                if ($node) {
                                    // Update the node's body field with the fetched content.
                                    // Adjust 'body' and the text format ('full_html' or other) as necessary.
                                    $node->set('body', [
                                        'value' => $content,
                                        'format' => 'full_html',
                                    ]);
                                    $node->save();
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log the exception.
                $this->getLogger('my_module')->error($e->getMessage());
            }
        }

        return new JsonResponse(['status' => 'updated']);
    }
}
