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
use Symfony\Component\DomCrawler\Crawler;

class ScrapeOutlet46Products extends Command
{
    protected $signature = 'scrape:products';

    protected $description = 'Create products from Outlet46';

    private const BASE_URL = 'https://www.outlet46.de';

    public function handle()
    {
        $this->scrapeMenProductLinks();
        $this->scrapeWomenProductLinks();
        $this->scrapeKidsProductLinks();

        return self::SUCCESS;
    }

    public function scrapeMenProductLinks()
    {
        $links = $this->scrapeByFacet(727, 'Men');

        $batchSize = 50;
        $batches = array_chunk($links, $batchSize);

        foreach ($batches as $batchLinks) {
            $this->processBatchProducts($batchLinks, 'Children');
        }
    }

    public function scrapeWomenProductLinks()
    {
        $links = $this->scrapeByFacet(726, 'Women');

        $batchSize = 50;
        $batches = array_chunk($links, $batchSize);

        foreach ($batches as $batchLinks) {
            $this->processBatchProducts($batchLinks, 'Children');
        }
    }


    public function scrapeKidsProductLinks()
    {
        $links = $this->scrapeByFacet(728, 'Kids');

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

        } while (!empty($links));

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

            $productData = [
                'name' => $jsonLd['name'] ? html_entity_decode(html_entity_decode($jsonLd['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                'sku' => $jsonLd['sku'] ?? '',
                'brand' => $jsonLd['brand']['name'] ?? '',
                'description' => $this->cleanDescription($jsonLd['description'] ?? ''),
/*                'gtin13' => $jsonLd['gtin13'] ?? '',*/

                'categories' => $categories,
                'gender' => $gender,

                'price' => $prices['price'] ?? 0,
                'special_price' => $prices['special_price'] ?? null,
                'price_valid_until' => $jsonLd['offers']['priceValidUntil'] ?? null,
                'currency' => $jsonLd['offers']['priceCurrency'] ?? 'EUR',

                'in_stock' => $this->isInStock($jsonLd['offers']['availability'] ?? ''),
                'condition' => $this->getCondition($jsonLd['offers']['itemCondition'] ?? ''),

                'attributes' => $attributes,

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

                    $listItems = $group->filter('[data-eg-attributes] li');
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

                            $id = $node->attr('data-eg-value');

                            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $text, $m)) {
                                $start = (int) $m[1];
                                $end   = (int) $m[2];
                                    for ($i = $start; $i <= $end; $i++) {
                                        $values[] = [
                                            'value'      => (string) $i,
                                            'available'  => $available,
                                            'id'         => $id,
                                        ];
                                    }
                                    return;
                            }

                            $values[] = [
                                'value'      => $text,
                                'available'  => $available,
                                'id'         => $id,
                            ];
                        } catch (\Throwable $e) {
                            Log::error('Error processing attribute li: ' . $e->getMessage());
                        }
                    });

                    if (!empty($values)) {
                        $attributes[$attributeName] = $values;
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

        try {
            $mainImage = $crawler->filter('#ItemImgZoom img');
            if ($mainImage->count() > 0) {
                $images['main'] = $mainImage->attr('src');
            }

            $thumbnails = $crawler->filter('.single-prev-images a');
            $additionalImages = $thumbnails->each(function (Crawler $node) {
                return $node->attr('href');
            });

            $images['additional'] = array_filter($additionalImages);

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


    private function downloadImage($imageUrl)
    {
        try {
            $fileName = basename(parse_url($imageUrl, PHP_URL_PATH));
            $existingFile = File::where('filename', $fileName)
                ->where('disk', config('filesystems.default'))
                ->first();

            if ($existingFile) {
                return $existingFile->id;
            }

            $contents = file_get_contents($imageUrl);
            $fileType = pathinfo($fileName, PATHINFO_EXTENSION);

            if (empty($fileType)) {
                $fileType = match($fileType) {
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

            return $file->id;
        } catch (\Exception $e) {
            Log::error("Error downloading image: " . $e->getMessage());
            return null;
        }
    }

    private function attachImages($productId, $images)
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
        if (!empty($additionalImages)) {
            $imagesToDownload['additional_images'] = $additionalImages;
        }

        $newEntityFiles = [];
        foreach ($imagesToDownload as $zone => $imageUrls) {
            foreach ($imageUrls as $imageUrl) {
                $fileId = $this->downloadImage($imageUrl);
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

    private function processBatchProducts(array $links, string $gender)
    {
        $productsData = [];
        foreach ($links as $link) {
            $productData = $this->scrapeProductDetails($link, $gender);
            if ($productData) {
                $productsData[] = $productData;
            }
        }

        if (empty($productsData)) {
            return;
        }

        $allSkus = array_column($productsData, 'sku');
        $allBrandNames = array_unique(array_column($productsData, 'brand'));
        $allCategoryNames = [];
        $allAttributeNames = [];
        $allAttributeValues = [];
        $allSizeValues = [];
        $allColorValues = [];

        foreach ($productsData as $productData) {
            if (!empty($productData['categories'])) {
                foreach ($productData['categories'] as $cat) {
                    $allCategoryNames[] = ucfirst($cat);
                }
            }

            if (!empty($productData['attributes'])) {
                foreach ($productData['attributes'] as $attrName => $attrValues) {
                    $allAttributeNames[] = ucfirst($attrName);
                    if (is_array($attrValues)) {
                        foreach ($attrValues as $val) {
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

        $allAttributeNames[] = 'Gender';
        $allAttributeValues[] = ucfirst($gender);
        $allAttributeNames[] = 'Brand';
        foreach ($allBrandNames as $brandName) {
            if (!empty($brandName)) {
                $allAttributeValues[] = ucfirst($brandName);
            }
        }
        $allCategoryNames[] = $gender;

        $allCategoryNames = array_unique($allCategoryNames);
        $allAttributeNames = array_unique($allAttributeNames);
        $allAttributeValues = array_unique($allAttributeValues);
        $allSizeValues = array_unique($allSizeValues);
        $allColorValues = array_unique($allColorValues);

        $locale = 'en';

        $existingProducts = Product::whereIn('sku', $allSkus)
            ->with(['translations' => function ($query) use ($locale) {
                $query->where('locale', $locale);
            }])
            ->get()
            ->keyBy('sku');

        $existingBrands = Brand::whereHas('translations', function ($query) use ($allBrandNames, $locale) {
            $query->where('locale', $locale)
                ->whereIn('name', $allBrandNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->name ?? '';
        });

        $existingCategories = Category::whereHas('translations', function ($query) use ($allCategoryNames, $locale) {
            $query->where('locale', $locale)
                ->whereIn('name', $allCategoryNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get();

        $categoriesByNameAndParent = $existingCategories->groupBy(function ($item) use ($locale) {
            $name = $item->translate($locale)->name ?? '';
            return $name . '|' . ($item->parent_id ?? 'null');
        });

        $existingAttributeSets = AttributeSet::whereHas('translations', function ($query) use ($allAttributeNames, $locale) {
            $query->where('locale', $locale)
                ->whereIn('name', $allAttributeNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->name ?? '';
        });

        $existingAttributes = Attribute::whereHas('translations', function ($query) use ($allAttributeNames, $locale) {
            $query->where('locale', $locale)
                ->whereIn('name', $allAttributeNames);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->name ?? '';
        });

        $existingAttributeValues = AttributeValue::whereHas('translations', function ($query) use ($allAttributeValues, $locale) {
            $query->where('locale', $locale)
                ->whereIn('value', $allAttributeValues);
        })->with(['translations' => function ($query) use ($locale) {
            $query->where('locale', $locale);
        }])->get()->keyBy(function ($item) use ($locale) {
            return $item->translate($locale)->value ?? '';
        });

        $existingVariations = collect();
        if (!empty($allSizeValues) || !empty($allColorValues)) {
            $variationNames = [];
            if (!empty($allSizeValues)) $variationNames[] = 'Size';
            if (!empty($allColorValues)) $variationNames[] = 'Color';

            $existingVariations = DB::table('variations')
                ->join('variation_translations', 'variations.id', '=', 'variation_translations.variation_id')
                ->whereIn('variation_translations.name', $variationNames)
                ->where('variation_translations.locale', $locale)
                ->where('variations.is_global', false)
                ->select('variations.*', 'variation_translations.name as variation_name')
                ->get()
                ->keyBy('variation_name');
        }

        $existingVariationValues = collect();
        if (!empty($allSizeValues) || !empty($allColorValues)) {
            $allVariationValues = array_merge($allSizeValues, $allColorValues);

            $variationValuesCollection = DB::table('variation_values')
                ->join('variation_value_translations', 'variation_values.id', '=', 'variation_value_translations.variation_value_id')
                ->whereIn('variation_value_translations.label', $allVariationValues)
                ->where('variation_value_translations.locale', $locale)
                ->select('variation_values.*', 'variation_value_translations.label')
                ->get();

            foreach ($variationValuesCollection as $vv) {
                $key = $vv->variation_id . '|' . $vv->label;
                $existingVariationValues->put($key, $vv);
            }
        }

        $existingVariants = collect();
        if ($existingProducts->isNotEmpty()) {
            $variantsCollection = DB::table('product_variants')
                ->whereIn('product_id', $existingProducts->pluck('id'))
                ->get();

            foreach ($variantsCollection as $variant) {
                $existingVariants->put($variant->sku, $variant);
            }
        }

        $existingProductVariations = collect();
        if ($existingProducts->isNotEmpty()) {
            $productVariationsCollection = DB::table('product_variations')
                ->whereIn('product_id', $existingProducts->pluck('id'))
                ->get();

            foreach ($productVariationsCollection as $pv) {
                $key = $pv->product_id . '|' . $pv->variation_id;
                $existingProductVariations->put($key, $pv);
            }
        }

        foreach ($productsData as $productData) {
            $this->createProduct(
                $productData,
                $gender,
                $existingProducts,
                $existingBrands,
                $categoriesByNameAndParent,
                $existingAttributeSets,
                $existingAttributes,
                $existingAttributeValues,
                $existingVariations,
                $existingVariationValues,
                $existingVariants,
                $existingProductVariations
            );
        }
    }
    private function createProduct(
        array $productData,
        string $gender,
        $existingProducts,
        $existingBrands,
        $categoriesByNameAndParent,
        $existingAttributeSets,
        $existingAttributes,
        $existingAttributeValues,
        $existingVariations,
        $existingVariationValues,
        $existingVariants,
        $existingProductVariations
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

        $product = $existingProducts->get($productData['sku']);
        $isNewProduct = !$product;

        if (!$product) {
            $product = new Product();
        }

        $product->name = $productData['name'];
        $product->sku = $productData['sku'];
        $product->description = $productData['full_description'];
        $product->price = $productData['price'];
        $product->special_price = $productData['special_price'];
        $product->special_price_start = $productData['special_price'] ? now() : null;
        $product->special_price_end = $productData['special_price'] ? $productData['price_valid_until'] : null;
        $product->special_price_type = $productData['special_price'] ? 'fixed' : null;

        $product->in_stock = $productData['in_stock'];
        $product->brand_id = $brand?->id;

        if ($isNewProduct) {
            $product->is_active = true;
        }

        $product->save();
        $product->selling_price = $productData['special_price'] ?: $productData['price'];
        $product->save();

        $existingProducts->put($product->sku, $product);

        $existingProductAttributes = ProductAttribute::where('product_id', $product->id)
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
        $newProductAttributeIds = [];

        foreach ($attributeIds as $attributeId => $valueMap) {
            $productAttribute = $existingProductAttributes->get($attributeId);

            if (!$productAttribute) {
                $productAttribute = new ProductAttribute();
                $productAttribute->product_id = $product->id;
                $productAttribute->attribute_id = $attributeId;
                $productAttribute->save();

                $newProductAttributeIds[$attributeId] = $productAttribute->id;
            }

            $productAttributeId = $productAttribute->id ?? $newProductAttributeIds[$attributeId];
            $existingValues = $existingProductAttributeValues[$productAttributeId] ?? collect();
            $existingValueIds = $existingValues->pluck('attribute_value_id')->toArray();

            foreach ($valueMap as $value => $attributeValueId) {
                if (!in_array($attributeValueId, $existingValueIds)) {
                    $productAttributeValuesToInsert[] = [
                        'product_attribute_id' => $productAttributeId,
                        'attribute_value_id' => $attributeValueId,
                    ];
                }
            }
        }

        if (!empty($productAttributeValuesToInsert)) {
            ProductAttributeValue::insert($productAttributeValuesToInsert);
        }

        $product->categories()->sync($categoryIds);
        $this->attachImages($product->id, $productData['images']);

        if (!empty($productData['attributes'])) {
            $sizeValues = [];
            $colorValues = [];

            foreach ($productData['attributes'] as $attrName => $attrValues) {
                if (strtolower($attrName) === 'size' && is_array($attrValues)) {
                    $sizeValues = $attrValues;
                } elseif (strtolower($attrName) === 'color' && is_array($attrValues)) {
                    $colorValues = $attrValues;
                }
            }


            $variationIds = [];

            if (!empty($sizeValues)) {
                $sizeVariationId = $this->createProductVariation('Size', $product->id);
                $variationIds['size'] = $sizeVariationId;

                foreach ($sizeValues as $sizeData) {
                    $valueId = $this->createProductVariationValue(
                        $sizeData['value'],
                        $sizeVariationId
                    );
                }
            }

            if (!empty($colorValues)) {
                $colorVariationId = $this->createProductVariation('Color', $product->id);
                $variationIds['color'] = $colorVariationId;

                foreach ($colorValues as $colorData) {
                    $valueId = $this->createProductVariationValue(
                        $colorData['value'],
                        $colorVariationId
                    );
                }
            }

            if (!empty($sizeValues) && !empty($colorValues)) {
                foreach ($sizeValues as $sizeData) {
                    foreach ($colorValues as $colorData) {
                        $sizeValue = $sizeData['value'];
                        $colorValue = $colorData['value'];
                        $available = ($sizeData['available'] ?? true) && ($colorData['available'] ?? true);

                        $sizeVariationValueId = $this->getVariationValueId($sizeValue, $variationIds['size']);
                        $colorVariationValueId = $this->getVariationValueId($colorValue, $variationIds['color']);

                        if (!$sizeVariationValueId || !$colorVariationValueId) {
                            $this->error("Missing variation value IDs for {$sizeValue} or {$colorValue}");
                            continue;
                        }

                        $combinedName = "{$sizeValue} - {$colorValue}";

                        $this->createProductVariant(
                            $product->id,
                            $combinedName,
                            $available,
                            [$sizeVariationValueId, $colorVariationValueId],
                            $productData['price'],
                            $productData['special_price'],
                            $productData['price_valid_until'],
                            $product->sku,
                            $existingVariants
                        );
                    }
                }
            } elseif (!empty($sizeValues)) {
                foreach ($sizeValues as $sizeData) {
                    $sizeValue = $sizeData['value'];
                    $available = $sizeData['available'] ?? true;

                    $sizeVariationValueId = $this->getVariationValueId($sizeValue, $variationIds['size']);

                    if (!$sizeVariationValueId) {
                        $this->error("Missing variation value ID for {$sizeValue}");
                        continue;
                    }

                    $this->createProductVariant(
                        $product->id,
                        $sizeValue,
                        $available,
                        [$sizeVariationValueId],
                        $productData['price'],
                        $productData['special_price'],
                        $productData['price_valid_until'],
                        $product->sku,
                        $existingVariants
                    );
                }
            } elseif (!empty($colorValues)) {
                foreach ($colorValues as $colorData) {
                    $colorValue = $colorData['value'];
                    $available = $colorData['available'] ?? true;

                    $colorVariationValueId = $this->getVariationValueId($colorValue, $variationIds['color']);

                    if (!$colorVariationValueId) {
                        $this->error("Missing variation value ID for {$colorValue}");
                        continue;
                    }

                    $this->createProductVariant(
                        $product->id,
                        $colorValue,
                        $available,
                        [$colorVariationValueId],
                        $productData['price'],
                        $productData['special_price'],
                        $productData['price_valid_until'],
                        $product->sku,
                        $existingVariants
                    );
                }
            }
        }
    }

    protected function createProductVariation($variationType, $productId)
    {
        $variationId = DB::table('variations')->insertGetId([
            'uid' => Str::uuid(),
            'type' => 'text',
            'is_global' => false,
            'position' => 1,
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

        return $variationId;
    }

    protected function createProductVariationValue($label, $variationId)
    {
        $position = DB::table('variation_values')
                ->where('variation_id', $variationId)
                ->count() + 1;

        $variationValueId = DB::table('variation_values')->insertGetId([
            'uid' => Str::uuid(),
            'variation_id' => $variationId,
            'value' => $label,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('variation_value_translations')->insert([
            'variation_value_id' => $variationValueId,
            'locale' => 'en',
            'label' => $label,
        ]);

        return $variationValueId;
    }

    protected function getVariationValueId($label, $variationId)
    {
        return DB::table('variation_values')
            ->join('variation_value_translations', 'variation_values.id', '=', 'variation_value_translations.variation_value_id')
            ->where('variation_values.variation_id', $variationId)
            ->where('variation_value_translations.label', $label)
            ->where('variation_value_translations.locale', 'en')
            ->value('variation_values.id');
    }

    protected function createProductVariant($productId, $name, $available, $variationValueIds, $price, $specialPrice, $specialPriceEnd, $baseSku, $existingVariants)
    {
        $skuSuffix = str_replace([' ', '.', '/', '-'], ['_', '_', '_', '_'], $name);
        $variantSku = $baseSku . '_' . $skuSuffix;

        $variant = $existingVariants->get($variantSku);
        if ($variant) {
            return $variant->id;
        }

        $dbVariant = DB::table('product_variants')
            ->where('product_id', $productId)
            ->where('sku', $variantSku)
            ->first();

        if ($dbVariant) {
            $existingVariants->put($variantSku, $dbVariant);
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

        $uids = implode(',', $variationValueUids);

        $dbVariantCount = DB::table('product_variants')
            ->where('product_id', $productId)
            ->count();

        $memoryVariantCount = $existingVariants->filter(function($item) use ($productId) {
            return $item->product_id == $productId;
        })->count();

        $existingVariantCount = max($dbVariantCount, $memoryVariantCount);

        $isDefault = ($existingVariantCount === 0);
        $position = $existingVariantCount + 1;

        try {
            $variantId = DB::table('product_variants')->insertGetId([
                'uid' => Str::uuid(),
                'uids' => $uids,
                'product_id' => $productId,
                'name' => $name,
                'price' => $price,
                'special_price' => $specialPrice,
                'special_price_type' => $specialPrice ? 'fixed' : null,
                'special_price_start' => $specialPrice ? now() : null,
                'special_price_end' => $specialPrice ? $specialPriceEnd : null,
                'selling_price' => $specialPrice ?: $price,
                'sku' => $variantSku,
                'manage_stock' => false,
                'qty' => 0,
                'in_stock' => $available,
                'is_default' => $isDefault,
                'is_active' => true,
                'position' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $newVariant = (object)[
                'id' => $variantId,
                'product_id' => $productId,
                'sku' => $variantSku,
            ];
            $existingVariants->put($variantSku, $newVariant);

            return $variantId;

        } catch (\Exception $e) {
            $this->error("Error creating variant: " . $e->getMessage());
            Log::error("Variant creation failed", [
                'product_id' => $productId,
                'sku' => $variantSku,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
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
            if (is_array($attributeValues)) {
                foreach ($attributeValues as $attributeValue) {
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

        foreach ($categories as $categoryName) {
            $categoryName = ucfirst($categoryName);

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
}
