<?php

/*
 * Copyright (C) 2019 Deciso B.V.
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

namespace OPNsense\Interfaces\Api;

use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Base\UserException;
use OPNsense\Base\ApiMutableModelControllerBase;

class VxlanSettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'vxlan';
    protected static $internalModelClass = 'OPNsense\Interfaces\VxLan';

    private function interfaceAssigned($if)
    {
        $configHandle = Config::getInstance()->object();
        if (!empty($configHandle->interfaces)) {
            foreach ($configHandle->interfaces->children() as $node) {
                if ((string)$node->if == $if) {
                    return true;
                }
            }
        }
        return false;
    }

    public function searchItemAction()
    {
        return $this->searchBase("vxlan", null, "vxlanid");
    }

    public function setItemAction($uuid)
    {
        return $this->setBase("vxlan", "vxlan", $uuid);
    }

    public function addItemAction()
    {
        return $this->addBase("vxlan", "vxlan");
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase("vxlan", "vxlan", $uuid);
    }

    public function delItemAction($uuids)
    {
        Config::getInstance()->lock();
        foreach (!empty($uuids) ? explode(',', $uuids) : [] as $uuid) {
            $node = $this->getModel()->getNodeByReference('vxlan.' . $uuid);
            $device = $node != null ? 'vxlan' . (string)$node->deviceId : null;
            if ($device != null && $this->interfaceAssigned($device)) {
                throw new UserException(
                    gettext("This VXLAN cannot be deleted because it is assigned as an interface.")
                );
            }
        }
        return $this->delBase("vxlan", $uuids);
    }

    public function reconfigureAction()
    {
        $result = array("status" => "failed");
        if ($this->request->isPost()) {
            $backend = new Backend();
            $result['status'] = strtolower(trim($backend->configdRun('interface vxlan configure')));
        }
        return $result;
    }
}
