<?php

/*
 * Copyright (C) 2026 Biptec
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace tests\OPNsense\Interfaces;

use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Interfaces\Vlan;

class VlanTest extends \PHPUnit\Framework\TestCase
{
    private function validateDeviceName(string $parent, string $name): array
    {
        (new AppConfig())->update('application.configDir', __DIR__ . '/VlanTest');
        Config::getInstance()->forceReload();

        $model = new Vlan();
        $vlan = $model->vlan->Add();
        $vlan->if = $parent;
        $vlan->tag = 100;
        $vlan->pcp = 0;
        $vlan->vlanif = $name;

        $result = [];
        foreach ($model->performValidation(true) as $message) {
            if (str_ends_with($message->getField(), '.vlanif')) {
                $result[] = $message->getMessage();
            }
        }
        return $result;
    }

    public function validDeviceNamesProvider(): array
    {
        return [
            ['em0', 'vlan0'],
            ['em0', 'vlan5'],
            ['em0', 'vlan55'],
            ['em0', 'vlan100'],
            ['em0', 'vlan0.1.104'],
            ['em0', 'vlan12345678901'],
            ['vlan100', 'qinq5'],
            ['vlan100', 'qinq0.3.4'],
        ];
    }

    /**
     * @dataProvider validDeviceNamesProvider
     */
    public function testValidDeviceNames(string $parent, string $name): void
    {
        $this->assertSame([], $this->validateDeviceName($parent, $name));
    }

    public function invalidDeviceNamesProvider(): array
    {
        return [
            ['em0', 'vlan'],
            ['em0', 'vlan.100'],
            ['em0', 'vlan-name'],
            ['em0', 'vlan123456789012'],
            ['em0', 'qinq100'],
            ['vlan100', 'vlan200'],
        ];
    }

    /**
     * @dataProvider invalidDeviceNamesProvider
     */
    public function testInvalidDeviceNames(string $parent, string $name): void
    {
        $this->assertNotSame([], $this->validateDeviceName($parent, $name));
    }
}
