<?php

namespace FleetCart\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Entities\EntityFile;
use Modules\Media\Entities\File;
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Promise;

class UpdateProductQuantity extends Command
{
    protected $signature = 'products:update-quantity';

    protected $description = 'Update product variant quantities and images from Outlet46';

    private const BASE_URL = 'https://www.outlet46.de';

    public function handle()
    {
        $batchSize = 200;
        $delay = 0.1;

        $productsData = $this->getProductsWithVariants();

        if ($productsData->isEmpty()) {
            return self::SUCCESS;
        }

        $batches = $productsData->chunk($batchSize);
        $existingFiles = $this->preloadAllImages();

        foreach ($batches as $batch) {
            foreach ($batch as $productData) {
                $this->processProduct($productData, $existingFiles, $stats, $delay);
            }
        }

        return self::SUCCESS;
    }

    private function getProductsWithVariants()
    {
        return DB::table('product_variants as pv')
            ->join('products as p', 'pv.product_id', '=', 'p.id')
            ->whereNotNull('pv.outlet_variation_id')
            ->whereNotNull('p.product_url')
            ->select('p.id as product_id', 'p.product_url')
            ->distinct()
            ->get();
    }

    private function processProduct($productData, &$existingFiles, &$stats, $delay)
    {
        $productId = $productData->product_id;
        $productUrl = $productData->product_url;

        $existingVariants = $this->getExistingVariants($productId);
        $variantsWithoutOutlet = $existingVariants->whereNull('outlet_variation_id');
        $variantsWithOutlet = $existingVariants->whereNotNull('outlet_variation_id');

        $scrapedData = null;
        if ($variantsWithoutOutlet->isNotEmpty()) {
            $scrapedData = $this->scrapeProductPage($productUrl);

            if (!$scrapedData) {
                return;
            }
        }

        $variantsToUpdate = [];
        $imagesToAttach = [];

        if ($variantsWithOutlet->isNotEmpty()) {
            $this->batchProcessExistingVariants(
                $variantsWithOutlet,
                $variantsToUpdate,
                $imagesToAttach,
                $stats
            );
        }

        if ($variantsWithoutOutlet->isNotEmpty() && $scrapedData) {
            $this->updateVariantsWithoutOutletId(
                $variantsWithoutOutlet,
                $scrapedData,
                $productId,
                $variantsToUpdate,
                $imagesToAttach,
                $stats
            );
        }

        if (!empty($variantsToUpdate)) {
            $this->bulkUpdateQuantities($variantsToUpdate);
        }

        if (!empty($imagesToAttach)) {
            $this->attachVariantImages($imagesToAttach, $existingFiles, $stats);
        }

        sleep($delay);
    }

