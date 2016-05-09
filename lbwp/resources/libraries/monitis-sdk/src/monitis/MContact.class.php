<?php
class MContact extends MApi{
    const TYPE_EMAIL        = 1;
    const TYPE_SMS          = 2;
    const TYPE_ICQ          = 3;
    const TYPE_GOOGLE       = 7;
    const TYPE_TWITTER      = 8;
    const TYPE_CALL         = 9;
    const TYPE_SMS_AND_CALL = 10;
    const TYPE_URL          = 11;
    /*
     * unsigned int $startDate (timestamp), unsigned int $endDate (timestamp), unsigned int $limit, int $timezone
     * return [status,data=>[[dataType,recDate,dataId,failDate,dataTypeId,contacts,dataName],...]] or [error]
     */
    public function requestAlerts($startDate = null, $endDate = null, $limit = null, $timezone = null){
        $params = array();
        if($startDate != null) $params['startDate'] = $startDate;
        if($endDate != null) $params['endDate'] = $endDate;
        if($limit != null) $params['limit'] = $limit;
        if($timezone != null) $params['timezone'] = $timezone;
        return $this->makeGetRequest('recentAlerts', $params);
    }
    /*
     * return [[newsletterFlag,timezone,portable,contactId,activeFlag,contactAccount,name,contactType,textType,confirmationFlag,country],...] or [error]
     */
    public function requestContacts(){
        return $this->makeGetRequest('contactsList');
    }
    /*
     * string $firstName, string $lastName, int $contactType = MContact::TYPE_*, string $contact, int $timezone (Ex: 300), string $group,
     * string $country (2 or 3 letters code), boll $isPortable (available for 'SMS' and 'SMS and Call' types),
     * bool $isTextAlerts, bool $isSendDailyReports, bool $isSendWeeklyReports, bool $isSendMonthlyReports
     * return [status,data=>[contactId,confirmationKey]] or [error]
     */
    public function addContact($firstName, $lastName, $contactType, $contact, $timezone, $group = null, $country = null, $isPortable = null,
                               $isTextAlerts = false, $isSendDailyReports = false, $isSendWeeklyReports = false, $isSendMonthlyReports = false){
        $params = array();
        $params['firstName'] = $firstName;
        $params['lastName'] = $lastName;
        $params['contactType'] = $contactType;
        $params['account'] = $contact;
        $params['timezone'] = $timezone;
        if($group != null) $params['group'] = $group;
        if($country != null) $params['country'] = $country;
        if($isPortable != null) $params['portable'] = $isPortable ? 'true' : 'false';
        $params['textType'] = $isTextAlerts ? 'true' : 'false';
        $params['sendDailyReport'] = $isSendDailyReports ? 'true' : 'false';
        $params['sendWeeklyReport'] = $isSendWeeklyReports ? 'true' : 'false';
        $params['sendMonthlyReport'] = $isSendMonthlyReports ? 'true' : 'false';
        return $this->makePostRequest('addContact', $params);
    }
    /*
     * unsigned int $contactId, string $firstName, string $lastName, int $contactType = MContact::TYPE_*, string $contact, int $timezone (Ex: 300), string $group,
     * string $country (2 or 3 letters code), boll $isPortable (available for 'SMS' and 'SMS and Call' types), bool $isTextAlerts, string $confirmationKey
     * return [status] or [error]
     */
    public function editContact($contactId, $firstName = null, $lastName = null, $contactType = null, $contact = null, $timezone = null, $country = null,
                                $isPortable = null, $isTextAlerts = null, $confirmationKey = null){
        $params = array();
        if($contactId != null) $params['contactId'] = $contactId;
        if($firstName != null) $params['firstName'] = $firstName;
        if($lastName != null) $params['lastName'] = $lastName;
        if($contactType != null) $params['contactType'] = $contactType;
        if($contact != null) $params['account'] = $contact;
        if($country != null) $params['country'] = $country;
        if($isPortable != null) $params['portable'] = $isPortable ? 'true' : 'false';
        if($isTextAlerts != null) $params['textAlerts'] = $isTextAlerts ? 'true' : 'false';
        if($timezone != null) $params['timezone'] = $timezone;
        if($confirmationKey != null) $params['code'] = $confirmationKey;
        return $this->makePostRequest('editContact', $params);
    }
    /*
     * unsigned int $contactId, unsigned int $type = MContact::TYPE_*, string $contact
     * Specifie only one parametr
     * return [status] or [error]
     */
    public function deleteContact($contactId, $contactType = null, $contact = null){
        $params = array();
        if($contactId != null) $params['contactId'] = $contactId;
        if($contactType != null) $params['contactType'] = $contactType;
        if($contact != null) $params['contanct'] = $contact;
        return $this->makePostRequest('deleteContact', $params);
    }
    /*
     * unsigned int $contactId, string $confirmationKey
     * return [status] or [error]
     */
    public function confirmContact($contactId, $confirmationKey){
        $params = array();
        $params['contactId'] = $contactId;
        $params['confirmationKey'] = $confirmationKey;
        return $this->makePostRequest('confirmContact', $params);
    }
    /*
     * unsigned int $contactId
     * return [status] or [error]
     */
    public function activateContact($contactId){
        $params = array();
        $params['contactId'] = $contactId;
        return $this->makePostRequest('contactActivate', $params);
    }
    /*
     * unsigned int $contactId
     * return [status] or [error]
     */
    public function deactivateContact($contactId){
        $params = array();
        $params['contactId'] = $contactId;
        return $this->makePostRequest('contactDeactivate', $params);
    }
    /*
     * return [[id,name,activateFlag],...] or [error]
     */
    public function requestContactGroups(){
        return $this->makeGetRequest('contactGroupList');
    }
    /*
     * string $groupName, bool $isActive
     * return [status] or [error]
     */
    public function addContactGroup($groupName, $isActive = true){
        $params = array();
        $params['groupName'] = $groupName;
        $params['active'] = $isActive ? '1' : '0';
        return $this->makePostRequest('addContactGroup', $params);
    }
    /*
     * string $groupOldName, string $groupNewName
     * return [status] or [error]
     */
    public function editContactGroup($groupOldName, $groupNewName){
        $params = array();
        $params['oldName'] = $groupOldName;
        $params['newName'] = $groupNewName;
        return $this->makePostRequest('editContactGroup', $params);
    }
    /*
     * string $groupName
     * return [status] or [error]
     */
    public function deleteContactGroup($groupName){
        $params = array();
        $params['groupName'] = $groupName;
        return $this->makePostRequest('deleteContactGroup', $params);
    }
}

?>