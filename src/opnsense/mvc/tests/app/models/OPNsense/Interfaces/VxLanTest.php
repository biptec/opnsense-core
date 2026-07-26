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

use OPNsense\Interfaces\VxLan;

class VxLanTest extends \PHPUnit\Framework\TestCase
{
    private array $settings = [
        'vxlanid' => '4242',
        'vxlanlocal' => '172.31.255.1',
        'vxlanlocalport' => '4789',
        'vxlanremote' => '172.31.255.2',
        'vxlanremoteport' => '4789',
        'vxlangroup' => '',
        'vxlandev' => '',
    ];

    public function testUnchangedSettingsDoNotRequireRecreate(): void
    {
        $this->assertFalse(VxLan::requiresRecreate($this->settings, $this->settings));
    }

    public function testDefaultPortsAreNormalized(): void
    {
        $current = $this->settings;
        unset($current['vxlanlocalport'], $current['vxlanremoteport']);
        $this->assertFalse(VxLan::requiresRecreate($current, $this->settings));
    }

    public function testPortChangeRequiresRecreate(): void
    {
        $desired = $this->settings;
        $desired['vxlanremoteport'] = '4790';
        $this->assertTrue(VxLan::requiresRecreate($this->settings, $desired));
    }

    public function testEndpointChangeRequiresRecreate(): void
    {
        $desired = $this->settings;
        $desired['vxlanremote'] = '172.31.255.3';
        $this->assertTrue(VxLan::requiresRecreate($this->settings, $desired));
    }

    public function testVniChangeRequiresRecreate(): void
    {
        $desired = $this->settings;
        $desired['vxlanid'] = '4243';
        $this->assertTrue(VxLan::requiresRecreate($this->settings, $desired));
    }

    public function testKnownDeviceChangeRequiresRecreate(): void
    {
        $current = $this->settings;
        $current['vxlandev'] = 'vtnet0';
        $desired = $this->settings;
        $desired['vxlandev'] = 'vtnet1';
        $this->assertTrue(VxLan::requiresRecreate($current, $desired));
    }
}
