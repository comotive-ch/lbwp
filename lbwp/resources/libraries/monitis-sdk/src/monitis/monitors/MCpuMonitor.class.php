<?php
class MCpuMonitor extends MInternalMonitor{
    /*
     * unsigned int $agentId
     * return [[id,tag,name,kernelMax,type,ip,niceMax,userMax,iowaitMax,idleMin],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('agentCPU', $agentId);
    }
    /*
     * unsigned int $monitorId
     * return [id,type,tag,name,ip,agentKey,agentPlatform,kernelMax,niceMax,userMax,iowaitMax,idleMin] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('CPUInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, string $period = MCpuMonitor::PERIOD_*, int $timezone
     * return [[idleValue,time,userValue,status,ioWaitValue,kernelValue,niceValue,cpuIndex],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $period = null, $timezone = null){
        return $this->_requestMonitorResults('cpuResult', $monitorId, $year, $month, $day, $timezone, null, $period);
    }
    /*
     * string $name, string $tag, string $agentKey, float $kernelMax, float $usedMax,
     * only for linux: float $niceMax, float $idleMin, float $ioWaitMax
     * return [status,data=>[testId]] or [error]
     */
    public function addMonitor($name, $tag, $agentKey, $kernelMax, $usedMax, $niceMax = null, $ioWaitMax = null, $idleMin = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['agentKey'] = $agentKey;
        $params['kernelMax'] = $kernelMax;
        $params['usedMax'] = $usedMax;
        if($niceMax != null) $params['niceMax'] = $niceMax;
        if($ioWaitMax != null) $params['ioWaitMax'] = $ioWaitMax;
        if($idleMin != null) $params['idleMin'] = $idleMin;
        return $this->makePostRequest('addCPUMonitor', $params);
    }
    /*
     * unsigned int $monitorId, string $name, string $tag, float $kernelMax, float $usedMax,
     * only for linux: float $niceMax, float $idleMin, float $ioWaitMax
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $kernelMax, $usedMax, $niceMax = null, $ioWaitMax = null, $idleMin = null){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['kernelMax'] = $kernelMax;
        $params['usedMax'] = $usedMax;
        if($niceMax != null) $params['niceMax'] = $niceMax;
        if($ioWaitMax != null) $params['ioWaitMax'] = $ioWaitMax;
        if($idleMin != null) $params['idleMin'] = $idleMin;
        return $this->makePostRequest('editCPUMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(7, $monitorIds);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[[id,tag,result,testName,status,lastCheckTime],...]] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topcpu', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>