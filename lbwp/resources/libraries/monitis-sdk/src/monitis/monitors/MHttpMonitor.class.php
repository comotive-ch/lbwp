<?php
class MHttpMonitor extends MInternalMonitor{
    const METHOD_GET  = 0;
    const METHOD_POST = 1;
    const METHOD_HEAD = 2;
    /*
     * unsigned int $agentId
     * return [[id,name,tag,url,port,timeout,httpmethod,postData,loadFullPage,useSSL,doRedirect,matchFlag,matchText,userAuth,passwordAuth],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('agentHttpTests', $agentId);
    }
    /*
     * unsigned int $monitorId
     * return [id,name,tag,url,port,timeout,httpmethod,postData,loadFullPage,useSSL,doRedirect,matchFlag,matchText,userAuth,passwordAuth] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('internalHttpInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, string $period = MMaintenanceRule::PERIOD_*, int $timezone
     * return [trend=>[min,max,okcount,oksum,nokcount],data=>[[0,1,2],...]] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $period = null, $timezone = null){
        return $this->_requestMonitorResults('internalHttpResult', $monitorId, $year, $month, $day, $timezone, null, $period);
    }
    /*
     * string $name, string $tag, unsigned int $agentId, string $url, unsigned int $timeout, unsigned int $method = MHttpMonitorss::METHOD_*,
     * array $postData, bool $isRedirect, bool $isLoadFull, string $contentMatch, bool $isSsl,
     * if $isSsl == true string $SslUsername, string $SslPassword
     * return [status,data=>[testId]] or [error,data=>[testId]]
     */
    public function addMonitor($name, $tag, $agentId, $url, $timeout, $method = 0, $postData = null, $isRedirect = false, $isLoadFull = false,
                               $contentMatch = null, $isSsl = false, $SslUsername = null, $SslPassword = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['userAgentId'] = $agentId;
        $params['url'] = $url;
        $params['timeout'] = $timeout;
        $params['method'] = $method;
        if(is_array($postData)) $params['postData'] = http_build_query($postData);
        $params['redirect'] = $isRedirect ? '1' : '0';
        $params['loadFull'] = $isLoadFull ? '1' : '0';
        $params['overSSL'] = $isSsl ? '1' : '0';
        $params['contentMatchFlag'] = $contentMatch ? '1' : '0';
        $params['contentMatchString'] = $contentMatch != null ? $contentMatch : '';
        if($SslUsername != null) $params['userAuth'] = $SslUsername;
        if($SslPassword != null) $params['passAuth'] = $SslPassword;
        
        return $this->makePostRequest('addInternalHttpMonitor', $params);
    }
    /*
     * unsigned int $monitorId, string $name, string $tag, unsigned int $timeout, array $urlParams, array $postData, string $contentMatchString,
     * string $userAuth, string $passAuth
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $timeout, $urlParams = null, $postData = null, $contentMatch = null,
                                $SslUsername = null, $SslPassword = null){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['timeout'] = $timeout;
        if(is_array($urlParams)) $params['urlParams'] = http_build_query($urlParams);
        if(is_array($postData)) $params['postData'] = http_build_query($postData);
        if($contentMatch != null) $params['contentMathString'] = $contentMatch;
        if($SslUsername != null) $params['userAuth'] = $SslUsername;
        if($SslPassword != null) $params['passAuth'] = $SslPassword;
        
        return $this->makePostRequest('editInternalHttpMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(4, $monitorIds);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[id,tag,result,testName,status,lastCheckTime],tests=>[],...]] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topInternalHTTP', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>