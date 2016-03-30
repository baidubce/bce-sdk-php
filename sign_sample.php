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

namespace BaiduBce\Auth;

class SignOption
{
    const EXPIRATION_IN_SECONDS = 'expirationInSeconds';

    const HEADERS_TO_SIGN = 'headersToSign';

    const TIMESTAMP = 'timestamp';

    const DEFAULT_EXPIRATION_IN_SECONDS = 1800;

    const MIN_EXPIRATION_IN_SECONDS = 300;

    const MAX_EXPIRATION_IN_SECONDS = 129600;
}

class HttpUtil
{
    // Encode every character according to RFC 3986, except：
    //   1.Alphabet in upper or lower case
    //   2.Numbers
    //   3.Dot '.', wave '~', minus '-' and underline '_'
    public static $PERCENT_ENCODED_STRINGS;

    //Fill encoding array
    public static function __init()
    {
        HttpUtil::$PERCENT_ENCODED_STRINGS = array();
        for ($i = 0; $i < 256; ++$i) {
            HttpUtil::$PERCENT_ENCODED_STRINGS[$i] = sprintf("%%%02X", $i);
        }

        foreach (range('a', 'z') as $ch) {
            HttpUtil::$PERCENT_ENCODED_STRINGS[ord($ch)] = $ch;
        }

        foreach (range('A', 'Z') as $ch) {
            HttpUtil::$PERCENT_ENCODED_STRINGS[ord($ch)] = $ch;
        }

        foreach (range('0', '9') as $ch) {
            HttpUtil::$PERCENT_ENCODED_STRINGS[ord($ch)] = $ch;
        }

        HttpUtil::$PERCENT_ENCODED_STRINGS[ord('-')] = '-';
        HttpUtil::$PERCENT_ENCODED_STRINGS[ord('.')] = '.';
        HttpUtil::$PERCENT_ENCODED_STRINGS[ord('_')] = '_';
        HttpUtil::$PERCENT_ENCODED_STRINGS[ord('~')] = '~';
    }

    //keep slash '/' in encoding result of uri
    public static function urlEncodeExceptSlash($path)
    {
        return str_replace("%2F", "/", HttpUtil::urlEncode($path));
    }

    public static function urlEncode($value)
    {
        $result = '';
        for ($i = 0; $i < strlen($value); ++$i) {
            $result .= HttpUtil::$PERCENT_ENCODED_STRINGS[ord($value[$i])];
        }
        return $result;
    }

    public static function getCanonicalQueryString(array $parameters)
    {
        if (count($parameters) == 0) {
            return '';
        }

        $parameterStrings = array();
        foreach ($parameters as $k => $v) {
            //Skip authorization in headers
            if (strcasecmp('Authorization', $k) == 0) {
                continue;
            }
            if (!isset($k)) {
                throw new \InvalidArgumentException(
                    "parameter key should not be null"
                );
            }
            if (isset($v)) {
                $parameterStrings[] = HttpUtil::urlEncode($k)
                    . '=' . HttpUtil::urlEncode((string) $v);
            } else {
                $parameterStrings[] = HttpUtil::urlEncode($k) . '=';
            }
        }
        //Sort in alphabet order
        sort($parameterStrings);

        //Catenate with &
        return implode('&', $parameterStrings);
    }

    public static function getCanonicalURIPath($path)
    {
        //empty path '/'
        if (empty($path)) {
            return '/';
        } else {
            //Uri should begin with slash '/'
            if ($path[0] == '/') {
                return HttpUtil::urlEncodeExceptSlash($path);
            } else {
                return '/' . HttpUtil::urlEncodeExceptSlash($path);
            }
        }
    }

    public static function getCanonicalHeaders($headers)
    {
        if (count($headers) == 0) {
            return '';
        }

        $headerStrings = array();
        foreach ($headers as $k => $v) {
            if ($k === null) {
                continue;
            }
            if ($v === null) {
                $v = '';
            }
            $headerStrings[] = HttpUtil::urlEncode(strtolower(trim($k))) . ':' . HttpUtil::urlEncode(trim($v));
        }
        //Sort in alphabet order
        sort($headerStrings);

        //Catenate with '\n'
        return implode("\n", $headerStrings);
    }
}
HttpUtil::__init();


class SampleSigner
{

    const BCE_AUTH_VERSION = "bce-auth-v1";
    const BCE_PREFIX = 'x-bce-';

    // If you don't specify header_to_sign, will use:
    //   1.host
    //   2.content-md5
    //   3.content-length
    //   4.content-type
    //   5.all the headers begin with x-bce-
    public static $defaultHeadersToSign;

    public static function  __init()
    {
        SampleSigner::$defaultHeadersToSign = array(
            "host",
            "content-length",
            "content-type",
            "content-md5",
        );
    }

