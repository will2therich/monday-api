<?php

namespace TBlack\MondayAPI;

class MondayAPI
{
    private $APIV2_Token;
    private $API_Url     = "https://api.monday.com/v2/";
    private $debug       = false;
    public $error        = '';

    /**
     * The monday.com API version sent with every request via the `API-Version` header.
     *
     * monday.com releases a new API version each quarter. When no header is sent, every
     * request is routed to the current default version - which changes over time and can
     * introduce breaking changes without warning. Pinning an explicit, supported version
     * keeps behaviour stable. Override per-instance with setApiVersion().
     *
     * @see https://developer.monday.com/api-reference/docs/api-versioning
     */
    public $apiVersion   = '2026-04';

    /**
     * Raw HTTP response headers (including the status line) from the most recent request.
     * Helps diagnose transport-level failures such as 401/400/429.
     *
     * @var array
     */
    public $lastResponseHeaders = [];

    const TYPE_QUERY    = 'query';
    const TYPE_MUTAT    = 'mutation';

    function __construct( Bool $debug = false )
    {
        $this->debug = $debug;
    }

    private function printDebug($print)
    {
        echo '<div style="background: #f9f9f9; padding: 20px; position: relative; border: solid 1px #dedede;">
        '.$print.'
        </div>';
    }

    public function setToken( Token $token )
    {
        $this->APIV2_Token = $token;
        return $this;
    }

    public function setApiVersion( String $version )
    {
        $this->apiVersion = $version;
        return $this;
    }

    private function content($type, $request)
    {
        if($this->debug){
            $this->printDebug( $type.' { '.$request.' } ' );
        }
        return json_encode(['query' => $type.' { '.$request.' } ']);
    }

    protected function request( $type = self::TYPE_QUERY, $request = null )
    {
        // set_error_handler(
        //     function ($severity, $message, $file, $line) {
        //         throw new \ErrorException($message, $severity, $severity, $file, $line);
        //     }
        // );

        try {
            $headers = [
                'Content-Type: application/json',
                'User-Agent: [Tblack-IT] GraphQL Client',
                'Authorization: ' . $this->APIV2_Token->getToken(),
                'API-Version: ' . $this->apiVersion,
            ];

            // ignore_errors lets file_get_contents return the response body even on a
            // 4xx/5xx status, so monday.com's error message is captured instead of lost.
            $http_response_header = [];
            $data = @file_get_contents($this->API_Url, false, stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => $headers,
                    'content'       => $this->content($type, $request),
                    'ignore_errors' => true,
                ]
            ]));

            // PHP populates $http_response_header in this scope after the request runs.
            $this->lastResponseHeaders = $http_response_header;

            if ($data === false) {
                $this->error = 'monday.com API request failed with no response body. Response headers: '
                    . json_encode($http_response_header);
                return false;
            }

            return $this->response($data);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    protected function response( $data )
    {
        if(!$data)
            return false;

        $json = json_decode($data, true);

        // A non-JSON body (e.g. an HTML gateway error page) cannot be decoded - surface it.
        if( is_null($json) ){
            $this->error = 'monday.com API returned a non-JSON response: ' . $data;
            return false;
        }

        // Capture any GraphQL errors so callers can log the real reason for a failure,
        // while preserving the original return contract (data when present, else errors).
        if( isset($json['errors']) && is_array($json['errors']) ){
            $this->error = json_encode($json['errors']);

            if( !isset($json['data']) || is_null($json['data']) ){
                return $json['errors'];
            }
        }

        if( isset($json['data']) ){
            return $json['data'];
        }

        return false;
    }
}

?>
