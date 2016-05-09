<?php
class MNotificationRule extends MApi{
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
    const MONITOR_CUSTOM           = 'custom';
    
    const PERIOD_ALWAYS         = 'always';
    const PERIOD_TIME = 'specifiedTime';
    const PERIOD_WEEKDAYS = 'specifiedDays';
    
    const WEEKDAY_SUNDAY    = 1;
    const WEEKDAY_MONDAY    = 2;
    const WEEKDAY_TUESDAY   = 3;
    const WEEKDAY_WEDNESDAY = 4;
    const WEEKDAY_THURSDAY  = 5;
    const WEEKDAY_FRIDAY    = 6;
    const WEEKDAY_SATURDAY  = 7;
    
    /*
     * unsigned int $monitorId, string $monitorType = MNotificationRule::MONITOR_*
     * return [[id,monitorId,oneMail,timeTo,notifyBackup,failureCount,monitorGroupId,muteMonitors,monitorName,minFailedLocationCount,
     * muteFlag,weekdayTo,customRules=>[],muteContacts,weekdayFrom,dataTypeId,contactGroup,continuousAlerts,excludeMonitor,contactConfirmed,
     * excludeContact,period,monitorGroup,contactId,contactAccount,contactName,contactActive,timeFrom,contactType],...] or [error]
     */
    public function requestRules($monitorId, $monitorType){
        $parmas = array();
        $parmas['monitorId'] = $monitorId;
        $parmas['monitorType'] = $monitorType;
        return $this->makeGetRequest('getNotificationRules', $parmas);
    }
    
    /*
     * unsigned int $monitorId, string $monitorType = MNotificationRule::MONITOR_*, bool $isNotifyBackup, bool $isContinuousAlerts, unsigned int $failuresCount,
     * string $periodType = MNotificationRule::PERIOD_* (PERIOD_ALWAYS by default),
     * if $periodType is PERIOD_TIME or PERIOD_WEEKDAYS unsigned int $weekdayFrom = MNotoficationsRules::WEEKDAY_*, unsigned int $weekdayTo = MNotoficationsRules::WEEKDAY_*,
     * if $periodType is PERIOD_TIME string $timeFrom = 'hh:mm:ss', string $timeTo = 'hh:mm:ss',
     * unsigned int|string $periodTo = see description of $periodFrom,
     * * unsigned int $contactId, string $contactGroup,
     * string $paramName, string $paramValue (you can use > and < to change comparing method, ex: <3)
     * return [status] or [error]
     */
    public function addRule($monitorId, $monitorType, $isNotifyBackup, $isContinuousAlerts, $failuresCount,
                            $periodType = null, $timeFrom = null, $timeTo = null, $weekdayFrom = null, $weekdayTo = null,
                            $contactId = null, $contactGroup = null,
                            $paramName = null, $paramValue = null){
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['monitorType'] = $monitorType;
        $params['notifyBackup'] = $isNotifyBackup ? '1' : '0';
        $params['continuousAlerts'] = $isContinuousAlerts ? '1' : '0';
        $params['failureCount'] = $failuresCount;
        if($periodType == null) $periodType = self::PERIOD_ALWAYS;
        $params['period'] = $periodType;
        if($periodType == self::PERIOD_TIME || $periodType == self::PERIOD_WEEKDAYS){
            $params['timeFrom'] = $timeFrom;
            $params['timeTo'] = $timeTo;
        }
        if($periodType == self::PERIOD_WEEKDAYS){
            $params['weekdayFrom'] = $weekdayFrom;
            $params['weekdayTo'] = $weekdayTo;
        }
        if($paramName != null) $parmas['parmaName'] = $paramName;
        if($paramValue != null){
            $params['comparingMethod'] = 'equals';
            if($paramValue[0] == '<'){
                $params['comparingMethod'] = 'less';
                $paramValue = substr($paramValue, 1);
            }
            else if($paramValue[0] == '>'){
                $params['comparingMethod'] = 'greater';
                $paramValue = substr($paramValue, 1);
            }
            else if($paramValue[0] == '='){
                $paramValue = substr($paramValue, 1);
            }
            $params['parmaValue'] = $paramValue;
        }
        if($contactId != null) $params['contactId'] = $contactId;
        if($contactGroup != null) $params['contactGroup'] = $contactGroup;
        return $this->makePostRequest('addNotificationRule', $params);
    }
    /*
     * unsigned int $monitorId, string $monitorType = MNotificationRule::MONITOR_*, unsigned int|array $contactIds
     * return [] or [error]
     */
    public function deleteRule($monitorId, $monitorType, $contactIds){
        $params = array();
        $params['monitorId'] = $monitorId;
        $params['monitorType'] = $monitorType;
        if(is_array($contactIds)){
            $params['contactIds'] = join(',', $contactIds);
        }
        else{
            $params['contactIds'] = $contactIds;
        }
        return $this->makePostRequest('deleteNotificationRule', $params);
    }
}

?>