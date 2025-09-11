<?php
class MApi{
    const URL_DEFAULT = 'http://api.monitis.com/api';
    const URL_CUSTOM = 'http://api.monitis.com/customMonitorApi';
    
    const PLATFORM_LINUX   = 'LINUX';
    const PLATFORM_WINDOWS = 'WINDOWS';
    const PLATFORM_SOLARIS = 'OPENSOLARIS';
    const PLATFORM_OSX     = 'MAC';
    const PLATFORM_FREEBSD = 'FREEBSD';
    
    protected $apiUrl;
    protected $apiKey;
    protected $secretKey;
    protected $authToken;
    protected $publicKey;
    protected $version;
    protected $lastRequestParams;
    protected $lastRequestQuery;
    protected $lastRequestType;
    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */
    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null){
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
        $this->authToken = $authToken;
        $this->publicKey = $publicKey;
        $this->version = $version != null ? $version : 2;
        $this->apiUrl = $apiUrl != null ? $apiUrl : self::URL_DEFAULT;
        $this->lastRequestParams = null;
        $this->lastRequestQuery = null;
        $this->lastRequestType = null;
    }
    /*
     * int $type = Request::GET|Request::POST, string $action, array $params
     * return array
     */
    private function _makeRequest($type, $action, $params = null, $isJson = true){
        if(!is_array($params)) $params = array();
        
        $params['action'] = $action;
        $params['version'] = $this->version;
        $params['output'] = 'json';
        
        if($this->version == 3){
            if($this->publicKey){
                $params['publickey'] = $this->publicKey;
            }
            else if($this->apiKey){
                $params['apikey'] = $this->apiKey;
            }
        }
        else{
            if($this->apiKey){
                $params['apikey'] = $this->apiKey;
            }
        }
        if($type == Request::POST || $this->version == 3){
            if($this->authToken){
                $params['validation'] = 'token';
                $params['authToken'] = $this->authToken;
            }
            else{
                $params['timestamp'] = date("Y-m-d H:i:s");
                ksort($params);
                $paramsStr = '';
                foreach($params as $key => $val){
                    $paramsStr .= $key;
                    $paramsStr .= $val;
                }
                if($this->secretKey){
					$params['checksum'] = base64_encode(hash_hmac('sha1', $paramsStr, $this->secretKey,true));
                }
            }
        }
        $request = new Request($this->apiUrl, $params, $type);
        $this->lastRequestParams = $request->getFields();
        $this->lastRequestQuery = $request->getQuery();
        $this->lastRequestType = $type;
        return $isJson ? json_decode($request->send(), true) : $request->send();
    }
    /*
     * Make "locationId-interval,licationId-interval,..." from arrays
     * unsigned int|array $locationIds, unsigned int|array $locationCheckIntervals
     * if $locationCheckIntervals count < $locationIds count the latter values will be copies of last real value
     * if $locationCheckIntervals 
     * return string
     */
    protected function _makeLocationsString($locationIds, $locationCheckIntervals){
        $locationsString = '';
        if(is_array($locationIds)){
            if(!is_array($locationCheckIntervals)) $lastInterval = $locationCheckIntervals;
            foreach($locationIds as $key => $val){
                $interval = isset($locationCheckIntervals[$key]) && is_array($locationCheckIntervals) ? $locationCheckIntervals[$key] : $lastInterval;
                $lastInterval = $interval;
                if($locationsString) $locationsString .= ',';
                $locationsString .= $val .'-'. $interval;
            }
        }
        else{
            $locationsString .= $locationIds .'-'. $locationCheckIntervals;
        }
        return $locationsString;
    }
    /*
     * string $action, array $params
     * return array
     */
    public function makeGetRequest($action, $params = null, $isJson = true){
        return $this->_makeRequest(Request::GET, $action, $params, $isJson);
    }
    /*
     * string $action, array $param
     * return array
     */
    public function makePostRequest($action, $params = null, $isJson = true){
        return $this->_makeRequest(Request::POST, $action, $params, $isJson);
    }
    /*
     * string $userName, string $password
     * return [apikey] or [error]
     */
    public function requestApiKey($userName, $password){
        $params = array();
        $params['userName'] = $userName;
        $params['password'] = md5($password);
        $response=$this->makeGetRequest('apikey', $params);
        if(isset($response['apikey'])){
            $this->apiKey=$response['apikey'];
			$this->secretKey=$response['secretkey'];
        }
        return $response;
    }	
    /*
     * return [authToken] or [error]
     */
    public function requestAuthToken(){
        $params = array();
        $params['secretkey'] = $this->secretKey;
        $response = $this->makeGetRequest('authToken', $params);
        if(isset($response['authToken'])){
            $this->authToken=$response['authToken'];
        }
        return $response;
    }
    /*
     * [[id,key,createdOn,,modifiedOn,params],...]
     */
    public function requestPublicKeys(){
        return $this->makeGetRequest('getPublicKeys');
    }
    /*
     * array $params
     * [status,data] or [error]
     */
    public function addPublicKey($params = null){
        $fields = array();
        if($params != null){
            $fields['params'] = json_encode($params);
        }
        else{
            $fields['params'] = '{}';
        }
        $this->publicKeyParams = $fields['params'];
        return $this->makePostRequest('addPublicKey', $fields);
    }
    /*
     * string $publicKey, array $params
     * [status] or [error]
     */
    public function updatePublicKey($publicKey, $params = null){
        $fields = array();
        $fields['key'] = $publicKey;
        if(is_array($params)){
            $fields['params'] = json_encode($params);
        }
        else{
            $fields['params'] = '{}';
        }
        return $this->makePostRequest('updatePublicKey', $fields);
    }
    /*
     * string|array $publicKeys
     * [status] or [error]
     */
    public function deletePublicKeys($publicKeys){
        $params = array();
        if(is_array($publicKeys)){
            $params['keys'] = join(',',$publicKeys);
        }
        else{
            $params['keys'] = $publicKeys;
        }
        return $this->makePostRequest('deletePublicKeys', $params);
    }
    
    public function getApiKey(){
        return $this->apiKey;
    }
    
    public function getSecretKey(){
        return $this->secretKey;
    }

    public function getAuthToken(){
        return $this->authToken;
    }
    
    public function getVersion(){
        return $this->version;
    }
    
    public function getApiUrl(){
        return $this->apiUrl;
    }
    
    public function getPublicKey(){
        return $this->publicKey;
    }
    
    public function getLastRequest(){
        if($this->lastRequestType){
            $post = $this->apiUrl."\npost:\n";
            foreach($this->lastRequestParams as $key => $val){
                $post .= $key.' = '.$val."\n";
            }
            return $post;
        }
        else{
            return $this->apiUrl.'?'.$this->lastRequestQuery;
        }
    }
    
    public function setApiKey($apiKey){
        $this->apiKey = $apiKey;
    }
    
    public function setSecretKey($secretKey){
        $this->secretKey = $secretKey;
    }
    
    public function setAuthToken($authToken){
        $this->authToken = $authToken;
    }
    
    public function setVersion($version){
        $this->version = $version;
    }
    
    public function setApiUrl($apiUrl){
        $this->apiUrl = $apiUrl;
    }
    
    public function setPublicKey($publicKey){
        $this->publicKey = $publicKey;
    }
}
?>