<?php

/**
 *    Copyright (C) 2015-2017 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Base\Validators;

use OPNsense\Base\BaseValidator;
use Phalcon\Messages\Message;

/**
 * Class MacaddrValidator validate MAC address.
 */
class MacaddrValidator extends BaseValidator
{
    /**
     * Executes macaddr validation.
     *
     * @param string $attribute
     */
    public function validate($validator, $attribute): bool
    {
        $value = $validator->getValue($attribute);
        $msg = $this->getOption('message');

        $result = 1 == preg_match('/^[0-9A-F]{2}(?:[:][0-9A-F]{2}){5}$/i', $value) ? true : false;
        if (!$result) {
            // append validation message
            $validator->appendMessage(new Message($msg, $attribute, 'MacaddrValidator'));
        }

        return $result;
    }
}