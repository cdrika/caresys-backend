<?php
use App\Controllers\MobilePairingController;
use CodeIgniter\Test\CIUnitTestCase;

final class MobileTestDeviceTest extends CIUnitTestCase
{
    public function testSimulatorIsUnavailableOutsideDevelopment(): void
    {
        $controller=new MobilePairingController();
        $controller->initController(service('request'),service('response'),service('logger'));
        $response=$controller->testDevice();
        $this->assertSame(404,$response->getStatusCode());
        $this->assertStringNotContainsString('Create pairing code',$response->getBody());
    }
}
