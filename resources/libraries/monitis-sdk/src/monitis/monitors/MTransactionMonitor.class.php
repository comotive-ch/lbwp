<?php
class MTransactionMonitor extends MBase{
    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */
    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null){
        parent::__construct($apiKey, $secretKey, $authToken, $version, $apiUrl, $publicKey);
        $this->monitorIdsString = 'monitorId'; /* yes there is monitorId not monitorIds */
    }
    /*
     * return [[id,name,tag,url,stepCount],...] or [error]
     */
    public function requestMonitors(){
        return $this->makeGetRequest('transactionTests');
    }
    /*
     * unsigned int $monitorId
     * return [testId,name,url,startDate,tag,sla,locations => [[id,checkInterval,name,fullName],...]] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('transactionTestInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, unsigned int|array $locationsIds, int $timezone
     * return [[id,locationName,data => [[0,1,2],...],trend => [min,okcount,max,oksum,nokcount]],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $locationsIds = null, $timezone = null){
        return $this->_requestMonitorResults('transactionTestResult', $monitorId, $year, $month, $day, $timezone, $locationsIds);
    }
    /*
     * return [[id,name,hostAddress,fullName,minCheckInterval],...] or [error]
     */
    public function requestLocations(){
        return $this->makeGetRequest('transactionLocations');
    }
    /*
     * unsigned int|array $locationIds
     * return [[id,name,data=>[[id,testType,time,perf,status,tag,isSuspended,name,locationId,frequency,timeout],...],locationShortName],...],...] or [error]
     */
    public function requestSnapshot($locationIds = null){
        $params = array();
        if(is_array($locationIds)){
            $params['locationIds'] = join(',', $locationIds);
        }
        else if($locationIds != null){
            $params['locationIds'] = $locationIds;
        }
        return $this->makeGetRequest('transactionSnapshot', $params);
    }
    /*
     * string $name, string $tag, unsigned int|array $locationIds, unsigned int|array $locationCheckIntervals, string $url, string $timeout,
     * string $data - html string which is executed by Monitis transaction recorder (see http://www.monitissupport.com/?page_id=69),
     * unsigned int $minUptimeSLA, unsigned int $maxResponseSLA
     * return [status,data] or [error]
     */
    public function addMonitor($name, $tag, $url, $locationIds, $locationCheckIntervals, $timeout, $data, $minUptime = null, $maxResponseTime = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['locationIds'] = $this->_makeLocationsString($locationIds, $locationCheckIntervals);
        $params['url'] = $url;
        $params['timeout'] = $timeout;
        $params['data'] = $data;
        if($minUptime != null) $params['uptimeSLA'] = $minUptime;
        if($maxResponseTime != null) $params['responseSLA'] = $maxResponseTime;
        return $this->makePostRequest('addTransactionMonitor', $params);
    }
    /*
     * unsigned int $monitorId, string $name, string $tag, unsigned int|array $locations, unsigned int|array $locationCheckIntervals, string $url, string $timeout,
     * string $data - html string which is executed by Monitis transaction recorder (see http://www.monitissupport.com/?page_id=69),
     * unsigned int $minUptimeSLA, unsigned int $maxResponseSLA
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $url, $locationIds, $locationCheckIntervals, $timeout, $data, $minUptime = null, $maxResponseTime = null){
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['locationIds'] = $this->_makeLocationsString($locationIds, $locationCheckIntervals);
        $params['url'] = $url;
        $params['timeout'] = $timeout;
        $params['data'] = $data;
        if($minUptime != null) $params['uptimeSLA'] = $minUptime;
        if($maxResponseTime != null) $params['responseSLA'] = $maxResponseTime;
        return $this->makePostRequest('editTransactionMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status] or [error]
     */
    public function deleteMonitors($monitorIds){
        $this->_deleteMonitors($monitorIds);
    }
    /*
     * unsigned int|array $monitorIds, string $tag
     * Specifie only one parametr, other parametr must be null
     * return [status,data => [failedToSuspend]] or [error]
     */
    public function suspendMonitors($monitorIds, $tag = null){
        return $this->_suspendOrActivateMonitors('suspendTransactionMonitor', $monitorIds, $tag);
    }
    /*
     * unsigned int|array $monitorIds, string $tag
     * Specifie only one parametr, other parametr must be null
     * return [status,data => [failedToSuspend]] or [error]
     */
    public function activateMonitors($monitorIds, $tag = null){
        return $this->_suspendOrActivateMonitors('activateTransactionMonitor', $monitorIds, $tag);
    }
    /*
     * unsigned int $stepResultId, unsigned int $year, unsigned int $month, unsigned int $day
     * return [[duration,status,description,name,step],...] or [error]
     */
    public function requestStepResults($resultId, $year, $month, $day){
        $params = array();
        $params['resultId'] = $resultId;
        $params['year'] = $year;
        $params['month'] = $month;
        $params['day'] = $day;
        return $this->makeGetRequest('transactionStepResult', $params);
    }
     /*
     * unsigned int $resultId, unsigned int $year, unsigned int $month, unsigned int $day
     * return [error,data =>
     * [netContent => [
     *      Started => [start],
     *      Resolving => [elapsed,start],
     *      Connecting => [elapsed,start],
     *      Blocking => [elapsed,start],
     *      Sending => [elapsed,start],
     *      Waiting => [elapsed,start],
     *      Receiving => [elapsed,start,loaded,fromCache], 
     *      ContentLoad => [start], 
     *      WindowLoad => [start], 
     *      Size, 
     *      Duration, 
     *      Domain, 
     *      Href, 
     *      URL, 
     *      Status
        ],
	summary => [count,TotalSize,loadTime]] or [error]
     */
    public function requestStepNet($resultId, $year, $month, $day){
        $params = array();
        $params['resultId'] = $resultId;
        $params['year'] = $year;
        $params['month'] = $month;
        $params['day'] = $day;
        return $this->makeGetRequest('transactionStepNet', $params);
    }
    /*
     * unsigned int $monitorId, unsigned int $resultId
     * return string image path or null
     */
    public function requestStepCapture($monitorId, $resultId){
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['resultId'] = $resultId;
        $params['action'] = 'transactionStepCapture';
        $params['apikey'] = $this->apiKey;
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->apiUrl .'?'. http_build_query($params));
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $a = curl_exec($curl);
        if(preg_match('#Location: (.*)#', $a, $r)){
            return trim($r[1]);
        }
        return null;
    }
    /*
     * string $type = MBaseMonitor::TOP_*, string $tag, bool $detailedResults, unsigned int $limit, int $timezoneoffset
     * return [*] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topTransaction', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>