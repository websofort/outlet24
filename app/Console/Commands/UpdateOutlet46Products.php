<?php

namespace FleetCart\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\Product;
use GuzzleHttp\Promise;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class UpdateOutlet46Products extends Command
{
    protected $signature = 'scrape:update-products';

    protected $description = 'Update existing products from Outlet46';

    private const BASE_URL = 'https://www.outlet46.de';

    private $guzzleClient;

    public function __construct()
    {
        parent::__construct();

        $this->guzzleClient = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ]
        ]);
    }

    public function handle()
    {
        $productsToUpdate = Product::whereNotNull('product_url')
            ->orderBy('updated_at', 'asc')
            ->get();

        if ($productsToUpdate->isEmpty()) {
            return self::SUCCESS;
        }

        $batchSize = 100;
        $batches = $productsToUpdate->chunk($batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $productUrls = $batch->pluck('product_url', 'id')->toArray();

            $productsData = $this->scrapeBatchAsync($productUrls);

            if (!empty($productsData)) {
                $this->updateBatchProducts($productsData);
            }

            if ($batchIndex < count($batches) - 1) {
                usleep(500000);
            }
        }

        return self::SUCCESS;
    }

    private function scrapeBatchAsync(array $productUrls): array
    {
        $promises = [];

        foreach ($productUrls as $productId => $url) {
            $promises[$productId] = $this->guzzleClient->getAsync($url);
        }

        $results = Promise\Utils::settle($promises)->wait();

        $productsData = [];

        foreach ($results as $productId => $result) {
            if ($result['state'] === 'fulfilled') {
                try {
                    $response = $result['value'];
                    $html = $response->getBody()->getContents();

                    $productData = $this->parseProductPage($html, $productUrls[$productId], $productId);

                    if ($productData) {
                        $productsData[] = $productData;
                    }

                } catch (\Exception $e) {
                    Log::error("Error parsing product {$productUrls[$productId]}: " . $e->getMessage());
                }
            } else {
                Log::error("Failed to fetch product {$productUrls[$productId]}");
            }
        }

        return $productsData;
    }

    private function parseProductPage(string $html, string $productUrl, int $productId)
    {
        try {
            $crawler = new Crawler($html);

            $jsonLd = $this->extractJsonLd($crawler);
            /*$attributes = $this->extractAttributes($crawler);*/
            $properties = $this->extractProperties($crawler);
            $prices = $this->extractPrices($crawler);
            /*$variants = $this->extractVariants($crawler);*/

            return [
                'id' => $productId,
                'name' => $jsonLd['name'] ? html_entity_decode(html_entity_decode($jsonLd['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                'description' => $this->cleanDescription($jsonLd['description'] ?? ''),
                'price' => $prices['price'] ?? 0,
                'special_price' => $prices['special_price'] ?? null,
                'price_valid_until' => $jsonLd['offers']['priceValidUntil'] ?? null,
                'in_stock' => 1,
                /*'attributes' => $attributes,
                'variants' => $variants,
                'properties' => $properties,*/
                'full_description' => $this->buildFullDescription(
                    $jsonLd['description'] ?? '',
                    $properties
                ),
                'product_url' => $productUrl,
            ];

        } catch (\Exception $e) {
            Log::error("Error parsing product page: " . $e->getMessage(), ['url' => $productUrl]);
            return null;
        }
    }

    private function updateBatchProducts(array $productsData)
    {
        $productsToUpdate = [];
        $variantsToUpdate = [];

        foreach ($productsData as $productData) {
            try {
                $productsToUpdate[] = [
                    'id' => $productData['id'],
                    'name' => $productData['name'],
                    'description' => $productData['full_description'],
                    'short_description' => $productData['description'],
                    'price' => $productData['price'],
                    'special_price' => $productData['special_price'],
                    'special_price_start' => $productData['special_price'] ? now()->format('Y-m-d') : null,
                    'special_price_end' => $productData['special_price'] ? $productData['price_valid_until'] : null,
                    'special_price_type' => $productData['special_price'] ? 'fixed' : null,
                    'selling_price' => $productData['special_price'] ?: $productData['price'],
                    'in_stock' => 1,
                    'product_url' => $productData['product_url'],
                ];

               /* if (!empty($productData['variants'])) {
                    $this->prepareVariantUpdates($productData, $variantsToUpdate);
                }*/

            } catch (\Exception $e) {
                Log::error("Error preparing product update: " . $e->getMessage(), [
                    'product_id' => $productData['id'] ?? 'unknown',
                ]);
            }
        }

        if (!empty($productsToUpdate)) {
            $this->updateProducts($productsToUpdate);
            $this->updateVariantPricesForProducts($productsToUpdate);
        }

        /*if (!empty($variantsToUpdate)) {
            $this->updateProductVariants($variantsToUpdate);
        }*/
    }

    private function updateVariantPricesForProducts(array $productsToUpdate): void
    {
        if (empty($productsToUpdate)) {
            return;
        }

        $columns = [
            'price',
            'selling_price',
            'special_price',
            'special_price_start',
            'special_price_end',
        ];

        $cases = array_fill_keys($columns, []);
        $binds = array_fill_keys($columns, []);
        $productIds = [];

        foreach ($productsToUpdate as $p) {
            $pid = (int) $p['id'];
            $productIds[] = $pid;

            $cases['price'][]                = "WHEN {$pid} THEN ?";
            $binds['price'][]                = (float) ($p['price'] ?? 0);

            $cases['selling_price'][]                = "WHEN {$pid} THEN ?";
            $binds['selling_price'][]                = isset($p['special_price']) ? (float) $p['special_price'] : (float) ($p['price'] ?? 0);;

            $cases['special_price'][]        = "WHEN {$pid} THEN ?";
            $binds['special_price'][]        = isset($p['special_price']) ? (float) $p['special_price'] : null;

            $cases['special_price_start'][]  = "WHEN {$pid} THEN ?";
            $binds['special_price_start'][]  = $p['special_price_start'] ?? null;

            $cases['special_price_end'][]    = "WHEN {$pid} THEN ?";
            $binds['special_price_end'][]    = $p['special_price_end'] ?? null;
        }

        $idsString = implode(',', array_unique($productIds));

        $sql = "UPDATE `product_variants` SET
        `price` = CASE `product_id` "               . implode(' ', $cases['price']) . " END,
        `selling_price` = CASE `product_id` "               . implode(' ', $cases['selling_price']) . " END,
        `special_price` = CASE `product_id` "       . implode(' ', $cases['special_price']) . " END,
        `special_price_start` = CASE `product_id` " . implode(' ', $cases['special_price_start']) . " END,
        `special_price_end` = CASE `product_id` "   . implode(' ', $cases['special_price_end']) . " END,
        `updated_at` = ?
    WHERE `product_id` IN ({$idsString})";

        $bindings = [];
        foreach ($columns as $col) {
            array_push($bindings, ...$binds[$col]);
        }
        $bindings[] = now();

        DB::update($sql, $bindings);
    }


    private function prepareVariantUpdates(array $productData, array &$variantsToUpdate)
    {
        $productId = $productData['id'];
        $variants = $productData['variants'];

        $existingVariants = DB::table('product_variants')
            ->where('product_id', $productId)
            ->get()
            ->keyBy('outlet_variation_id');

        foreach ($variants as $variantData) {
            $outletVariationId = $variantData['outlet_variation_id'] ?? null;

            if (!$outletVariationId) {
                continue;
            }

            $dbVariant = $existingVariants->get($outletVariationId);

            if (!$dbVariant) {
                continue;
            }

            $variationDetails = $this->scrapeVariationDetails($outletVariationId);
            $stockNet = $variationDetails['stock_net'] ?? 0;

            $variantsToUpdate[] = [
                'id' => $dbVariant->id,
                'in_stock' => 1,
                'manage_stock' => $stockNet !== 0 ? 1 : 0,
                'qty' => $stockNet,
                'price' => $productData['price'],
                'special_price' => $productData['special_price'],
                'special_price_type' => $productData['special_price'] ? 'fixed' : null,
                'special_price_start' => $productData['special_price'] ? now()->format('Y-m-d H:i:s') : null,
                'special_price_end' => $productData['special_price'] ? $productData['price_valid_until'] : null,
                'selling_price' => $productData['special_price'] ?: $productData['price'],
                'outlet_variation_id' => $outletVariationId,
            ];
        }
    }

    private function extractJsonLd(Crawler $crawler): array
    {
        try {
            $jsonLdScript = $crawler->filter('script[type="application/ld+json"]')->first();

            if ($jsonLdScript->count() > 0) {
                $jsonText = $jsonLdScript->text();
                return json_decode($jsonText, true) ?? [];
            }
        } catch (\Exception $e) {
            Log::error("Could not extract JSON-LD: " . $e->getMessage());
        }

        return [];
    }

    private function extractCategories(string $html): array
    {
        $categories = [];

        if (preg_match("/'addCartCategory':\s*'([^']+)'/", $html, $matches)) {
            $categoryString = $matches[1];

            $parts = explode('\\/', $categoryString);

            $parts = array_map(function($part) {
                $part = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m) {
                    $code = hexdec($m[1]);
                    return mb_convert_encoding(pack('n', $code), 'UTF-8', 'UTF-16BE');
                }, $part);

                $part = str_replace(['\\/', '\\u0020'], ['/', ' '], $part);

                $part = stripcslashes($part);

                $part = html_entity_decode($part, ENT_QUOTES | ENT_HTML5);

                $part = trim($part);

                $part = preg_replace('/\s*&\s*/', ' & ', $part);

                return trim($part);
            }, $parts);

            $categories = array_values(array_filter($parts, function($v) { return $v !== ''; }));
        }

        return $categories;
    }

    private function extractAttributes(Crawler $crawler): array
    {
        $attributes = [];

        try {
            $attributeGroups = $crawler->filter('[data-item-variation-select]');

            $attributeGroups->each(function (Crawler $group) use (&$attributes) {
                try {
                    $labelNode = $group->filter('.select-attr-name');
                    if ($labelNode->count() === 0) {
                        return;
                    }

                    $labelText = trim($labelNode->text());
                    $rawName = strtolower(trim(preg_replace('/:.*/', '', $labelText)));

                    $attributeName = $rawName;
                    if (str_contains($rawName, 'size')) {
                        $attributeName = 'size';
                    } elseif (str_contains($rawName, 'color') || str_contains($rawName, 'colour')) {
                        $attributeName = 'color';
                    }

                    $ulNode = $group->filter('[data-eg-attributes]');
                    if ($ulNode->count() === 0) {
                        return;
                    }

                    $outletAttributeId = $ulNode->attr('data-eg-attributes');

                    $listItems = $ulNode->filter('li');
                    if ($listItems->count() === 0) {
                        return;
                    }

                    $values = [];
                    $listItems->each(function (Crawler $node) use (&$values) {
                        try {
                            $text = null;
                            if ($node->filter('span')->count() > 0) {
                                $text = trim($node->filter('span')->text());
                            }
                            if ($text === null || $text === '') {
                                $attrName = $node->attr('data-eg-attrname');
                                if (!empty($attrName)) {
                                    $text = trim($attrName);
                                }
                            }
                            if ($text === null || $text === '') {
                                $text = trim($node->text(''));
                            }
                            if ($text === '') {
                                return;
                            }

                            $availableAttr = $node->attr('data-eg-issalable');
                            if ($availableAttr !== null) {
                                $available = filter_var($availableAttr, FILTER_VALIDATE_BOOLEAN);
                            } else {
                                $classes = $node->attr('class') ?? '';
                                $available = !str_contains($classes, 'disabled') && !str_contains($classes, 'out-of-stock');
                            }

                            $outletValueId = $node->attr('data-eg-value');

                            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $text, $m)) {
                                $start = (int) $m[1];
                                $end   = (int) $m[2];
                                for ($i = $start; $i <= $end; $i++) {
                                    $values[] = [
                                        'value'      => (string) $i,
                                        'available'  => $available,
                                        'outlet_value_id'         => $outletValueId,
                                    ];
                                }
                                return;
                            }

                            $values[] = [
                                'value'      => $text,
                                'available'  => $available,
                                'outlet_value_id'  => $outletValueId,
                            ];
                        } catch (\Throwable $e) {
                            Log::error('Error processing attribute li: ' . $e->getMessage());
                        }
                    });

                    if (!empty($values)) {
                        $attributes[$attributeName] = [
                            'values' => $values,
                            'outlet_attribute_id' => $outletAttributeId,
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::error('Error processing attribute group: ' . $e->getMessage());
                }
            });


        } catch (\Throwable $e) {
            Log::error('Could not extract attributes: ' . $e->getMessage());
        }

        return $attributes;
    }

    private function extractVariants(Crawler $crawler): array
    {
        $variants = [];

        try{
            $node = $crawler->filter('[data-eg-variations]');

            if ($node->count() === 0) {
                return $variants;
            }

            $raw = $node->attr('data-eg-variations');

            if (empty($raw)) {
                return $variants;
            }

            $decodedJson = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
            $data = json_decode($decodedJson, true);

            if (!is_array($data)){
                return $variants;
            }

            foreach ($data as $variant) {
                if (isset($variant['variationId']) && isset($variant['attributes'])){
                    $variantData = [
                        'outlet_variation_id' => $variant['variationId'],
                        'attributes' => []
                    ];

                    foreach ($variant['attributes'] as $attribute) {
                        $variantData['attributes'][] = [
                            'outlet_value_id' => $attribute['attributeValueId'],
                        ];
                    }
                    $variants[] = $variantData;
                }
            }

        }catch (\Throwable $e) {
            Log::error('extractVariants: unexpected error: ' . $e->getMessage(), ['exception' => $e]);
        }
        return $variants;
    }

    private function extractProperties(Crawler $crawler): array
    {
        $properties = [];

        try {
            $propertyNodes = $crawler->filter('.property-box div');

            $propertyNodes->each(function (Crawler $node) use (&$properties) {
                try {
                    $label = $node->filter('.span-item1')->text();
                    $value = trim(str_replace($label, '', $node->text()));

                    $label = trim(str_replace(':', '', $label));
                    $properties[$label] = trim($value);
                } catch (\Exception $e) {
                }
            });
        } catch (\Exception $e) {
            Log::error("Could not extract properties: " . $e->getMessage());
        }

        return $properties;
    }

    private function extractImages(Crawler $crawler): array
    {
        $images = [];
        $mainImageUrl = null;

        try {
            $mainImage = $crawler->filter('#ItemImgZoom img');
            if ($mainImage->count() > 0) {
                $mainImageUrl = $mainImage->attr('src');
                $images['main'] = $mainImageUrl;
            }

            $thumbnails = $crawler->filter('.single-prev-images a');
            $additionalImages = $thumbnails->each(function (Crawler $node) {
                return $node->attr('href');
            });

            $images['additional'] = array_values(array_filter($additionalImages, function($url) use ($mainImageUrl) {
                return $url !== $mainImageUrl;
            }));

        } catch (\Exception $e) {
            Log::error("Could not extract images: " . $e->getMessage());
        }

        return $images;
    }

    private function extractPrices(Crawler $crawler): array
    {
        $prices = [];

        try {
            $priceNode = $crawler->filter('[data-eg-item-price]');
            if ($priceNode->count() > 0) {
                $priceText = $priceNode->text();
                $prices['price'] = $this->parsePrice($priceText);
            }

            $rrpNode = $crawler->filter('[data-eg-item-rrp]');
            if ($rrpNode->count() > 0) {
                $rrpText = $rrpNode->text();
                $prices['rrp'] = $this->parsePrice($rrpText);
            }

            if (isset($prices['rrp']) && isset($prices['price']) && $prices['rrp'] > $prices['price']) {
                $prices['special_price'] = $prices['price'];
                $prices['price'] = $prices['rrp'];
            }

        } catch (\Exception $e) {
            Log::error("Could not extract prices: " . $e->getMessage());
        }

        return $prices;
    }

    private function parsePrice(string $priceText): float
    {
        $cleaned = preg_replace('/[^\d,.]/', '', $priceText);
        $cleaned = str_replace(',', '.', $cleaned);
        return (float) $cleaned;
    }

    private function cleanDescription(string $description): string
    {
        $cleaned = html_entity_decode($description);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        return trim($cleaned);
    }

    private function buildFullDescription(string $baseDescription, array $properties): string
    {
        $description = $this->cleanDescription($baseDescription);

        if (!empty($properties)) {
            $description .= "\n\n<h3>Product Details:</h3>\n<ul>";
            foreach ($properties as $key => $value) {
                $description .= "\n<li><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</li>";
            }
            $description .= "\n</ul>";
        }

        return $description;
    }

    private function scrapeVariationDetails($variationId)
    {
        try {
            $url = self::BASE_URL . '/rest/io/variations/' . $variationId . '?template=Ceres%3A%3AItem.SingleItem';

            $response = $this->guzzleClient->get($url);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);

            $stockNet = $data['data']['documents'][0]['data']['stock']['net'] ?? 0;
            $variationImages = $data['data']['documents'][0]['data']['images']['variation'] ?? [];

            return [
                'stock_net' => $stockNet,
                'images' => $variationImages,
            ];
        } catch (\Exception $e) {
            Log::error("Error scraping variation details: " . $e->getMessage());
            return null;
        }
    }
    private function updateProducts(array $productsToUpdate)
    {
        if (empty($productsToUpdate)) {
            return;
        }

        $locale = 'en';
        $productIds = [];

        $productFields = [
            'price', 'special_price', 'special_price_start', 'special_price_end',
            'special_price_type', 'selling_price', 'in_stock', 'product_url', 'brand_id'
        ];

        $translationFields = ['name', 'description', 'short_description'];

        $productCases = array_fill_keys($productFields, []);
        $productBindings = array_fill_keys($productFields, []);

        $translationCases = array_fill_keys($translationFields, []);
        $translationBindings = array_fill_keys($translationFields, []);

        foreach ($productsToUpdate as $product) {
            $productId = (int) $product['id'];
            $productIds[] = $productId;

            foreach ($productFields as $field) {
                $productCases[$field][] = "WHEN {$productId} THEN ?";
                $productBindings[$field][] = $product[$field] ?? null;
            }

            foreach ($translationFields as $field) {
                $translationCases[$field][] = "WHEN {$productId} THEN ?";
                $translationBindings[$field][] = $product[$field] ?? null;
            }
        }

        $idsString = implode(',', $productIds);

        $flatProductBindings = [];
        foreach ($productFields as $field) {
            array_push($flatProductBindings, ...$productBindings[$field]);
        }
        $flatProductBindings[] = now();

        $productSql = "UPDATE products SET
    price = CASE id " . implode(' ', $productCases['price']) . " END,
    special_price = CASE id " . implode(' ', $productCases['special_price']) . " END,
    special_price_start = CASE id " . implode(' ', $productCases['special_price_start']) . " END,
    special_price_end = CASE id " . implode(' ', $productCases['special_price_end']) . " END,
    special_price_type = CASE id " . implode(' ', $productCases['special_price_type']) . " END,
    selling_price = CASE id " . implode(' ', $productCases['selling_price']) . " END,
    in_stock = CASE id " . implode(' ', $productCases['in_stock']) . " END,
    product_url = CASE id " . implode(' ', $productCases['product_url']) . " END,
    brand_id = CASE id " . implode(' ', $productCases['brand_id']) . " END,
    updated_at = ?
    WHERE id IN ({$idsString})";

        DB::update($productSql, $flatProductBindings);

        $flatTranslationBindings = [];
        foreach ($translationFields as $field) {
            array_push($flatTranslationBindings, ...$translationBindings[$field]);
        }
        $flatTranslationBindings[] = $locale;

        $translationSql = "UPDATE product_translations SET
    name = CASE product_id " . implode(' ', $translationCases['name']) . " END,
    description = CASE product_id " . implode(' ', $translationCases['description']) . " END,
    short_description = CASE product_id " . implode(' ', $translationCases['short_description']) . " END
    WHERE product_id IN ({$idsString}) AND locale = ?";

        DB::update($translationSql, $flatTranslationBindings);
    }

    private function updateProductVariants(array $variantsToUpdate)
    {
        if (empty($variantsToUpdate)) {
            return;
        }

        $columns = [
            'in_stock',
            'manage_stock',
            'qty',
            'price',
            'special_price',
            'special_price_type',
            'special_price_start',
            'special_price_end',
            'selling_price',
            'outlet_variation_id',
        ];

        $cases = array_fill_keys($columns, []);
        $binds = array_fill_keys($columns, []);

        $ids = [];

        foreach ($variantsToUpdate as $variant) {
            $id = (int) $variant['id'];
            $ids[] = $id;

            $sps = $variant['special_price_start'] ?? null;
            if ($sps === '' || $sps === null) {
                $sps = now()->format('Y-m-d');
            } else {
                try {
                    $sps = \Carbon\Carbon::parse($sps)->format('Y-m-d');
                } catch (\Throwable $e) {
                    $sps = now()->format('Y-m-d');
                }
            }

            $spe = $variant['special_price_end'] ?? null;
            if ($spe === '' || $spe === null) {
                $spe = null;
            } else {
                try {
                    $spe = \Carbon\Carbon::parse($spe)->format('Y-m-d');
                } catch (\Throwable $e) {
                    $spe = null;
                }
            }

            $cases['in_stock'][]              = "WHEN {$id} THEN ?";
            $binds['in_stock'][]              = (int) ($variant['in_stock'] ?? 0);

            $cases['manage_stock'][]          = "WHEN {$id} THEN ?";
            $binds['manage_stock'][]          = (int) ($variant['manage_stock'] ?? 0);

            $cases['qty'][]                   = "WHEN {$id} THEN ?";
            $binds['qty'][]                   = (int) ($variant['qty'] ?? 0);

            $cases['price'][]                 = "WHEN {$id} THEN ?";
            $binds['price'][]                 = (float) ($variant['price'] ?? 0);

            $cases['special_price'][]         = "WHEN {$id} THEN ?";
            $binds['special_price'][]         = isset($variant['special_price']) ? (float) $variant['special_price'] : null;

            $cases['special_price_type'][]    = "WHEN {$id} THEN ?";
            $binds['special_price_type'][]    = $variant['special_price_type'] ?? 'fixed';

            $cases['special_price_start'][]   = "WHEN {$id} THEN ?";
            $binds['special_price_start'][]   = $sps;

            $cases['special_price_end'][]     = "WHEN {$id} THEN ?";
            $binds['special_price_end'][]     = $spe;

            $cases['selling_price'][]         = "WHEN {$id} THEN ?";
            $binds['selling_price'][]         = isset($variant['selling_price']) ? (float) $variant['selling_price'] : null;

            $cases['outlet_variation_id'][]   = "WHEN {$id} THEN ?";
            $binds['outlet_variation_id'][]   = $variant['outlet_variation_id'] ?? null;
        }

        $idsString = implode(',', $ids);

        $sql = "UPDATE `product_variants` SET
        `in_stock` = CASE `id` "              . implode(' ', $cases['in_stock']) . " END,
        `manage_stock` = CASE `id` "          . implode(' ', $cases['manage_stock']) . " END,
        `qty` = CASE `id` "                   . implode(' ', $cases['qty']) . " END,
        `price` = CASE `id` "                 . implode(' ', $cases['price']) . " END,
        `special_price` = CASE `id` "         . implode(' ', $cases['special_price']) . " END,
        `special_price_type` = CASE `id` "    . implode(' ', $cases['special_price_type']) . " END,
        `special_price_start` = CASE `id` "   . implode(' ', $cases['special_price_start']) . " END,
        `special_price_end` = CASE `id` "     . implode(' ', $cases['special_price_end']) . " END,
        `selling_price` = CASE `id` "         . implode(' ', $cases['selling_price']) . " END,
        `outlet_variation_id` = CASE `id` "   . implode(' ', $cases['outlet_variation_id']) . " END,
        `updated_at` = ?
        WHERE `id` IN ({$idsString})";

        $bindings = [];
        foreach ($columns as $col) {
            array_push($bindings, ...$binds[$col]);
        }
        $bindings[] = now();

        DB::update($sql, $bindings);
    }

}
