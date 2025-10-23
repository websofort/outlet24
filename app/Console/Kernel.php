<?php

namespace FleetCart\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\ScaffoldModuleCommand::class,
        Commands\ScaffoldEntityCommand::class,
        Commands\ScrapeOutlet46Brands::class,
        Commands\ScrapeOutlet46Categories::class,
        Commands\ScrapeOutlet46Products::class,
        Commands\UpdateProductQuantity::class,
        Commands\ScrapeNewOutlet46Products::class,
        Commands\UpdateOutlet46Products::class,
    ];


    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     *
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')
        //          ->hourly();

        $schedule->command('products:update-quantity')->twiceDaily(14, 21)
        ->timezone('Europe/Skopje');
        $schedule->command('scrape:update-products')->twiceDaily(14, 21)
            ->timezone('Europe/Skopje');
        $schedule->command('scrape:update-products')->dailyAt('20:00')
            ->timezone('Europe/Skopje');

    }
}
