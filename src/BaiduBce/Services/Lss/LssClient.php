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

namespace BaiduBce\Services\Lss;

use BaiduBce\Auth\BceV1Signer;
use BaiduBce\BceBaseClient;
use BaiduBce\Exception\BceClientException;
use BaiduBce\Http\BceHttpClient;
use BaiduBce\Http\HttpContentTypes;
use BaiduBce\Http\HttpHeaders;
use BaiduBce\Http\HttpMethod;
use BaiduBce\Util\DateUtils;

class LssClient extends BceBaseClient
{

    private $signer;
    private $httpClient;
    private $prefix = '/v5';

    /**
     * The LssClient constructor
     *
     * @param array $config The client configuration
     */
    function __construct(array $config)
    {
        parent::__construct($config, 'LssClient');
        $this->signer = new BceV1Signer();
        $this->httpClient = new BceHttpClient();
    }

    /**
     * Create a session.
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *          description: string, session description
     *          preset: string, session preset name
     *          region: string, session region, valid values: bj/gz
     *          pullUrl: string, pulling session's live source url
     *          notification: string, session notification name
     *          securityPolicy: string, session security policy name
     *      }
     * @return mixed created session detail
     */
    public function createSession($options = array())
    {
        list($config, $description, $preset, $recording, $region, $pullUrl, $notification, $securityPolicy) = $this->parseOptions(
            $options,
            'config',
            'description',
            'preset',
            'recording',
            'region',
            'pullUrl',
            'notification',
            'securityPolicy'
        );

        $body = array();

        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($preset !== null) {
            $body['preset'] = $preset;
        }
        if ($recording !== null) {
            $body['recording'] = $recording;
        }
        if ($notification !== null) {
            $body['notification'] = $notification;
        }
        if ($securityPolicy !== null) {
            $body['securityPolicy'] = $securityPolicy;
        }

        $publish = array();
        if ($region !== null) {
            $publish['region'] = $region;
        }
        if ($pullUrl !== null) {
            $publish['pullUrl'] = $pullUrl;
        }
        if (count($publish) > 0) {
            $body['publish'] = $publish;
        }

        return $this->sendRequest(
            HttpMethod::POST,
            array(
                'config' => $config,
                'body' => json_encode($body, JSON_FORCE_OBJECT),
            ),
            '/session'
        );
    }

    /**
     * Get a session.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed session detail
     * @throws BceClientException
     */
    public function getSession($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Get a session with token in push/play url.
     *
     * More specifically, if the security policy used by this session enables push/play auth,
     * its push/play url need be updated with a token parameter computed from the session id,
     * the session stream, etc., and the security policy auth key. This function returns the
     * detailed session info with push/play url updated, if necessary.
     *
     * @param $sessionId string, session id
     * @param int $expireInMinute number, the push/play url expire time in minute
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function getSessionWithToken($sessionId, $expireInMinute = 120, $options = array())
    {
        $session = $this->getSession($sessionId, $options);
        $securityPolicy = $this->getSecurityPolicy($session->securityPolicy);
        $currentTime = new \DateTime();
        $expireTime = $currentTime->add(new \DateInterval("PT{$expireInMinute}M"));
        $expireTime->setTimezone(DateUtils::$UTC_TIMEZONE);
        $expireString = DateUtils::formatAlternateIso8601Date($expireTime);
        if ($securityPolicy->auth->play) {
            if (isset($session->play->hlsUrl)) {
                $hlsUrl = $session->play->hlsUrl;
                $hlsTokenPlain = '/' . $sessionId . '/live.m3u8;' . $expireString;
                $hlsToken = hash_hmac('sha256', $hlsTokenPlain, $securityPolicy->auth->key);
                $session->play->hlsUrl = $hlsUrl . '?token=' . $hlsToken . '&expire=' . $expireString;
            }

            if (isset($session->play->rtmpUrl)) {
                $rtmpUrl = $session->play->rtmpUrl;
                $rtmpTokenPlain = $sessionId . ';' . $expireString;
                $rtmpToken = hash_hmac('sha256', $rtmpTokenPlain, $securityPolicy->auth->key);
                $session->play->rtmpUrl = $rtmpUrl . '?token=' . $rtmpToken . '&expire=' . $expireString;
            }
        }

        if ($securityPolicy->auth->push) {
            if (isset($session->publish->pushUrl)) {
                $pushUrl = $session->publish->pushUrl;
                $pushTokenPlain = $session->publish->pushStream . ';' . $expireString;
                $pushToken = hash_hmac('sha256', $pushTokenPlain, $securityPolicy->auth->key);
                $session->publish->pushUrl = $pushUrl . '?token=' . $pushToken . '&expire=' . $expireString;
            }
        }

        return $session;
    }

    /**
     * List sessions.
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed session list
     */
    public function listSessions($options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            '/session'
        );
    }

