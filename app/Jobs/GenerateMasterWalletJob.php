<?php

namespace App\Jobs;

use App\Services\Crypto\MasterWalletGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateMasterWalletJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $blockchain,
        public bool $force = false,
        public ?string $label = null
    ) {}

    public function handle(MasterWalletGeneratorService $generator): void
    {
        try {
            $wallet = $generator->generate($this->blockchain, $this->force, $this->label);
            Log::info('Master wallet generated', [
                'blockchain' => $wallet->blockchain,
                'address' => $wallet->address,
            ]);
        } catch (Throwable $e) {
            Log::error('GenerateMasterWalletJob failed', [
                'blockchain' => $this->blockchain,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
