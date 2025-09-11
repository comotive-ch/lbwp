<?php

class MCustomMonitor extends MApi {

    const INTERVAL_MINUTES = 'min';
    const INTERVAL_HOURS = 'hour';
    const INTERVAL_DAYS = 'day';
    const INTERVAL_MONTHES = 'month';
    const PARAM_TYPE_BOOL = 1;
    const PARAM_TYPE_INT = 2;
    const PARAM_TYPE_STRING = 3;
    const PARAM_TYPE_FLOAT = 4;
    const AGGREGATE_AVG = 'avg';
    const AGGREGATE_SUM = 'sum';
    const AGGREGATE_MIN = 'min';
    const AGGREGATE_MAX = 'max';
    const AGGREGATE_LAST = 'last';

    /*
     * string $apiKey, string $secretKey, string $authToken, string $version, string $apiUrl, string $publicKey
     */

    public function __construct($apiKey = null, $secretKey = null, $authToken = null, $version = null, $apiUrl = null, $publicKey = null) {
        if ($apiUrl == null)
            $apiUrl = self::URL_CUSTOM;
        parent::__construct($apiKey, $secretKey, $authToken, $version, $apiUrl, $publicKey);
    }

    /*
     * array $params, array $defaults
     * Returning string format is param11:param12:param13:...;... (if param is array it converts to json string)
     */

    private function _makeParamsString($params, $defaults = null) {
        $paramsString = '';
        foreach ($params as $param) {
            if ($paramsString)
                $paramsString .= ';';
            if (is_array($defaults)) {
                foreach ($defaults as $key => $default) {
                    if (!isset($param[$key]) && $default !== null) {
                        $param[$key] = $default;
                    }
                }
            }
            $paramString = '';
            foreach ($param as $val) {
                if ($paramString)
                    $paramString .= ':';
                if (is_array($val)) {
                    $paramString .= json_encode($val);
                } else if (is_bool($val)) {
                    $paramString .= $val ? 'true' : 'false';
                } else {
                    $paramString .= urlencode($val);
                }
            }
            $paramsString .= $paramString;
        }
        return $paramsString;
    }

    /*
     * array $results = [
     *   param1 => [value11, value21,...],
     *   ...
     * ]
     * or [
     *   param1 => value1,
     *   ...
     * ]
     * Returing string format is param1:[value11,value21,...];...
     */

    private function _makeResultsString($results) {
        $resultsString = '';
        foreach ($results as $key => $vals) {
            if ($resultsString)
                $resultsString .= ';';
            $resultsString .= $key . ':';
            if (is_array($vals)) {
                $valuesString = '';
                foreach ($vals as $val) {
                    if ($valuesString)
                        $valuesString .= ',';
					if(is_string($val))	
						$valuesString .='"'. $val.'"';
					else
						$valuesString .=$val;
                }
                $resultsString .=urlencode('[' . $valuesString . ']');
            }
            else {
                $resultsString .= urlencode($vals);
            }
        }
        return $resultsString;
    }

    /*
     * array $results = [
     *   param1 => [value11, value21,...],
     *   ...
     * ]
     * or [
     *   param1 => value1,
     *   ...
     * ]
     * Returning string format is [{param1:value11,param2:value21,...},...]
     */

    private function _makeAdditionalResultsString($results) {
        $resultsArray = array();
        if (is_array($results)) {
            foreach ($results as $key => $vals) {
                if (is_array($vals)) {
                    foreach ($vals as $i => $val) {
                        if (!is_array($resultsArray[$i]))
                            $resultsArray[$i] = array();
                        $resultsArray[$i][$key] = $val;
                    }
                }
                else {
                    $resultsArray[0][$key] = $vals;
                }
            }
        }
        return json_encode($resultsArray);
    }

    /*
     * string $type, string $tag, string $name, unsigned int $agentId
     * return [[id,name,type,tag,monitorParams=>[]],...] or [error]
     */

