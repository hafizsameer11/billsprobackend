<?php

namespace Tests\Feature;

use App\Models\ServiceMaintenanceSetting;
use App\Models\User;
use App\Services\Platform\ServiceMaintenanceService;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ServiceMaintenanceService::class)->syncCatalog();
    }

    public function test_public_endpoint_returns_only_active_maintenance_rows(): void
    {
        $row = ServiceMaintenanceSetting::query()->where('slug', 'virtual_card.mastercard.fund')->firstOrFail();
        $row->update([
            'is_under_maintenance' => true,
            'notice_title' => 'Mastercard funding paused',
            'notice_message' => 'Please use Visa while we fix Mastercard funding.',
            'alternate_hint' => 'Use Visa card instead',
        ]);

        ServiceMaintenanceSetting::query()->where('slug', 'virtual_card.visa.fund')->firstOrFail();

        $this->getJson('/api/service-maintenance')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.slug', 'virtual_card.mastercard.fund')
            ->assertJsonPath('data.items.0.notice_title', 'Mastercard funding paused')
            ->assertJsonPath('data.items.0.alternate_hint', 'Use Visa card instead');
    }

    public function test_admin_can_toggle_maintenance_notice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $row = ServiceMaintenanceSetting::query()->where('slug', 'bill_payment.airtime.MTN')->first();

        if ($row === null) {
            $row = ServiceMaintenanceSetting::query()->create([
                'slug' => 'bill_payment.airtime.MTN',
                'group' => 'Bill Payments',
                'label' => 'Airtime — MTN',
                'is_under_maintenance' => false,
            ]);
        }

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/service-maintenance/{$row->id}", [
            'is_under_maintenance' => true,
            'notice_title' => 'MTN airtime maintenance',
            'notice_message' => 'MTN airtime is temporarily unavailable.',
            'alternate_hint' => 'Try GLO or Airtel',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_under_maintenance', true)
            ->assertJsonPath('data.notice_message', 'MTN airtime is temporarily unavailable.');

        $this->getJson('/api/service-maintenance')
            ->assertOk()
            ->assertJsonPath('data.items.0.slug', 'bill_payment.airtime.MTN');
    }

    public function test_mastercard_create_blocked_when_under_maintenance(): void
    {
        ServiceMaintenanceSetting::query()
            ->where('slug', 'virtual_card.mastercard')
            ->update([
                'is_under_maintenance' => true,
                'notice_message' => 'Mastercard is under maintenance. Use Visa instead.',
            ]);

        app(ServiceMaintenanceService::class)->clearCache();

        $user = User::factory()->create();
        $result = app(VirtualCardService::class)->createCard($user->id, [
            'payment_wallet_type' => 'naira_wallet',
            'payment_wallet_currency' => 'NGN',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['status']);
        $this->assertSame('SERVICE_MAINTENANCE', $result['code']);
        $this->assertStringContainsString('Mastercard is under maintenance', (string) $result['message']);
    }
}
