<?php

namespace FleetCart\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Str;

class ScrapeOutlet46Categories extends Command
{
    protected $signature = 'scrape:categories';

    protected $description = 'Create Men/Women/Children parent categories and their children from Outlet46 menu';

    public function handle(): int
    {
        $url = 'https://www.outlet46.de/en/';

        $res = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; Outlet46BrandBot/1.0)',
            'Accept'     => 'text/html,application/xhtml+xml',
        ])->timeout(25)->retry(2, 500)->get($url);

        if (!$res->ok()) {
            $this->error("HTTP ".$res->status()." loading {$url}");
            return self::FAILURE;
        }

        $html = $res->body();
        $crawler = new Crawler($html);

        $parents = [
            'Men'      => '.cccount.scrollable-nav.cat-52',
            'Women'    => '.cccount.scrollable-nav.cat-44',
            'Children' => '.cccount.scrollable-nav.cat-81',
        ];

        $tree = [];
        foreach ($parents as $pName => $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() === 0) {
                $this->warn("Parent selector not found: {$selector}");
                continue;
            }

            $items = [];
            $currentParent = null;
            $hasAnyUnderline = $node->filter('li a u')->count() > 0;

            $node->filter('li')->each(function (Crawler $li, $i) use (&$items, &$currentParent, $hasAnyUnderline) {
                $a = $li->filter('a')->first();
                if ($a->count() === 0) return;

                $name = $this->normalizeCategory($a->text(''));
                if ($name === '' || str_starts_with($name, 'Show All ')) return;

                $href = trim($a->attr('href') ?? '');
                $hasUnderline = $a->filter('u')->count() > 0;

                if ($hasAnyUnderline) {
                    if ($hasUnderline) {
                        $currentParent = [
                            'name'     => $name,
                            'href'     => $href,
                            'position' => count($items) + 1,
                            'children' => [],
                        ];
                        $items[] = $currentParent;
                    } else {
                        if ($currentParent !== null) {
                            $items[count($items) - 1]['children'][] = [
                                'name'     => $name,
                                'href'     => $href,
                                'position' => count($items[count($items) - 1]['children']) + 1,
                            ];
                        }
                    }
                } else {
                    $items[] = [
                        'name'     => $name,
                        'href'     => $href,
                        'position' => count($items) + 1,
                        'children' => [],
                    ];
                }
            });

            $tree[] = [
                'parent'   => $pName,
                'children' => $items,
            ];
        }

        DB::transaction(function () use ($tree) {
            foreach ($tree as $blockIndex => $block) {
                $parentId = $this->upsertCategory($block['parent'], null, $blockIndex + 1);

                foreach ($block['children'] as $subIndex => $subCategory) {
                    $subCategoryId = $this->upsertCategory(
                        $subCategory['name'],
                        $parentId,
                        (int)$subCategory['position']
                    );

                    if (!empty($subCategory['children'])) {
                        foreach ($subCategory['children'] as $child) {
                            $this->upsertCategory(
                                $child['name'],
                                $subCategoryId,
                                (int)$child['position']
                            );
                        }
                    }
                }
            }
        });

        return self::SUCCESS;
    }
    private function upsertCategory(string $name, ?int $parentId, int $position): int
    {
        $baseSlug = Str::slug($name, '-');
        $slug = $baseSlug;
        $n = 1;
        while (DB::table('categories')->where('slug', $slug)->when($parentId, fn($q)=>$q)->exists()) {
            $slug = $baseSlug.'-'.$n++;
        }

        $existing = DB::table('categories')
            ->join('category_translations', 'category_translations.category_id', '=', 'categories.id')
            ->whereRaw('LOWER(category_translations.name) = LOWER(?)', [$name])
            ->where('category_translations.locale', 'en')
            ->when(!is_null($parentId), fn($q)=>$q->where('categories.parent_id', $parentId))
            ->select('categories.id', 'categories.slug')
            ->first();

        if ($existing) {
            DB::table('categories')->where('id', $existing->id)->update([
                'parent_id'     => $parentId,
                'position'      => $position,
                'is_searchable' => 0,
                'is_active'     => 1,
                'updated_at'    => now(),
            ]);
            $trans = DB::table('category_translations')
                ->where('category_id', $existing->id)
                ->where('locale', 'en')->first();
            if (!$trans) {
                DB::table('category_translations')->insert([
                    'category_id' => $existing->id,
                    'locale'      => 'en',
                    'name'        => $name,
                ]);
            }
            return (int)$existing->id;
        }

        $categoryId = (int)DB::table('categories')->insertGetId([
            'parent_id'     => $parentId,
            'slug'          => $slug,
            'position'      => $position,
            'is_searchable' => 0,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'locale'      => 'en',
            'name'        => $name,
        ]);

        return $categoryId;
    }

    private function normalizeCategory(string $name): string
    {
        $name = trim($name);

        $name = preg_replace('/\s*&\s*/', ' & ', $name);

        $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

        return $name;
    }
}