    public function requestMonitors($type = null, $tag = null, $name = null, $agentId = null) {
        $params = array();
        if ($type != null)
            $params['type'] = $type;
        if ($tag != null)
            $params['tag'] = $tag;
        if ($name != null)
            $params['name'] = $name;
        if ($agentId != null)
            $params['agentId'] = $agentId;
        return $this->makeGetRequest('getMonitors', $params);
    }

    /*
     * unsigned int $monitorId, bool $isExcludeHidden
     * return [id,name,type,tag,resultParams=>[[id,dataType,uom,name,displayName],...],monitorParams=>[[id,dataType,name,value,displayName],...],additionalResultParams=>[[id,dataType,uom,name,displayName],...]] or [error]
     */

    public function requestMonitorInfo($monitorId, $isExcludeHidden = false) {
        $params = array();
        $params['monitorId'] = $monitorId;
        if ($isExcludeHidden)
            $params['isExcludeHidden'] = 'true';
        return $this->makeGetRequest('getMonitorInfo', $params);
    }

    /*
     * unsigned int $monitorId, unsigned int $year, unsigned int $month, unsigned int $day, int $timezone
     * return [[paramName1,paramName2,...,checkTime,checkTimeInGMT],...] or [error]
     */

    public function requestMonitorResults($monitorId, $year, $month, $day, $timezone = null) {
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['year'] = $year;
        $params['month'] = $month;
        $params['day'] = $day;
        if ($timezone != null)
            $params['timezone'] = $timezone;
        return $this->makeGetRequest('getMonitorResults', $params);
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

    /*
     * unsigned int $monitorId, unsigned int $checkTime (timestamp)
     * return [[paramName1,paramName2,...],...] or [error]
     */

    public function requestMonitorAdditionalResults($monitorId, $checkTime = null) {
        $params = array();
        $params['monitorId'] = $monitorId;
        if ($checkTime != null)
            $params['checktime'] = $checkTime;
        return $this->makeGetRequest('getAdditionalResults', $params);
    }

    /*
     * unsigned int $monitorId, unsigned int $dateFrom, unsigned int $dateTo, int $timezone
     * return [] or [error]
     */

    public function requestMonitorAdditionalReport($monitorId, $dateFrom, $dateTo, $timezone = null) {
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['dateFrom'] = $dateFrom;
        $params['dateTo'] = $dateTo;
        if ($timezone != null)
            $params['timezone'] = $timezone;
        return $this->makeGetRequest('getAdditionalDataReport', $params);
    }

    /*
     * string $name, string $type, string $tag, array $resultParams = [
     *  [string name, string display name, string unit of measure, unsigned int data type = MCustomMonitor::PARAM_TYPE_* (int by default),
     *  bool has fixed values (optional), array groups (optional), string aggregation function = MCustomMonitor::AGREGATE_* (optional) ], ...
     * ], bool $isMultiValue, array $monitorParams  = [
     *  string name, string display name, string value, unsigned int data type  = MCustomMonitor::PARAM_TYPE_* (int by default), bool is hidden (false by default)
     * ], array $additionalResultParams = [
     *  string name, string display name, string unit of measure, unsigned int data type = MCustomMonitor::PARAM_TYPE_* (int by defaylt)
     * ], unsigned int $agentId, string $agentName
     * return [status,data] or [error]
     */

    public function addMonitor($name, $type, $tag, $resultParams, $isMultiValue = false, $monitorParams = null, $additionalResultParams = null, $agentId = null, $agentName = null) {
        $params = array();
        $params['name'] = $name;
        $params['type'] = $type;
        $params['tag'] = $tag;
        $params['resultParams'] = $this->_makeParamsString($resultParams, array(null, null, null, MCustomMonitor::PARAM_TYPE_INT, false));

        $params['multiValue'] = $isMultiValue ? 'true' : 'false';
        if ($monitorParams != null) {
            $params['monitorParams'] = $this->_makeParamsString($monitorParams, array(null, null, null, MCustomMonitor::PARAM_TYPE_INT, false));
        }
        if ($additionalResultParams != null)
            $params['additionalResultParams'] = $this->_makeParamsString($additionalResultParams, array(null, null, null, MCustomMonitor::PARAM_TYPE_INT));
        if ($agentId != null)
            $params['agentId'] = $agentId;
        if ($agentName != null)
            $params['agentName'] = $agentName;
        return $this->makePostRequest('addMonitor', $params);
    }

    /*
     * unsigned int $monitorId, string $name, string $tag, array $resultParams = [
     *  [string name, string display name, string unit of measure, unsigned int data type = MCustomMonitor::PARAM_TYPE_*,
     *  bool has fixed values (optional), groups = [] (optional)], ...
     * ], bool $isMultiValue, array $monitorParams  = [
     *  string name, string display name, string value, unsigned int data type  = MCustomMonitor::PARAM_TYPE_*, bool is hidden (optional)
     * ], $additionalResultParams = [
     *  string name, string display name, string unit of measure, unsigned int data type = MCustomMonitor::PARAM_TYPE_*
     * ]
     * return [status] or [error]
     */

    public function editMonitor($monitorId, $name, $tag = null, $resultParams = null, $isMultiValue = false, $monitorParams = null, $additionalResultParams = null) {
        $params = array();
        $params['monitorId'] = $monitorId;
        if ($name != null)
            $params['name'] = $name;
        if ($tag != null)
            $params['tag'] = $tag;
        if ($resultParams != null)
            $params['resultParams'] = $this->_makeParamsString($resultParams, array(null, null, null, MCustomMonitor::PARAM_TYPE_INT, false));
        if ($isMultiValue)
            $params['multiValue'] = 'true';
        if ($monitorParams != null)
            $params['monitorParams'] = $this->_makeParamsString($monitorParams, array(null, null, null, MCustomMonitor::PARAM_TYPE_INT, false));
        if ($additionalResultParams != null)
            $params['additionalResultParams'] = $this->_makeParamsString($additionalResultParams, array(null, null, null, MCustomMonitor::PARAM_TYPE_INT));
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
     * unsigned int $monitorId, unsigned int $checkTime (timestamp), array $results = [
     *   param name => [value 1, value 2], ... (or string if value is only one)
     * ], array $additionalResults = [
     *   param name =>[value 1, value 2], ... (or string if value is only one)
     * ]
     * return [status] or [error]
     */

    public function addMonitorResults($monitorId, $checkTime, $results, $additionalResults = null) {
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['checktime'] = $checkTime;
        $params['results'] = $this->_makeResultsString($results);
        if ($additionalResults != null)
            $params['additionalResults'] = $this->_makeAdditionalResultsString($additionalResults);
        return $this->makePostRequest('addResult', $params);
    }

    /*
     * unsigned int $monitorId, unsigned int $checkTime (timestamp), array $results = [
     *   param name => [value 1, value 2], ... (or string if value is only one)
     * ]
     * return [] or [error]
     */

    public function addMonitorAdditionalResults($monitorId, $checkTime, $results) {
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['checktime'] = $checkTime;
        $params['results'] = $this->_makeAdditionalResultsString($results);
        return $this->makePostRequest('addAdditionalResults', $params);
    }

    /*
     * unsigned int $monitorId, array $styles = [[
     *  name => string,
     *  colors => [
     *    [condition => string (<number, >number, =number, !=number), color => string (color name or #rgb) ]
     *  ]
     * ], ...]
     * Ex:
     * [
     *   [
     *     name: 'test', 
     *     colors: [ [condition => '<3', color: 'green'], [condition => '>12', color: 'red'] ]
     *   ]
     * ]
     * return [] or [error]
     */

    public function colorizeMonitor($monitorId, $styles) {
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['guiStyle'] = json_encode($styles);
        return $this->makePostRequest('colorizeMonitors', $params);
    }

}

?>