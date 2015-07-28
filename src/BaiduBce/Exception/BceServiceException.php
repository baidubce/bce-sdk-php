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

namespace BaiduBce\Exception;

class BceServiceException extends BceBaseException
{
    /**
     * @var int
     */
    private $statusCode;

    /**
     * @var string
     */
    private $requestId;

    /**
     * @var int
     */
    private $errorCode;

    function __construct(
        $requestId,
        $errorCode,
        $errorMessage,
        $statusCode
    ) {
        $this->requestId = $requestId;
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        parent::__construct(
            "$errorMessage [requestId:$requestId "
            . "status:$statusCode code:$errorCode]"
        );
    }

    /**
     * @return string
     */
    public function getRequestId()
    {
        return $this->requestId;
    }

    /**
     * @return int
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }
}