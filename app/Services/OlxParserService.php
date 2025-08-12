<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class OlxParserService
{
    private Client $httpClient;
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 30,
            'verify' => false,
        ]);
    }

    /**
     * Parse listing data from OLX URL
     *
     * @param string $url
     * @return array|null
     */
    public function parseListingData(string $url): ?array
    {
        try {
            // Try mobile API first
            $mobileData = $this->parseMobileAPI($url);
            if ($mobileData) {
                return $mobileData;
            }

            // Fallback to web scraping
            return $this->parseWebPage($url);
        } catch (\Exception $e) {
            Log::error('OLX parsing failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract listing ID from URL
     *
     * @param string $url
     * @return string|null
     */
    private function extractListingId(string $url): ?string
    {
        if (preg_match('/ID([0-9]+)\.html/', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Parse data using mobile API
     *
     * @param string $url
     * @return array|null
     */
    private function parseMobileAPI(string $url): ?array
    {
        $listingId = $this->extractListingId($url);
        if (!$listingId) {
            return null;
        }

        try {
            $apiUrl = "https://www.olx.ua/api/v1/offers/{$listingId}/";
            $response = $this->httpClient->get($apiUrl, [
                'headers' => [
                    'User-Agent' => $this->getRandomUserAgent(),
                    'Accept' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data || !isset($data['data'])) {
                return null;
            }

            $listing = $data['data'];
            return [
                'price' => $this->extractPrice($listing['params'] ?? []),
                'title' => $listing['title'] ?? null,
                'is_active' => ($listing['status']['allow_edit'] ?? false),
            ];
        } catch (GuzzleException $e) {
            Log::warning('Mobile API parsing failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse data by scraping web page
     *
     * @param string $url
     * @return array|null
     */
    private function parseWebPage(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'User-Agent' => $this->getRandomUserAgent(),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'uk-UA,uk;q=0.8,en-US;q=0.5,en;q=0.3',
                ]
            ]);

            $html = $response->getBody()->getContents();

            // Extract price using regex patterns
            $price = $this->extractPriceFromHtml($html);
            $title = $this->extractTitleFromHtml($html);
            $isActive = $this->checkIfListingActive($html);

            if ($price === null) {
                return null;
            }

            return [
                'price' => $price,
                'title' => $title,
                'is_active' => $isActive,
            ];
        } catch (GuzzleException $e) {
            Log::warning('Web scraping failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Extract price from API response params
     *
     * @param array $params
     * @return float|null
     */
    private function extractPrice(array $params): ?float
    {
        foreach ($params as $param) {
            if (isset($param['key']) && $param['key'] === 'price' && isset($param['value']['value'])) {
                return (float) $param['value']['value'];
            }
        }
        return null;
    }

    /**
     * Extract price from HTML content
     *
     * @param string $html
     * @return float|null
     */
    private function extractPriceFromHtml(string $html): ?float
    {
        // Multiple patterns for price extraction
        $patterns = [
            '/data-testid="ad-price-container"[^>]*>.*?(\d+(?:\s?\d{3})*)\s*грн/sui',
            '/class="[^"]*price[^"]*"[^>]*>.*?(\d+(?:\s?\d{3})*)\s*грн/sui',
            '/"price":\s*"?(\d+)"?/i',
            '/(\d+(?:\s?\d{3})*)\s*грн/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $priceString = str_replace([' ', "\u{00A0}"], '', $matches[1]);
                if (is_numeric($priceString)) {
                    return (float) $priceString;
                }
            }
        }

        return null;
    }

    /**
     * Extract title from HTML content
     *
     * @param string $html
     * @return string|null
     */
    private function extractTitleFromHtml(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
            return str_replace(' - OLX.ua', '', $title);
        }
        return null;
    }

    /**
     * Check if listing is still active
     *
     * @param string $html
     * @return bool
     */
    private function checkIfListingActive(string $html): bool
    {
        $inactivePatterns = [
            '/оголошення.*?видален/ui',
            '/removed.*?listing/i',
            '/неактивн/ui',
            '/inactive/i',
        ];

        foreach ($inactivePatterns as $pattern) {
            if (preg_match($pattern, $html)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get random user agent for requests
     *
     * @return string
     */
    private function getRandomUserAgent(): string
    {
        return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    }
}
