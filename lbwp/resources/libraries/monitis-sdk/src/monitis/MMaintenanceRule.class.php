<?php
class MMaintenanceRule extends MApi{
    const MONITOR_EXTERNAL         = 'external';
    const MONITOR_INTERNAL_CPU     = 'cpu';
    const MONITOR_INTERNAL_LOAD    = 'load';
    const MONITOR_INTERNAL_MEMORY  = 'memory';
    const MONITOR_INTERNAL_DRIVE   = 'drive';
    const MONITOR_INTERNAL_PROCESS = 'process';
    const MONITOR_INTERNAL_PING    = 'agentPingTest';
    const MONITOR_INTERNAL_HTTP    = 'agentHttpTest';
    const MONITOR_TRANSACTION      = 'transaction';
    const MONITOR_FULLPAGELOAD     = 'fillPageLoad';
    
    const PERIOD_DATETIME = 1;
    const PERIOD_TIME = 2;
    const PERIOD_WEEKDAYS = 3;
    
    const WEEKDAY_SUNDAY    = 1;
    const WEEKDAY_MONDAY    = 2;
    const WEEKDAY_TUESDAY   = 3;
    const WEEKDAY_WEDNESDAY = 4;
    const WEEKDAY_THURSDAY  = 5;
    const WEEKDAY_FRIDAY    = 6;
    const WEEKDAY_SATURDAY  = 7;

    /*
     * unsigned int $monitorId, string $monitorType = MMaintenanceRule::MONITOR_*
     * return [[groupType,timezone,weekdayTo,timeTo,maintenanceId,weekdayFrom,timeFrom,period],...] or [error]
     */
    public function requestRules($monitorId, $monitorType){
        $parmas = array();
        $parmas['monitorId'] = $monitorId;
        $parmas['monitorType'] = $monitorType;
        return $this->makeGetRequest('getMaintenanceRules', $parmas);
    }
    
    /*
     * unsigned int $monitorId, string $monitorType = MMaintenanceRule::MONITOR_*, bool $isNotifyBackup, bool $isContinuousAlerts, unsigned int $failuresCount,
     * string $periodType = MMaintenanceRule::PERIOD_* (PERIOD_ALWAYS by default),
     * Must be specified if $periodType is PERIOD_WEEKDAYS: unsigned int $weekdayFrom = MNotoficationsRules::WEEKDAY_*, unsigned int $weekdayTo = MNotoficationsRules::WEEKDAY_*,
     * Must be specified if $periodType is PERIOD_WEEKDAYS or PERIOD_TIME: string $timeFrom = 'hh:mm:ss', string $timeTo = 'hh:mm:ss',
     * Must be specified if $periodType is PERIOD_DATE: string $dateTimeFrom = 'yyyy:mm:dd hh:mm:ss', string $dateTimeTo = 'yyyy:mm:dd hh:mm:ss';
     * unsigned int $timezone
     * return [status] or [error]
     */
    public function addRule($monitorId, $monitorType, $periodType, $timeForm = null, $timeTo = null, $weekdayFrom, $weekdayTo, $dateTimeForm = null, $dateTimeTo = null, $timezone = null){
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['monitorType'] = $monitorType;
        $params['period'] = $periodType;
        
        if($periodType == self::PERIOD_WEEKDAYS){
            $params['weekdayFrom'] = $weekdayFrom;
            $params['weekdayTo'] = $weekdayTo;
        }
        if($periodType == self::PERIOD_TIME || $periodType == self::PERIOD_WEEKDAYS){
            $params['timeFrom'] = $timeForm;
            $params['timeTo'] = $timeTo;
        }
        if($periodType == self::PERIOD_DATETIME){
            $params['dateTimeFrom'] = $dateTimeForm;
            $params['dateTimeTo'] = $dateTimeTo;
        }
        if($timezone != null) $params['timezone'] = $timezone;
        return $this->makePostRequest('addMaintenanceRule', $params);
    }
    /*
     * unsigned int $monitorId, string $monitorType = MNotificatioRules::MONITOR_*, unsigned int|array $contactIds
     * return [status] or [error]
     */
    public function deleteRule($monitorId, $monitorType, $maintenanceIds){
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['monitorType'] = $monitorType;
        if(is_array($maintenanceIds)){
            $params['maintenanceIds'] = join(',', $maintenanceIds);
        }
        else{
            $params['maintenanceIds'] = $maintenanceIds;
        }
        return $this->makePostRequest('deleteMaintenanceRule', $params);
    }
}    
?>