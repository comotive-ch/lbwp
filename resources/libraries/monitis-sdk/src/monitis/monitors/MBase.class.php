<?php
class MBase extends MApi{
    protected $monitorIdString;
    protected $monitorIdsString;
    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */
    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null){
        parent::__construct($apiKey, $secretKey, $authToken, $version, $apiUrl, $publicKey);
        $this->monitorIdString = 'monitorId';
        $this->monitorIdsString = 'monitorIds';
    }
    /*
     * unsigned int $monitorId
     * return [*] or [error]
     */
    protected function _requestMonitorInfo($action, $monitorId){
        $params = array();
        $params[$this->monitorIdString] = $monitorId;
        return $this->makeGetRequest($action, $params);
    }
    /*
     * string $action, unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day,
     * int $timezone, unsigned int|array $locationsIds, string $period
     * return [*] or [error]
     */
    protected function _requestMonitorResults($action, $monitorId, $year, $month, $day, $timezone = null, $locationsIds = null, $period = null){
        $params =  array();
        $params[$this->monitorIdString] = $monitorId;
        $params['year'] = $year;
        $params['month'] = $month;
        $params['day'] = $day;
        if(is_array($locationsIds)){
            $params['locationsIds'] = join(',', $locationsIds);
        }
        else if($locationsIds != null){
            $params['locationsIds'] = $locationsIds;
        }
        if($period != null) $params['period'] = $period;
        if($timezone != null) $params['timezone'] = $timezone;
        return $this->makeGetRequest($action, $params);
    }
    /*
     * unsigned int|array $locationIds
     * return [*] or [error]
     */
    protected function _requestSnapshot($action, $locationIds = null){
        $params = array();
        if(is_array($locationIds)){
            $params['locationIds'] = join(',', $locationIds);
        }
        else if($locationIds != null){
            $params['locationIds'] = $locationIds;
        }
        return $this->makeGetRequest($action, $params);
    }
    /*
     * unsigned int|array $monitorIds
     * return [status] or [error]
     */
    protected function _deleteMonitors($action, $monitorIds){
        $params = array();
        if(is_array($monitorIds)){
            $params[$this->monitorIdsString] = join(',', $monitorIds);
        }
        else{
            $params[$this->monitorIdsString] = $monitorIds;
        }
        return $this->makePostRequest($action, $params);
    }
    /*
     * unsigned int|array $monitorIds, string $tag
     * Specifie only one parametr, other parametr must be null
     * return [status,data => [failedToSuspend]] or [error]
     */
    protected function _suspendOrActivateMonitors($action, $monitorsIds, $tag = null){
        $params = array();
        if(is_array($monitorsIds)){
            $params['monitorIds'] = join(',', $monitorsIds);
        }
        else if($monitorsIds != null){
            $params['monitorIds'] = $monitorsIds;
        }
        else{
            $params['tag'] = $tag;
        }
        return $this->makePostRequest($action, $params);
    }
    /*
     * string $type = MBase::TOP_*, string $tag, bool $detailedResults, unsigned int $limit, int $timezoneoffset
     * return [*] or [error]
     */
    protected function _requestTopResults($action, $tag = null, $isDetailedResults = null, $limit = null, $timezone = null){
        $params = array();
        if($tag != null) $params['tag'] = $tag;
        if($isDetailedResults != null) $params['detailedResults'] = $isDetailedResults ? 'ture' : 'false';
        if($limit != null) $params['limit'] = $limit;
        if($timezone != null) $params['timezoneoffset'] = $timezone;
        return $this->makeGetRequest($action, $params);
    }
}
?>