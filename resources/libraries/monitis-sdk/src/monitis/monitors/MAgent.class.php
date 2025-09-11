<?php
class MAgent extends MApi{
    /*
     * string $keyRegExp
     * return [[id,key,platform,status,processes=>[],drives=>[]],...] or [error]
     */
    public function requestAgents($keyRegExp = null){
        $params = array();
        if($keyRegExp != null) $params['keyRegExp'] = $keyRegExp;
        return $this->makeGetRequest('agents', $params);
    }
    /*
     * unsigned int $agentId, bool $loadTests
     * return [id,key,platform,status,drives=>[[id,name,tag,letter,checkInterval,totalMemory,freeLimit],...],
     * processes=>[[id,name,tag,processName,memoryLimit,cpuLimit,virtMemoryLimit],...],
     * httpTests=>[[id,name,tag,url,port,timeout,httpmethod,postData,passwordAuth,loadFullPage,doRedirect,matchFlag,matchText,useSSL],...],
     * pingTests=>[[id,name,tag,url,timeout,packetCount,packetSize,packetTimeout],...],
     * cpu=>[[id,name,tag,iowaitMax,kernaelMax,userMax,idleMin,niceMax],...],
     * memory=>[[id,name,ip,checkInterval,totalMemory,freeLimit,freeVirtualLimit,freeSwapLimit],...],
     * loadAvg=>[[id,name,tag,ip,maxLimit1,maxLimit5,maxLimit15],...]] or [error]
     */
    public function requestAgentInfo($agentId, $isLoadMonitors = null){
        $params = array();
        $params['agentId'] = $agentId;
        if($isLoadMonitors != null) $params['loadTests'] = $isLoadMonitors ? 'true' : 'false';
        return $this->makeGetRequest('agentInfo', $params);
    }
    /*
     * string $tag, string $platform = MAgents::PLATFORM_*, int $timezone
     * return [agents=>[[id,key,platform,status,cpu=>[id,name,status,idle,time,nice,iowait,kernel,used],
     * devices=>[[id,name,status,freeSpace,time,usedSpace],...],
     * memory=>[[id,name,status,buffered,total,cached,freeswap,freeVirtual,free,time,status,totalswap,totalVirtual],...],...]] or [error]
     */
    public function requestAllAgentsShapshot($tag = null, $platform = null, $timezone = null){
        $params = array();
        if($tag != null) $params['tag'] = $tag;
        if($platform != null) $params['platform'] = $platform;
        if($timezone != null) $params['timezone'] = $timezone;
        return $this->makeGetRequest('allAgentsSnapshot', $params);
    }
    /*
     * string $agentKey, int $timezone
     * return [agents=>[[id,key,platform,status,cpu=>[id,idle,time,status,name,nice,iowait,kernel,used],
     * drives=>[[id,freeSpace,time,usedSpace,status,name],...],
     * memory=>[id,buffered,total,cached,freeswap,freeVirtual,free,time,status,name,totalswap,totalVirtual]],...]] or [error]
     */
    public function requestAgentSnapshot($agentKey, $timezone = null){
        $params = array();
        $params['agentKey'] = $agentKey;
        if($timezone != null) $params['timezone'] = $timezone;
        return $this->makeGetRequest('agentSnapshot', $params);
    }
    /*
     * unsigned int|array $agentIds, string $keyRegExp
     * return [status] or [error]
     */
    public function deleteAgents($agentIds, $keyRegExp = null){
        $params = array();
        if(is_array($agentIds)) $params['agentIds'] = join(',', $agentIds);
        else $params['agentIds'] = $agentIds;
        if($keyRegExp != null) $params['keyRegExp'] = $keyRegExp;
        return $this->makePostRequest('deleteAgents', $params);
    }
}
?>