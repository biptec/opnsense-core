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
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
 * STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace tests\OPNsense\Auth;

use OPNsense\Auth\ApiKeyCommand;

class ApiKeyStub
{
    public $createResult = ['key' => 'test-key', 'secret' => 'test-secret'];
    public $deleteResult = true;
    public $createdFor = null;
    public $deletedFor = null;

    public function createKey($username)
    {
        $this->createdFor = $username;
        return $this->createResult;
    }

    public function dropKey($username, $key)
    {
        $this->deletedFor = [$username, $key];
        return $this->deleteResult;
    }
}

class ApiKeyCommandTest extends \PHPUnit\Framework\TestCase
{
    private function runCommand(array $argv, ApiKeyStub $api): array
    {
        $stdout = '';
        $stderr = '';
        $status = (new ApiKeyCommand($api))->run(
            $argv,
            function ($message) use (&$stdout) {
                $stdout .= $message;
            },
            function ($message) use (&$stderr) {
                $stderr .= $message;
            }
        );
        return [$status, $stdout, $stderr];
    }

    public function testHelp(): void
    {
        [$status, $stdout, $stderr] = $this->runCommand(['opnsense-apikey', '--help'], new ApiKeyStub());
        $this->assertSame(0, $status);
        $this->assertStringStartsWith("Usage:\n", $stdout);
        $this->assertSame('', $stderr);
    }

    public function testCreateForDefaultUser(): void
    {
        $api = new ApiKeyStub();
        [$status, $stdout, $stderr] = $this->runCommand(['opnsense-apikey', 'create'], $api);
        $this->assertSame(0, $status);
        $this->assertSame("key=test-key\nsecret=test-secret\n", $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame('root', $api->createdFor);
    }

    public function testCreateJsonForSelectedUser(): void
    {
        $api = new ApiKeyStub();
        [$status, $stdout] = $this->runCommand(
            ['opnsense-apikey', '--user=automation', '--json', 'create'],
            $api
        );
        $this->assertSame(0, $status);
        $this->assertSame("{\"key\":\"test-key\",\"secret\":\"test-secret\"}\n", $stdout);
        $this->assertSame('automation', $api->createdFor);
    }

    public function testCreateForMissingUser(): void
    {
        $api = new ApiKeyStub();
        $api->createResult = false;
        [$status, $stdout, $stderr] = $this->runCommand(['opnsense-apikey', '-u', 'missing', 'create'], $api);
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('was not found', $stderr);
    }

    public function testDelete(): void
    {
        $api = new ApiKeyStub();
        [$status, $stdout, $stderr] = $this->runCommand(
            ['opnsense-apikey', '--user', 'automation', 'delete', 'test-key'],
            $api
        );
        $this->assertSame(0, $status);
        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame(['automation', 'test-key'], $api->deletedFor);
    }

    public function testDeleteMissingKey(): void
    {
        $api = new ApiKeyStub();
        $api->deleteResult = false;
        [$status, $stdout, $stderr] = $this->runCommand(['opnsense-apikey', 'delete', 'missing'], $api);
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('API key was not found', $stderr);
    }

    public function invalidArgumentsProvider(): array
    {
        return [
            [['opnsense-apikey']],
            [['opnsense-apikey', '--unknown']],
            [['opnsense-apikey', '--user']],
            [['opnsense-apikey', 'create', 'extra']],
            [['opnsense-apikey', 'delete']],
            [['opnsense-apikey', 'unknown-command']],
        ];
    }

    /**
     * @dataProvider invalidArgumentsProvider
     */
    public function testInvalidArguments(array $argv): void
    {
        [$status, $stdout, $stderr] = $this->runCommand($argv, new ApiKeyStub());
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Usage:', $stderr);
    }
}
