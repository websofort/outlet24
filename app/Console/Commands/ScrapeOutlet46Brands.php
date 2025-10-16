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
use Symfony\Component\DomCrawler\Crawler;
use Modules\Brand\Entities\Brand;
use Modules\Media\Entities\File;

class ScrapeOutlet46Brands extends Command
{
    protected $signature = 'scrape:brands';

    protected $description = 'Scrape Outlet46 Brands A–Z and export name, url, image, count';

    public function handle(): int
    {
        $url = 'https://www.outlet46.de/marken-online-outlet/';

        $res = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; Outlet46BrandBot/1.0)',
            'Accept'     => 'text/html,application/xhtml+xml',
        ])->timeout(25)->retry(2, 500)->get($url);

        if (!$res->ok()) {
            $this->error("Failed to load page: HTTP ".$res->status());
            return self::FAILURE;
        }

        $existingAttributeSets = AttributeSet::with('translations')->get()->keyBy('id');
        $existingAttributes = Attribute::with('translations')->get()->keyBy('id');
        $existingAttributeValues = AttributeValue::with('translations')->get()->keyBy('id');

        $html = $res->body();
        $brands = $this->extractBrands($html);

        foreach ($brands as $brandData) {
            try {
                DB::beginTransaction();

                $brand = Brand::withoutGlobalScope('active')
                    ->where('slug', $brandData['slug'])
                    ->first();

                if (!$brand) {
                    $brand = new Brand();
                    $brand->slug = $brandData['slug'];
                    $brand->is_active = true;
                }

                $brand->save();

                $brand->translateOrNew('en')->name = $brandData['name'];
                $brand->save();

                if (!empty($brandData['image'])) {
                    $this->attachLogo($brand, $brandData['image'], $brandData['name']);
                }

                $this->createBrandAttribute(
                    $brand,
                    $existingAttributeSets,
                    $existingAttributes,
                    $existingAttributeValues
                );

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error('Failed to process brand: ' . $brandData['name']);
                $this->error($e->getMessage());
            }
        }

        \Cache::tags('brands')->flush();

        return self::SUCCESS;
    }
    private function extractBrands(string $html): array
    {
        $crawler = new Crawler($html);

        $items = [];
        $crawler->filter('.brandTiles .row a')->each(function (Crawler $a) use (&$items) {
            $href = trim($a->attr('href') ?? '');
            if ($href === '') return;

            $label = trim($a->filter('span')->count() ? $a->filter('span')->text('') : $a->text(''));
            if ($label === '') return;

            $name  = preg_replace('~\s*\(\d+\)\s*$~', '', $label);
            $count = null;
            if (preg_match('~\((\d+)\)\s*$~', $label, $m)) $count = (int)$m[1];

            $img = null;
            if ($a->filter('img')->count()) {
                $img = $a->filter('img')->attr('data-src') ?: $a->filter('img')->attr('src');
                $img = $img ? $this->abs($img) : null;
            }

            $url  = $this->abs($href);
            $slug = trim(parse_url($href, PHP_URL_PATH), '/');

            $items[] = [
                'name'   => $name,
                'slug'   => $slug,
                'url'    => $url,
                'image'  => $img,
                'count'  => $count,
                'source' => 'https://www.outlet46.de/en/branded-online-outlet/',
            ];
        });

        $uniq = [];
        foreach ($items as $it) $uniq[$it['slug']] = $it;
        return array_values($uniq);
    }

    private function abs(string $url): string
    {
        if (preg_match('~^https?://~i', $url)) return $url;
        return 'https://www.outlet46.de/'.ltrim($url, '/');
    }

    private function attachLogo(Brand $brand, string $imageUrl, string $brandName): void
    {
        try {
            $response = Http::timeout(30)->get($imageUrl);

            if (!$response->successful()) {
                throw new \Exception("Failed to download image: HTTP " . $response->status());
            }

            $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (empty($extension)) {
                $contentType = $response->header('Content-Type');
                $extension = match($contentType) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg'
                };
            }

            $filename = Str::slug($brandName) . '-logo-' . time() . '.' . $extension;
            $path = 'media/' . $filename;

            Storage::disk('public')->put($path, $response->body());

            $file = File::create([
                'user_id' => 1,
                'disk' => 'public',
                'filename' => $filename,
                'path' => $path,
                'extension' => $extension,
                'mime' => $response->header('Content-Type', 'image/jpeg'),
                'size' => (string) strlen($response->body()),
            ]);

            $brand->filterFiles('logo')->detach();

            $brand->files()->attach($file->id, [
                'zone' => 'logo',
                'entity_type' => Brand::class,
                'entity_id' => $brand->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to attach logo: ' . $e->getMessage());
        }
    }

    private function createBrandAttribute(
        Brand $brand,
              $existingAttributeSets,
              $existingAttributes,
              $existingAttributeValues
    ) {
        $brandLabel = 'Brand';
        $brandName = $brand->translate('en')->name ?? $brand->name;

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

        $brandAttributeValue = $existingAttributeValues->first(function ($item) use ($brandName) {
            return strcasecmp($item->translate('en')->value ?? '', $brandName) === 0;
        });

        if (!$brandAttributeValue) {
            $brandAttributeValue = new AttributeValue();
            $brandAttributeValue->attribute_id = $brandAttribute->id;
            $brandAttributeValue->position = 1;
            $brandAttributeValue->value = $brandName;
            $brandAttributeValue->save();
            $existingAttributeValues->put($brandName, $brandAttributeValue);
        }

        return [
            'attribute_id' => $brandAttribute->id,
            'value_id' => $brandAttributeValue->id
        ];
    }
}
