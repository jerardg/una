<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) UNA, Inc - https://una.io
 * MIT License - https://opensource.org/licenses/MIT
 *
 * @defgroup    Notifications Notifications
 * @ingroup     UnaModules
 *
 * @{
 */

class BxNtfsDb extends BxBaseModNotificationsDb
{
    protected $_sTableEvt2Usr;
    protected $_sTableQueue;

    /*
     * Constructor.
     */
    function __construct(&$oConfig)
    {
        parent::__construct($oConfig);

        $this->_aDeliveryTypes = array(
            BX_BASE_MOD_NTFS_DTYPE_SITE, 
            BX_BASE_MOD_NTFS_DTYPE_EMAIL, 
            BX_BASE_MOD_NTFS_DTYPE_PUSH
        );

        $this->_sTableEvt2Usr = $this->_sPrefix . 'events2users';
        $this->_sTableQueue = $this->_sPrefix . 'queue';
    }

    public function markAsRead($iUserId, $iEventId)
    {
        $this->queueDeleteByProfile($iUserId, $iEventId);

        return (int)$this->query("REPLACE `" . $this->_sTableEvt2Usr . "` SET `user_id`=:user_id, `event_id`=:event_id", array(
            'user_id' => $iUserId,
            'event_id' => $iEventId
        )) > 0;
    }

    public function getLastRead($iUserId)
    {
        return (int)$this->getOne("SELECT `event_id` FROM `" . $this->_sTableEvt2Usr . "` WHERE `user_id`=:user_id LIMIT 1", array(
            'user_id' => (int)$iUserId
        ));
    }

    protected function _getSqlPartsEvents($aParams)
    {
        list($sMethod, $sSelectClause, $sJoinClause, $sWhereClause, $sOrderClause, $sLimitClause) = parent::_getSqlPartsEvents($aParams);

        switch($aParams['browse']) {
            case 'list':
                if(!empty($aParams['count_only'])) {
                    $sMethod = 'getOne';
                    $sSelectClause = 'COUNT(DISTINCT `' . $this->_sTable . '`.`id`)';
                }
                else if(in_array($aParams['type'], array(BX_BASE_MOD_NTFS_TYPE_CONNECTIONS, BX_NTFS_TYPE_OBJECT_OWNER_AND_CONNECTIONS)))
                    $sSelectClause  = 'DISTINCT ' . $sSelectClause;

                break;
        }

        $sJoinClause .= " INNER JOIN `{$this->_sTableSettings}` ON `{$this->_sTableHandlers}`.`id`=`{$this->_sTableSettings}`.`handler_id` AND `{$this->_sTableSettings}`.`delivery`='" . BX_BASE_MOD_NTFS_DTYPE_SITE . "' AND `{$this->_sTableSettings}`.`active`='1'";
        $sJoinClause .= "LEFT JOIN `{$this->_sTableSettings2Users}` ON `{$this->_sTableSettings}`.`id`=`{$this->_sTableSettings2Users}`.`setting_id` ";

        return array($sMethod, $sSelectClause, $sJoinClause, $sWhereClause, $sOrderClause, $sLimitClause);
    }

    protected function _getSqlPartsEventsList($aParams)
    {
        $sJoinClause = $sWhereClause = "";

        if(isset($aParams['active']))
            $sWhereClause .= $this->prepareAsString("AND `{$this->_sTable}`.`active`=? ", (int)$aParams['active']);

        //--- Apply modules or handlers filter
        $sWhereModuleFilter = '';
        if(!empty($aParams['modules']) && is_array($aParams['modules']))
            $sWhereModuleFilter = "AND `" . $this->_sTable . "`.`type` IN (" . $this->implode_escape($aParams['modules']) . ") ";

        if($sWhereModuleFilter == '') {
            $aHidden = $this->_oConfig->getHandlersHidden();
            $sWhereModuleFilter = !empty($aHidden) && is_array($aHidden) ? "AND `" . $this->_sTableHandlers . "`.`id` NOT IN (" . $this->implode_escape($aHidden) . ") " : "";
        }

        if($sWhereModuleFilter != '')
            $sWhereClause .= $sWhereModuleFilter;

        //--- Check flag 'New'
        if(!empty($aParams['new']) && !empty($aParams['owner_id']))
            $sWhereClause .= $this->prepareAsString("AND `{$this->_sTable}`.`id`>? ", $this->getLastRead((int)$aParams['owner_id']));

        //--- Check type
        if(!empty($aParams['owner_id']))
            switch($aParams['type']) {
                case BX_BASE_MOD_NTFS_TYPE_OWNER:
                    $sWhereClause .= $this->prepareAsString("AND `{$this->_sTable}`.`owner_id`=? ", $aParams['owner_id']);
                    break;

                case BX_BASE_MOD_NTFS_TYPE_OBJECT_OWNER:
                    $sWhereClause .= $this->prepareAsString("AND `{$this->_sTable}`.`owner_id`<>`{$this->_sTable}`.`object_owner_id` AND `{$this->_sTable}`.`object_owner_id`=? ", $aParams['owner_id']);
                    $sWhereClause .= $this->prepareAsString("AND `{$this->_sTableSettings}`.`type`=? AND `{$this->_sTableSettings}`.`active`='1' AND (ISNULL(`{$this->_sTableSettings2Users}`.`active`) OR `{$this->_sTableSettings2Users}`.`active`='1') ", BX_NTFS_STYPE_PERSONAL);
                    break;

                case BX_BASE_MOD_NTFS_TYPE_CONNECTIONS:
                    $oConnection = BxDolConnection::getObjectInstance($this->_oConfig->getObject('conn_subscriptions'));
                    $aQueryParts = $oConnection->getConnectedContentAsSQLParts($this->_sPrefix . "events", 'owner_id', $aParams['owner_id']);
                    if(empty($aQueryParts) || !is_array($aQueryParts) || empty($aQueryParts['join']))
                        break;

                    $sJoinClause .= " LEFT JOIN `sys_profiles` AS `tsp` ON `{$this->_sTable}`.`owner_id`=`tsp`.`id` " . $aQueryParts['join'];

                    if(!empty($aQueryParts['fields']['added']))
                        $sWhereClause .= "AND `{$this->_sTable}`.`date` > " . $aQueryParts['fields']['added'];

                    list($aModulesProfiles, $aModulesContexts) = $this->_oConfig->getProfileBasedModules();
                    $sWhereClause .= $this->prepareAsString("AND ((`{$this->_sTableSettings}`.`type`=? AND `tsp`.`type` IN (" . $this->implode_escape($aModulesProfiles) . ")) || (`{$this->_sTableSettings}`.`type`=? AND `tsp`.`type` IN (" . $this->implode_escape($aModulesContexts) . "))) ", BX_NTFS_STYPE_FOLLOW_MEMBER, BX_NTFS_STYPE_FOLLOW_CONTEXT);
                    break;

                case BX_NTFS_TYPE_OBJECT_OWNER_AND_CONNECTIONS:
                    /**
                     * 'personal' notifications are taken by: 
                     * 1. 'owner_id', in case of somebody performed an action in requested profile's context
                     * 'object_privacy_view' < 0 AND 'owner_id' == ABS('object_privacy_view'))
                     * Currently it happens when somebody posted a Direct Timeline post in requested profile.
                     * 2. 'object_owner_id', in case of somebody performed an action with content 
                     * owned by the requested profile.
                     */
                    $sWhereClauseObjectOwner = $this->prepareAsString("`{$this->_sTable}`.`owner_id` <> `{$this->_sTable}`.`object_owner_id` AND ((`{$this->_sTable}`.`owner_id`=? AND `{$this->_sTable}`.`object_privacy_view`<0 AND `{$this->_sTable}`.`owner_id`=ABS(`{$this->_sTable}`.`object_privacy_view`)) OR `{$this->_sTable}`.`object_owner_id`=?) ", $aParams['owner_id'], $aParams['owner_id']);
                    $sWhereClauseObjectOwner .= $this->prepareAsString("AND `{$this->_sTableSettings}`.`type`=?", BX_NTFS_STYPE_PERSONAL);

                    /**
                     * 'follow' notifications are taken by connection with `owner_id`
                     */
                    $sWhereClauseConnections = '0';
                    $oConnection = BxDolConnection::getObjectInstance($this->_oConfig->getObject('conn_subscriptions'));
                    $aQueryParts = $oConnection->getConnectedContentAsSQLPartsExt($this->_sTable, 'owner_id', $aParams['owner_id']);
                    if(!empty($aQueryParts) && is_array($aQueryParts) && !empty($aQueryParts['join'])) {
                        $sJoinClause .= " LEFT JOIN `sys_profiles` AS `tsp` ON `{$this->_sTable}`.`owner_id`=`tsp`.`id`";
                        $sJoinClause .= " LEFT JOIN `" . $aQueryParts['join']['table'] . "` AS `" . $aQueryParts['join']['table_alias'] . "` ON (" . $aQueryParts['join']['condition'] . ")";

                        $sWhereClauseConnections = "NOT ISNULL(`c`.`content`) ";
                        if(!empty($aQueryParts['fields']['added']))
                            $sWhereClauseConnections .= "AND `{$this->_sTable}`.`date` > `" . $aQueryParts['fields']['added']['table_alias'] . "`.`" . $aQueryParts['fields']['added']['name'] . "` ";

                        list($aModulesProfiles, $aModulesContexts) = $this->_oConfig->getProfileBasedModules();
                        $sWhereClauseConnections .= $this->prepareAsString("AND ((`{$this->_sTableSettings}`.`type`=? AND `tsp`.`type` IN (" . $this->implode_escape($aModulesProfiles) . ")) || (`{$this->_sTableSettings}`.`type`=? AND `tsp`.`type` IN (" . $this->implode_escape($aModulesContexts) . "))) ", BX_NTFS_STYPE_FOLLOW_MEMBER, BX_NTFS_STYPE_FOLLOW_CONTEXT);
                    }

                    $sWhereClause .= "AND ((" . $sWhereClauseObjectOwner . ") || (" . $sWhereClauseConnections . ")) AND `{$this->_sTableSettings}`.`active`='1' AND (ISNULL(`{$this->_sTableSettings2Users}`.`active`) OR `{$this->_sTableSettings2Users}`.`active`='1') ";
                    break;
            }

        return array($sJoinClause, $sWhereClause);
    }

    public function queueGet($aParams)
    {
    	$aMethod = array('name' => 'getAll', 'params' => array(0 => 'query'));

    	$sSelectClause = "*";
    	$sWhereClause = $sOrderClause = $sLimitClause = "";

        switch($aParams['type']) {
            case 'id':
            	$aMethod['name'] = 'getRow';
            	$aMethod['params'][1] = array(
                    'id' => $aParams['id']
                );

                $sWhereClause = " AND `id`=:id";
                break;

            case 'all_to_send':
                $aMethod['params'][1] = array(
                    'timeout' => $aParams['timeout']
                );

                $sWhereClause = " AND `date`+:timeout < UNIX_TIMESTAMP()";
                $sOrderClause = "`date` ASC";
                break;
        }

        $sOrderClause = !empty($sOrderClause) ? "ORDER BY " . $sOrderClause : $sOrderClause;
        $sLimitClause = !empty($sLimitClause) ? "LIMIT " . $sLimitClause : $sLimitClause;

        $aMethod['params'][0] = "SELECT
                " . $sSelectClause . "
            FROM `" . $this->_sTableQueue . "`
            WHERE 1" . $sWhereClause . " " . $sOrderClause . " " . $sLimitClause;

        return call_user_func_array(array($this, $aMethod['name']), $aMethod['params']);
    }

    public function queueAdd($aSet)
    {
        if(empty($aSet))
            return false;

        return (int)$this->query("INSERT INTO `" . $this->_sTableQueue . "` SET " . $this->arrayToSQL($aSet)) > 0;
    }

    public function queueDelete($aWhere)
    {
    	if(empty($aWhere))
            return false;

        return (int)$this->query("DELETE FROM `" . $this->_sTableQueue . "` WHERE " . $this->arrayToSQL($aWhere, ' AND ')) > 0;
    }

    public function queueDeleteByProfile($iProfileId, $iEventId)
    {
        return (int)$this->query("DELETE FROM `" . $this->_sTableQueue . "` WHERE `profile_id`=:profile_id AND `event_id`<=:event_id", array(
            'profile_id' => $iProfileId,
            'event_id' => $iEventId
        )) > 0;
    }

    public function queueDeleteByIds($mixedId)
    {
        if(empty($mixedId))
            return false;

        if(!is_array($mixedId))
            $mixedId = array($mixedId);

        return (int)$this->query("DELETE FROM `" . $this->_sTableQueue . "` WHERE `id` IN (" . $this->implode_escape($mixedId) . ")") > 0;
    }
}

/** @} */
