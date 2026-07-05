<?php

namespace App\Services\Platform;

use App\Models\BillPaymentCategory;
use App\Models\BillPaymentProvider;
use App\Models\ServiceMaintenanceSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ServiceMaintenanceService
{
    private const CACHE_KEY = 'service_maintenance:active_v1';

    private const CACHE_TTL_SECONDS = 60;

    public function syncCatalog(): void
    {
        foreach (config('service_maintenance.catalog', []) as $item) {
            ServiceMaintenanceSetting::query()->firstOrCreate(
                ['slug' => $item['slug']],
                [
                    'group' => $item['group'],
                    'label' => $item['label'],
                    'is_under_maintenance' => false,
                ]
            );
        }

        $providers = BillPaymentProvider::query()
            ->where('is_active', true)
            ->with('category:id,code,name')
            ->get();

        foreach ($providers as $provider) {
            $categoryCode = (string) ($provider->category?->code ?? '');
            if ($categoryCode === '') {
                continue;
            }

            $providerCode = strtoupper((string) $provider->code);
            $slug = "bill_payment.{$categoryCode}.{$providerCode}";
            $categoryLabel = (string) ($provider->category?->name ?? ucfirst(str_replace('_', ' ', $categoryCode)));

            ServiceMaintenanceSetting::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'group' => 'Bill Payments',
                    'label' => "{$categoryLabel} — {$provider->name}",
                    'is_under_maintenance' => false,
                ]
            );
        }
    }

    /**
     * @return Collection<int, ServiceMaintenanceSetting>
     */
    public function listForAdmin(): Collection
    {
        $this->syncCatalog();

        return ServiceMaintenanceSetting::query()
            ->orderBy('group')
            ->orderBy('label')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForPublic(): array
    {
        return array_values($this->activeMap());
    }

    /**
     * @param  list<string>  $slugs  Checked in order; first active match wins.
     * @return array<string, mixed>|null
     */
    public function resolveBlock(array $slugs): ?array
    {
        $active = $this->activeMap();
        foreach ($slugs as $slug) {
            if (isset($active[$slug])) {
                return $active[$slug];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>|null
     */
    public function blockResult(array $slugs): ?array
    {
        $hit = $this->resolveBlock($slugs);
        if ($hit === null) {
            return null;
        }

        $message = trim((string) ($hit['notice_message'] ?? ''));
        if ($message === '') {
            $message = 'This service is temporarily unavailable. Please try again later.';
        }

        return [
            'success' => false,
            'message' => $message,
            'status' => 503,
            'code' => 'SERVICE_MAINTENANCE',
            'maintenance' => $hit,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function billPaymentSlugs(string $categoryCode, ?string $providerCode = null): array
    {
        $categoryCode = strtolower(trim($categoryCode));
        $slugs = ["bill_payment.{$categoryCode}"];

        if ($providerCode !== null && $providerCode !== '') {
            $normalized = strtoupper(trim($providerCode));
            array_unshift($slugs, "bill_payment.{$categoryCode}.{$normalized}");
        }

        return $slugs;
    }

    /**
     * @return list<string>
     */
    public function virtualCardSlugs(string $scheme, string $operation): array
    {
        $scheme = strtolower(trim($scheme));

        return [
            "virtual_card.{$scheme}.{$operation}",
            "virtual_card.{$scheme}",
        ];
    }

    public function update(int $id, array $data): ServiceMaintenanceSetting
    {
        $row = ServiceMaintenanceSetting::query()->findOrFail($id);
        $row->fill([
            'is_under_maintenance' => (bool) ($data['is_under_maintenance'] ?? false),
            'notice_title' => $data['notice_title'] ?? null,
            'notice_message' => $data['notice_message'] ?? null,
            'alternate_hint' => $data['alternate_hint'] ?? null,
        ]);
        $row->save();
        $this->clearCache();

        return $row->fresh();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function activeMap(): array
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return ServiceMaintenanceSetting::query()
                ->where('is_under_maintenance', true)
                ->orderBy('slug')
                ->get()
                ->mapWithKeys(fn (ServiceMaintenanceSetting $row): array => [
                    $row->slug => $row->toPublicArray(),
                ])
                ->all();
        });

        return $map;
    }
}
