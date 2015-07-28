<?php
/*
* Copyright (c) 2014 Baidu.com, Inc. All Rights Reserved
*
* Licensed under the Apache License, Version 2.0 (the "License"); you may not
* use this file except in compliance with the License. You may obtain a copy of
* the License at
*
* Http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
* WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
* License for the specific language governing permissions and limitations under
* the License.
*/

namespace BaiduBce\Http;

use BaiduBce\Log\LogFactory;
use Guzzle\Log\AbstractLogAdapter;
use Psr\Log\LogLevel;

class GuzzleLogAdapter extends AbstractLogAdapter
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        $this->logger = LogFactory::getLogger('HTTP');
    }

    public function log($message, $priority = LOG_INFO, $extras = array())
    {
        // All guzzle logs should be DEBUG, regardless of its own priority.
        if (LogFactory::isDebugEnabled()) {
            $this->logger->log(LogLevel::DEBUG, $message, $extras);
        }
    }
}