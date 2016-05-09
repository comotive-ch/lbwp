<?php
class MSnmpMonitor extends MInternalMonitor{
    const AUTH_PROTOCOL_SHA1 = 'SHA1';
    const AUTH_PROTOCOL_MD5  = 'MD5';
    
    const PRIV_PROTOCOL_AES = 'AES';
    const PRIV_PROTOCOL_DES = 'DES';
    
    /*
     * unsigned int $agentId
     * return [[id,name,tag,host,port,oid,privproto,community,public,retries,privpass],...] or [error]
     */
    public function requestMonitors($agentId){
        return $this->_requestMonitors('SNMPObjectMonitors', $agentId);
    }
    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, int $timezone
     * return [[time,respomnseTime,value,status],...] or [error]
     */
    public function requestMonitorResults($monitorId, $year, $month, $day, $timezone = null){
        return $this->_requestMonitorResults('SNMPObjectResult', $monitorId, $year, $month, $day, $timezone);
    }
    /*
     * string $name, string $tag, string $agentKey, string $url, unsigned int $timeout,
     * string $version = 1|2c|3, unsigned int $timeout, unsigned int $port, unsigned int $retries,
     * unsigned int $minValue, unsigned int $maxValue, string $community, string $login, string $password,
     * only for SNMP version 3: string $authProtocol = MSnmtMonitors::AUTH_PROTOCOL_*, string $privProtocol = MSnmtMonitors::PRIV_PROTOCOL_*,
     * string $privPass
     * return [status,data=>[testId]] or [error,data=>[testId]] or [error]
     */
    public function addMonitor($name, $tag, $agentKey, $url, $host, $oid, $version, $timeout = null, $port = null, $retries = null,
                               $minValue = null, $maxValue = null, $community = null, $username = null, $password = null,
                               $authProtocol = null, $privProtocol = null, $privPassword = null){
        $params = array();
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['agentKey'] = $agentKey;
        $params['url'] = $url;
        $params['host'] = $host;
        $params['oid'] = $oid;
        $params['version'] = $version;
        if($timeout != null) $params['timeout'] = $timeout;
        if($port != null) $params['port'] = $port;
        if($retries != null) $params['retries'] = $retries;
        if($minValue != null) $params['minValue'] = $minValue;
        if($maxValue != null) $params['maxValue'] = $maxValue;
        if($community != null) $params['community'] = $community;
        if($username != null) $params['login'] = $username;
        if($password != null) $params['pass'] = $password;
        if($authProtocol != null) $params['authProto'] = $authProtocol;
        if($privProtocol != null) $params['privProto'] = $privProtocol;
        if($privPassword != null) $params['privPass'] = $privPassword;
        
        return $this->makePostRequest('addSNMPObjectMonitor', $params);
    }
    /*
     * unsigned int $monitorId, string $name, string $tag, string $agentKey, string $url, unsigned int $timeout,
     * string $version = 1|2c|3, unsigned int $timeout, unsigned int $port, unsigned int $retries,
     * unsigned int $minValue, unsigned int $maxValue, string $community, string $login, string $password,
     * only for SNMP version 3: string $authProtocol = MSnmtMonitors::AUTH_PROTOCOL_*, string $privProtocol = MSnmtMonitors::PRIV_PROTOCOL_*,
     * string $privPass
     * return [status] or [error]
     */
    public function editMonitor($monitorId, $name, $tag, $agentKey, $url, $host, $oid, $version, $timeout = null, $port = null, $retries = null,
                                $minValue = null, $maxValue = null, $community = null, $username = null, $password = null,
                                $authProtocol = null, $privProtocol = null, $privPassword = null){
        $params = array();
        $params['testId'] = $monitorId;
        $params['name'] = $name;
        $params['tag'] = $tag;
        $params['agentkey'] = $agentKey;
        $params['url'] = $url;
        $params['host'] = $host;
        $params['oid'] = $oid;
        $params['version'] = $version;
        if($timeout != null) $params['timeout'] = $timeout;
        if($port != null) $params['port'] = $port;
        if($retries != null) $params['retries'] = $retries;
        if($minValue != null) $params['minValue'] = $minValue;
        if($maxValue != null) $params['maxValue'] = $maxValue;
        if($community != null) $params['community'] = $community;
        if($username != null) $params['login'] = $username;
        if($password != null) $params['pass'] = $password;
        if($authProtocol != null) $params['authProto'] = $authProtocol;
        if($privProtocol != null) $params['privProto'] = $privProtocol;
        if($privPassword != null) $params['privPass'] = $privPassword;
        
        return $this->makePostRequest('editSNMPObjectMonitor', $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status]
     */
    public function deleteMonitors($monitorIds){
        return $this->_deleteMonitors(11, $monitorIds);
    }
}
?>