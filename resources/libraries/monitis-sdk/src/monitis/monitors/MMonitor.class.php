<?php

class MMonitor extends MApi {

    const TYPE_DISKIO    = 2;
	const TYPE_BANDWIDTH = 6;
	const TYPE_TOMCAT    = 10;
	const TYPE_LOG       = 11;
	const TYPE_SERVICE   = 12;
	const TYPE_ORACLE    = 13;
	const TYPE_MYSQL     = 36;
	const TYPE_NODEJS    = 37;
	
    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */

    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null) {
        if ($apiUrl == null)
            $apiUrl = self::URL_DEFAULT;
        parent::__construct($apiKey, $secretKey, $authToken, $version, $apiUrl, $publicKey);
    }

    /*
     * string $name, integer $monitorTypeId, string $tag, json object $monitorParams = {"param1":"value1",...} string $agentKey
     * return [status,data] or [error]
     */

    public function addMonitor($name, $monitorTypeId, $tag, $monitorParams, $agentKey) {
        $params = array();
        $params['name'] = $name;
        $params['monitorTypeId'] = $monitorTypeId;
        $params['tag'] = $tag;
        $params['monitorParams'] = json_encode($monitorParams);
        $params['agentKey'] = $agentKey;
		
        return $this->makePostRequest('addMonitor', $params);
    }

    /*
     * unsigned int $monitorId, string $name, string $tag, json object $monitorParams = {"param1":"value1",...}
     * return [status] or [error]
     */

    public function editMonitor($monitorId, $name = null, $tag = null, $monitorParams = null) {
        $params = array();
        $params['monitorId'] = $monitorId;
        if ($name != null)
            $params['name'] = $name;
        if ($tag != null)
            $params['tag'] = $tag;
		if ($monitorParams != null)
            $params['monitorParams'] = json_encode($monitorParams);
        return $this->makePostRequest('editMonitor', $params);
    }

    /*
     * unsigned int|array $monitorIds
     * return [status] or [error]
     */

    public function deleteMonitors($monitorIds) {
        $params = array();
        if (is_array($monitorIds)) {
            $params['monitorId'] = join(',', $monitorIds);
        } else {
            $params['monitorId'] = $monitorIds;
        }
        return $this->makePostRequest('deleteMonitor', $params);
    }
	
	/*
     * integer $monitorTypeId, string $tag, string $name, unsigned int $agentId
     * return [[id,name,tag,monitorParams=>[]],...] or [error]
     */

    public function requestMonitors($monitorTypeId = null, $tag = null) {
        $params = array();
        if ($monitorTypeId != null)
            $params['monitorTypeId'] = $monitorTypeId;
        if ($tag != null)
            $params['tag'] = $tag;
        return $this->makeGetRequest('getMonitors', $params);
    }
	
    /*
     * unsigned int $monitorId
     * return [id,name,monitorTypeId,tag,monitorParams=>{paramName:paramValue,...}] or [error]
     */

    public function requestMonitorInfo($monitorId) {
        $params = array();
        $params['monitorId'] = $monitorId;
        return $this->makeGetRequest('getMonitorInfo', $params);
    }
	
    /*
     * unsigned int $monitorId, unsigned int $dateFrom (timestamp), unsigned int $dateTo (timestamp),
     * unsigned int $interval, string $intervalType = MCustomMonitor::INTERVAL_* (by default minutes), int $timezone
     * return [[paramName1,paramName2,...,checkTime,checkTimeInGMT],...] or [error]
     */

    public function requestMonitorReport($monitorId, $dateFrom, $dateTo, $interval = null, $intervalType = null, $timezone = null) {
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['dateFrom'] = $dateFrom;
        $params['dateTo'] = $dateTo;
        if ($interval != null) {
            $params['interval'] = $interval;
            if ($intervalType != null) {
                $params['intervalType'] = $intervalType;
            } else {
                $params['intervalType'] = MCustomMonitor::INTERVAL_MINUTES;
            }
        }
        if ($timezone != null)
            $params['timezone'] = $timezone;
        return $this->makeGetRequest('getReport', $params);
    }
}

?>
