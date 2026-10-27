<?php
use App\Services\StripeWebhookSignature;
use CodeIgniter\Test\CIUnitTestCase;
final class StripeWebhookSignatureTest extends CIUnitTestCase
{
    public function testSignatureRejectsTamperingAndReplay(): void
    {
        $body='{"type":"checkout.session.completed"}';$secret='test-signing-secret';$time=1234567890;
        $header='t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$body,$secret);
        $this->assertTrue(StripeWebhookSignature::valid($body,$header,$secret,$time));
        $this->assertFalse(StripeWebhookSignature::valid($body.' ',$header,$secret,$time));
        $this->assertFalse(StripeWebhookSignature::valid($body,$header,$secret,$time+301));
        $this->assertFalse(StripeWebhookSignature::valid($body,$header,'',$time));
    }
}
