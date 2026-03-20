<?php

namespace Tests\Unit;

use App\Services\Crypto\KeyEncryptionService;
use Tests\TestCase;

class KeyEncryptionServiceTest extends TestCase
{
    public function test_encrypt_then_decrypt_round_trips(): void
    {
        $svc = app(KeyEncryptionService::class);
        $plain = 'private-key-or-mnemonic-material';
        $enc = $svc->encrypt($plain);

        $this->assertStringContainsString(':', $enc);
        $this->assertSame($plain, $svc->decrypt($enc));
    }
}
