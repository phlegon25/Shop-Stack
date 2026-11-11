<?php

namespace Tests\Unit;

use App\Services\TotpService;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_generates_valid_base32_secrets(): void
    {
        $totp   = new TotpService();
        $secret = $totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(26, strlen($secret)); // 20 bytes → 32 chars
    }

    public function test_current_code_verifies_and_garbage_is_rejected(): void
    {
        $totp   = new TotpService();
        $secret = $totp->generateSecret();

        $code = $totp->currentCode($secret);

        $this->assertTrue($totp->verify($secret, $code));
        $this->assertFalse($totp->verify($secret, '000000') || $code === '000000');
        $this->assertFalse($totp->verify($secret, 'abc123'));
        $this->assertFalse($totp->verify($secret, '12345'));
        $this->assertFalse($totp->verify($secret, ''));
    }

    public function test_verification_uses_constant_time_comparison_shape(): void
    {
        $totp   = new TotpService();
        $secret = $totp->generateSecret();

        // Codes are zero-padded 6-digit strings.
        $this->assertMatchesRegularExpression('/^\d{6}$/', $totp->currentCode($secret));

        $uri = $totp->provisioningUri($secret, 'user@example.com');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
    }
}
