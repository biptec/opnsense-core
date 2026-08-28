<?php

/*
 * Copyright (c) 2024 Deciso B.V.
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

namespace OPNsense\Core\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Hasync;

/**
 * Class HasyncStatusController
 * @package OPNsense\Core
 */
class HasyncStatusController extends ApiControllerBase
{
    private function remoteServiceAction($action, $service, $service_id)
    {
        $backend = new Backend();
        $backend->configdRun('system ha exec exec_sync');
        $backend->configdRun('system ha exec reload_templates');
        return json_decode($backend->configdpRun('system ha exec', [$action, $service, $service_id]), true);
    }

    public function versionAction()
    {
        return json_decode((new Backend())->configdRun('system ha exec version'), true);
    }

    private function selectedSyncItems(): array
    {
        $options = json_decode((new Backend())->configdRun('system ha options'), true);
        if (!is_array($options)) {
            return [];
        }
        $selected = array_filter(explode(',', (string)(new Hasync())->syncitems));
        return array_intersect_key($options, array_flip($selected));
    }

    public function servicesAction()
    {
        $data = json_decode((new Backend())->configdRun('system ha services_cached'), true);
        $records = !empty($data['response']) ? $data['response'] : [];
        foreach ($this->selectedSyncItems() as $item => $description) {
            $matched = false;
            foreach ($records as &$record) {
                if (strcasecmp(trim((string)($record['description'] ?? '')), trim((string)$description)) === 0) {
                    $record['sync_item'] = $item;
                    $matched = true;
                    break;
                }
            }
            unset($record);
            if (!$matched) {
                $records[] = [
                    'name' => $item,
                    'description' => $description,
                    'sync_item' => $item,
                    'nocheck' => true,
                ];
            }
        }
        return $this->searchRecordsetBase($records, null, null, function (&$record) {
            $record['uid'] = $record['name'] ?? '';
            if (!empty($record['id'])) {
                $record['uid'] .= '_' . $record['id'];
            }
            return true;
        });
    }

    public function syncAction()
    {
        $items = $this->request->getPost('items');
        if (!$this->request->isPost() || !is_array($items)) {
            return ['status' => 'failed', 'message' => 'one or more HA synchronization items are required'];
        }
        $requested = array_values(array_unique(array_filter(array_map(
            fn($item) => is_string($item) ? trim($item) : '',
            $items
        ))));
        if (count($requested) === 0 || array_filter($requested, fn($item) => !preg_match('/^[A-Za-z0-9_-]+$/D', $item))) {
            return ['status' => 'failed', 'message' => 'invalid HA synchronization item'];
        }

        $selected = $this->selectedSyncItems();
        foreach ($requested as $item) {
            if (!isset($selected[$item])) {
                return ['status' => 'failed', 'message' => sprintf('HA synchronization item %s is not enabled in High Availability settings', $item)];
            }
        }

        $result = json_decode(
            (new Backend())->configdpRun('system ha exec', ['exec_sync', implode(',', $requested), ''], false, 300),
            true
        );
        if (!is_array($result) || ($result['status'] ?? '') !== 'ok') {
            return [
                'status' => 'failed',
                'message' => is_array($result) ? trim((string)($result['message'] ?? 'HA synchronization failed')) : 'invalid HA synchronization response',
            ];
        }
        return ['status' => 'ok', 'items' => $requested];
    }

    public function stopAction($service = null, $service_id = null)
    {
        if ($this->request->isPost()) {
            return $this->remoteServiceAction('stop', $service, $service_id);
        }
        return ["status" => "failed"];
    }

    public function startAction($service = null, $service_id = null)
    {
        if ($this->request->isPost()) {
            return $this->remoteServiceAction('start', $service, $service_id);
        }
        return ["status" => "failed"];
    }

    public function restartAction($service = null, $service_id = null)
    {
        if ($this->request->isPost()) {
            return $this->remoteServiceAction('restart', $service, $service_id);
        }
        return ["status" => "failed"];
    }

    public function restartAllAction($service = null, $service_id = null)
    {
        if ($this->request->isPost()) {
            $backend = new Backend();

            $services = json_decode((new Backend())->configdRun('system ha exec services'), true);
            if (!empty($services['response'])) {
                $backend->configdRun('system ha exec exec_sync');
                $backend->configdRun('system ha exec reload_templates');
                foreach ($services['response'] as $service) {
                    $backend->configdpRun('system ha exec', ['restart', $service['name'], $service['id'] ?? '']);
                }
                return ["status" => "ok", "count" =>  count($services['response'])];
            }

            return $this->remoteServiceAction('restart', $service, $service_id);
        }
        return ["status" => "failed"];
    }
}
