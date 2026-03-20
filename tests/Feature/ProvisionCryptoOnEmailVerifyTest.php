<?php

namespace Tests\Feature;

use App\Jobs\ProvisionUserCryptoDepositAddressesJob;
use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProvisionCryptoOnEmailVerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_email_dispatches_provision_crypto_job(): void
    {
        Bus::fake();
        Mail::fake();

        $user = User::factory()->create([
            'email_verified' => false,
            'email' => 'provision@example.com',
            'country_code' => 'NG',
        ]);

        $otpService = app(\App\Services\Auth\OtpService::class);
        $otpService->sendOtp($user->email, null, 'email');

        $otp = OtpVerification::query()
            ->where('email', $user->email)
            ->where('type', 'email')
            ->where('verified', false)
            ->value('otp');
        $this->assertNotNull($otp);

        $auth = app(\App\Services\Auth\AuthService::class);
        $result = $auth->verifyEmailOtp($user->email, $otp);

        $this->assertTrue($result['success']);
        Bus::assertDispatched(ProvisionUserCryptoDepositAddressesJob::class, function (ProvisionUserCryptoDepositAddressesJob $job) use ($user) {
            return $job->userId === $user->id;
        });
    }
}
