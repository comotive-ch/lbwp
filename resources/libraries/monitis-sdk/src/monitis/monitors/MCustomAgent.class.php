<?php
class MCustomAgent extends MApi{
    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */
    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null){
        if($apiUrl == null) $apiUrl = self::URL_CUSTOM;
        parent::__construct($apiKey, $secretKey, $authToken, $version, $apiUrl, $publicKey);
    }
    /*
     * string $type, bool $isLoadTests, bool $isLoadParamets
     * return [[id,name,type,jobPollingInterval,createdOn,lastAccess,
     *      monitors=>[id,name,tag,type] (if $isLoadMonitors is true)
     * ],...] or [error]
     * Gets all agents including agents for internal monitors
     */
    public function requestAgetns($type = null, $isLoadMonitors = null, $isLoadParamets = null){
        $fields = array();
        if($type != null) $fields['type'] = $type;
        if($isLoadMonitors != null) $fields['loadTest'] = $isLoadMonitors ? 'true' : 'false';
        if($isLoadParamets != null) $fields['loadParametrs'] = $isLoadParamets ? 'true' : 'false';
        return $this->makeGetRequest('getAgents', $fields);
    }
    /*
     * unsigned int $agentId
     * return [id,type,name,jobPollingInterval,createdOn,lastAccess,monitors=>[],params=>[[id,name,value],...]] or [error]
     */
    public function requestAgentInfo($agentId){
        $fields = array();
        $fields['agentId'] = $agentId;
        return $this->makeGetRequest('agentInfo', $fields);
    }
    /*
     * string $name, array $params
     * return [status,data] or [error]
     */
    public function addAgent($name, $params){
        $fields = array();
        $fields['name'] = $name;
        $fields['params'] = json_encode($params);
        return $this->makePostRequest('addAgent', $fields);
    }
    /*
     * unsigned int $agentId, string $name, array $params
     * return [status] or [error]
     */
    public function editAgent($agentId, $name, $params){
        $fields = array();
        $fields['agentId'] = $agentId;
        $fields['name'] = $name;
        $fields['params'] = json_encode($params);
        return $this->makePostRequest('editAgent', $fields);
    }
    /*
     * unsigned int|array $agentIds, bool $isDeleteMonitors
     * return [status] or [error]
     */
    public function deleteAgents($agentIds, $isDeleteMonitors = null){
        $fields = array();
        if(is_array($agentIds)) $fields['agentIds'] = join(',', $agentIds);
        else $fields['agentIds'] = $agentIds;
        if($isDeleteMonitors != null) $fields['deleteMonitors'] = $isDeleteMonitors ? 'true' : 'false';
        return $this->makePostRequest('deleteAgent', $fields);
    }
}
?>