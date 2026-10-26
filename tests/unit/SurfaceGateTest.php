<?php
use App\Deployment\SurfaceGate;
use App\Services\MobilePairingService;
use App\Services\DomainError;
use CodeIgniter\Test\CIUnitTestCase;

final class SurfaceGateTest extends CIUnitTestCase
{
    public function testHostSurfacesAreMutuallyExclusive(): void
    {
        $this->assertTrue(SurfaceGate::allows('api','/mobile/v1/me/profile'));
        $this->assertTrue(SurfaceGate::allows('web','/mobile-enrollment'));
        foreach(['/login','/app','/assets/caresys.css','/index.php/mobile/v1/me/profile','/.env','/'] as $path) $this->assertFalse(SurfaceGate::allows('api',$path));
        $this->assertFalse(SurfaceGate::allows('web','/mobile/v1/me/profile'));
        $this->assertFalse(SurfaceGate::allows('web','/index.php/mobile/v1/me/profile'));
        $this->assertFalse(SurfaceGate::allows('web','/CareSys/public/index.php/mobile/v1/me/profile'));
        $this->assertFalse(SurfaceGate::allows('unknown','/mobile/v1/me/profile'));
    }

    public function testEncodedAndTraversalAliasesAreRejected(): void
    {
        foreach(['/mobile/v1/../login','/mobile/v1/%2e%2e/login','//mobile/v1/me','/mobile/v1/./me','/mobile/v1/\\login'] as $path) $this->assertFalse(SurfaceGate::allows('api',$path));
    }

    public function testPairingCodeNormalization(): void
    {
        $this->assertSame('A1B2C3D4E5F6',MobilePairingService::normalizeCode('a1b2-c3d4-e5f6'));
        $this->expectException(DomainError::class);
        MobilePairingService::normalizeCode('<script>');
    }
}
