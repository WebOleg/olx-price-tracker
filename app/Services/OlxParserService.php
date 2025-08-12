<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OlxParserService
{
    private int $requestDelay;
    private int $maxRetries;
    private int $timeout;

    public function __construct()
    {
        $this->requestDelay = config('app.olx_request_delay', 2000);
        $this->maxRetries = config('app.olx_max_retries', 3);
        $this->timeout = config('app.olx_timeout', 30);
    }

    /**
     * Parse listing data from OLX URL
     *
     * @param string $url
     * @return array|null
     */
    public function parseListingData(string $url): ?array
    {
        $retryCount = 0;

        while ($retryCount < $this->maxRetries) {
            try {
                // Add delay between requests to avoid rate limiting
                if ($retryCount > 0) {
                    usleep($this->requestDelay * 1000 * $retryCount);
                }

                $html = $this->fetchPageContent($url);

                if (!$html) {
                    $retryCount++;
                    continue;
                }

                $listingData = $this->extractListingData($html);

                if ($listingData) {
                    Log::info('Successfully parsed OLX listing', [
                        'url' => $url,
                        'title' => $listingData['title'] ?? 'Unknown',
                        'price' => $listingData['price'] ?? 'Unknown',
                        'retry_count' => $retryCount,
                    ]);

                    return $listingData;
                }

                $retryCount++;

            } catch (Exception $e) {
                Log::warning('Failed to parse OLX listing', [
                    'url' => $url,
                    'retry_count' => $retryCount,
                    'error' => $e->getMessage(),
                ]);

                $retryCount++;
            }
        }

        Log::error('Failed to parse OLX listing after all retries', [
            'url' => $url,
            'max_retries' => $this->maxRetries,
        ]);

        return null;
    }

    /**
     * Fetch page content with proper headers
     *
     * @param string $url
     * @return string|null
     */
    private function fetchPageContent(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'uk-UA,uk;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ])
                ->timeout($this->timeout)
                ->get($url);

            if ($response->successful()) {
                return $response->body();
            }

            Log::warning('HTTP request failed', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;

        } catch (Exception $e) {
            Log::error('Failed to fetch page content', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract listing data from HTML
     *
     * @param string $html
     * @return array|null
     */
    private function extractListingData(string $html): ?array
    {
        $data = [
            'title' => null,
            'price' => null,
            'is_active' => true,
            'currency' => 'UAH',
            'location' => null,
            'posted_at' => null,
        ];

        // Check if listing is removed or inactive
        if ($this->isListingInactive($html)) {
            $data['is_active'] = false;
            return $data;
        }

        // Extract title
        $data['title'] = $this->extractTitle($html);

        // Extract price
        $data['price'] = $this->extractPrice($html);

        // Extract location
        $data['location'] = $this->extractLocation($html);

        // Extract posting date
        $data['posted_at'] = $this->extractPostedDate($html);

        // Validate extracted data
        if (!$data['title'] && !$data['price']) {
            Log::warning('Could not extract essential data from listing');
            return null;
        }

        return $data;
    }

    /**
     * Check if listing is inactive or removed
     *
     * @param string $html
     * @return bool
     */
    private function isListingInactive(string $html): bool
    {
        $inactiveIndicators = [
            'Оголошення видалено',
            'Оголошення неактивне',
            'Об\'єкт не знайдено',
            'Сторінка не знайдена',
            'Оголошення більше неактивне',
            'removed',
            'deleted',
            'not found',
        ];

        $htmlLower = mb_strtolower($html);

        foreach ($inactiveIndicators as $indicator) {
            if (mb_strpos($htmlLower, mb_strtolower($indicator)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract title from HTML
     *
     * @param string $html
     * @return string|null
     */
    private function extractTitle(string $html): ?string
    {
        $patterns = [
            '/<h1[^>]*class="[^"]*css-[^"]*"[^>]*>([^<]+)<\/h1>/ui',
            '/<h1[^>]*>([^<]+)<\/h1>/ui',
            '/<title>([^<]+) - OLX\.ua<\/title>/ui',
            '/"title":"([^"]+)"/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $title = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
                if (!empty($title) && strlen($title) > 5) {
                    return $title;
                }
            }
        }

        return null;
    }

    /**
     * Extract price from HTML
     *
     * @param string $html
     * @return float|null
     */
    private function extractPrice(string $html): ?float
    {
        // JSON-LD structured data
        if (preg_match('/<script type="application\/ld\+json"[^>]*>([^<]+)<\/script>/ui', $html, $matches)) {
            $jsonData = json_decode($matches[1], true);
            if (isset($jsonData['offers']['price'])) {
                $price = (float) $jsonData['offers']['price'];
                if ($price > 0) {
                    return $price;
                }
            }
        }

        // Price patterns
        $patterns = [
            '/data-testid="ad-price-container"[^>]*>.*?(\d+(?:\s*\d+)*)\s*грн/ui',
            '/class="[^"]*price[^"]*"[^>]*>.*?(\d+(?:\s*\d+)*)\s*грн/ui',
            '/"price":(\d+)/ui',
            '/(\d+(?:\s*\d+)*)\s*грн/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $priceString = preg_replace('/\s+/', '', $matches[1]);
                $price = (float) $priceString;

                if ($price > 0 && $price < 100000000) { // Reasonable price range
                    return $price;
                }
            }
        }

        return null;
    }

    /**
     * Extract location from HTML
     *
     * @param string $html
     * @return string|null
     */
    private function extractLocation(string $html): ?string
    {
        $patterns = [
            '/data-testid="location-date-container"[^>]*>([^<]+)</ui',
            '/class="[^"]*location[^"]*"[^>]*>([^<]+)</ui',
            '/"region":"([^"]+)"/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $location = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
                if (!empty($location)) {
                    return $location;
                }
            }
        }

        return null;
    }

    /**
     * Extract posting date from HTML
     *
     * @param string $html
     * @return string|null
     */
    private function extractPostedDate(string $html): ?string
    {
        $patterns = [
            '/"dateCreated":"([^"]+)"/ui',
            '/"dateModified":"([^"]+)"/ui',
            '/data-testid="location-date-container".*?(\d{2}\.\d{2}\.\d{4})/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Validate OLX URL format
     *
     * @param string $url
     * @return bool
     */
    public function isValidOlxUrl(string $url): bool
    {
        return preg_match('/^https:\/\/(www\.)?olx\.ua\/.*\/obyavlenie\/.+/', $url) === 1;
    }

    /**
     * Extract listing ID from URL
     *
     * @param string $url
     * @return string|null
     */
    public function extractListingId(string $url): ?string
    {
        if (preg_match('/obyavlenie\/[^\/]+-ID([A-Za-z0-9]+)\.html/', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\/([A-Za-z0-9]+)\.html$/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
