<?php

namespace MauticPlugin\MauticPostmarkBundle\Service;

class PostmarkApiService
{
    /**
     * Fetches server list from Postmark Account API.
     *
     * @param string $accountToken Account-level API token from Postmark
     * @param int    $offset       Starting position (default: 0)
     * @param int    $count        Number of servers to fetch (default: 100)
     *
     * @return array [success(bool), servers(array), error(string|null)]
     */
    public function getServerList(string $accountToken, int $offset = 0, int $count = 100): array
    {
        if (empty($accountToken)) {
            return [false, [], 'Account token is required'];
        }

        $url = 'https://api.postmarkapp.com/servers?offset=' . $offset . '&count=' . $count;
        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Account-Token: ' . $accountToken,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => 'GET',
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        $error      = $errno ? curl_error($ch) : null;
        curl_close($ch);

        if ($errno !== 0) {
            return [false, [], 'cURL error: ' . $error];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $decoded = json_decode((string) $response, true);
            $errorMsg = 'HTTP ' . $statusCode;
            if (is_array($decoded) && !empty($decoded['Message'])) {
                $errorMsg .= ': ' . $decoded['Message'];
            }
            return [false, [], $errorMsg];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return [false, [], 'Invalid JSON response'];
        }

        // Extract servers array from response
        $servers = $decoded['Servers'] ?? [];
        if (!is_array($servers)) {
            return [false, [], 'Invalid servers data in response'];
        }

        return [true, $servers, null];
    }

    /**
     * Formats server list for use in form choices.
     *
     * @param array $servers Server list from API
     *
     * @return array [server_token => 'Server Name (ID)']
     */
    public function formatServerChoices(array $servers): array
    {
        $choices = [];
        
        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            
            $name = $server['Name'] ?? 'Unknown';
            $id = $server['ID'] ?? '';
            
            // Handle ApiTokens - could be array or string
            $token = '';
            if (!empty($server['ApiTokens'])) {
                if (is_array($server['ApiTokens']) && !empty($server['ApiTokens'])) {
                    $token = $server['ApiTokens'][0] ?? '';
                } elseif (is_string($server['ApiTokens'])) {
                    $token = $server['ApiTokens'];
                }
            }
            
            if (!empty($token)) {
                $choices[$name . ' (ID: ' . $id . ')'] = $token;
            }
        }
        
        return $choices;
    }

    /**
     * Fetches templates from Postmark Server API.
     *
     * @param string $serverToken Server token from Postmark
     * @param int    $offset      Starting position (default: 0)
     * @param int    $count       Number of templates to fetch (default: 100)
     *
     * @return array [success(bool), templates(array), error(string|null)]
     */
    public function getTemplateList(string $serverToken, int $offset = 0, int $count = 100): array
    {
        if (empty($serverToken)) {
            return [false, [], 'Server token is required'];
        }

        $url = 'https://api.postmarkapp.com/templates?offset=' . $offset . '&count=' . $count;
        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: ' . $serverToken,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => 'GET',
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        $error      = $errno ? curl_error($ch) : null;
        curl_close($ch);

        if ($errno !== 0) {
            return [false, [], 'cURL error: ' . $error];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $decoded = json_decode((string) $response, true);
            $errorMsg = 'HTTP ' . $statusCode;
            if (is_array($decoded) && !empty($decoded['Message'])) {
                $errorMsg .= ': ' . $decoded['Message'];
            }
            return [false, [], $errorMsg];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return [false, [], 'Invalid JSON response'];
        }

        // Extract templates array from response
        $templates = $decoded['Templates'] ?? [];
        if (!is_array($templates)) {
            return [false, [], 'Invalid templates data in response'];
        }

        return [true, $templates, null];
    }

    /**
     * Formats template list for use in form choices.
     *
     * @param array $templates Template list from API
     *
     * @return array [template_alias => 'Template Name (Alias)']
     */
    public function formatTemplateChoices(array $templates): array
    {
        $choices = [];
        
        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }
            
            $name = $template['Name'] ?? 'Unknown';
            $alias = $template['Alias'] ?? '';
            
            if (!empty($alias)) {
                $choices[$name . ' (' . $alias . ')'] = $alias;
            }
        }
        
        return $choices;
    }

    /**
     * Fetches a specific template details from Postmark Server API.
     *
     * @param string $serverToken Server token from Postmark
     * @param string $templateAlias Template alias to fetch
     *
     * @return array [success(bool), template(array), error(string|null)]
     */
    public function getTemplate(string $serverToken, string $templateAlias): array
    {
        if (empty($serverToken)) {
            return [false, [], 'Server token is required'];
        }
        
        if (empty($templateAlias)) {
            return [false, [], 'Template alias is required'];
        }

        $url = 'https://api.postmarkapp.com/templates/' . urlencode($templateAlias);
        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: ' . $serverToken,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => 'GET',
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        $error      = $errno ? curl_error($ch) : null;
        curl_close($ch);

        if ($errno !== 0) {
            return [false, [], 'cURL error: ' . $error];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $decoded = json_decode((string) $response, true);
            $errorMsg = 'HTTP ' . $statusCode;
            if (is_array($decoded) && !empty($decoded['Message'])) {
                $errorMsg .= ': ' . $decoded['Message'];
            }
            return [false, [], $errorMsg];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return [false, [], 'Invalid JSON response'];
        }

        return [true, $decoded, null];
    }

    /**
     * Extracts template variables from template HTML and text content.
     *
     * @param array $template Template data from Postmark API
     *
     * @return array List of unique template variables found
     */
    public function extractTemplateVariables(array $template): array
    {
        $variables = [];
        
        // Get HTML and Text content
        $htmlBody = $template['HtmlBody'] ?? '';
        $textBody = $template['TextBody'] ?? '';
        $subject = $template['Subject'] ?? '';
        
        // Combine all content to search for variables
        $content = $htmlBody . ' ' . $textBody . ' ' . $subject;
        
        // Find Postmark template variables in various formats:
        // {{variable}}, {{#variable}}, {{^variable}}, {{/variable}}
        preg_match_all('/\{\{\s*([#^\/]?)([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $content, $matches);
        
        if (!empty($matches[2])) {
            foreach ($matches[2] as $variable) {
                // Skip Handlebars block helpers (starting with #, ^, /)
                if (!in_array($variable, $variables) && !empty($variable)) {
                    $variables[] = $variable;
                }
            }
        }
        
        // Also check for simple {{variable}} without modifiers
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $content, $simpleMatches);
        
        if (!empty($simpleMatches[1])) {
            foreach ($simpleMatches[1] as $variable) {
                if (!in_array($variable, $variables)) {
                    $variables[] = $variable;
                }
            }
        }
        
        // Remove common Handlebars keywords that aren't variables
        $reservedWords = ['if', 'unless', 'each', 'with', 'else', 'this'];
        $variables = array_diff($variables, $reservedWords);
        
        return array_unique($variables);
    }
}