<?php

namespace tests\OPNsense\Interfaces;

use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Interfaces\NetworkInterface;

class NetworkInterfaceTest extends \PHPUnit\Framework\TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/network-interface-' . uniqid();
        mkdir($this->configDir, 0700, true);
        copy(__DIR__ . '/NetworkInterfaceTest/config.xml', $this->configDir . '/config.xml');
        (new AppConfig())->update('application.configDir', $this->configDir);
        Config::getInstance()->forceReload();
    }

    protected function tearDown(): void
    {
        @unlink($this->configDir . '/config.xml');
        @rmdir($this->configDir);
    }

    public function testStaticAndDynamicAddressesAreLoadedSafely(): void
    {
        $model = new NetworkInterface();
        $data = $model->interface->getNodeContent();
        $this->assertSame('192.0.2.2', $data['wan']['ipaddr']);
        $this->assertSame('24', $data['wan']['subnet']);
        $this->assertSame('WAN_GW', $data['wan']['gateway']);
    }

    public function testAddressRequiresPrefix(): void
    {
        $model = new NetworkInterface();
        $model->getNodeByReference('interface.wan')->ipaddrv6->setValue('2001:db8::2');
        $messages = $model->performValidation();
        $this->assertNotEmpty($messages->getMessages());
    }

    public function testPrefixRangeIsValidated(): void
    {
        $model = new NetworkInterface();
        $model->getNodeByReference('interface.wan')->subnet->setValue('33');
        $messages = $model->performValidation();
        $this->assertNotEmpty($messages->getMessages());
    }

    public function testGatewayMustExistAndMatchInterfaceFamily(): void
    {
        $model = new NetworkInterface();
        $model->getNodeByReference('interface.wan')->gateway->setValue('WAN6_GW');
        $messages = $model->performValidation();
        $this->assertNotEmpty($messages->getMessages());
    }

    public function testDynamicConfigurationIsPreservedWhenDescriptionChanges(): void
    {
        $config = Config::getInstance()->object();
        $config->interfaces->wan->ipaddr = 'dhcp';
        $config->interfaces->wan->ipaddrv6 = 'slaac';
        $model = new NetworkInterface();
        $model->getNodeByReference('interface.wan')->descr->setValue('Updated WAN');
        $this->assertTrue($model->serializeToConfig());
        $this->assertSame('dhcp', (string)$config->interfaces->wan->ipaddr);
        $this->assertSame('slaac', (string)$config->interfaces->wan->ipaddrv6);
    }

    public function testStaticIpv6IsSerialized(): void
    {
        $model = new NetworkInterface();
        $model->getNodeByReference('interface.wan')->ipaddrv6->setValue('2001:db8::2');
        $model->getNodeByReference('interface.wan')->subnetv6->setValue('64');
        $model->getNodeByReference('interface.wan')->gatewayv6->setValue('WAN6_GW');
        $this->assertEmpty($model->performValidation()->getMessages());
        $this->assertTrue($model->serializeToConfig());
        $config = Config::getInstance()->object();
        $this->assertSame('2001:db8::2', (string)$config->interfaces->wan->ipaddrv6);
        $this->assertSame('64', (string)$config->interfaces->wan->subnetv6);
        $this->assertSame('WAN6_GW', (string)$config->interfaces->wan->gatewayv6);
    }
}
