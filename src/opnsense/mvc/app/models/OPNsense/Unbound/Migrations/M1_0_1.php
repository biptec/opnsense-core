<?php

/*
 * Copyright (C) 2022 Deciso B.V.
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

namespace OPNsense\Unbound\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Base\FieldTypes\BooleanField;
use OPNsense\Base\FieldTypes\NetworkField;
use OPNsense\Base\FieldTypes\PortField;
use OPNsense\Core\Config;

class M1_0_1 extends BaseModelMigration
{
    /**
     * Migrate older models into shared model
     * @param $model
     */
    public function run($model)
    {
        $config = Config::getInstance()->object();

        if (!empty($config->unbound->hosts)) {
            foreach ($config->unbound->hosts as $old_host) {
                $new_host = $model->hosts->host->add();

                /* Backwards compatibility for records created before introducing RR types. */
                if (!isset($old_host->rr)) {
                    $old_host->rr = (strpos($old_host->ip, ':') !== false) ? 'AAAA' : 'A';
                }

                $host_data = [
                    'enabled' => 1,
                    'hostname' => !empty($old_host->host) ? $old_host->host : null,
                    'domain' => $old_host->domain,
                    'rr' => $old_host->rr,
                    'server' => $old_host->rr == 'A' ? $old_host->ip : null,
                    'mxprio' => $old_host->rr == 'MX' ? $old_host->mxprio : null,
                    'mx' => $old_host->rr == 'MX' ? $old_host->mx : null,
                    'description' => !empty($old_host->descr) ? $old_host->descr : null
                ];

                $new_host->setNodes($host_data);

                $uuid = $new_host->getAttribute('uuid');
                foreach ($old_host->aliases->item as $old_alias) {
                    if (!empty($old_alias)) {
                        $new_alias = $model->aliases->alias->add();
                        $alias_data = [
                            'enabled' => 1,
                            'host' => $uuid,
                            'domain' => $old_alias->domain,
                            'hostname' => !empty($old_alias->host) ? $old_alias->host : null
                        ];
                        $new_alias->setNodes($alias_data);
                    }
                }
            }
        }

        if (!empty($config->unbound->domainoverrides)) {
            foreach ($config->unbound->domainoverrides as $old_domain) {
                $new_domain = $model->domains->domain->add();
                $domain_data = [
                    'enabled' => 1,
                    'domain' => $old_domain->domain,
                    'server' => $old_domain->ip,
                    'description' => !empty($old_domain->descr) ? $old_domain->descr : null
                ];
                $new_domain->setNodes($domain_data);
            }
        }
    }

    /**
     * cleanup old config after config save
     * @param $model
     */
    public function post($model)
    {
        $cfgObj = Config::getInstance()->object();
        unset($cfgObj->unbound->hosts, $cfgObj->unbound->domainoverrides);
    }
}
