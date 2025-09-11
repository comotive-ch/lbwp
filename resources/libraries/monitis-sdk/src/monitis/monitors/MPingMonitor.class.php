<?php
class MPingMonitor extends MInternalMonitor{
    /*
     * unsigned int $agentId
     * return [[id,name,tag,url,timeout,packetTimeout,packetCount,packetSize,maxLost],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('agentPingTests', $agentId);
    }
    /*
     * unsigned int $monitorId
     * return [id,name,tag,url,timeout,packetTimeout,packetCount,packetSize,maxLost] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('internalPingInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, string $period = MPingMonitor::PERIOD_*, int $timezone
     * return [trend=>[min,max,okcount,oksum,nokcount],data=>[]] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $period = null, $timezone = null){
        return $this->_requestMonitorResults('internalPingResult', $monitorId, $year, $month, $day, $timezone, null, $period);
    }
    /*
     * string $name, string $tag, string $agentKey, string $url, unsigned int $timeout,
     * unsigned int $packetsSize, unsigned int $packetsCount, unsigned int $maxLost
     * return [status,data=>[testId]] or [error,data=>[testId]]
     */
    public function addMonitor($name, $tag, $agentId, $url, $timeout, $packetsSize, $packetsCount, $maxLost){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['userAgentId'] = $agentId;
        $params['url'] = $url;
        $params['timeout'] = $timeout;
        $params['packetsSize'] = $packetsSize;
        $params['packetsCount'] = $packetsCount;
        $params['maxLost'] = $maxLost;
        
        return $this->makePostRequest('addInternalPingMonitor', $params);
    }
    /*
     * string $name, string $tag, string $agentKey, unsigned int $timeout,
     * unsigned int $packetsSize, unsigned int $packetsCount, unsigned int $maxLost
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $timeout, $packetsSize, $packetsCount, $maxLost){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['timeout'] = $timeout;
        $params['packetsSize'] = $packetsSize;
        $params['packetsCount'] = $packetsCount;
        $params['maxLost'] = $maxLost;
        
        return $this->makePostRequest('editInternalPingMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(5, $monitorIds);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[[id,tag,result,testName,status,lastCheckTime],...]] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topInternalPing', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>