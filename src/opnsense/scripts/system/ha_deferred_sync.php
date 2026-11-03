#!/usr/local/bin/php
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

require_once('script/load_phalcon.php');

use OPNsense\Core\Backend;

const HASYNC_DEFERRED_STATE = '/var/db/hasync_deferred.json';
const HASYNC_DEFERRED_LOCK = '/var/run/hasync_deferred.lock';

function hasync_deferred_items(string $value): array
{
    $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
    if (count($items) === 0) {
        throw new InvalidArgumentException('one or more HA synchronization items are required');
    }
    foreach ($items as $item) {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $item)) {
            throw new InvalidArgumentException('invalid HA synchronization item');
        }
    }
    sort($items);
    return $items;
}

function hasync_deferred_load(): array
{
    if (!file_exists(HASYNC_DEFERRED_STATE)) {
        return [];
    }
    $payload = json_decode((string)file_get_contents(HASYNC_DEFERRED_STATE), true);
    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        throw new RuntimeException('invalid deferred HA synchronization state');
    }
    return hasync_deferred_items(implode(',', $payload['items']));
}

function hasync_deferred_store(array $items): void
{
    if (count($items) === 0) {
        if (file_exists(HASYNC_DEFERRED_STATE) && !unlink(HASYNC_DEFERRED_STATE)) {
            throw new RuntimeException('unable to clear deferred HA synchronization state');
        }
        return;
    }

    $temporary = tempnam(dirname(HASYNC_DEFERRED_STATE), '.hasync_deferred.');
    if ($temporary === false) {
        throw new RuntimeException('unable to create deferred HA synchronization state');
    }
    try {
        $payload = json_encode(['items' => array_values($items)], JSON_UNESCAPED_SLASHES);
        if ($payload === false || file_put_contents($temporary, $payload . "\n") === false) {
            throw new RuntimeException('unable to write deferred HA synchronization state');
        }
        if (!chmod($temporary, 0600)) {
            throw new RuntimeException('unable to protect deferred HA synchronization state');
        }
        if (!rename($temporary, HASYNC_DEFERRED_STATE)) {
            throw new RuntimeException('unable to replace deferred HA synchronization state');
        }
    } finally {
        if (file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

function hasync_deferred_response(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

$mode = $argv[1] ?? '';
$lock = fopen(HASYNC_DEFERRED_LOCK, 'c');
if ($lock === false) {
    hasync_deferred_response(['status' => 'failed', 'message' => 'unable to open deferred HA synchronization lock']);
    exit;
}

$lock_mode = $mode === 'reconcile' ? LOCK_EX | LOCK_NB : LOCK_EX;
if (!flock($lock, $lock_mode)) {
    hasync_deferred_response(['status' => 'ok', 'deferred' => true, 'busy' => true]);
    exit;
}

try {
    $pending = hasync_deferred_load();

    if ($mode === 'enqueue') {
        $requested = hasync_deferred_items($argv[2] ?? '');
        $pending = array_values(array_unique(array_merge($pending, $requested)));
        sort($pending);
        hasync_deferred_store($pending);
    } elseif ($mode !== 'reconcile') {
        throw new InvalidArgumentException('usage: ha_deferred_sync.php enqueue items | reconcile');
    }

    if (count($pending) === 0) {
        hasync_deferred_response(['status' => 'ok', 'items' => [], 'deferred' => false]);
        exit;
    }

    $result = trim((new Backend())->configdpRun('filter sync_deferred', [implode(',', $pending)]));
    if ($result === 'ok') {
        hasync_deferred_store([]);
        hasync_deferred_response(['status' => 'ok', 'items' => $pending, 'deferred' => false]);
    } elseif ($result === 'peer_unavailable') {
        hasync_deferred_response(['status' => 'ok', 'items' => $pending, 'deferred' => true]);
    } else {
        hasync_deferred_response([
            'status' => 'failed',
            'items' => $pending,
            'message' => 'selective HA configuration synchronization failed',
        ]);
    }
} catch (Throwable $error) {
    hasync_deferred_response(['status' => 'failed', 'message' => $error->getMessage()]);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
