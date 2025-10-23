<?php

namespace FleetCart\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Attribute\Entities\Attribute;
use Modules\Attribute\Entities\AttributeSet;
use Modules\Attribute\Entities\AttributeValue;
use Modules\Attribute\Entities\ProductAttribute;
use Modules\Attribute\Entities\ProductAttributeValue;
use Modules\Brand\Entities\Brand;
use Modules\Category\Entities\Category;
use Modules\Media\Entities\EntityFile;
use Modules\Media\Entities\File;
use Modules\Product\Entities\Product;
use Modules\Variation\Entities\VariationValue;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeOutlet46Products extends Command
{
    protected $signature = 'scrape:products';

    protected $description = 'Create products from Outlet46';

    private const BASE_URL = 'https://www.outlet46.de';

    public function handle()
    {
        $this->scrapeMenProductLinks();
        /*$this->scrapeWomenProductLinks();
        $this->scrapeKidsProductLinks();*/

        return self::SUCCESS;
    }

    public function scrapeMenProductLinks()
    {
        $links = $this->scrapeByFacet(727, 'Men');

        $batchSize = 50;
         $batches = array_chunk($links, $batchSize);

         foreach ($batches as $batchLinks) {
             $this->processBatchProducts($batchLinks, 'Men');
         }
    }

    public function scrapeWomenProductLinks()
    {
        $links = $this->scrapeByFacet(726, 'Women');

        $batchSize = 50;
        $batches = array_chunk($links, $batchSize);

        foreach ($batches as $batchLinks) {
            $this->processBatchProducts($batchLinks, 'Women');
        }
    }


    public function scrapeKidsProductLinks()
    {
        $links = $this->scrapeByFacet(728, 'Children');

        $batchSize = 50;
        $batches = array_chunk($links, $batchSize);

        foreach ($batches as $batchLinks) {
            $this->processBatchProducts($batchLinks, 'Children');
        }
    }

    private function scrapeByFacet(int $facetId, string $category)
    {
        $page = 0;
        $allLinks = [];

        do {
            $url = self::BASE_URL . "/en/All-Articles/?facets={$facetId}&page={$page}";

            $links = $this->extractProductLinks($url);

            if (empty($links)) {
                break;
            }

            $allLinks = array_merge($allLinks, $links);
            $page++;

            sleep(2);

        } while (!empty($links) && $page <= 1);

        return array_unique($allLinks);
    }

    private function extractProductLinks(string $url): array
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(30)->retry(3, 1000)->get($url);

            if (!$res->ok()) {
                $this->error("HTTP {$res->status()} loading {$url}");
                return [];
            }

            $html = $res->body();
            $crawler = new Crawler($html);

            $links = $crawler->filter('.article-body a.product-list.article.a')->each(function (Crawler $node) {
                return $node->attr('href');
            });

            $links = array_map(function($link) {
                if (strpos($link, 'http') !== 0) {
                    return self::BASE_URL . $link;
                }
                return $link;
            }, $links);


            return $links;

        } catch (\Exception $e) {
            $this->error("Error scraping {$url}: " . $e->getMessage());
            return [];
        }
    }

    public function scrapeProductDetails(string $productUrl,$gender = null)
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])->timeout(30)->retry(3, 1000)->get($productUrl);

            if (!$res->ok()) {
                $this->error("HTTP {$res->status()} loading {$productUrl}");
                return null;
            }

            $html = $res->body();
            $crawler = new Crawler($html);

            $jsonLd = $this->extractJsonLd($crawler);

            $categories = $this->extractCategories($html);

            $attributes = $this->extractAttributes($crawler);

            $properties = $this->extractProperties($crawler);

            $images = $this->extractImages($crawler);

            $prices = $this->extractPrices($crawler);

            $variants = $this->extractVariants($crawler);

            $productData = [
                'name' => $jsonLd['name'] ? html_entity_decode(html_entity_decode($jsonLd['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                'sku' => $jsonLd['sku'] ?? '',
                'product_url' => $productUrl,
                'brand' => $jsonLd['brand']['name'] ?? '',
                'description' => $this->cleanDescription($jsonLd['description'] ?? ''),
/*                'gtin13' => $jsonLd['gtin13'] ?? '',*/

                'categories' => $categories,
                'gender' => $gender,

                'price' => $prices['price'] ?? 0,
                'special_price' => $prices['special_price'] ?? null,
                'price_valid_until' => $jsonLd['offers']['priceValidUntil'] ?? null,
                'currency' => $jsonLd['offers']['priceCurrency'] ?? 'EUR',

                'in_stock' => 1,
                'condition' => $this->getCondition($jsonLd['offers']['itemCondition'] ?? ''),

                'attributes' => $attributes,
                'variants' => $variants,

                'properties' => $properties,
                'full_description' => $this->buildFullDescription(
                    $jsonLd['description'] ?? '',
                    $properties
                ),

                'images' => $images,

                'weight' => $jsonLd['weight']['value'] ?? null,
                'depth' => $jsonLd['depth']['value'] ?? null,
                'width' => $jsonLd['width']['value'] ?? null,
                'height' => $jsonLd['height']['value'] ?? null,

                'url' => $productUrl,
                'scraped_at' => now()->toDateTimeString(),
            ];

            sleep(1);
            return $productData;

        } catch (\Exception $e) {
            $this->error("Error scraping product details from {$productUrl}: " . $e->getMessage());
            Log::error("Scraping error: " . $e->getMessage(), ['url' => $productUrl]);
            return null;
        }
    }

    private function scrapeVariationDetails($variationId)
    {
        try {
            $url = self::BASE_URL . '/rest/io/variations/' . $variationId . '?template=Ceres%3A%3AItem.SingleItem';
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])->timeout(30)->retry(3, 1000)->get($url);

            if (!$res->ok()) {
                return null;
            }

            $data = $res->json();

            $stockNet = $data['data']['documents'][0]['data']['stock']['net'] ?? 0;
            $variationImages = $data['data']['documents'][0]['data']['images']['variation'] ?? [];

            return [
                'stock_net' => $stockNet,
                'images' => $variationImages,
            ];
        }catch (\Exception $e) {
            Log::error("Error scraping variation details: " . $e->getMessage());
            return null;
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

    private function isInStock(string $availability): bool
    {
        return str_contains($availability, 'InStock');
    }

    private function getCondition(string $condition): string
    {
        if (str_contains($condition, 'NewCondition')) {
            return 'new';
        }
        return 'used';
    }


    private function downloadImage($imageUrl, &$existingFiles = null)
    {
        try {
            $fileName = basename(parse_url($imageUrl, PHP_URL_PATH));

            if ($existingFiles && isset($existingFiles[$fileName])) {
                return $existingFiles[$fileName]->id;
            }

            $existingFile = File::where('filename', $fileName)
                ->where('disk', config('filesystems.default'))
                ->first();

            if ($existingFile) {
                if ($existingFiles !== null) {
                    $existingFiles[$fileName] = $existingFile;
                }
                return $existingFile->id;
            }

            $contents = @file_get_contents($imageUrl);
            if ($contents === false) {
                Log::error("Failed to download image: {$imageUrl}");
                return null;
            }

            $fileType = pathinfo($fileName, PATHINFO_EXTENSION);

            if (empty($fileType)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_buffer($finfo, $contents);
                finfo_close($finfo);

                $fileType = match($mimeType) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg'
                };
            }

            Storage::disk(config('filesystems.default'))->put('media/'.$fileName, $contents);

            $file = new File();
            $file->user_id = 1;
            $file->filename = $fileName;
            $file->disk = config('filesystems.default');
            $file->path = 'media/'.$fileName;
            $file->extension = $fileType;
            $file->mime = 'image/'.$fileType;
            $file->size = strlen($contents);
            $file->save();

            if ($existingFiles !== null) {
                $existingFiles[$fileName] = $file;
            }

            return $file->id;
        } catch (\Exception $e) {
            Log::error("Error downloading image: " . $e->getMessage(), ['url' => $imageUrl]);
            return null;
        }
    }
    private function attachImages($productId, $images,&$existingFiles = null)
    {
        $product = Product::find($productId);
        if (!$product) {
            $this->error("Product not found: $productId");
            return;
        }

        $baseImage = $images['main'] ?? null;
        $additionalImages = $images['additional'] ?? [];

        $existingEntityFiles = EntityFile::where('entity_type', 'Modules\Product\Entities\Product')
            ->where('entity_id', $productId)
            ->get()
            ->keyBy('zone');

        $imagesToDownload = [];
        if ($baseImage) {
            $imagesToDownload['base_image'] = [$baseImage];
        }
       /* if (!empty($additionalImages)) {
            $imagesToDownload['additional_images'] = $additionalImages;
        }*/

        $newEntityFiles = [];
        foreach ($imagesToDownload as $zone => $imageUrls) {
            foreach ($imageUrls as $imageUrl) {
                $fileId = $this->downloadImage($imageUrl,$existingFiles);
                if ($fileId) {
                    $newEntityFiles[] = [
                        'file_id' => $fileId,
                        'zone' => $zone,
                    ];
                }
            }
        }

        $oldFileIds = $existingEntityFiles->pluck('file_id')->toArray();
        if (!empty($oldFileIds)) {
            EntityFile::where('entity_type', 'Modules\Product\Entities\Product')
                ->where('entity_id', $productId)
                ->delete();
        }

        if (!empty($newEntityFiles)) {
            $insertData = array_map(function($item) use ($productId) {
                return [
                    'file_id' => $item['file_id'],
                    'entity_type' => 'Modules\Product\Entities\Product',
                    'entity_id' => $productId,
                    'zone' => $item['zone'],
                ];
            }, $newEntityFiles);

            EntityFile::insert($insertData);
        }
    }

    private function attachAdditionalImages($productId, $images,&$existingFiles = null)
    {
        $product = Product::find($productId);
        if (!$product) {
            $this->error("Product not found: $productId");
            return;
        }

        if (empty($images)) {
            return;
        }

        $existingProductEntityFiles = EntityFile::where('entity_type', 'Modules\Product\Entities\Product')
            ->where('entity_id', $productId)
            ->pluck('file_id')
            ->toArray();

        $newFileIds = [];
        foreach ($images as $imageUrl) {
            $fileName = basename(parse_url($imageUrl, PHP_URL_PATH));

            if ($existingFiles && isset($existingFiles[$fileName])) {
                $existingFile = $existingFiles[$fileName];
            } else {
                $existingFile = File::where('filename', $fileName)
                    ->where('disk', config('filesystems.default'))
                    ->first();
            }

            if ($existingFile) {
                if (!in_array($existingFile->id, $existingProductEntityFiles)) {
                    $newFileIds[] = $existingFile->id;
                }
            } else {
                $fileId = $this->downloadImage($imageUrl,$existingFiles);
                if ($fileId && !in_array($fileId, $existingProductEntityFiles)) {
                    $newFileIds[] = $fileId;
                }
            }
        }

        if (!empty($newFileIds)) {
            $insertData = array_map(function($fileId) use ($productId) {
                return [
                    'file_id' => $fileId,
                    'entity_type' => 'Modules\Product\Entities\Product',
                    'entity_id' => $productId,
                    'zone' => 'additional_images',
                ];
            }, $newFileIds);

            EntityFile::insert($insertData);
        }
    }

    private function attachVariationImages($variantId, $images, &$existingFiles = null)
    {
        if (empty($images)) {
            return;
        }

        $newFileIds = [];
        foreach ($images as $image) {
            $imageUrl = $image['url'] ?? null;
            if (!$imageUrl) {
                continue;
            }

            $fileName = basename(parse_url($imageUrl, PHP_URL_PATH));

            if ($existingFiles && isset($existingFiles[$fileName])) {
                $existingFile = $existingFiles[$fileName];
            } else {
                $existingFile = File::where('filename', $fileName)
                    ->where('disk', config('filesystems.default'))
                    ->first();

                if ($existingFile && $existingFiles !== null) {
                    $existingFiles[$fileName] = $existingFile;
                }
            }

            if ($existingFile) {
                $newFileIds[] = $existingFile->id;
            } else {
                $fileId = $this->downloadImage($imageUrl, $existingFiles);
                if ($fileId) {
                    $newFileIds[] = $fileId;
                }
            }
        }

        if (empty($newFileIds)) {
            return;
        }

        $existingVariantImages = EntityFile::where('entity_type', 'Modules\Product\Entities\ProductVariant')
            ->where('entity_id', $variantId)
            ->where('zone', 'additional_images')
            ->pluck('file_id')
            ->toArray();

        $imagesToInsert = array_diff($newFileIds, $existingVariantImages);

        if (!empty($imagesToInsert)) {
            $insertData = array_map(function($fileId) use ($variantId) {
                return [
                    'file_id' => $fileId,
                    'entity_type' => 'Modules\Product\Entities\ProductVariant',
                    'entity_id' => $variantId,
                    'zone' => 'additional_images',
                ];
            }, $imagesToInsert);

            EntityFile::insert($insertData);
        }
    }
    private function processBatchProducts(array $links, string $gender)
    {
        $productsData = [];
        foreach ($links as $link) {
            $productData = $this->scrapeProductDetails($link, $gender);
            if ($productData && $this->validateProductData($productData)) {
                $productsData[] = $productData;
            }
        }

        if (empty($productsData)) {
            return;
        }

        $existingFiles = $this->preloadImageFiles($productsData);
        $entities = $this->preloadEntities($productsData, $gender);

        $productsToUpdate = [];
        $allVariantsToUpdate = [];

        foreach ($productsData as $productData) {
            $variantsToUpdate = [];

            $this->createProduct(
                $productData,
                $gender,
                $entities['existingProducts'],
                $entities['existingBrands'],
                $entities['categoriesByNameAndParent'],
                $entities['existingAttributeSets'],
                $entities['existingAttributes'],
                $entities['existingAttributeValues'],
                $entities['existingVariations'],
                $entities['existingVariationValues'],
                $entities['existingVariants'],
                $entities['existingProductVariations'],
                $existingFiles,
                $productsToUpdate,
                $variantsToUpdate
            );

            foreach ($variantsToUpdate as $variant) {
                $allVariantsToUpdate[] = [
                    'variant' => $variant,
                    'productData' => $productData
                ];
            }
        }

        if (!empty($productsToUpdate)) {
            $this->updateProducts($productsToUpdate);
        }

        if (!empty($allVariantsToUpdate)) {
            $variantsOnly = array_column($allVariantsToUpdate, 'variant');
            $this->updateProductVariants($variantsOnly);

            foreach ($allVariantsToUpdate as $item) {
                $variantUpdate = $item['variant'];
                $productData = $item['productData'];

                $variantId = $variantUpdate['id'];
                $outletVariationId = $variantUpdate['outlet_variation_id'] ?? null;

                if (!$outletVariationId) {
                    continue;
                }

                $hasImages = EntityFile::where('entity_type', 'Modules\Product\Entities\ProductVariant')
                    ->where('entity_id', $variantId)
                    ->where('zone', 'additional_images')
                    ->exists();

                if ($hasImages) {
                    continue;
                }

                $variationData = $this->scrapeVariationDetails($outletVariationId);

                if ($variationData && !empty($variationData['images'])) {
                    $this->attachVariationImages($variantId, $variationData['images'], $existingFiles);
                } else {
                    $productImages = $productData['images']['additional'] ?? [];
                    if (!empty($productImages)) {
                        $productId = DB::table('product_variants')
                            ->where('id', $variantId)
                            ->value('product_id');

                        if ($productId) {
                            $this->attachAdditionalImages($productId, $productImages, $existingFiles);
                        }
                    }
                }
            }
        }
    }

    private function preloadImageFiles(array $productsData): \Illuminate\Support\Collection
    {
        $allImageUrls = [];
        foreach ($productsData as $productData) {
            if (!empty($productData['images']['main'])) {
                $allImageUrls[] = basename(parse_url($productData['images']['main'], PHP_URL_PATH));
            }
            if (!empty($productData['images']['additional'])) {
                foreach ($productData['images']['additional'] as $url) {
                    $allImageUrls[] = basename(parse_url($url, PHP_URL_PATH));
                }
            }
        }

        $allImageUrls = array_unique($allImageUrls);

        return File::whereIn('filename', $allImageUrls)
            ->where('disk', config('filesystems.default'))
            ->get()
            ->keyBy('filename');
    }

    private function preloadEntities(array $productsData, string $gender): array
    {
        $locale = 'en';

        $allSkus = array_column($productsData, 'sku');
        $allProductUrls = array_column($productsData, 'url');
        $allBrandNames = array_unique(array_column($productsData, 'brand'));
        $allCategoryNames = [$gender];
        $allAttributeNames = ['Gender', 'Brand'];
        $allAttributeValues = [ucfirst($gender)];
        $allSizeValues = [];
        $allColorValues = [];

        foreach ($productsData as $productData) {
            if (!empty($productData['categories'])) {
                foreach ($productData['categories'] as $cat) {
                    $allCategoryNames[] = ucfirst($cat);
                }
            }

            foreach ($allBrandNames as $brandName) {
                if (!empty($brandName)) {
                    $allAttributeValues[] = ucfirst($brandName);
                }
            }

            if (!empty($productData['attributes'])) {
                foreach ($productData['attributes'] as $attrName => $attrData) {
                    $allAttributeNames[] = ucfirst($attrName);
                    $values = $attrData['values'] ?? $attrData;

                    if (is_array($values)) {
                        foreach ($values as $val) {
                            $allAttributeValues[] = ucfirst($val['value']);

                            if (strtolower($attrName) === 'size') {
                                $allSizeValues[] = ucfirst($val['value']);
                            } elseif (strtolower($attrName) === 'color') {
                                $allColorValues[] = ucfirst($val['value']);
                            }
                        }
                    }
                }
            }
        }

        $allCategoryNames = array_unique($allCategoryNames);
        $allAttributeNames = array_unique($allAttributeNames);
        $allAttributeValues = array_unique($allAttributeValues);
        $allSizeValues = array_unique($allSizeValues);
        $allColorValues = array_unique($allColorValues);

        return [
            'existingProducts' => $this->loadProducts($allProductUrls, $locale),
            'existingBrands' => $this->loadBrands($allBrandNames, $locale),
            'categoriesByNameAndParent' => $this->loadCategories($allCategoryNames, $locale),
            'existingAttributeSets' => $this->loadAttributeSets($allAttributeNames, $locale),
            'existingAttributes' => $this->loadAttributes($allAttributeNames, $locale),
            'existingAttributeValues' => $this->loadAttributeValues($allAttributeValues, $locale),
            'existingVariations' => $this->loadVariations($allSizeValues, $allColorValues, $locale),
            'existingVariationValues' => $this->loadVariationValues($allSizeValues, $allColorValues, $locale),
            'existingVariants' => $this->loadVariants($allProductUrls),
            'existingProductVariations' => collect(),
        ];
    }

    private function loadProducts(array $product_urls, string $locale)
    {
        return Product::whereIn('product_url', $product_urls)
            ->with(['translations' => function ($query) use ($locale) {
                $query->where('locale', $locale);
            }])
            ->get()
            ->keyBy('product_url');
    }

    private function loadBrands(array $brandNames, string $locale)
    {
        return Brand::whereHas('translations', function ($query) use ($brandNames, $locale) {
            $query->where('locale', $locale)->whereIn('name', $brandNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->name ?? '';
        });
    }

    private function loadCategories(array $categoryNames, string $locale)
    {
        $categories = Category::whereHas('translations', function ($query) use ($categoryNames, $locale) {
            $query->where('locale', $locale)->whereIn('name', $categoryNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get();

        return $categories->groupBy(function ($item) use ($locale) {
            $name = $item->translate($locale)->name ?? '';
            return $name . '|' . ($item->parent_id ?? 'null');
        });
    }

    private function loadAttributeSets(array $attributeNames, string $locale)
    {
        return AttributeSet::whereHas('translations', function ($query) use ($attributeNames, $locale) {
            $query->where('locale', $locale)->whereIn('name', $attributeNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->name ?? '';
        });
    }

    private function loadAttributes(array $attributeNames, string $locale)
    {
        return Attribute::whereHas('translations', function ($query) use ($attributeNames, $locale) {
            $query->where('locale', $locale)->whereIn('name', $attributeNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->name ?? '';
        });
    }

    private function loadAttributeValues(array $attributeValues, string $locale)
    {
        return AttributeValue::whereHas('translations', function ($query) use ($attributeValues, $locale) {
            $query->where('locale', $locale)->whereIn('value', $attributeValues);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->value ?? '';
        });
    }

    private function loadVariations(array $sizeValues, array $colorValues, string $locale)
    {
        if (empty($sizeValues) && empty($colorValues)) {
            return collect();
        }

        $variationNames = [];
        if (!empty($sizeValues)) $variationNames[] = 'Size';
        if (!empty($colorValues)) $variationNames[] = 'Color';

        return DB::table('variations')
            ->join('variation_translations', 'variations.id', '=', 'variation_translations.variation_id')
            ->whereIn('variation_translations.name', $variationNames)
            ->where('variation_translations.locale', $locale)
            ->where('variations.is_global', false)
            ->select('variations.*', 'variation_translations.name as variation_name')
            ->get()
            ->keyBy('variation_name');
    }

    private function loadVariationValues(array $sizeValues, array $colorValues, string $locale)
    {
        if (empty($sizeValues) && empty($colorValues)) {
            return collect();
        }

        $allVariationValues = array_merge($sizeValues, $colorValues);

        $variationValuesCollection = DB::table('variation_values')
            ->join('variation_value_translations', 'variation_values.id', '=', 'variation_value_translations.variation_value_id')
            ->whereIn('variation_value_translations.label', $allVariationValues)
            ->where('variation_value_translations.locale', $locale)
            ->select('variation_values.*', 'variation_value_translations.label')
            ->get();

        $result = collect();
        foreach ($variationValuesCollection as $vv) {
            $key = $vv->variation_id . '|' . $vv->label;
            $result->put($key, $vv);
        }

        return $result;
    }

    private function loadVariants(array $allProductUrls)
    {
        $products = Product::whereIn('product_url', $allProductUrls)->pluck('id');

        if ($products->isEmpty()) {
            return collect();
        }

        return DB::table('product_variants')
            ->whereIn('product_id', $products)
            ->get()
            ->keyBy('sku');
    }

    private function createProduct(
        array $productData,
        string $gender,
        &$existingProducts,
        &$existingBrands,
        &$categoriesByNameAndParent,
        &$existingAttributeSets,
        &$existingAttributes,
        &$existingAttributeValues,
        &$existingVariations,
        &$existingVariationValues,
        &$existingVariants,
        &$existingProductVariations,
        &$existingFiles,
        &$productsToUpdate,
        &$variantsToUpdate
    ) {
        $categoryIds = $this->createCategory(
            $productData['categories'],
            $gender,
            $categoriesByNameAndParent
        );

        $attributeIds = $this->createAttribute(
            $productData['attributes'],
            $productData['gender'],
            $existingAttributeSets,
            $existingAttributes,
            $existingAttributeValues,
            $productData['brand'] ?? null
        );

        $brand = $existingBrands->get($productData['brand']);
        $product = $existingProducts->get($productData['product_url']);

        if (!$product) {
            $product = new Product();
            $product->name = $productData['name'];
            $product->sku = $productData['sku'];
            $product->product_url = $productData['product_url'];
            $product->description = $productData['full_description'];
            $product->short_description = $productData['description'];
            $product->price = $productData['price'];
            $product->special_price = $productData['special_price'];
            $product->special_price_start = $productData['special_price'] ? now()->format('Y-m-d') : null;
            $product->special_price_end = $productData['special_price'] ? $productData['price_valid_until'] : null;
            $product->special_price_type = $productData['special_price'] ? 'fixed' : null;
            $product->in_stock = $productData['in_stock'];
            $product->brand_id = $brand?->id;
            $product->is_active = true;
            $product->save();

            $product->selling_price = $productData['special_price'] ?: $productData['price'];
            $product->save();

            $existingProducts->put($product->product_url, $product);
        } else {
            $productsToUpdate[] = [
                'id' => $product->id,
                'name' => $productData['name'],
                'description' => $productData['full_description'],
                'short_description' => $productData['description'],
                'price' => $productData['price'],
                'special_price' => $productData['special_price'],
                'special_price_start' => $productData['special_price'] ? now()->format('Y-m-d') : null,
                'special_price_end' => $productData['special_price'] ? $productData['price_valid_until'] : null,
                'special_price_type' => $productData['special_price'] ? 'fixed' : null,
                'selling_price' => $productData['special_price'] ?: $productData['price'],
                'in_stock' => $productData['in_stock'],
                'product_url' => $productData['product_url'],
                'brand_id' => $brand?->id,
            ];
        }

        $this->syncProductAttributes($product->id, $attributeIds);

        $product->categories()->sync($categoryIds->unique()->values()->toArray());

        $this->attachImages($product->id, $productData['images'], $existingFiles);

        if (!empty($productData['attributes'])) {
            $this->processProductVariants(
                $product,
                $productData,
                $existingVariations,
                $existingVariationValues,
                $existingVariants,
                $existingFiles,
                $variantsToUpdate
            );
        }

        $this->syncVariantImagesToProduct($product->id);
    }

    private function syncProductAttributes($productId, $attributeIds)
    {
        $existingProductAttributes = ProductAttribute::where('product_id', $productId)
            ->whereIn('attribute_id', $attributeIds->keys())
            ->get()
            ->keyBy('attribute_id');

        $existingProductAttributeIds = $existingProductAttributes->pluck('id')->toArray();
        $existingProductAttributeValues = [];

        if (!empty($existingProductAttributeIds)) {
            $existingProductAttributeValues = ProductAttributeValue::whereIn('product_attribute_id', $existingProductAttributeIds)
                ->get()
                ->groupBy('product_attribute_id');
        }

        $productAttributeValuesToInsert = [];
        $insertedCombinations = [];

        foreach ($attributeIds as $attributeId => $valueMap) {
            $productAttribute = $existingProductAttributes->get($attributeId);

            if (!$productAttribute) {
                $productAttribute = new ProductAttribute();
                $productAttribute->product_id = $productId;
                $productAttribute->attribute_id = $attributeId;
                $productAttribute->save();
                $existingProductAttributes->put($attributeId, $productAttribute);
            }

            $productAttributeId = $productAttribute->id;
            $existingValues = $existingProductAttributeValues[$productAttributeId] ?? collect();
            $existingValueIds = $existingValues->pluck('attribute_value_id')->toArray();

            foreach ($valueMap as $value => $attributeValueId) {
                $combinationKey = $productAttributeId . '-' . $attributeValueId;

                if (!in_array($attributeValueId, $existingValueIds) && !isset($insertedCombinations[$combinationKey])) {
                    $productAttributeValuesToInsert[] = [
                        'product_attribute_id' => $productAttributeId,
                        'attribute_value_id' => $attributeValueId,
                    ];
                    $insertedCombinations[$combinationKey] = true;
                }
            }
        }

        if (!empty($productAttributeValuesToInsert)) {
            ProductAttributeValue::insert($productAttributeValuesToInsert);
        }
    }

    private function processProductVariants(
        $product,
        array $productData,
        &$existingVariations,
        &$existingVariationValues,
        &$existingVariants,
        &$existingFiles,
        &$variantsToUpdate
    ) {
        $sizeValues = [];
        $colorValues = [];

        foreach ($productData['attributes'] as $attrName => $attrData) {
            $values = $attrData['values'] ?? $attrData;

            if (strtolower($attrName) === 'size' && is_array($values)) {
                $sizeValues = $values;
            } elseif (strtolower($attrName) === 'color' && is_array($values)) {
                $colorValues = $values;
            }
        }

        $variationIds = [];

        if (!empty($sizeValues)) {
            $sizeVariationId = $this->createProductVariation('Size', $product->id, $existingVariations);
            $variationIds['size'] = $sizeVariationId;

            foreach ($sizeValues as $sizeData) {
                $this->createProductVariationValue(
                    $sizeData['value'],
                    $sizeVariationId,
                    $sizeData['outlet_value_id'] ?? null,
                    $existingVariationValues
                );
            }
        }

        if (!empty($colorValues)) {
            $colorVariationId = $this->createProductVariation('Color', $product->id, $existingVariations);
            $variationIds['color'] = $colorVariationId;

            foreach ($colorValues as $colorData) {
                $this->createProductVariationValue(
                    $colorData['value'],
                    $colorVariationId,
                    $colorData['outlet_value_id'] ?? null,
                    $existingVariationValues
                );
            }
        }

        if (!empty($sizeValues) && !empty($colorValues)) {
            foreach ($sizeValues as $sizeData) {
                foreach ($colorValues as $colorData) {
                    $this->createSingleVariant(
                        $product,
                        $productData,
                        $sizeData,
                        $colorData,
                        $variationIds,
                        $existingVariationValues,
                        $existingVariants,
                        $existingFiles,
                        $variantsToUpdate
                    );
                }
            }
        } elseif (!empty($sizeValues)) {
            foreach ($sizeValues as $sizeData) {
                $this->createSingleVariant(
                    $product,
                    $productData,
                    $sizeData,
                    null,
                    $variationIds,
                    $existingVariationValues,
                    $existingVariants,
                    $existingFiles,
                    $variantsToUpdate
                );
            }
        } elseif (!empty($colorValues)) {
            foreach ($colorValues as $colorData) {
                $this->createSingleVariant(
                    $product,
                    $productData,
                    null,
                    $colorData,
                    $variationIds,
                    $existingVariationValues,
                    $existingVariants,
                    $existingFiles,
                    $variantsToUpdate
                );
            }
        }
    }

    private function createSingleVariant(
        $product,
        array $productData,
        $sizeData,
        $colorData,
        array $variationIds,
        &$existingVariationValues,
        &$existingVariants,
        &$existingFiles,
        &$variantsToUpdate
    ) {
        $variationValueIds = [];
        $combinedName = '';
        $available = true;
        $outletValueIds = [];

        if ($sizeData) {
            $sizeValue = $sizeData['value'];
            $sizeVariationValueId = $this->getVariationValueId($sizeValue, $variationIds['size'], $existingVariationValues);
            if ($sizeVariationValueId) {
                $variationValueIds[] = $sizeVariationValueId;
                $combinedName = $sizeValue;
                $available = $available && ($sizeData['available'] ?? true);
                if (isset($sizeData['outlet_value_id'])) {
                    $outletValueIds[] = $sizeData['outlet_value_id'];
                }
            }
        }

        if ($colorData) {
            $colorValue = $colorData['value'];
            $colorVariationValueId = $this->getVariationValueId($colorValue, $variationIds['color'], $existingVariationValues);
            if ($colorVariationValueId) {
                $variationValueIds[] = $colorVariationValueId;
                $combinedName = $combinedName ? "{$combinedName} - {$colorValue}" : $colorValue;
                $available = $available && ($colorData['available'] ?? true);
                if (isset($colorData['outlet_value_id'])) {
                    $outletValueIds[] = $colorData['outlet_value_id'];
                }
            }
        }

        if (empty($variationValueIds)) {
            return;
        }

        $outletVariationId = $this->findOutletVariationId(
            $productData['variants'] ?? [],
            ...$outletValueIds
        );

        $this->createProductVariant(
            $product->id,
            $combinedName,
            $available,
            $variationValueIds,
            $productData['price'],
            $productData['special_price'],
            $productData['price_valid_until'],
            $product->sku,
            $existingVariants,
            $outletVariationId,
            $outletVariationId,
            $productData,
            $existingFiles,
            $variantsToUpdate
        );
    }

    protected function createProductVariation($variationType, $productId, &$existingVariations = null )
    {
        $existingVariation = DB::table('product_variations')
            ->join('variations', 'product_variations.variation_id', '=', 'variations.id')
            ->join('variation_translations', 'variations.id', '=', 'variation_translations.variation_id')
            ->where('product_variations.product_id', $productId)
            ->where('variation_translations.name', $variationType)
            ->where('variation_translations.locale', 'en')
            ->select('variations.id')
            ->first();

        if ($existingVariation) {
            if ($existingVariations !== null) {
                $variation = (object)[
                    'id' => $existingVariation->id,
                    'variation_name' => $variationType,
                ];
                $existingVariations->put($variationType, $variation);
            }
            return $existingVariation->id;
        }

        $maxPosition = DB::table('product_variations')
            ->join('variations', 'product_variations.variation_id', '=', 'variations.id')
            ->where('product_variations.product_id', $productId)
            ->max('variations.position');

        $nextPosition = ($maxPosition ?? 0) + 1;

        $variationId = DB::table('variations')->insertGetId([
            'uid' => $this->generateUid(),
            'type' => 'text',
            'is_global' => false,
            'position' => $nextPosition,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('variation_translations')->insert([
            'variation_id' => $variationId,
            'locale' => 'en',
            'name' => $variationType,
        ]);

        DB::table('product_variations')->insert([
            'product_id' => $productId,
            'variation_id' => $variationId,
        ]);

        if ($existingVariations !== null) {
            $variation = (object)[
                'id' => $variationId,
                'variation_name' => $variationType,
            ];
            $existingVariations->put($variationType, $variation);
        }

        return $variationId;
    }
    protected function createProductVariationValue($label, $variationId,$outletValueId = null, &$existingVariationValues = null)
    {
        $existingValue = DB::table('variation_values')
            ->join('variation_value_translations', 'variation_values.id', '=', 'variation_value_translations.variation_value_id')
            ->where('variation_values.variation_id', $variationId)
            ->where('variation_value_translations.label', $label)
            ->where('variation_value_translations.locale', 'en')
            ->select(['variation_values.id','variation_values.outlet_value_id'])
            ->first();

        if ($existingValue) {
            if ($outletValueId && ($existingValue->outlet_value_id !== null && $existingValue->outlet_value_id != $outletValueId)) {
                DB::table('variation_values')
                    ->where('id', $existingValue->id)
                    ->update([
                        'outlet_value_id' => $outletValueId,
                        'updated_at' => now(),
                    ]);
            }

            if ($existingVariationValues !== null) {
                $key = $variationId . '|' . $label;
                $variationValue = (object)[
                    'id' => $existingValue->id,
                    'variation_id' => $variationId,
                    'label' => $label,
                    'outlet_value_id' => $outletValueId ?? $existingValue->outlet_value_id,
                ];
                $existingVariationValues->put($key, $variationValue);
            }
            return $existingValue->id;
        }

        $position = DB::table('variation_values')
                ->where('variation_id', $variationId)
                ->count() + 1;

        $variationValueId = DB::table('variation_values')->insertGetId([
            'uid' => $this->generateUid(),
            'variation_id' => $variationId,
            'value' => $label,
            'position' => $position,
            'outlet_value_id' => $outletValueId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('variation_value_translations')->insert([
            'variation_value_id' => $variationValueId,
            'locale' => 'en',
            'label' => $label,
        ]);

        if ($existingVariationValues !== null) {
            $key = $variationId . '|' . $label;
            $variationValue = (object)[
                'id' => $variationValueId,
                'variation_id' => $variationId,
                'label' => $label,
                'outlet_value_id' => $outletValueId,
            ];
            $existingVariationValues->put($key, $variationValue);
        }

        return $variationValueId;
    }
    protected function getVariationValueId($label, $variationId, $existingVariationValues)
    {
        $key = $variationId . '|' . $label;
        $variationValue = $existingVariationValues->get($key);

        if ($variationValue) {
            return $variationValue->id;
        }

        return DB::table('variation_values')
            ->join('variation_value_translations', 'variation_values.id', '=', 'variation_value_translations.variation_value_id')
            ->where('variation_values.variation_id', $variationId)
            ->where('variation_value_translations.label', $label)
            ->where('variation_value_translations.locale', 'en')
            ->value('variation_values.id');
    }

    protected function createProductVariant(
        $productId,
        $name,
        $available,
        $variationValueIds,
        $price,
        $specialPrice,
        $specialPriceEnd,
        $baseSku,
        &$existingVariants,
        $outletVariationId = null,
        $outletVariationIdForImages = null,
        $productData = [],
        &$existingFiles = null,
        &$variantsToUpdate = null
    ) {
        $variationValueIds = array_unique($variationValueIds);

        $skuSuffix = str_replace([' ', '.', '/', '-'], ['_', '_', '_', '_'], $name);
        $variantSku = $baseSku . '_' . $skuSuffix;

        $dbVariant = $existingVariants->get($variantSku);

        $variationData = null;
        $stockNet = 0;

        if ($outletVariationIdForImages) {
            $variationData = $this->scrapeVariationDetails($outletVariationIdForImages);
            if ($variationData) {
                $stockNet = $variationData['stock_net'] ?? 0;
            }
        }

        if ($dbVariant) {
            if ($variantsToUpdate !== null) {
                $variantsToUpdate[] = [
                    'id' => $dbVariant->id,
                    'in_stock' => $outletVariationId ? 1 : 0,
                    'manage_stock' => $stockNet !== 0 ? 1 : 0,
                    'qty' => $stockNet,
                    'price' => $price,
                    'special_price' => $specialPrice,
                    'special_price_type' => $specialPrice ? 'fixed' : null,
                    'special_price_start' => $specialPrice ? now()->format('Y-m-d H:i:s') : null,
                    'special_price_end' => $specialPrice ? $specialPriceEnd : null,
                    'selling_price' => $specialPrice ?: $price,
                    'outlet_variation_id' => $outletVariationId,
                ];
            }

            if ($variationData && !empty($variationData['images'])) {
                $this->attachVariationImages($dbVariant->id, $variationData['images'], $existingFiles);
            } else {
                $productImages = $productData['images']['additional'] ?? [];
                if (!empty($productImages)) {
                    $this->attachAdditionalImages($productId, $productImages, $existingFiles);
                }
            }

            return $dbVariant->id;
        }

        $variationValueUids = DB::table('variation_values')
            ->whereIn('id', $variationValueIds)
            ->pluck('uid')
            ->toArray();

        if (empty($variationValueUids)) {
            $this->error("No variation value UIDs found for IDs: " . implode(',', $variationValueIds));
            return null;
        }

        $uids = implode('.', $variationValueUids);

        $existingVariantCount = DB::table('product_variants')
            ->where('product_id', $productId)
            ->count();

        $currentDefault = DB::table('product_variants')
            ->where('product_id', $productId)
            ->where('is_default', 1)
            ->first();

        $newInStock = $outletVariationId ? 1 : 0;
        $makeDefault = false;

        if ($existingVariantCount === 0) {
            $makeDefault = true;
        } else {
            if ($currentDefault && (int) $currentDefault->in_stock === 0 && $newInStock === 1) {
                $makeDefault = true;
            }
            if (!$currentDefault) {
                $makeDefault = true;
            }
        }

        try {
            $variantId = DB::table('product_variants')->insertGetId([
                'uid' => $this->generateUid(),
                'uids' => $uids,
                'product_id' => $productId,
                'name' => $name,
                'price' => $price,
                'special_price' => $specialPrice,
                'special_price_type' => $specialPrice ? 'fixed' : null,
                'special_price_start' => $specialPrice ? now()->format('Y-m-d') : null,
                'special_price_end' => $specialPrice ? $specialPriceEnd : null,
                'selling_price' => $specialPrice ?: $price,
                'sku' => $variantSku,
                'manage_stock' => $stockNet !== 0 ? 1 : 0,
                'qty' => $stockNet,
                'in_stock' => $newInStock,
                'is_default' => $makeDefault ? 1 : 0,
                'is_active' => true,
                'outlet_variation_id' => $outletVariationId,
                'position' => $existingVariantCount + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($makeDefault && $currentDefault && $currentDefault->id !== $variantId) {
                DB::table('product_variants')
                    ->where('id', $currentDefault->id)
                    ->update(['is_default' => 0, 'updated_at' => now()]);
            }

            $defaults = DB::table('product_variants')
                ->where('product_id', $productId)
                ->where('is_default', 1)
                ->pluck('id')
                ->toArray();

            if (count($defaults) > 1) {
                $winner = DB::table('product_variants')
                    ->where('product_id', $productId)
                    ->orderByDesc('in_stock')
                    ->orderBy('position')
                    ->first(['id']);

                DB::table('product_variants')
                    ->where('product_id', $productId)
                    ->where('id', '!=', $winner->id)
                    ->where('is_default', 1)
                    ->update(['is_default' => 0, 'updated_at' => now()]);
            }

            $newVariant = (object)[
                'id' => $variantId,
                'product_id' => $productId,
                'sku' => $variantSku,
                'in_stock' => $available,
                'outlet_variation_id' => $outletVariationId,
            ];
            $existingVariants->put($variantSku, $newVariant);

            if ($variationData) {
                if (!empty($variationData['images'])) {
                    $this->attachVariationImages($variantId, $variationData['images'], $existingFiles);
                } else {
                    $productImages = $productData['images']['additional'] ?? [];
                    if (!empty($productImages)) {
                        $this->attachAdditionalImages($productId, $productImages, $existingFiles);
                    }
                }
            } else {
                $productImages = $productData['images']['additional'] ?? [];
                if (!empty($productImages)) {
                    $this->attachAdditionalImages($productId, $productImages, $existingFiles);
                }
            }


            return $variantId;


        } catch (\Exception $e) {
            $this->error("Error creating variant: " . $e->getMessage());
            Log::error("Variant creation failed", [
                'product_id' => $productId,
                'sku' => $variantSku,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
    private function getCategoryWithAllChildren($categoryId)
    {
        $ids = collect([$categoryId]);

        $children = Category::where('parent_id', $categoryId)->pluck('id');

        foreach ($children as $childId) {
            $ids = $ids->merge($this->getCategoryWithAllChildren($childId));
        }

        return $ids;
    }

    private function createAttribute(
        array $attributes,
        string $gender,
        $existingAttributeSets,
        $existingAttributes,
        $existingAttributeValues,
        string $brandName = null
    ) {
        $attributeIds = collect();

        foreach ($attributes as $attributeName => $attributeValues) {
            $label = ucfirst($attributeName);

            $attributeSet = $existingAttributeSets->first(function ($item) use ($label) {
                return strcasecmp($item->translate('en')->name ?? '', $label) === 0;
            });

            if (!$attributeSet) {
                $attributeSet = new AttributeSet();
                $attributeSet->name = $label;
                $attributeSet->save();
                $existingAttributeSets->put($label, $attributeSet);
            }

            $attribute = $existingAttributes->first(function ($item) use ($label) {
                return strcasecmp($item->translate('en')->name ?? '', $label) === 0;
            });

            if (!$attribute) {
                $attribute = new Attribute();
                $attribute->attribute_set_id = $attributeSet->id;
                $attribute->is_filterable = true;
                $attribute->name = $label;
                $attribute->save();
                $existingAttributes->put($label, $attribute);
            }

            $genderCategory = Category::whereTranslation('name', $gender)->first();
            if ($genderCategory) {
                $allCategoryIds = $this->getCategoryWithAllChildren($genderCategory->id);
                $attribute->categories()->sync($allCategoryIds->toArray(),false);
            }

            $valueMap = [];
            $values = $attributeValues['values'] ?? $attributeValues;
            if (is_array($values)) {
                foreach ($values as $attributeValue) {
                    $value = ucfirst($attributeValue['value']);

                    $attributeVal = $existingAttributeValues->first(function ($item) use ($value) {
                        return strcasecmp($item->translate('en')->value ?? '', $value) === 0;
                    });

                    if (!$attributeVal) {
                        $attributeVal = new AttributeValue();
                        $attributeVal->attribute_id = $attribute->id;
                        $attributeVal->position = 1;
                        $attributeVal->value = $value;
                        $attributeVal->save();
                        $existingAttributeValues->put($value, $attributeVal);
                    }

                    $valueMap[$value] = $attributeVal->id;
                }
            }

            $attributeIds->put($attribute->id, $valueMap);
        }

        $genderLabel = 'Gender';
        $genderValue = ucfirst($gender);

        $genderAttributeSet = $existingAttributeSets->first(function ($item) use ($genderLabel) {
            return strcasecmp($item->translate('en')->name ?? '', $genderLabel) === 0;
        });

        if (!$genderAttributeSet) {
            $genderAttributeSet = new AttributeSet();
            $genderAttributeSet->name = $genderLabel;
            $genderAttributeSet->save();
            $existingAttributeSets->put($genderLabel, $genderAttributeSet);
        }

        $genderAttribute = $existingAttributes->first(function ($item) use ($genderLabel) {
            return strcasecmp($item->translate('en')->name ?? '', $genderLabel) === 0;
        });

        if (!$genderAttribute) {
            $genderAttribute = new Attribute();
            $genderAttribute->attribute_set_id = $genderAttributeSet->id;
            $genderAttribute->is_filterable = true;
            $genderAttribute->name = $genderLabel;
            $genderAttribute->save();
            $existingAttributes->put($genderLabel, $genderAttribute);
        }

        $genderAttributeVal = $existingAttributeValues->first(function ($item) use ($genderValue) {
            return strcasecmp($item->translate('en')->value ?? '', $genderValue) === 0;
        });

        if (!$genderAttributeVal) {
            $genderAttributeVal = new AttributeValue();
            $genderAttributeVal->attribute_id = $genderAttribute->id;
            $genderAttributeVal->position = 1;
            $genderAttributeVal->value = $genderValue;
            $genderAttributeVal->save();
            $existingAttributeValues->put($genderValue, $genderAttributeVal);
        }

        $attributeIds->put($genderAttribute->id, [$genderValue => $genderAttributeVal->id]);

        if (!empty($brandName)) {
            $brandLabel = 'Brand';
            $brandValue = ucfirst($brandName);

            $brandAttributeSet = $existingAttributeSets->first(function ($item) use ($brandLabel) {
                return strcasecmp($item->translate('en')->name ?? '', $brandLabel) === 0;
            });

            if (!$brandAttributeSet) {
                $brandAttributeSet = new AttributeSet();
                $brandAttributeSet->name = $brandLabel;
                $brandAttributeSet->save();
                $existingAttributeSets->put($brandLabel, $brandAttributeSet);
            }

            $brandAttribute = $existingAttributes->first(function ($item) use ($brandLabel) {
                return strcasecmp($item->translate('en')->name ?? '', $brandLabel) === 0;
            });

            if (!$brandAttribute) {
                $brandAttribute = new Attribute();
                $brandAttribute->attribute_set_id = $brandAttributeSet->id;
                $brandAttribute->is_filterable = true;
                $brandAttribute->name = $brandLabel;
                $brandAttribute->save();
                $existingAttributes->put($brandLabel, $brandAttribute);
            }

            $brandAttributeVal = $existingAttributeValues->first(function ($item) use ($brandValue) {
                return strcasecmp($item->translate('en')->value ?? '', $brandValue) === 0;
            });

            if (!$brandAttributeVal) {
                $brandAttributeVal = new AttributeValue();
                $brandAttributeVal->attribute_id = $brandAttribute->id;
                $brandAttributeVal->position = 1;
                $brandAttributeVal->value = $brandValue;
                $brandAttributeVal->save();
                $existingAttributeValues->put($brandValue, $brandAttributeVal);
            }

            $attributeIds->put($brandAttribute->id, [$brandValue => $brandAttributeVal->id]);
        }

        return $attributeIds;
    }

    private function createCategory(
        array $categories,
        string $gender,
        $categoriesByNameAndParent
    ) {
        $categoryIds = collect();

        $rootKey = strtolower($gender) . '|null';

        $rootCategory = $categoriesByNameAndParent->first(function ($items, $key) use ($gender) {
            $parts = explode('|', $key);
            return strcasecmp(trim($parts[0]), trim($gender)) === 0 && $parts[1] === 'null';
        })?->first();

        if (!$rootCategory) {
            $rootCategory = new Category();
            $rootCategory->position = 1;
            $rootCategory->is_active = true;
            $rootCategory->is_searchable = false;
            $rootCategory->name = $gender;
            $rootCategory->save();

            $categoriesByNameAndParent->put($rootKey, collect([$rootCategory]));
        }

        $parentCategory = $rootCategory;

        foreach ($categories as $index => $categoryName) {
            $categoryName = ucfirst($categoryName);

            if ($index === 0) {
                $categoryNameLower = strtolower($categoryName);
                $genderLower = strtolower($gender);

                $isOtherGender = false;
                $genderKeywords = ['men', 'women', 'children', 'kids', 'boys', 'girls'];

                foreach ($genderKeywords as $keyword) {
                    if (str_contains($categoryNameLower, $keyword) && !str_contains($genderLower, $keyword)) {
                        if (!($genderLower === 'men' && str_contains($categoryNameLower, 'women')) &&
                            !($genderLower === 'women' && str_contains($categoryNameLower, 'men'))) {
                            $isOtherGender = true;
                            break;
                        }
                    }
                }

                if ($isOtherGender) {
                    continue;
                }
            }

            if (strcasecmp(trim($categoryName), trim($gender)) === 0 && $parentCategory === $rootCategory) {
                continue;
            }

            $categoryKeyLower = strtolower($categoryName) . '|' . $parentCategory->id;

            $findCategory = $categoriesByNameAndParent->first(function ($items, $key) use ($categoryName, $parentCategory) {
                $parts = explode('|', $key);
                return strcasecmp($parts[0], $categoryName) === 0 &&
                    $parts[1] == $parentCategory->id;
            })?->first();

            if (!$findCategory) {
                $newCategory = new Category();
                $newCategory->position = 1;
                $newCategory->is_active = true;
                $newCategory->parent_id = $parentCategory->id;
                $newCategory->is_searchable = false;
                $newCategory->name = $categoryName;
                $newCategory->save();

                $findCategory = $newCategory;
                $categoriesByNameAndParent->put($categoryKeyLower, collect([$findCategory]));
            }

            $categoryIds->push($findCategory->id);
            $parentCategory = $findCategory;
        }

        $categoryIds->push($rootCategory->id);
        return $categoryIds;
    }

    private function generateUid()
    {
        $timestamp = base_convert((int)(microtime(true) * 1000) * rand(1, 1000), 10, 36);
        $randomPart = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyz'), 0, 6);

        return substr($timestamp . $randomPart, 0, 12);
    }

    private function findOutletVariationId(array $variants,$outletValueId1 = null, $outletValueId2 = null)
    {
        if(empty($variants)){
            return null;
        }
        foreach ($variants as $variant) {
            if (!isset($variant['attributes']) || !isset($variant['outlet_variation_id']))
            {
                continue;
            }

            $attributeValueIds = array_column($variant['attributes'], 'outlet_value_id');

            if ($outletValueId2 !== null){
                if(in_array($outletValueId1,$attributeValueIds) && in_array($outletValueId2,$attributeValueIds)){
                    return $variant['outlet_variation_id'];
                }
            }else{
                if(in_array($outletValueId1,$attributeValueIds)){
                    return $variant['outlet_variation_id'];
                }
            }
        }
        return null;
    }

    private function syncVariantImagesToProduct($productId)
    {
        $variantImages = DB::table('entity_files')
            ->join('product_variants', function($join) use ($productId) {
                $join->on('entity_files.entity_id', '=', 'product_variants.id')
                    ->where('product_variants.product_id', '=', $productId);
            })
            ->where('entity_files.entity_type', 'Modules\Product\Entities\ProductVariant')
            ->where('entity_files.zone', 'additional_images')
            ->pluck('entity_files.file_id')
            ->unique();

        if ($variantImages->isEmpty()) {
            return;
        }

        $existingProductImages = EntityFile::where('entity_type', 'Modules\Product\Entities\Product')
            ->where('entity_id', $productId)
            ->pluck('file_id');

        $newImageIds = $variantImages->diff($existingProductImages);

        if ($newImageIds->isEmpty()) {
            return;
        }

        $insertData = $newImageIds->map(function($fileId) use ($productId) {
            return [
                'file_id' => $fileId,
                'entity_type' => 'Modules\Product\Entities\Product',
                'entity_id' => $productId,
                'zone' => 'additional_images',
            ];
        })->toArray();

        EntityFile::insert($insertData);
    }

    private function validateProductData(array $productData): bool
    {
        $requiredFields = ['name', 'sku', 'brand', 'categories', 'price'];

        foreach ($requiredFields as $field) {
            if (empty($productData[$field])) {
                $this->error("Missing required field: {$field} for product SKU: " . ($productData['sku'] ?? 'unknown'));
                Log::warning("Product validation failed", ['missing_field' => $field, 'sku' => $productData['sku'] ?? 'unknown']);
                return false;
            }
        }

        if (empty($productData['attributes']) && empty($productData['images']) && empty($productData['variants'])) {
            $this->error("Product has no attributes or images: " . ($productData['sku'] ?? 'unknown'));
            return false;
        }

        return true;
    }

    private function attachMissingVariantImages(array $variantsToUpdate, array $productData, &$existingFiles)
    {
        foreach ($variantsToUpdate as $variantUpdate) {
            $variantId = $variantUpdate['id'];
            $outletVariationId = $variantUpdate['outlet_variation_id'] ?? null;

            if (!$outletVariationId) {
                continue;
            }

            $existingImages = EntityFile::where('entity_type', 'Modules\Product\Entities\ProductVariant')
                ->where('entity_id', $variantId)
                ->where('zone', 'additional_images')
                ->exists();

            if ($existingImages) {
                continue;
            }

            $variationData = $this->scrapeVariationDetails($outletVariationId);

            if ($variationData && !empty($variationData['images'])) {
                $this->attachVariationImages($variantId, $variationData['images'], $existingFiles);
            }
        }
    }
}