    public function sign(
        array $credentials,
        $httpMethod,
        $path,
        $headers,
        $params,
        $options = array()
    ) {
        if (!isset($options[SignOption::EXPIRATION_IN_SECONDS])) {
            $expirationInSeconds = SignOption::DEFAULT_EXPIRATION_IN_SECONDS;
        } else {
            $expirationInSeconds = $options[SignOption::EXPIRATION_IN_SECONDS];
        }

        $accessKeyId = $credentials['ak'];
        $secretAccessKey = $credentials['sk'];

        //Notice: timestamp should be UTC
        if (!isset($options[SignOption::TIMESTAMP])) {
            $timestamp = new \DateTime();
        } else {
            $timestamp = $options[SignOption::TIMESTAMP];
        }
        $timestamp->setTimezone(new \DateTimeZone("UTC"));

        //Generate authString
        $authString = SampleSigner::BCE_AUTH_VERSION . '/' . $accessKeyId . '/'
            . $timestamp->format("Y-m-d\TH:i:s\Z") . '/' . $expirationInSeconds;

        //Generate sign key with auth-string and SK using SHA-256
        $signingKey = hash_hmac('sha256', $authString, $secretAccessKey);

        //Generate canonical uri
        $canonicalURI = HttpUtil::getCanonicalURIPath($path);

        //Generate canonical query string
        $canonicalQueryString = HttpUtil::getCanonicalQueryString($params);

        //Fill headersToSign to specify which header do you want to sign
        $headersToSign = null;
        if (isset($options[SignOption::HEADERS_TO_SIGN])) {
            $headersToSign = $options[SignOption::HEADERS_TO_SIGN];
        }

        //Generate canonical headers
        $canonicalHeader = HttpUtil::getCanonicalHeaders(
            SampleSigner::getHeadersToSign($headers, $headersToSign)
        );

        $signedHeaders = '';
        if ($headersToSign !== null) {
            $signedHeaders = strtolower(
                trim(implode(";", array_keys($headersToSign)))
            );
        }

        //Generate canonical request
        $canonicalRequest = "$httpMethod\n$canonicalURI\n"
            . "$canonicalQueryString\n$canonicalHeader";

        //Generate signature with canonical request and sign key using SHA-256
        $signature = hash_hmac('sha256', $canonicalRequest, $signingKey);

        //.Catenate result string
        $authorizationHeader = "$authString/$signedHeaders/$signature";

        return $authorizationHeader;
    }

    public static function getHeadersToSign($headers, $headersToSign)
    {
        //Do not sign headers whose value is empty after trim
        $filter_empty = function($v) {
            return trim((string) $v) !== '';
        };
        $headers = array_filter($headers, $filter_empty);

        //Trim key in headers and change them to lower case
        $trim_and_lower = function($str){
            return strtolower(trim($str));
        };
        $temp = array();
        $process_keys = function($k, $v) use(&$temp, $trim_and_lower) {
            $temp[$trim_and_lower($k)] = $v;
        };
        array_map($process_keys, array_keys($headers), $headers);
        $headers = $temp;

        $header_keys = array_keys($headers);

        $filtered_keys = null;
        if ($headersToSign !== null) {
            //Select headers according to headersToSign
            $headersToSign = array_map($trim_and_lower, $headersToSign);
            $filtered_keys = array_intersect_key($header_keys, $headersToSign);
        } else {
            //Select headers by default
            $filter_by_default = function($k) {
                return SampleSigner::isDefaultHeaderToSign($k);
            };
            $filtered_keys = array_filter($header_keys, $filter_by_default);
        }

        return array_intersect_key($headers, array_flip($filtered_keys));
    }

    public static function isDefaultHeaderToSign($header)
    {
        $header = strtolower(trim($header));
        if (in_array($header, SampleSigner::$defaultHeadersToSign)) {
            return true;
        }
        return substr_compare($header, SampleSigner::BCE_PREFIX, 0, strlen(SampleSigner::BCE_PREFIX)) == 0;
    }
}
SampleSigner::__init();



$signer = new SampleSigner();
$credentials = array("ak" => "0b0f67dfb88244b289b72b142befad0c","sk" => "bad522c2126a4618a8125f4b6cf6356f");
$httpMethod = "PUT";
$path = "/v1/test/myfolder/readme.txt";
$headers = array("Host" => "bj.bcebos.com",
                "Content-Length" => 8,
                "Content-MD5" => "0a52730597fb4ffa01fc117d9e71e3a9",
                "Content-Type" => "text/plain",
                "x-bce-date" => "2015-04-27T08:23:49Z");
$params = array("partNumber" => 9, "uploadId" => "VXBsb2FkIElpZS5tMnRzIHVwbG9hZA");
$timestamp = new \DateTime();
$timestamp->setTimestamp(1430123029);
$options = array(SignOption::TIMESTAMP => $timestamp);
$ret = $signer->sign($credentials, $httpMethod, $path, $headers, $params, $options);
print $ret;