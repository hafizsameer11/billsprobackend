<?php

namespace Database\Seeders;

use App\Services\Platform\ServiceMaintenanceService;
use Illuminate\Database\Seeder;

class ServiceMaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        app(ServiceMaintenanceService::class)->syncCatalog();
    }
}
