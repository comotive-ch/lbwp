<?php
class MExternalMonitor extends MBase{
    const TYPE_HTTP  = 'http';
    const TYPE_HTTPS = 'https';
    const TYPE_FTP   = 'ftp';
    const TYPE_PING  = 'ping';
    const TYPE_DNS   = 'dns';
    const TYPE_MYSQL = 'mysql';
    const TYPE_UDP   = 'udp';
    const TYPE_TCP   = 'tcp';
    const TYPE_SIP   = 'sip';
    const TYPE_SMTP  = 'smtp';
    const TYPE_IMAP  = 'imap';
    const TYPE_POP   = 'pop';
    
    const TYPE_DETAILED_GET    = 1;
    const TYPE_DETAILED_POST   = 2;
    const TYPE_DETAILED_PUT    = 3;
    const TYPE_DETAILED_DELETE = 4;
    
    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */
    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null){
        parent::__construct($apiKey, $secretKey, $authToken, $version, $apiUrl, $publicKey);
        $this->monitorIdString = 'testId';
        $this->monitorIdsString = 'testIds';
    }
    /*
     * return [testList=>[[id,isSuspended,name,type,url],...]] or [error]
     */
    public function requestMonitors(){
        return $this->makeGetRequest('tests');
    }
    /* 
     * unsigned int $monitorId
     * return [testList=>[startDate,postData,interval,testId,detailedType,authPassword,tag,authUsername,params=>[],type,url,
     * locations=>[[id,name,checkInterval,fullName],...],name,sla=>[],match,matchText,timeout]] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('testinfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, unsigned int|array $locationsIds, int $timezone
     * return [[id,trend => [min,okcount,max,oksum,nokcount],data => [[0(date),1(time),2(status)],...],locationName,
     * adddatas => [0(information),1(count of corresponding to this information)]],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $locationsIds = null, $timezone = null){
        return $this->_requestMonitorResults('testresult', $monitorId, $year, $month, $day, $timezone, $locationsIds);
    }
    /*
     * return [[id,name,hostAddress,fullName,minCheckInterval],...] or [error]
     */
    public function requestLocations(){
        return $this->makeGetRequest('locations');
    }
    /*
     * string|array $locationIds
     * return [[id,name,locationShortName,data => [[id,name,testType,status,tag,timeout,time,perf,isSuspended],...]],...] or [error]
     */
    public function requestSnapshot($locationIds = null){
        return $this->_requestSnapshot('testsLastValues', $locationIds);
    }
    /*
     * return [tags => [[rank,title],...]] or [error]
     */
    public function requestTags(){
        return $this->makeGetRequest('tags');
    }
    /*
     * string $tag
     * return [testList => [[id,name],...]] or [error]
     */
    public function requestTagMonitors($tag){
        $params = array();
        $params['tag'] = $tag;
        return $this->makeGetRequest('tagtests', $params);
    }
    /*
     * string $name, string $tag, string $url, string $type = MExternalMonitor::TYPE_*, unsigned int|array $locationIds, unsigned int $checkInterval = 1|3|5|10|15|20|30|40|60,
     * unsigned int $detailedType = MExternalMonitor::TYPE_DETAILED_*,
     * array $params = if $type is mysql [username,password,port,timeout] else if type is dns [server,expip,expauth],
     * unsigned int $timeout (10000 ms by defaylt), array $postData,
     * bool $overSSL (can be specified for FTP, UPD, TCP, SMTP, IMAP, POP monitor types),
     * bool $contentMatchFlag, string $contentMatchString,
     * int $minUptime (%), int $maxResponseTime (seconds),
     * string $basicAuthUser, string $basicAuthPass
     * return [status,data => [testId,startDate,isTestNew]] or [error]
     */
    public function addMonitor($name, $tag, $url, $type, $locationIds, $checkInterval, $detailedType = null, $params = null, $timeout = null, $postData = null, $overSSL = null,
                               $contentMatchFlag = null, $contentMatchString = null, $minUptime = null, $maxResponseTime = null, $authUser = null, $authPass = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['url'] = $url;
        $params['type'] = $type;
        if(is_array($locationIds)){
            $params['locationIds'] = join(',',$locationIds);
        }
        else{
            $params['locationIds'] = $locationIds;
        }
        $params['interval'] = $checkInterval;
        if(is_array($params)){
            $params['params'] = '';
            foreach($params as $key => $val){
                if($params['params'] != '') $params['params'] .= ';';
                $params['params'] .= $key.':'.$val;
            }
        }
        if($detailedType != null) $params['detailedTestType'] = $detailedType;
        if($timeout != null) $params['timeout'] = $timeout;
        if(is_array($postData)) $params['postData'] =  http_build_query($postData);
        if($overSSL != null) $params['overSSL'] = $overSSL;
        if($contentMatchFlag != null) $params['contentMatchFlag'] =  $contentMatchFlag ? '1' : '0';
        if($contentMatchString != null) $params['contentMatchString'] = $contentMatchString;
        if($minUptime != null) $params['uptimeSLA'] = $minUptime;
        if($maxResponseTime != null) $params['responseSLA'] = $maxResponseTime;
        if($authUser != null) $params['basicAuthUser'] = $authUser;
        if($authPass != null) $params['basicAuthPass'] = $authPass;
        return $this->makePostRequest('addExternalMonitor', $params);
    }
    /*
     * int $monitorId, string $name, string $tag, string $url,
     * unsigned int|array $locationIds, unsigned int|array $locationCheckIntervals,
     * int $timeout = 1-50 (milliseconds), string $contentMatchString,
     * unsigned int $minUptime (%), unsigned int $maxResponseTime (seconds)
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $url, $locationIds, $locationCheckIntervals, $timeout, $contentMatchString = null,
                                $minUptime = null, $maxResponseTime = null){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['url'] = $url;
        $params['locationIds'] = $this->_makeLocationsString($locationIds, $locationCheckIntervals);
        $params['timeout'] = $timeout;
        if($contentMatchString != null) $params['contentMatchString'] = $contentMatchString;
        if($minUptime != null) $params['uptimeSLA'] = $minUptime;
        if($maxResponseTime != null) $params['responseSLA'] = $maxResponseTime;
        return $this->makePostRequest('editExternalMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status] or [error]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors('deleteExternalMonitor', $monitorIds);
    }
    /*
     * unsigned int|array $monitorIds, string $tag
     * Specifie only one parametr, other parametr must be null
     * return [status,data => [failedToSuspend]] or [error]
     */
    public function suspendMonitors($monitorsIds, $tag = null){
        return $this->_suspendOrActivateMonitors('suspendExternalMonitor', $monitorsIds, $tag);
    }
    /*
     * unsigned int|array $monitorIds, string $tag
     * Specifie only one parametr, other parametr must be null
     * return [status] or [error]
     */
    public function activateMonitors($monitorsIds, $tag = null){
        return $this->_suspendOrActivateMonitors('activateExternalMonitor', $monitorsIds, $tag);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topexternal', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>