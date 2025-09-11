<?php
class MInternalMonitor extends MBase{
    const TYPE_PROCESS = 'process';
    const TYPE_DRIVE   = 'drive';
    const TYPE_MEMORY  = 'memory';
    const TYPE_HTTP    = 'agentHttpTest';
    const TYPE_PING    = 'agentPingTest';
    const TYPE_LOAD    = 'load';
    const TYPE_CPU     = 'cpu';
    
    const PERIOD_TODAY         = 'dayView';
    const PERIOD_LAST_24_HOURS = 'last24hour';
    const PERIOD_LAST_3_DAYS   = 'last3day';
    const PERIOD_LAST_7_DAYS   = 'last7day';
    const PERIOD_LAST_30_DAYS  = 'last30day';
    
    /*
     * string $action, unsigned int $agentId
     * return [[id,kernelMax,tag,name,niceMax,type,userMax,iowaitMax,idleMin,ip],...] or [error]
     */
    protected function _requestMonitors($action, $agentId){
        $params = array();
        $params['agentId'] = $agentId;
        return $this->makeGetRequest($action, $params);
    }
    /*
     * unsigned int $typeId, unsigned int|array $monitorIds
     * return [status] or [error]
     */
    protected function _deleteMonitors($typeId, $monitorIds){
        $params = array();
        if(is_array($monitorIds)){
            $params['testIds'] = join(',', $monitorIds);
        }
        else{
            $params['testIds'] = $monitorIds;
        }
        $params['type'] = $typeId;
        return $this->makePostRequest('deleteInternalMonitors', $params);
    }
    /* 
     * string|array $types, string $tag, string $tagRegExp
     * return [pingTests => [[id,name,tag,url,timeout,packetTimeout,packetCount,packetSize,maxLost],...],
     *        processes  => [[id,name,tag,processName,memoryLimit,virtMemoryLimit],...],
     *        loads      => [[id,name,tag,maxLimit1,maxLimit5,maxLimit15],...],
     *        httpTests  => [[id,name,tag,url,port,httpmethod,postData,timeout,loadFullPage,doRedirect,userAuth,passwordAuth,useSSL,matchFlag,matchText],...],
     *        cpus       => [[id,name,tag,kernelMax,userMax,niceMax,iowaitMax,idleMin],...],
     *        drives     => [[id,name,tag,letter,freeLimit,totalMemory,checkInterval],...],
     *        memories   => [[id,name,tag,ip,freeLimit,cachedLimit,bufferedLimit,freeSwapLimit,totalMemory,checkInterval],...]
     * ] or [error]
     */
    public function requestInternalMonitors($types = null, $tag = null, $tagRegExp = null){
        $params = array();
        if(is_array($types)){
            $params['types'] = join(',',$types);
        }
        else if($types != null){
            $params['types'] = $types;
        }
        if($tag != null) $params['tag'] = $tag;
        if($tagRegExp != null) $params['tagRegExp'] = $tagRegExp;
        return $this->makeGetRequest('internalMonitors', $params);
    }
}
?>