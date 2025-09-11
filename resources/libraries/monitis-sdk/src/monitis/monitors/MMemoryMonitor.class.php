<?php
class MMemoryMonitor extends MInternalMonitor{
    /*
     * unsigned int $agentId
     * return [[id,name,platform,key,type,ip,tag,freeLimit,cachedLimit,checkInterval,bufferedLimit,freeSwapLimit],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('agentMemory', $agentId);
    }
    /*
     * unsigned int $monitorId
     * return [id,name,agentPlatform,key,type,ip,tag,platform,,agentKey,freeSwapLimit,freeLimit,cachedLimit,checkInterval,bufferedLimit] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('memoryInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, string $period = MMaintenanceRule::PERIOD_*, int $timezone
     * return [[time,freeMemory,totalMemory,freeswap,totalSwap,freeVirtual,totalVirtual,buffered,cached,status],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $period = null, $timezone = null){
        return $this->_requestMonitorResults('memoryResult', $monitorId, $year, $month, $day, $timezone, null, $period);
    }
    /*
     * string $name, string $tag, string $agentKey, string $platform = MMaintenanceRule::PLATFORM_*
     * for Windows, Linux, Solaris: unsigned int $freeLimit (MB), unsigned int $freeSwapLimit (MB), unsigned int $freeVirtualLimit (MB),
     * unsigned int $bufferedLimit (MB), unsigned int $cachedLimit (MB)
     * return [error,data=>[testId]] or [error,data=>[testId]]
     */
    public function addMonitor($name, $tag, $agentKey, $platform, $freeLimit = null, $freeSwapLimit = null, $freeVirtualLimit = null, $bufferedLimit = null, $cachedLimit = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['agentKey'] = $agentKey;
        $params['platform'] = $platform;
        if($freeLimit != null) $params['freeLimit'] = $freeLimit;
        if($freeSwapLimit != null) $params['freeSwapLimit'] = $freeSwapLimit;
        if($freeVirtualLimit != null) $params['freeVirtualLimit'] = $freeVirtualLimit;
        if($bufferedLimit != null) $params['bufferedLimit'] = $bufferedLimit;
        if($cachedLimit != null) $params['cachedLimit'] = $cachedLimit;
        return $this->makePostRequest('addMemoryMonitor', $params);
    }
    /*
     * unsigned int $monitorId, string $name, string $tag, string $platform = MMaintenanceRule::PLATFORM_*
     * for Windows, Linux, Solaris: unsigned int $freeLimit (MB), unsigned int $freeSwapLimit (MB), unsigned int $freeVirtualLimit (MB),
     * unsigned int $bufferedLimit (MB), unsigned int $cachedLimit (MB)
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $platform, $freeLimit = null, $freeSwapLimit = null, $freeVirtualLimit = null, $bufferedLimit = null, $cachedLimit = null){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['platform'] = $platform;
        if($freeLimit != null) $params['freeLimit'] = $freeLimit;
        if($freeSwapLimit != null) $params['freeSwapLimit'] = $freeSwapLimit;
        if($freeVirtualLimit != null) $params['freeVirtualLimit'] = $freeVirtualLimit;
        if($bufferedLimit != null) $params['bufferedLimit'] = $bufferedLimit;
        if($cachedLimit != null) $params['cachedLimit'] = $cachedLimit;
        return $this->makePostRequest('editMemoryMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(3, $monitorIds);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[[id,tag,result,testName,status,lastCheckTime],...]] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topmemory', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>