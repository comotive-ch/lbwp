<?php
class MDriveMonitor extends MInternalMonitor{
    /*
     * unsigned int $agentId
     * return [[freeLimit,id,totalMemory,letter,tag,name,checkInterval],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('agentDrives', $agentId);
    }
    /*
     * unsigned int $monitorId
     * return [freeLimit,id,totalMemory,letter,tag,agentPlatform,name,agentKey,checkInterval] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('driveInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, string $period = MDriveMonitor::PERIOD_*, int $timezone
     * return [time,freeSpace,usedSpace,status] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $period = null, $timezone = null){
        return $this->_requestMonitorResults('driveResult', $monitorId, $year, $month, $day, $timezone, null, $period);
    }
    /*
     * string $name, string $tag, string $agentKey, string $driveName (drive letter for windows and drive name for other OS),
     * unsigned int $freeLimit (GB)
     * return [status,data=>[testId]] or [error,data=>[testId]]
     */
    public function addMonitor($name, $tag, $agentKey, $driveName = null, $freeLimit = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['agentKey'] = $agentKey;
        if($driveName != null) $params['driveLetter'] = $driveName;
        if($freeLimit != null) $params['freeLimit'] = $freeLimit;
        return $this->makePostRequest('addDriveMonitor', $params);
    }
    /*
     * string $name, string $tag, string $agentKey, string $driveName (drive letter for windows and drive name for other OS),
     * unsigned int $freeLimit (GB)
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $driveName = null, $freeLimit = null){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        if($driveName != null) $params['driveLetter'] = $driveName;
        if($freeLimit != null) $params['freeLimit'] = $freeLimit;
        return $this->makePostRequest('editDriveMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(2, $monitorIds);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[[id,tag,result,testName,status,lastCheckTime],...]] or [error]
     */
    public function requestTopResults($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topdrive', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>