    private function batchProcessExistingVariants($variants, &$variantsToUpdate, &$imagesToAttach, &$stats)
    {
        $variantIds = $variants->pluck('id')->toArray();
        $variantsWithImages = DB::table('entity_files')
            ->where('entity_type', 'Modules\Product\Entities\ProductVariant')
            ->whereIn('entity_id', $variantIds)
            ->where('zone', 'additional_images')
            ->pluck('entity_id')
            ->toArray();

        $outletVariationIds = $variants->pluck('outlet_variation_id')->toArray();
        $variationsData = $this->batchScrapeVariationDetails($outletVariationIds);

        foreach ($variants as $existingVariant) {
            $variationData = $variationsData[$existingVariant->outlet_variation_id] ?? null;

            if (!$variationData) {
                continue;
            }

            $newQty = $variationData['stock_net'] ?? 0;
            $manageStock = $newQty !== 0 ? 1 : 0;
            $inStock = $newQty > 0 ? 1 : 0;

            if ($existingVariant->qty != $newQty) {
                $variantsToUpdate[] = [
                    'id' => $existingVariant->id,
                    'qty' => $newQty,
                    'manage_stock' => $manageStock,
                    'in_stock' => $inStock,
                ];
            }

            $hasImages = in_array($existingVariant->id, $variantsWithImages);

            if (!$hasImages && !empty($variationData['images'])) {
                $imagesToAttach[] = [
                    'variant_id' => $existingVariant->id,
                    'images' => $variationData['images'],
                ];
            }
        }
    }
    private function batchScrapeVariationDetails($variationIds)
    {
        $results = [];
        $chunks = array_chunk($variationIds, 10);

        foreach ($chunks as $chunk) {
            $promises = [];

            foreach ($chunk as $variationId) {
                $url = self::BASE_URL . '/rest/io/variations/' . $variationId . '?template=Ceres%3A%3AItem.SingleItem';

                $promises[$variationId] = Http::async()
                    ->timeout(10)
                    ->get($url);
            }

            $responses = Promise\Utils::settle($promises)->wait();

            foreach ($responses as $variationId => $response) {
                if ($response['state'] === 'fulfilled') {
                    try {
                        $data = $response['value']->json();
                        $stockNet = $data['data']['documents'][0]['data']['stock']['net'] ?? 0;
                        $variationImages = $data['data']['documents'][0]['data']['images']['variation'] ?? [];

                        $results[$variationId] = [
                            'stock_net' => $stockNet,
                            'images' => $variationImages,
                        ];
                    } catch (\Exception $e) {
                        Log::error("Error parsing variation {$variationId}: " . $e->getMessage());
                    }
                }
            }
        }

        return $results;
    }
    private function updateVariantsWithoutOutletId($variantsWithoutOutlet, $scrapedData, $productId, &$variantsToUpdate, &$imagesToAttach, &$stats)
    {
        $scrapedVariants = $scrapedData['variants'] ?? [];

        if (empty($scrapedVariants)) {
            return;
        }

        $outletToVariationValue = DB::table('variation_values as vv')
            ->join('product_variations as pvar', 'vv.variation_id', '=', 'pvar.variation_id')
            ->where('pvar.product_id', $productId)
            ->whereNotNull('vv.outlet_value_id')
            ->pluck('vv.outlet_value_id', 'vv.uid')
            ->toArray();

        if (empty($outletToVariationValue)) {
            return;
        }

        $variantIds = $variantsWithoutOutlet->pluck('id')->toArray();
        $variantsWithImages = DB::table('entity_files')
            ->where('entity_type', 'Modules\Product\Entities\ProductVariant')
            ->whereIn('entity_id', $variantIds)
            ->where('zone', 'additional_images')
            ->pluck('entity_id')
            ->toArray();

        $matchedVariants = [];
        foreach ($variantsWithoutOutlet as $existingVariant) {
            $variantUids = explode('.', $existingVariant->uids);

            $variantOutletValueIds = [];
            foreach ($variantUids as $uid) {
                if (isset($outletToVariationValue[$uid])) {
                    $variantOutletValueIds[] = $outletToVariationValue[$uid];
                }
            }

            if (empty($variantOutletValueIds)) {
                continue;
            }

            sort($variantOutletValueIds);

            foreach ($scrapedVariants as $scrapedVariant) {
                $scrapedOutletValueIds = array_column($scrapedVariant['attributes'] ?? [], 'outlet_value_id');
                sort($scrapedOutletValueIds);

                if ($variantOutletValueIds === $scrapedOutletValueIds) {
                    $matchedVariants[] = [
                        'variant' => $existingVariant,
                        'outlet_variation_id' => $scrapedVariant['outlet_variation_id'],
                        'has_images' => in_array($existingVariant->id, $variantsWithImages)
                    ];
                    break;
                }
            }
        }

        if (empty($matchedVariants)) {
            return;
        }

        $outletVariationIds = array_column($matchedVariants, 'outlet_variation_id');
        $variationsData = $this->batchScrapeVariationDetails($outletVariationIds);

        foreach ($matchedVariants as $matched) {
            $existingVariant = $matched['variant'];
            $outletVariationId = $matched['outlet_variation_id'];
            $variationData = $variationsData[$outletVariationId] ?? null;

            if (!$variationData) {
                continue;
            }

            $newQty = $variationData['stock_net'] ?? 0;
            $manageStock = $newQty !== 0 ? 1 : 0;
            $inStock = $newQty > 0 ? 1 : 0;

            $variantsToUpdate[] = [
                'id' => $existingVariant->id,
                'qty' => $newQty,
                'manage_stock' => $manageStock,
                'in_stock' => $inStock,
                'outlet_variation_id' => $outletVariationId,
            ];

            if (!$matched['has_images'] && !empty($variationData['images'])) {
                $imagesToAttach[] = [
                    'variant_id' => $existingVariant->id,
                    'images' => $variationData['images'],
                ];
            }
        }
    }