    /**
     * List sessions with a status filter.
     *
     * @param $status string, session status as a filter,
     *                 valid values: READY / ONGOING / PAUSED
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed session list
     * @throws BceClientException
     */
    public function listSessionsByStatus($status, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($status)) {
            throw new BceClientException("The parameter status "
                . "should NOT be null or empty string");
        }

        $params = array(
            'status' => $status,
        );

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
                'params' => $params,
            ),
            '/session'
        );
    }

    /**
     * Begin a pulling session.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function pullSession($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        $params = array(
            'pull' => null,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/session/$sessionId"
        );
    }


    /**
     * Pause a session.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function pauseSession($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        $params = array(
            'pause' => null,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Resume a session.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function resumeSession($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        $params = array(
            'resume' => null,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Refresh a session.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed refreshed session detail
     * @throws BceClientException
     */
    public function refreshSession($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        $params = array(
            'refresh' => null,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Delete a session.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function deleteSession($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::DELETE,
            array(
                'config' => $config,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Start recording a session.
     *
     * @param $sessionId string, session id
     * @param $recording string, recording name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function startRecording($sessionId, $recording, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        if (empty($recording)) {
            throw new BceClientException("The parameter recording "
                . "should NOT be null or empty string");
        }

        $params = array(
            'recording' => $recording,
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Stop recording a session.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function stopRecording($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        $params = array(
            'recording' => '',
        );

        return $this->sendRequest(
            HttpMethod::PUT,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Get session real-time source info.
     *
     * @param $sessionId string, session id
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function getSessionSourceInfo($sessionId, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($sessionId)) {
            throw new BceClientException("The parameter sessionId "
                . "should NOT be null or empty string");
        }

        $params = array(
            'sourceInfo' => null,
        );

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
                'params' => $params,
            ),
            "/session/$sessionId"
        );
    }

    /**
     * Create a preset.
     *
     * @param $name string, preset name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *          description: string, preset description
     *          forwardOnly: boolean, whether the preset is forward-only.
     *                      when forwardOnly = true, should not set audio/video.
     *          audio: { audio output settings
     *              bitRateInBps: number, output audio bit rate
     *              sampleRateInHz: number, output audio sample rate
     *              channels: number, output audio
     *          },
     *          video: { video output settings
     *              codec: string, output video codec, valid values: h264
     *              codecOptions: {
     *                  profile: string, valid values: baseline/main/high
     *              }
     *              bitRateInBps: number, output video bit rate
     *              maxFrameRate: number, output video max frame rate
     *              maxWidthInPixel: number, output video max width
     *              maxHeightInPixel: number, output video max height
     *              sizingPolicy: string, output video sizing policy,
     *                            valid values: keep/stretch/shrinkToFit
     *          },
     *          hls: { hls output settings
     *              segmentTimeInSecond: number, each hls segment time length
     *              segmentListSize: number, length of segment list in the output m3u8
     *              adaptive: boolean, whether adaptive hls is enabled
     *          },
     *          rmtp: { rmtp output settings
     *              gopCache: boolean, whether or not cache 1 gop
     *          }
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function createPreset($name, $options = array())
    {
        list($config, $description, $forwardOnly, $audio, $video, $hls, $rtmp) = $this->parseOptions(
            $options,
            'config',
            'description',
            'forwardOnly',
            'audio',
            'video',
            'hls',
            'rtmp'
        );

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }

        $body = array(
            'name' => $name,
        );

        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($forwardOnly !== null) {
            $body['forwardOnly'] = $forwardOnly;
        }
        if ($audio !== null) {
            $body['audio'] = $audio;
        }
        if ($video !== null) {
            $body['video'] = $video;
        }
        if ($hls !== null) {
            $body['hls'] = $hls;
        }
        if ($rtmp !== null) {
            $body['rtmp'] = $rtmp;
        }

        return $this->sendRequest(
            HttpMethod::POST,
            array(
                'config' => $config,
                'body' => json_encode($body),
            ),
            '/preset'
        );
    }

    /**
     * Get a preset.
     *
     * @param $name string, preset name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed preset detail
     * @throws BceClientException
     */
    public function getPreset($name, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            "/preset/$name"
        );
    }

    /**
     * List presets.
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @param array $options
     * @return mixed
     */
    public function listPresets($options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            '/preset'
        );
    }

    /**
     * Delete a preset.
     *
     * @param $name string, preset name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function deletePreset($name, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::DELETE,
            array(
                'config' => $config,
            ),
            "/preset/$name"
        );
    }

    /**
     * Creates a notification.
     *
     * @param $name string, notification name
     * @param $endpoint string, notification endpoint
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function createNotification($name, $endpoint, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }
        if (empty($endpoint)) {
            throw new BceClientException("The parameter endpoint "
                . "should NOT be null or empty string");
        }

        $body = array(
            'name' => $name,
            'endpoint' => $endpoint,
        );

        return $this->sendRequest(
            HttpMethod::POST,
            array(
                'config' => $config,
                'body' => json_encode($body),
            ),
            '/notification'
        );
    }

    /**
     * Gets a notification.
     *
     * @param $name string, notification name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed notification detail
     * @throws BceClientException
     */
    public function getNotification($name, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            "/notification/$name"
        );
    }

    /**
     * List notifications.
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed notification list
     */
    public function listNotifications($options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            '/notification'
        );
    }

    /**
     * Delete a notification.
     *
     * @param $name string notification name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed
     * @throws BceClientException
     */
    public function deleteNotification($name, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::DELETE,
            array(
                'config' => $config,
            ),
            "/notification/$name"
        );
    }

    /**
     * Get a security policy.
     *
     * @param $name string, security policy name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed security policy detail
     * @throws BceClientException
     */
    public function getSecurityPolicy($name, $options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            "/securitypolicy/$name"
        );
    }

    /**
     * List security policies.
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed security policy list
     */
    public function listSecurityPolicies($options = array())
    {
        list($config) = $this->parseOptions($options, 'config');

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            '/securitypolicy'
        );
    }

    /**
     * Get a recording.
     *
     * @param $name string, recording name
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed security policy detail
     * @throws BceClientException
     */
    public function getRecording($name, $options = array()) {
        list($config) = $this->parseOptions($options, 'config');

        if (empty($name)) {
            throw new BceClientException("The parameter name "
                . "should NOT be null or empty string");
        }

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            "/recording/$name"
        );
    }

    /**
     * List recordings.
     *
     * @param array $options Supported options:
     *      {
     *          config: the optional bce configuration, which will overwrite the
     *                  default client configuration that was passed in constructor.
     *      }
     * @return mixed recording list
     */
    public function listRecordings($options = array()) {
        list($config) = $this->parseOptions($options, 'config');

        return $this->sendRequest(
            HttpMethod::GET,
            array(
                'config' => $config,
            ),
            '/recording'
        );
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