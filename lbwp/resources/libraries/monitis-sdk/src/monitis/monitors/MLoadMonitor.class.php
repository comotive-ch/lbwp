<?php
class MLoadMonitor extends MInternalMonitor{
    /*
     * unsigned int $agentId
     * return [[id,name,tag,ip,maxLimit1,maxLimit5,maxLimit15],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('agentLoadAvg', $agentId);
    }
    /*
     * unsigned int $monitorId
     * return [id,name,tag,ip,agentKey,agentPlatform,maxLimit1,maxLimit5,maxLimit15] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('loadAvgInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, string $period = MLoadMonitor::PERIOD_*, int $timezone
     * return [[time,status,result1,result5,result15],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $period = null, $timezone = null){
        return $this->_requestMonitorResults('loadAvgResult', $monitorId, $year, $month, $day, $timezone, null, $period);
    }
    /*
     * string $name, string $tag, string $agentKey, unsigned int $maxLimitFirstCheck, unsigned int $maxLimitAfter5Minutes, unsigned int $maxLimitAfter15Minutes
     * return [status,data=>[testId]] or [error,data=>[testId]]
     */
    public function addMonitor($name, $tag, $agentKey, $maxLimitFirstCheck, $maxLimitAfter5Minutes, $maxLimitAfter15Minutes){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['agentKey'] = $agentKey;
        $params['limit1'] = $maxLimitFirstCheck;
        $params['limit5'] = $maxLimitAfter5Minutes;
        $params['limit15'] = $maxLimitAfter15Minutes;
        return $this->makePostRequest('addLoadAverageMonitor', $params);
    }
    /*
     * string $name, string $tag, string $agentKey, unsigned int $maxLimitFirstCheck, unsigned int $maxLimitAfter5Minutes, unsigned int $maxLimitAfter15Minutes
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $maxLimitFirstCheck, $maxLimitAfter5Minutes, $maxLimitAfter15Minutes){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['limit1'] = $maxLimitFirstCheck;
        $params['limit5'] = $maxLimitAfter5Minutes;
        $params['limit15'] = $maxLimitAfter15Minutes;
        return $this->makePostRequest('editLoadAverageMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(6, $monitorIds);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[],...]] or [error]
     */
    public function requestTopResultsFirstCheck($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topload1', $tag, $isDetailedResults, $limit, $timezone);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[] or [error]
     */
    public function requestTopResultsAfter5Minutes($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topload5', $tag, $isDetailedResults, $limit, $timezone);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[] or [error]
     */
    public function requestTopResultsAfter15Minutes($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topload15', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>