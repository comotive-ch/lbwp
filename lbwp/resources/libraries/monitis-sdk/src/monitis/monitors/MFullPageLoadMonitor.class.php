<?php
class MFullPageLoadMonitor extends MBase{
    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */
    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null){
        parent::__construct($apiKey, $secretKey, $authToken, $version, $apiUrl, $publicKey);
        $this->monitorIdsString = 'monitorId'; /* yes there is monitorId not monitorIds */
    }
    /*
     * return [[id,tag,name,url],...] or [error]
     */
    public function requestMonitors(){
        return $this->makeGetRequest('fullPageLoadTests');
    }
    /*
     * unsigned int $monitorId
     * return [testId,name,tag,url,timeout,startDate,source,sla,locations=>[[id,name,fullName,ip,anchorBgColor,anchorBorderColor,color,active],...]] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('fullPageLoadTestInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, unsigned int|array $locationsIds, int $timezone
     * return [[id,trend=>[min,okcount,max,oksum,nokcount],data=>[[],...],locationName],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $locationsIds = null, $timezone = null){
        return $this->_requestMonitorResults('fullPageLoadTestResult', $monitorId, $year, $month, $day, $timezone, $locationsIds);
    }
    /*
     * return [[id,name,hostAddress,fullName,minCheckInterval],...] or [error]
     */
    public function requestLocations(){
        return $this->makeGetRequest('fullPageLoadLocations');
    }
    /*
     * unsigned int|array $locationIds
     * return [[id,name,locationShortName,data=>[[id,testType,time,perf,status,tag,name,frequency,timeout],...]],...] or [error]
     */
    public function requestSnapshot($locationIds = null){
        $params = array();
        if(is_array($locationIds)){
            $params['locationIds'] = join(',', $locationIds);
        }
        else if($locationIds != null){
            $params['locationIds'] = $locationIds;
        }
        return $this->makeGetRequest('fullPageLoadSnapshot', $params);
    }
    /*
     * string $name, string $tag, string $url, unsigned int|array $locationIds, unsigned int $locationCheckIntervals (minutes), unsigned int $timeout,
     * unsigned int $minUptimeSLA, unsigned int $maxResponseSLA
     * return [status,data] or [error]
     */
    public function addMonitor($name, $tag, $url, $locationIds, $locationCheckIntervals, $timeout, $minUptime = null, $maxResponseTime = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['url'] = $url;
        if(is_array($locationIds)){
            $params['locationIds'] = join(',', $locationIds);
        }
        else if($locationIds != null){
            $params['locationIds'] = $locationIds;
        }
        if(is_array($locationCheckIntervals)){
            $params['checkInterval'] = join(',', $locationCheckIntervals);
        }
        else if($locationCheckIntervals != null){
            $params['checkInterval'] = $locationCheckIntervals;
        }
        $params['timeout'] = $timeout;
        if($minUptime != null) $params['uptimeSLA'] = $minUptime;
        if($maxResponseTime != null) $params['responseSLA'] = $maxResponseTime;
        return $this->makePostRequest('addFullPageLoadMonitor', $params);
    }
    /*
     * string $name, string $tag, string $url, unsigned int|array $locationIds, unsigned int $locationCheckIntervals (minutes), unsigned int $timeout,
     * unsigned int $minUptimeSla (%), unsigned int $maxResponseSLA (seconds)
     * return [status,data=>[testId]] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $url, $locationIds, $locationCheckIntervals, $timeout, $minUptime = null, $maxResponseTime = null){
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['url'] = $url;
        if(is_array($locationIds)){
            $params['locationIds'] = join(',', $locationIds);
        }
        else if($locationIds != null){
            $params['locationIds'] = $locationIds;
        }
        if(is_array($locationCheckIntervals)){
            $params['checkInterval'] = join(',', $locationCheckIntervals);
        }
        else if($locationCheckIntervals != null){
            $params['checkInterval'] = $locationCheckIntervals;
        }
        $params['timeout'] = $timeout;
        if($minUptime != null) $params['uptimeSLA'] = $minUptime;
        if($maxResponseTime != null) $params['responseSLA'] = $maxResponseTime;
        return $this->makePostRequest('editFullPageLoadMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status] or [error]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors('deleteFullPageLoadMonitor', $monitorIds);
    }
    /*
     * unsigned int|array $monitorIds, string $tag
     * Specifie only one parametr, other parametr must be null
     * return [status,data => [failedToSuspend]] or [error]
     */
    public function suspendMonitors($monitorIds, $tag = null){
        return $this->_suspendOrActivateMonitors('suspendFullPageLoadMonitor', $monitorIds, $tag);
    }
    /*
     * unsigned int|array $monitorIds, string $tag
     * Specifie only one parametr, other parametr must be null
     * return [status,data => [failedToSuspend]] or [error]
     */
    public function activateMonitors($monitorIds, $tag = null){
        return $this->_suspendOrActivateMonitors('activateFullPageLoadMonitor', $monitorIds, $tag);
    }
    /*
     * string $type = MBase::TOP_*, string $tag, bool $detailedResults, unsigned int $limit, int $timezoneoffset
     * return [*] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topFullpage', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>