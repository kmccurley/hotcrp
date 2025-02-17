<?php
// curls3result.php -- S3 access using curl functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

/** @template T
 * @inherits S3Result<T> */
class CurlS3Result extends S3Result {
    /** @var ?CurlHandle */
    public $curlh;
    /** @var resource */
    public $hstream;
    /** @var ?resource */
    public $dstream;
    /** @var ?resource */
    private $_fstream;
    /** @var int */
    private $_fsize;
    /** @var int */
    private $_xsize = 0;
    /** @var int */
    public $runindex = 0;
    /** @var list */
    private $tries;
    /** @var float */
    private $start;
    /** @var ?float */
    private $first_start;
    private $observed_success_timeout;

    /** @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string> $args
     * @param callable(S3Result):T $finisher */
    function __construct(S3Client $s3, $skey, $method, $args, $finisher) {
        parent::__construct($s3, $skey, $method, $args, $finisher);
        if (isset($args["content"])) {
            $this->_fsize = strlen($args["content"]);
        } else if (isset($args["content_file"])) {
            $this->_fsize = (int) filesize($args["content_file"]);
            $this->args["Content-Length"] = (string) $this->_fsize;
        } else {
            $this->_fsize = 0;
        }
    }

    /** @param resource $stream
     * @return $this */
    function set_response_body_stream($stream) {
        assert($this->dstream === null);
        $this->dstream = $stream;
        return $this;
    }

    /** @param int $xsize
     * @return $this */
    function set_expected_size($xsize) {
        $this->_xsize = $xsize;
        return $this;
    }

    /** @return $this */
    function reset() {
        $this->status = null;
        $this->observed_success_timeout = false;
        return $this;
    }

    function prepare() {
        $this->clear_result();
        if ($this->curlh === null) {
            $this->curlh = curl_init();
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT, 6 + ($this->_fsize >> 19) + ($this->_xsize >> 26));
            $this->hstream = fopen("php://memory", "w+b");
            curl_setopt($this->curlh, CURLOPT_WRITEHEADER, $this->hstream);
        }
        if (++$this->runindex > 1) {
            curl_setopt($this->curlh, CURLOPT_FRESH_CONNECT, true);
            $tf = $this->runindex;
            if (!$this->observed_success_timeout && $tf > 2) {
                $tf = 2;
            }
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 6 * $tf);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT, 15 * $tf + ($this->_xsize >> 26));
            rewind($this->hstream);
            ftruncate($this->hstream, 0);
            rewind($this->dstream);
            ftruncate($this->dstream, 0);
        } else {
            $this->dstream = $this->dstream ?? fopen("php://memory", "w+b");
            curl_setopt($this->curlh, CURLOPT_FILE, $this->dstream);
        }
        list($this->url, $hdr) = $this->s3->signed_headers($this->skey, $this->method, $this->args);
        curl_setopt($this->curlh, CURLOPT_URL, $this->url);
        curl_setopt($this->curlh, CURLOPT_CUSTOMREQUEST, $this->method);
        if (isset($this->args["content"])) {
            curl_setopt($this->curlh, CURLOPT_POSTFIELDS, $this->args["content"]);
        } else if (isset($this->args["content_file"])) {
            if ($this->_fstream) {
                rewind($this->_fstream);
            } else {
                $this->_fstream = fopen($this->args["content_file"], "rb");
            }
            curl_setopt($this->curlh, CURLOPT_PUT, true);
            curl_setopt($this->curlh, CURLOPT_INFILE, $this->_fstream);
        }
        $hdr[] = "Expect:";
        $hdr[] = "Transfer-Encoding:";
        curl_setopt($this->curlh, CURLOPT_HTTPHEADER, $hdr);
        $this->start = microtime(true);
        if ($this->first_start === null) {
            $this->first_start = $this->start;
        }
    }

    function exec() {
        curl_exec($this->curlh);
    }

    function parse_result() {
        rewind($this->hstream);
        $hstr = stream_get_contents($this->hstream);
        $hstr = preg_replace('/(?:\r\n?|\n)[ \t]+/s', " ", $hstr);
        $this->parse_response_lines(preg_split('/\r\n?|\n/', $hstr));
        $this->status = curl_getinfo($this->curlh, CURLINFO_RESPONSE_CODE);
        if ($this->status === 0) {
            $this->status = null;
        } else if ($this->status === 403) {
            $this->status = $this->s3->check_403();
        }
        if (curl_errno($this->curlh) !== 0) {
            error_log($this->method . " " . $this->url . " -> " . $this->status . " " . $this->status_text . ": CURL error " . curl_errno($this->curlh) . "/" . curl_error($this->curlh));
            if ($this->status >= 200 && $this->status < 300) {
                if (curl_errno($this->curlh) === CURLE_OPERATION_TIMEDOUT) {
                    $this->observed_success_timeout = true;
                }
                $this->status = null;
            }
        }
        if ($this->status === null || $this->status === 500) {
            $now = microtime(true);
            $this->tries[] = [$this->runindex, round(($now - $this->start) * 1000) / 1000, round(($now - $this->first_start) * 1000) / 1000, $this->status, curl_errno($this->curlh)];
            if (S3Client::$retry_timeout_allowance <= 0 || $this->runindex >= 5) {
                trigger_error("S3 error: $this->method $this->skey: curl failed " . json_encode($this->tries), E_USER_WARNING);
                $this->status = 598;
            }
        }
        if ($this->status !== null && S3Client::$verbose) {
            error_log($this->method . " " . $this->url . " -> " . $this->status . " " . $this->status_text);
        }
        if ($this->status !== null && $this->status !== 500) {
            $this->close();
            return true;
        } else {
            return false;
        }
    }

    /** @return $this */
    function run() {
        while ($this->status === null || $this->status === 500) {
            $this->prepare();
            $this->exec();
            if ($this->parse_result()) {
                break;
            }
            $timeout = 0.005 * (1 << $this->runindex);
            S3Client::$retry_timeout_allowance -= $timeout;
            usleep((int) (1000000 * $timeout));
        }
        Conf::$blocked_time += microtime(true) - $this->first_start;
        return $this;
    }

    /** @return string */
    function response_body() {
        $this->run();
        rewind($this->dstream);
        return stream_get_contents($this->dstream);
    }

    function close() {
        if ($this->curlh !== null) {
            curl_close($this->curlh);
            fclose($this->hstream);
            if ($this->_fstream) {
                fclose($this->_fstream);
            }
            $this->curlh = $this->hstream = $this->_fstream = null;
            fflush($this->dstream);
        }
    }
}
