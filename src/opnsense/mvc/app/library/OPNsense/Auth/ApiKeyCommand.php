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

namespace OPNsense\Auth;

class ApiKeyCommand
{
    private $api;

    public function __construct($api = null)
    {
        $this->api = $api ?? new API();
    }

    public function run(array $argv, callable $stdout, callable $stderr): int
    {
        $options = $this->parseArguments($argv);
        if (!empty($options['error'])) {
            $stderr($options['error'] . "\n");
            $this->usage($stderr);
            return 1;
        }
        if ($options['help']) {
            $this->usage($stdout);
            return 0;
        }

        $args = $options['args'];
        if (count($args) === 0) {
            $this->usage($stderr);
            return 1;
        }

        switch ($args[0]) {
            case 'create':
                if (count($args) !== 1) {
                    $this->usage($stderr);
                    return 1;
                }
                $data = $this->api->createKey($options['username']);
                if ($data === false) {
                    $stderr(sprintf('User "%s" was not found.', $options['username']) . "\n");
                    return 1;
                }
                if ($options['json']) {
                    $stdout(json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
                } else {
                    $stdout(sprintf("key=%s\nsecret=%s\n", $data['key'], $data['secret']));
                }
                return 0;

            case 'delete':
                if (count($args) !== 2) {
                    $this->usage($stderr);
                    return 1;
                }
                if (!$this->api->dropKey($options['username'], $args[1])) {
                    $stderr(sprintf('API key was not found for user "%s".', $options['username']) . "\n");
                    return 1;
                }
                return 0;

            default:
                $this->usage($stderr);
                return 1;
        }
    }

    private function parseArguments(array $argv): array
    {
        $result = [
            'username' => 'root',
            'json' => false,
            'help' => false,
            'args' => [],
            'error' => '',
        ];

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--') {
                $result['args'] = array_slice($argv, $i + 1);
                break;
            } elseif ($arg === '-h' || $arg === '--help') {
                $result['help'] = true;
            } elseif ($arg === '-j' || $arg === '--json') {
                $result['json'] = true;
            } elseif ($arg === '-u' || $arg === '--user') {
                if (!isset($argv[$i + 1]) || $argv[$i + 1] === '') {
                    $result['error'] = 'A username is required.';
                    break;
                }
                $result['username'] = $argv[++$i];
            } elseif (str_starts_with($arg, '--user=')) {
                $result['username'] = substr($arg, strlen('--user='));
                if ($result['username'] === '') {
                    $result['error'] = 'A username is required.';
                    break;
                }
            } elseif (str_starts_with($arg, '-')) {
                $result['error'] = sprintf('Unknown option "%s".', $arg);
                break;
            } else {
                $result['args'] = array_slice($argv, $i);
                break;
            }
        }
        return $result;
    }

    private function usage(callable $output): void
    {
        $output("Usage:\n");
        $output("  opnsense-apikey [-u username] [-j|--json] create\n");
        $output("  opnsense-apikey [-u username] delete apikey\n");
    }
}
