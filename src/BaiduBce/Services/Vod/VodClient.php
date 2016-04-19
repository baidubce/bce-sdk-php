<?php
/*
* Copyright (c) 2015 Baidu.com, Inc. All Rights Reserved
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

namespace BaiduBce\Services\Vod;

use BaiduBce\Auth\BceV1Signer;
use BaiduBce\BceBaseClient;
use BaiduBce\BceClientConfigOptions;
use BaiduBce\Exception\BceClientException;
use BaiduBce\Http\BceHttpClient;
use BaiduBce\Http\HttpContentTypes;
use BaiduBce\Http\HttpHeaders;
use BaiduBce\Http\HttpMethod;
use BaiduBce\Services\Bos\BosClient;

class VodClient extends BceBaseClient
{
    private $signer;
    private $httpClient;
    private $bosClient;
    private $prefix = '/v1';

    /**
     * @param array $config The client configuration to connect VOD Server
     * @param array $bosConfig The client configuration to connect BOS server
     */
    function __construct(array $config, $bosConfig = array())
    {
        parent::__construct($config, 'VodClient', false);
        $this->signer = new BceV1Signer();
        $this->httpClient = new BceHttpClient();
        if (count($bosConfig) == 0) {
            $bosConfig = $config;
            if (isset($bosConfig[BceClientConfigOptions::ENDPOINT])) {
                unset($bosConfig[BceClientConfigOptions::ENDPOINT]);
            }
        }
        $bosConfig[BceClientConfigOptions::REGION] = 'bj';
        $this->bosClient = new BosClient($bosConfig);
    }

    /**
     * apply a vod media
     * Apply a new vod media, get mediaId, sourceBucket, sourceKey.
     * You account have the access to write the sourceBucket and sourceKey.
     * You need upload video to sourceBucket and sourceKey via BosClient,
     * Then call processMedia method to get a VOD media.
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed created vod media info
     * @throws BceClientException
     */
    public function applyMedia($options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        $params = array(
            'apply' => null,
        );
        return $this->sendRequest(
            HttpMethod::POST,
            array(
                'config' => $config,
                'params' => $params,
            ),
            '/media'
        );
    }

    /**
     * process a vod media
     * After applying media, uploading original video to bosClient,
     * you MUST call processMedia method to get a VOD media.
     *
     * @param $mediaId
     * @param $title string, the title of the media
     * @param $description string, the description of the media
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed created vod media info
     * @throws BceClientException
     */
    public function processMedia($mediaId, $title, $description, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        $params = array(
            'process' => null,
        );
        $body = array(
            'title' => $title,
            'description' => $description,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
                'body' => json_encode($body),
            ),
            "/media/$mediaId"
        );
    }

    /**
     * Create a vod media via local file.
     *
     * @param $fileName string, path of local file
     * @param $title string, the title of the media
     * @param $description string, the description of the media, optional
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed created vod media info
     * @throws BceClientException
     */
    public function createMediaFromFile($fileName, $title, $description = '', $options = array())
    {
        if (empty($fileName)) {
            throw new BceClientException("The parameter fileName should NOT be null or empty string");
        }

        if (empty($title)) {
            throw new BceClientException("The parameter title should NOT be null or empty string");
        }

        if (empty($title)) {
            throw new BceClientException("The parameter title should NOT be null or empty string");
        }
        // apply media
        $uploadInfo = $this->applyMedia($options);
        // upload file to bos
        $this->uploadMedia($fileName, $uploadInfo->sourceBucket, $uploadInfo->sourceKey);
        // process media
        return $this->processMedia($uploadInfo->mediaId, $title, $description, $options);
    }


    /**
     * Create a vod media via bos object.
     *
     * @param $bucket string, bos bucket name
     * @param $key    string, bos object key
     * @param $title  string, the title of the media
     * @param $description string, the description of the media, optional
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed created vod media info
     * @throws BceClientException
     */
    public function createMediaFromBosObject($bucket, $key, $title, $description = '', $options = array())
    {
        if (empty($bucket)) {
            throw new BceClientException("The parameter fileName should NOT be null or empty string");
        }
        if (empty($key)) {
            throw new BceClientException("The parameter fileName should NOT be null or empty string");
        }
        if (empty($title)) {
            throw new BceClientException("The parameter title should NOT be null or empty string");
        }

        // apply media
        $uploadInfo = $this->applyMedia($options);
        // copy bos object
        $this->bosClient->copyObject($bucket, $key, $uploadInfo->sourceBucket, $uploadInfo->sourceKey);
        // process media
        return $this->processMedia($uploadInfo->mediaId, $title, $description, $options);
    }

    /**
     * get the info of a vod media by mediaId
     *
     * @param $mediaId string, mediaId of the media
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed media info
     * @throws BceClientException
     */
    public function getMedia($mediaId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($mediaId)) {
            throw new BceClientException("The parameter mediaId should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            "/media/$mediaId"
        );
    }

    /**
     * get the info of current user's all vod media
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed the info of user's all media
     * @throws BceClientException
     */
    public function listMedia($options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            '/media'
        );
    }

    /**
     * update the attributes of a vod media
     *
     * @param $mediaId string, mediaId of the media
     * @param $title string, new title of the media
     * @param $description string, new description of the media
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed the result of updating
     * @throws BceClientException
     */
    public function updateMedia($mediaId, $title, $description, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($mediaId)) {
            throw new BceClientException("The parameter mediaId should NOT be null or empty string");
        }

        if (empty($title)) {
            throw new BceClientException("The parameter title should NOT be null or empty string");
        }

        $body = array(
            'title' => $title,
            'description' => $description,
        );

        $params = array(
            'attributes' => null,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'body' => json_encode($body),
                'params' => $params,
            ),
            "/media/$mediaId"
        );
    }

    /**
     * disable a vod media
     *
     * @param $mediaId string, mediaId of the media
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed the result of disabling
     * @throws BceClientException
     */
    public function disableMedia($mediaId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($mediaId)) {
            throw new BceClientException("The parameter mediaId should NOT be null or empty string");
        }

        $params = array(
            'disable' => null,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/media/$mediaId"
        );
    }

    /**
     * publish a vod media
     *
     * @param $mediaId string, mediaId of the media
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed the result of publishing
     * @throws BceClientException
     */
    public function publishMedia($mediaId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($mediaId)) {
            throw new BceClientException("The parameter mediaId should NOT be null or empty string");
        }

        $params = array(
            'publish' => null,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/media/$mediaId"
        );
    }

    /**
     * delete a vod media
     *
     * @param $mediaId string, mediaId of the media
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed the result of deleting
     * @throws BceClientException
     */
    public function deleteMedia($mediaId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($mediaId)) {
            throw new BceClientException("The parameter mediaId should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::DELETE,
            array(
                'config' => $config,
            ),
            "/media/$mediaId"
        );
    }


    /**
     * get the playable file and cover page of a vod media
     *
     * @param $mediaId string, mediaId of the media
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed the vod media's playable source file and cover page
     * @throws BceClientException
     */
    public function getPlayableUrl($mediaId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($mediaId)) {
            throw new BceClientException("The parameter mediaId should NOT be null or empty string");
        }

        $params = array(
            'media_id' => $mediaId,
        );

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/service/file"
        );
    }


    /**
     * get the source code for a vod media.
     * vod offer 3 kinds of code: html, flash and url.
     * html and flash can be simply embed in your html code,
     * while url can be fill in the address bar of the browser.
     *
     * @param $mediaId string, mediaId of the media
     * @param $width integer, the width of the player size
     * @param $height integer, the height of the player size
     * @param $autoStart boolean, whether the player start to play the media automatically
     * @param $autoDecodeBase64 boolean, the vod restful api return html/object code in base64 by default.
     *   if $autoDecodeBase64 is set, the sdk will decode them automatically
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default vod client configuration that was passed in constructor.
     *      }
     * @return mixed the vod media's playable source file and cover page
     * @throws BceClientException
     */
    public function getMediaPlayerCode($mediaId, $width = 720, $height = 480, $autoStart = true, $autoDecodeBase64 = false, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($mediaId)) {
            throw new BceClientException("The parameter mediaId should NOT be null or empty string");
        }

        $params = array(
            'media_id' => $mediaId,
            'width' => $width,
            'height' => $height,
            'auto_start' => $autoStart,
        );

        $response = $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/service/code"
        );

        if ($autoDecodeBase64) {
            $codes = $response->codes;
            for ($i = 0; $i < count($codes); $i++) {
                if ($codes[$i]->codeType != "url") {
                    $codes[$i]->sourceCode = base64_decode($codes[$i]->sourceCode);
                }
            }
        }

        return $response;
    }



    /**
     * Upload the media source to bos.
     *
     * @param $fileName
     * @param $bucket
     * @param $key
     * @throws \Exception
     */
    private function uploadMedia($fileName, $bucket, $key)
    {
        // init multi-part upload
        $initUploadResponse = $this->bosClient->initiateMultipartUpload($bucket, $key);
        $uploadId = $initUploadResponse->uploadId;

        // do upload part
        try {
            $offset = 0;
            $partNumber = 1;
            $partSize = BosClient::MIN_PART_SIZE;
            $length = $partSize;
            $partList = array();
            $bytesLeft = filesize($fileName);

            while ($bytesLeft > 0) {
                $length = ($length > $bytesLeft) ? $bytesLeft : $length;
                $uploadResponse = $this->bosClient->uploadPartFromFile(
                    $bucket,
                    $key,
                    $uploadId,
                    $partNumber,
                    $fileName,
                    $offset,
                    $length);
                array_push($partList, array(
                    'partNumber' => $partNumber,
                    'eTag' => $uploadResponse->metadata['etag'],
                ));
                $offset += $length;
                $partNumber++;
                $bytesLeft -= $length;
            }

            // complete upload
            $this->bosClient->completeMultipartUpload($bucket, $key, $uploadId, $partList);
        } catch (\Exception $e) {
            $this->bosClient->abortMultipartUpload($bucket, $key, $uploadId);
            throw $e;
        }
    }



    /**
     * Create HttpClient and send request
     * @param string $httpMethod The Http request method
     * @param array $varArgs The extra arguments
     * @param string $requestPath The Http request uri
     * @return mixed The Http response and headers.
     */
    private function sendRequest($httpMethod, array $varArgs, $requestPath = '/')
    {
        $defaultArgs = array(
            'config' => array(),
            'body' => null,
            'headers' => array(),
            'params' => array(),
        );

        $args = array_merge($defaultArgs, $varArgs);
        if (empty($args['config'])) {
            $config = $this->config;
        } else {
            $config = array_merge(
                array(),
                $this->config,
                $args['config']
            );
        }
        if (!isset($args['headers'][HttpHeaders::CONTENT_TYPE])) {
            $args['headers'][HttpHeaders::CONTENT_TYPE] = HttpContentTypes::JSON;
        }
        $path = $this->prefix . $requestPath;
        $response = $this->httpClient->sendRequest(
            $config,
            $httpMethod,
            $path,
            $args['body'],
            $args['headers'],
            $args['params'],
            $this->signer
        );

        $result = $this->parseJsonResult($response['body']);
        $result->metadata = $this->convertHttpHeadersToMetadata($response['headers']);
        return $result;
    }
}