    private function getExistingVariants($productId)
    {
        return DB::table('product_variants')
            ->where('product_id', $productId)
            ->select('id', 'outlet_variation_id', 'sku', 'qty', 'product_id', 'uids')
            ->get();
    }

    private function scrapeProductPage($productUrl)
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])->timeout(30)->retry(3, 1000)->get($productUrl);

            if (!$res->ok()) {
                return null;
            }

            $html = $res->body();
            $crawler = new Crawler($html);

            $variants = $this->extractVariants($crawler);
            $attributes = $this->extractAttributes($crawler);

            return [
                'variants' => $variants,
                'attributes' => $attributes,
            ];

        } catch (\Exception $e) {
            Log::error("Error scraping product page: " . $e->getMessage(), ['url' => $productUrl]);
            return null;
        }
    }

    private function extractVariants(Crawler $crawler): array
    {
        $variants = [];

        try {
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

            if (!is_array($data)) {
                return $variants;
            }

            foreach ($data as $variant) {
                if (isset($variant['variationId']) && isset($variant['attributes'])) {
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

        } catch (\Throwable $e) {
            Log::error('extractVariants error: ' . $e->getMessage());
        }

        return $variants;
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
                        $outletValueId = $node->attr('data-eg-value');
                        if ($outletValueId) {
                            $values[] = ['outlet_value_id' => $outletValueId];
                        }
                    });

                    if (!empty($values)) {
                        $attributes[$outletAttributeId] = ['values' => $values];
                    }
                } catch (\Throwable $e) {
                    Log::error('Error processing attribute group: ' . $e->getMessage());
                }
            });

        } catch (\Throwable $e) {
            Log::error('extractAttributes error: ' . $e->getMessage());
        }

        return $attributes;
    }

    private function scrapeVariationDetails($variationId)
    {
        try {
            $url = self::BASE_URL . '/rest/io/variations/' . $variationId . '?template=Ceres%3A%3AItem.SingleItem';

            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
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

        } catch (\Exception $e) {
            Log::error("Error scraping variation details: " . $e->getMessage());
            return null;
        }
    }

    private function bulkUpdateQuantities(array $updates)
    {
        if (empty($updates)) {
            return;
        }

        $qtyCase = [];
        $manageStockCase = [];
        $inStockCase = [];
        $outletVariationIdCase = [];
        $ids = [];
        $idsWithOutletUpdate = [];

        $qtyBindings = [];
        $manageStockBindings = [];
        $inStockBindings = [];
        $outletVariationIdBindings = [];

        foreach ($updates as $update) {
            $id = (int) $update['id'];
            $ids[] = $id;

            $qtyCase[] = "WHEN {$id} THEN ?";
            $qtyBindings[] = (int) $update['qty'];

            $manageStockCase[] = "WHEN {$id} THEN ?";
            $manageStockBindings[] = (int) $update['manage_stock'];

            $inStockCase[] = "WHEN {$id} THEN ?";
            $inStockBindings[] = (int) $update['in_stock'];

            if (isset($update['outlet_variation_id'])) {
                $idsWithOutletUpdate[] = $id;
                $outletVariationIdCase[] = "WHEN {$id} THEN ?";
                $outletVariationIdBindings[] = $update['outlet_variation_id'];
            }
        }

        $idsString = implode(',', $ids);

        $bindings = array_merge($qtyBindings, $manageStockBindings, $inStockBindings);

        $sql = "UPDATE `product_variants` SET
    `qty` = CASE `id` " . implode(' ', $qtyCase) . " END,
    `manage_stock` = CASE `id` " . implode(' ', $manageStockCase) . " END,
    `in_stock` = CASE `id` " . implode(' ', $inStockCase) . " END";

        if (!empty($outletVariationIdCase)) {
            $idsWithOutletUpdateString = implode(',', $idsWithOutletUpdate);
            $sql .= ",
    `outlet_variation_id` = CASE WHEN `id` IN ({$idsWithOutletUpdateString}) THEN CASE `id` " . implode(' ', $outletVariationIdCase) . " END ELSE `outlet_variation_id` END";
            $bindings = array_merge($bindings, $outletVariationIdBindings);
        }

        $bindings[] = now();

        $sql .= ",
    `updated_at` = ?
    WHERE `id` IN ({$idsString})";

        DB::update($sql, $bindings);
    }
    private function attachVariantImages(array $imagesToAttach, &$existingFiles, &$stats)
    {
        foreach ($imagesToAttach as $item) {
            $variantId = $item['variant_id'];
            $images = $item['images'];

            if (empty($images)) {
                continue;
            }

            $newFileIds = [];
            foreach ($images as $image) {
                $imageUrl = $image['url'] ?? null;
                if (!$imageUrl) {
                    continue;
                }

                $fileId = $this->downloadImage($imageUrl, $existingFiles);
                if ($fileId) {
                    $newFileIds[] = $fileId;
                }
            }

            if (!empty($newFileIds)) {
                $insertData = array_map(function($fileId) use ($variantId) {
                    return [
                        'file_id' => $fileId,
                        'entity_type' => 'Modules\Product\Entities\ProductVariant',
                        'entity_id' => $variantId,
                        'zone' => 'additional_images',
                    ];
                }, $newFileIds);

                EntityFile::insert($insertData);
            }
        }
    }

    private function createMissingVariants($variantsToCreate, $outletValueToVariationValue, $attributes, &$existingFiles, &$stats)
    {
        foreach ($variantsToCreate as $variantData) {
            $outletVariationId = $variantData['outlet_variation_id'];
            $productId = $variantData['product_id'];
            $scrapedVariant = $variantData['scraped_variant'];

            $variationDetails = $this->scrapeVariationDetails($outletVariationId);

            if (!$variationDetails) {
                $this->error("Failed to get details for variation: {$outletVariationId}");
                continue;
            }

            $stockNet = $variationDetails['stock_net'] ?? 0;

            if ($stockNet == 0) {
                continue;
            }

            $product = DB::table('products')->where('id', $productId)->first();
            if (!$product) {
                continue;
            }

            $variationValueIds = [];
            foreach ($scrapedVariant['attributes'] as $attr) {
                $outletValueId = $attr['outlet_value_id'];
                if (isset($outletValueToVariationValue[$outletValueId])) {
                    $variationValueIds[] = $outletValueToVariationValue[$outletValueId];
                }
            }

            if (empty($variationValueIds)) {
                $this->error("Could not map variation values for {$outletVariationId}");
                continue;
            }

            $labels = DB::table('variation_value_translations')
                ->whereIn('variation_value_id', $variationValueIds)
                ->where('locale', 'en')
                ->pluck('label')
                ->toArray();

            $name = implode(' - ', $labels);

            $skuSuffix = str_replace([' ', '.', '/', '-'], ['_', '_', '_', '_'], $name);
            $variantSku = $product->sku . '_' . $skuSuffix;

            $variationValueUids = DB::table('variation_values')
                ->whereIn('id', $variationValueIds)
                ->pluck('uid')
                ->toArray();

            $uids = implode('.', $variationValueUids);

            $existingVariantCount = DB::table('product_variants')
                ->where('product_id', $productId)
                ->count();

            $variantId = DB::table('product_variants')->insertGetId([
                'uid' => $this->generateUid(),
                'uids' => $uids,
                'product_id' => $productId,
                'name' => $name,
                'price' => $product->price,
                'special_price' => $product->special_price,
                'special_price_type' => $product->special_price_type,
                'special_price_start' => $product->special_price_start,
                'special_price_end' => $product->special_price_end,
                'selling_price' => $product->selling_price,
                'sku' => $variantSku,
                'manage_stock' => $stockNet !== 0 ? 1 : 0,
                'qty' => $stockNet,
                'in_stock' => 1,
                'is_default' => 0,
                'is_active' => true,
                'outlet_variation_id' => $outletVariationId,
                'position' => $existingVariantCount + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);


            if (!empty($variationDetails['images'])) {
                $this->attachVariantImages([
                    [
                        'variant_id' => $variantId,
                        'images' => $variationDetails['images']
                    ]
                ], $existingFiles, $stats);
            }
        }
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

    private function preloadAllImages()
    {
        return File::where('disk', config('filesystems.default'))
            ->get()
            ->keyBy('filename');
    }

    private function generateUid()
    {
        $timestamp = base_convert((int)(microtime(true) * 1000) * rand(1, 1000), 10, 36);
        $randomPart = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyz'), 0, 6);

        return substr($timestamp . $randomPart, 0, 12);
    }
}
