<?php

namespace tests\OPNsense\Interfaces;

use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Interfaces\NetworkInterface;

class NetworkInterfaceTest extends \PHPUnit\Framework\TestCase
{
    private string $configDir;
    private string $todoFile;
    private ?string $defaultTodoBackup = null;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/network-interface-' . uniqid();
        $this->todoFile = $this->configDir . '/interfaces.todo';
        mkdir($this->configDir, 0700, true);
        if (is_file('/tmp/.interfaces.todo')) {
            $this->defaultTodoBackup = $this->configDir . '/default-interfaces.todo';
            rename('/tmp/.interfaces.todo', $this->defaultTodoBackup);
        }
        copy(__DIR__ . '/NetworkInterfaceTest/config.xml', $this->configDir . '/config.xml');
        (new AppConfig())->update('application.configDir', $this->configDir);
        Config::getInstance()->forceReload();
    }

    protected function tearDown(): void
    {
        @unlink($this->todoFile);
        @unlink($this->configDir . '/config.xml');
        if ($this->defaultTodoBackup !== null && is_file($this->defaultTodoBackup)) {
            rename($this->defaultTodoBackup, '/tmp/.interfaces.todo');
        }
        @rmdir($this->configDir);
    }

    private function newModel(): NetworkInterface
    {
        $model = new NetworkInterface();
        $model->todo_file = $this->todoFile;
        return $model;
    }

    public function testStaticAndDynamicAddressesAreLoadedSafely(): void
    {
        $model = $this->newModel();
        $data = $model->interface->getNodeContent();
        $this->assertSame('192.0.2.2', $data['wan']['ipaddr']);
        $this->assertSame('24', $data['wan']['subnet']);
        $this->assertSame('WAN_GW', $data['wan']['gateway']);
    }

    public function testBasicSettingsAreLoaded(): void
    {
        $data = $this->newModel()->interface->getNodeContent()['wan'];
        $this->assertSame('1', $data['enable']);
        $this->assertSame('1', $data['blockpriv']);
        $this->assertSame('0', $data['blockbogons']);
        $this->assertSame('1', $data['gateway_interface']);
        $this->assertSame('1', $data['promisc']);
        $this->assertSame('02:00:00:00:00:01', $data['spoofmac']);
        $this->assertSame('9000', $data['mtu']);
        $this->assertSame('1400', $data['mss']);
    }

    public function testFilterOnlySettingsScheduleFilterReload(): void
    {
        $model = $this->newModel();
        $node = $model->getNodeByReference('interface.wan');
        $node->blockbogons->setValue('1');
        $node->mss->setValue('1300');
        $this->assertTrue($model->serializeToConfig());

        $config = Config::getInstance()->object()->interfaces->wan;
        $this->assertSame('1', (string)$config->blockbogons);
        $this->assertSame('1300', (string)$config->mss);
        $this->assertSame('filter', $model->get_if_todo()['wan']['pending_action']);
    }

    public function testLinkSettingsScheduleReconfigure(): void
    {
        $model = $this->newModel();
        $node = $model->getNodeByReference('interface.wan');
        $node->mtu->setValue('1500');
        $node->spoofmac->setValue('02:00:00:00:00:02');
        $this->assertTrue($model->serializeToConfig());

        $config = Config::getInstance()->object()->interfaces->wan;
        $this->assertSame('1500', (string)$config->mtu);
        $this->assertSame('02:00:00:00:00:02', (string)$config->spoofmac);
        $this->assertSame('reconfigure', $model->get_if_todo()['wan']['pending_action']);
    }

    public function testReconfigureTakesPrecedenceOverFilterReload(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->mtu->setValue('1500');
        $this->assertTrue($model->serializeToConfig());

        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->blockpriv->setValue('0');
        $this->assertTrue($model->serializeToConfig());
        $this->assertSame('reconfigure', $model->get_if_todo()['wan']['pending_action']);
    }

    public function testDisablingInterfaceRemovesEnableAndSchedulesReconfigure(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->enable->setValue('0');
        $this->assertTrue($model->serializeToConfig());
        $this->assertFalse(isset(Config::getInstance()->object()->interfaces->wan->enable));
        $this->assertSame('reconfigure', $model->get_if_todo()['wan']['pending_action']);
    }

    public function testInvalidMacAddressIsRejected(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->spoofmac->setValue('not-a-mac');
        $this->assertNotEmpty($model->performValidation()->getMessages());
    }

    public function testJumboMtuIsAcceptedAndOversizedMtuIsRejected(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->mtu->setValue('9000');
        $this->assertEmpty($model->performValidation()->getMessages());

        $model->getNodeByReference('interface.wan')->mtu->setValue('65536');
        $this->assertNotEmpty($model->performValidation()->getMessages());
    }

    public function testNewInterfaceCopiesBasicSettingsAndSchedulesApply(): void
    {
        $model = $this->newModel();
        $node = $model->interface->add();
        $node->if->setValue('em1');
        $node->descr->setValue('TEST');
        $node->enable->setValue('1');
        $node->mtu->setValue('9000');
        $node->blockbogons->setValue('1');
        $this->assertTrue($model->serializeToConfig());

        $config = Config::getInstance()->object()->interfaces->opt1;
        $this->assertSame('em1', (string)$config->if);
        $this->assertSame('1', (string)$config->enable);
        $this->assertSame('9000', (string)$config->mtu);
        $this->assertSame('1', (string)$config->blockbogons);
        $this->assertSame('reconfigure', $model->get_if_todo()['opt1']['pending_action']);
    }

    public function testAddressRequiresPrefix(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->ipaddrv6->setValue('2001:db8::2');
        $messages = $model->performValidation();
        $this->assertNotEmpty($messages->getMessages());
    }

    public function testPrefixRangeIsValidated(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->subnet->setValue('33');
        $messages = $model->performValidation();
        $this->assertNotEmpty($messages->getMessages());
    }

    public function testGatewayMustExistAndMatchInterfaceFamily(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->gateway->setValue('WAN6_GW');
        $messages = $model->performValidation();
        $this->assertNotEmpty($messages->getMessages());
    }

    public function testDynamicConfigurationIsPreservedWhenDescriptionChanges(): void
    {
        $config = Config::getInstance()->object();
        $config->interfaces->wan->ipaddr = 'dhcp';
        $config->interfaces->wan->ipaddrv6 = 'slaac';
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->descr->setValue('Updated WAN');
        $this->assertTrue($model->serializeToConfig());
        $this->assertSame('dhcp', (string)$config->interfaces->wan->ipaddr);
        $this->assertSame('slaac', (string)$config->interfaces->wan->ipaddrv6);
    }

    public function testStaticIpv6IsSerialized(): void
    {
        $model = $this->newModel();
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

    public function testAddressChangeSchedulesReconfigure(): void
    {
        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->ipaddr->setValue('192.0.2.3');
        $this->assertTrue($model->serializeToConfig());
        $todo = $model->get_if_todo();
        $this->assertSame('reconfigure', $todo['wan']['pending_action']);
        $this->assertSame([4], $todo['wan']['pending_families']);
    }

    public function testRelinkTakesPrecedenceOverAddressReconfigure(): void
    {
        $model = $this->newModel();
        $node = $model->getNodeByReference('interface.wan');
        $node->ipaddr->setValue('192.0.2.3');
        $node->if->setValue('em9');
        $this->assertTrue($model->serializeToConfig());
        $todo = $model->get_if_todo();
        $this->assertSame('relink', $todo['wan']['pending_action']);
        $this->assertSame('em9', $todo['wan']['pending_if']);
        $this->assertSame([4], $todo['wan']['pending_families']);
    }

    public function testAddressFamiliesAccumulateUntilApply(): void
    {
        $model = $this->newModel();
        $node = $model->getNodeByReference('interface.wan');
        $node->ipaddrv6->setValue('2001:db8::2');
        $node->subnetv6->setValue('64');
        $this->assertTrue($model->serializeToConfig());

        $model = $this->newModel();
        $model->getNodeByReference('interface.wan')->ipaddr->setValue('192.0.2.3');
        $this->assertTrue($model->serializeToConfig());
        $todo = $model->get_if_todo();
        $this->assertSame('reconfigure', $todo['wan']['pending_action']);
        $this->assertSame([6, 4], $todo['wan']['pending_families']);
    }
}
