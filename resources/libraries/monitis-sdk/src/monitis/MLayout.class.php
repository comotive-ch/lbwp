<?php
class MLayout extends MApi{
    const MODULE_EXTERNAL          = 'External';
    const MODULE_INTERNAL_PROCESS  = 'Process';
    const MODULE_INTERNAL_DRIVE    = 'Drive';
    const MODULE_INTERNAL_MEMORY   = 'Memory';
    const MODULE_INTERNAL_HTTP     = 'InternalHTTP';
    const MODULE_INTERNAL_PING     = 'InternalPing';
    const MODULE_INTERNAL_LOAD     = 'LoadAverage';
    const MODULE_INTERNAL_CPU      = 'CPU';
    const MODULE_TRANSACTION       = 'Transaction';
    const MODULE_FULL_PAGE_LOAD    = 'Fullpageload';
    const MODULE_VISITORS_TRACKING = 'VisitorsTracking';
    const MODULE_COSTOM            = 'CustomMonitor';
    /*
     * return [[id,title],...] or [error]
     */
    public function requestPages(){
        return $this->makeGetRequest('pages');
    }
    /*
     * string $title, unsigned int $columnCount
     * return [pageId] or [error]
     */
    public function addPage($title, $columnCount = 1){
        $params = array();
        $params['title'] = $title;
        $params['columnCount'] = $columnCount;
        return $this->makePostRequest('addPage', $params);;
    }
    /*
     * unsigned int $pageId
     * return [status=>ok] or [error]
     */
    public function deletePage($pageId){
        $params = array();
        $params['pageId'] = $pageId;
        return $this->makePostRequest('deletePage', $params);
    }
    /*
     * string $pageName
     * return [[id,moduleName,dataModuleId],...] or [error]
     */
    public function requestPageModules($pageName){
        $params = array();
        $params['pageName'] = $pageName;
        return $this->makeGetRequest('pageModules', $params);
    }
    /*
     * unsigned int $pageId, string $moduleType:MLayout::MODULE_*, unsigned int $monitorId,
     * unsiged int $column, unsigned int $row, unsigned int $height
     * return [status,data=>[pageModuleId]] or [error]
     */
    public function addPageModule($pageId, $moduleType, $moduleId, $column, $row, $height = null){
        $params = array();
        $params['pageId'] = $pageId;
        $params['moduleName'] = $moduleType;
        $params['dataModuleId'] = $moduleId;
        $params['column'] = $column;
        $params['row'] = $row;
        if($height != null) $params['height'] = $height;
        return $this->makePostRequest('addPageModule', $params);
    }
    /*
     * unsigned int $moduleId
     * return [status] or [error]
     */
    public function deletePageModule($moduleId){
        $params = array();
        $params['pageModuleId'] = $moduleId;
        return $this->makePostRequest('deletePageModule', $params);
    }
}
?>