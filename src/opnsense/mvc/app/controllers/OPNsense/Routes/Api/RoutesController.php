<?php

/*
 * Copyright (C) 2015-2018 Deciso B.V.
 * Copyright (C) 2017 Fabian Franz
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

namespace OPNsense\Routes\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Routes\Route;
use OPNsense\Routing\Gateways;

/**
 * @package OPNsense\Routes
 */
class RoutesController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'route';
    protected static $internalModelClass = '\OPNsense\Routes\Route';

    private function withRoutingReconfigure(array $result, array $gatewayNames): array
    {
        $interfaces = [];
        $gateways = new Gateways();
        foreach ($gatewayNames as $gatewayName) {
            $interface = $gateways->getInterfaceName(trim((string)$gatewayName));
            if ($interface !== null && $interface !== '') {
                $interfaces[$interface] = true;
            }
        }
        if (!empty($interfaces)) {
            $result['reconfigure'] = ['interfaces' => array_keys($interfaces)];
        }
        return $result;
    }

    /**
     * search routes
     * @return array search results
     * @throws \ReflectionException
     */
    public function searchrouteAction()
    {
        return $this->searchBase("route", null, "description");
    }

    /**
     * Update route with given properties
     * @param string $uuid internal id
     * @return array save result + validation output
     * @throws \OPNsense\Base\ValidationException when field validations fail
     * @throws \ReflectionException when not bound to model
     */
    public function setrouteAction($uuid)
    {
        $node = $this->getBase("route", "route", $uuid);
        $modelNode = (new Route())->getNodeByReference('route.' . $uuid);
        $previousGateway = $modelNode !== null ? (string)$modelNode->gateway : '';
        // delete previous route when changed (one shot, apply should only delete the last known situation)
        if (
            !empty($node['route']['network']) && $_POST['route']['network'] != $node['route']['network']
            && !file_exists("/tmp/delete_route_{$uuid}.todo")
        ) {
            file_put_contents("/tmp/delete_route_{$uuid}.todo", $node['route']['network']);
        }
        $result = $this->setBase("route", "route", $uuid);
        if (($result['result'] ?? '') === 'saved') {
            $current = (new Route())->getNodeByReference('route.' . $uuid);
            $result = $this->withRoutingReconfigure($result, [
                $previousGateway,
                $current !== null ? (string)$current->gateway : '',
            ]);
        }
        return $result;
    }

    /**
     * Add new route and set with attributes from post
     * @return array save result + validation output
     * @throws \OPNsense\Base\ModelException when not bound to model
     * @throws \OPNsense\Base\ValidationException when field validations fail
     * @throws \ReflectionException
     */
    public function addrouteAction()
    {
        $result = $this->addBase("route", "route");
        if (($result['result'] ?? '') === 'saved' && !empty($result['uuid'])) {
            $route = (new Route())->getNodeByReference('route.' . $result['uuid']);
            if ($route !== null) {
                $result = $this->withRoutingReconfigure($result, [(string)$route->gateway]);
            }
        }
        return $result;
    }

    /**
     * Retrieve route settings or return defaults for new one
     * @param $uuid item unique id
     * @return array route content
     * @throws \ReflectionException when not bound to model
     */
    public function getrouteAction($uuid = null)
    {
        return $this->getBase("route", "route", $uuid);
    }

    /**
     * Delete route by uuid, save contents to tmp for removal on apply
     * @param string $uuid internal id
     * @return array save status
     * @throws \OPNsense\Base\ValidationException when field validations fail
     * @throws \ReflectionException when not bound to model
     * @throws \OPNsense\Base\ModelException when not bound to model
     */
    public function delrouteAction($uuid)
    {
        $node = (new Route())->getNodeByReference('route.' . $uuid);
        $gateway = $node !== null ? (string)$node->gateway : '';
        $response = $this->delBase("route", $uuid);
        if (!empty($response['result']) && $response['result'] == 'deleted') {
            // we don't know for sure if this route was already removed, flush to disk to remove on apply
            file_put_contents("/tmp/delete_route_{$uuid}.todo", (string)$node->network);
            $response = $this->withRoutingReconfigure($response, [$gateway]);
        }
        return $response;
    }

    /**
     * @param string $uuid id to toggled
     * @param string|null $disabled set disabled by default
     * @return array status
     * @throws \OPNsense\Base\ValidationException when field validations fail
     * @throws \ReflectionException when not bound to model
     * @throws \OPNsense\Base\ModelException when not bound to model
     */
    public function togglerouteAction($uuid, $enabled = null)
    {
        $node = (new Route())->getNodeByReference('route.' . $uuid);
        $gateway = $node !== null ? (string)$node->gateway : '';
        $result = $this->toggleBase("route", $uuid, $enabled);
        if (!empty($result['changed'])) {
            $result = $this->withRoutingReconfigure($result, [$gateway]);
        }
        return $result;
    }

    /**
     * reconfigure routes
     * @return array reconfigure status
     * @throws \Exception when unable to execute configd command
     */
    public function reconfigureAction()
    {
        if ($this->request->isPost()) {
            $backend = new Backend();
            $command = 'interface routes configure';
            if ($this->request->hasPost('interfaces')) {
                $requested = $this->request->getPost('interfaces');
                if (!is_array($requested) || empty($requested)) {
                    return array('status' => 'failed');
                }
                $configured = array_fill_keys(array_keys(Config::getInstance()->toArray()['interfaces'] ?? []), true);
                $interfaces = [];
                foreach ($requested as $interface) {
                    $interface = trim((string)$interface);
                    if ($interface === '' || !isset($configured[$interface])) {
                        return array('status' => 'failed');
                    }
                    $interfaces[$interface] = true;
                }
                $command = 'interface routes configure_selected ' . implode(',', array_keys($interfaces));
            }
            $bckresult = trim($backend->configdRun($command));
            if ($bckresult == 'OK') {
                $status = 'ok';
            } else {
                $status = "error reloading routes ($bckresult)";
            }

            return array('status' => $status);
        } else {
            return array('status' => 'failed');
        }
    }
}
