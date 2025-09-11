<?php
class MProcessMonitor extends MInternalMonitor{
    /*
     * string $apiKey, string $secretKey, unsigned int $version, string $apiUrl
     */
    public function __construct($apiKey = null, $secretKey = null, $version = null, $apiUrl = null){
        parent::__construct($apiKey, $secretKey, $version, $apiUrl);
        $this->type = self::TYPE_PROCESS;
        $this->typeId = self::TYPE_ID_PROCESS;
        $this->requestMonitorsString = 'agentProcesses';
        $this->requestMonitorInfoString = 'processInfo';
    }
    /*
     * unsigned int $agentId
     * return [[id,name,tag,processName,cpuLimit,memoryLimit,virtMemoryLimit],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('agentProcesses', $agentId);
    }
    /*
     * unsigned int $monitorId
     * return [id,name,tag,agentKey,agentPlatform,processName,cpuLimit,memoryLimit,virtMemoryLimit] or [error]
     */
    public function requestMonitorInfo($monitorId){
        return $this->_requestMonitorInfo('processInfo', $monitorId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, string $period = MProcessMonitor::PERIOD_*, int $timezone
     * return [[id,name,tag,processName,cpuLimit,memoryLimit,virtMemoryLimit],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $period = null, $timezone = null){
        return $this->_requestMonitorResults('processResult', $monitorId, $year, $month, $day, $timezone, null, $period);
    }
    /*
     * string $name, string $tag, string $agentKey, string $processName, unsigned int $cpuLimit (%),
     * float $memoryLimit (MB), float $virtualMemoryLimit (MB)
     * return [success,data=>[testId]] or [error,data=>[testId]]
     */
    public function addMonitor($name, $tag, $agentKey, $processName, $cpuLimit, $memoryLimit, $virtualMemoryLimit){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['agentKey'] = $agentKey;
        $params['processName'] = $processName;
        $params['cpuLimit'] = $cpuLimit;
        $params['memoryLimit'] = $memoryLimit;
        $params['virtualMemoryLimit'] = $virtualMemoryLimit;
        return $this->makePostRequest('addProcessMonitor', $params);
    }
    /*
     * unsigned int $monitorId, string $name, string $tag, string $agentKey, string $processName, unsigned int $cpuLimit (%),
     * float $memoryLimit (MB), float $virtualMemoryLimit (MB)
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $processName, $cpuLimit, $memoryLimit, $virtualMemoryLimit){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['processName'] = $processName;
        $params['cpuLimit'] = $cpuLimit;
        $params['memoryLimit'] = $memoryLimit;
        $params['virtualMemoryLimit'] = $virtualMemoryLimit;
        return $this->makePostRequest('editProcessMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(1, $monitorIds);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[[id,memory_usage,testName,vm_size,cpu_usage,status,lastCheckTime],...]] or [error]
     */
    public function requestTopResultsByCpuUsage($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topProcessByCPUUsage', $tag, $isDetailedResults, $limit, $timezone);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[] or [error]
     */
    public function requestTopResultsByMemoryUsage($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topProcessByMemoryUsage', $tag, $isDetailedResults, $limit, $timezone);
    }
    /*
     * string $tag, bool $isDetailedResults, unsigned int $limit, int $timezone
     * return [tags=>[],tests=>[] or [error]
     */
    public function requestTopResultsByVirtualMemoryUsage($tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        return $this->_requestTopResults('topProcessByVirtMemoryUsage', $tag, $isDetailedResults, $limit, $timezone);
    }
}
?>