<?php
class MCloudInstance extends MApi{
    const TYPE_EC2 = 'ec2';
    const TYPE_RACKSPACE = 'rackspace';
    const TYPE_GOGRID = 'gogrid';
    
    /*
     * string $apiKey, string $secretKey, unsigned int $version, string $apiUrl
     */
    public function __construct($apiKey = null, $secretKey = null, $version = null, $apiUrl = null){
        parent::__construct($apiKey, $secretKey, $version, $apiUrl);
    }
    /*
     * int $timezone
     * return [
     *      gogrid=>[[id,state,launchTime,imageId,instanceId,publicDnsName,extraInfo=>[name,terminatedTime,type]],...],
     *      ec2 => [[id,state,launchTime,imageId,instanceId,publicDnsName,extraInfo=>[keyName,zone,stateReason]],...],
     *      rackspace => [[id,state,launchTime,imageId,instanceId,publicDnsName,extraInfo=>[serverLabel,terminatedTime]],...]
     * ] or [error]
     */
    public function requestInstances($timezone = null){
        $params = array();
        if($timezone != null) $params['timezoneoffset'] = $timezone;
        return $this->makeGetRequest('cloudInstances', $params);
    }
    /*
     * unsigned int $instanceId, string $type = MCloudInstance::TYPE_*, int $timezone
     * return [id,launchTime,imageId,instanceId,state,extraInfo=>*,publicDnsName,monitors=>[
     *      http=>[id,time,result,status]
     *      ping=>[id,time,result,status]
     *      ssh=>[id,time,result,status]
     * ]]
     * externalInfo for ec2:
     * [keyName,zone,stateReason]
     * externalInfo for gogrid:
     * [serverLabel,terminatedTime]
     * externalInfo for rackspace:
     * [name,terminatedTime,type]
     */
    public function requestInstanceInfo($instanceId, $type, $timezone = null){
        $params = array();
        $params['instanceId'] = $instanceId;
        $params['type'] = $type;
        if($timezone != null) $params['timezoneoffset'] = $timezone;
        return $this->makeGetRequest('cloudInstanceInfo', $params);
    }
}
?>