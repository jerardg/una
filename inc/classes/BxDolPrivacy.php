<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) UNA, Inc - https://una.io
 * MIT License - https://opensource.org/licenses/MIT
 *
 * @defgroup    UnaCore UNA Core
 * @{
 */

define('BX_DOL_PG_HIDDEN', '1');
define('BX_DOL_PG_MEONLY', '2');
define('BX_DOL_PG_ALL', '3');
define('BX_DOL_PG_MEMBERS', '4');
define('BX_DOL_PG_FRIENDS', '5');

define('BX_DOL_PG_DEFAULT', BX_DOL_PG_ALL);

/**
 * Privacy settings for any content.
 *
 * Integration of the content with privacy engine allows site member
 * to organize the access to his content.
 *
 * In addition to regular privacy groups (Public, Friends), spaces are supported. 
 * When some space (usually some another profile) is specified as privacy, 
 * then another profile visibility is used to check the privacy.
 *
 * Related classes:
 *  BxDolPrivacyQuery - database queries.
 *
 * Example of usage:
 * 1. Register your privacy actions in `sys_privacy_actions` database table.
 * 2. Add one privacy field(with INT type) in the table with your items for each action.
 *    For example, for action 'comment', the field name should be 'allow_comment_to'.
 * 3. Add group choosers for necessary actions in the form, which is used to add new items.
 * @code
 *    $oPrivacy = new BxDolPrivacy();
 *    $oPrivacy->getGroupChooser($iItemOwnerId, $sModuleUri, $sModuleAction);
 * @endcode
 *
 * 4. Check privacy when any user tries to view an item.
 * @code
 *    $oPrivacy = new BxDolPrivacy($sTable, $sFieldId, $sFieldOwnerId);
 *    if($oPrivacy->check($sAction, $iObjectId, $iViewerId)) {
 *     //show necessary content
 *    }
 * @endcode
 *
 *    @see an example of integration in BoonEx modules, for example: Posts
 *
 *
 * Memberships/ACL:
 * Doesn't depend on user's membership.
 *
 *
 * Alerts:
 * no alerts available
 *
 */
class BxDolPrivacy extends BxDolFactory implements iBxDolFactoryObject
{
    protected $_oDb;
    protected $_sObject;
    protected $_aObject;

    protected $_aGroupsExclude;

    /**
     * Constructor
     * @param $aObject array of grid options
     */
    protected function __construct($aObject)
    {
        parent::__construct();

        $this->_aObject = $aObject;
        $this->_sObject = $aObject['object'];

        $this->_oDb = new BxDolPrivacyQuery();
        $this->_oDb->init($this->_aObject);

        $this->_aGroupsExclude = array();
    }

    /**
     * Get privacy object instance by object name
     * @param $sObject object name
     * @return object instance or false on error
     */
    public static function getObjectInstance($sObject)
    {
        if(isset($GLOBALS['bxDolClasses']['BxDolPrivacy!' . $sObject]))
            return $GLOBALS['bxDolClasses']['BxDolPrivacy!' . $sObject];

        $aObject = BxDolPrivacyQuery::getPrivacyObject($sObject);
        if(!$aObject || !is_array($aObject))
            return false;

        $sClass = 'BxTemplPrivacy';
        if(!empty($aObject['override_class_name'])) {
            $sClass = $aObject['override_class_name'];
            if(!empty($aObject['override_class_file']))
                require_once(BX_DIRECTORY_PATH_ROOT . $aObject['override_class_file']);
        }

        $o = new $sClass($aObject);
        return ($GLOBALS['bxDolClasses']['BxDolPrivacy!' . $sObject] = $o);
    }

    /**
     * Get Select element with available groups.
     *
     * @param  string  $sObject  privacy object name.
     * @param  integer $iOwnerId object's owner ID.
     * @param  array   $aParams  an array of custom selector's params (dynamic_groups - an array of arrays('key' => group_id, 'value' => group_title), title - the title to be used for generated field).
     * @return an      array with Select element description.
     */
    public static function getGroupChooser($sObject, $iOwnerId = 0, $aParams = array())
    {
        $oPrivacy = BxDolPrivacy::getObjectInstance($sObject);
        if(empty($oPrivacy))
            return array();

        $sModule = $oPrivacy->_aObject['module'];
        $sAction = $oPrivacy->_aObject['action'];

        if($iOwnerId == 0)
            $iOwnerId = bx_get_logged_profile_id();

        $sValue = $oPrivacy->_oDb->getDefaultGroupByUser($sModule, $sAction, $iOwnerId);
        if(empty($sValue))
            $sValue = $oPrivacy->_oDb->getDefaultGroup($sModule, $sAction);

        $aValues = $oPrivacy->getGroups();

        $aValues = $oPrivacy->addDynamicGroups($aValues, $iOwnerId, $aParams);
        
        $aValues = $oPrivacy->addSpaces($aValues, $iOwnerId, $aParams);

        $sName = $oPrivacy->convertActionToField($sAction);

        $sTitle = isset($aParams['title']) && !empty($aParams['title']) ? $aParams['title'] : '';
        if(empty($sTitle)) {
            $sTitle = $oPrivacy->_oDb->getTitle($sModule, $sAction);
            $sTitle = _t(!empty($sTitle) ? $sTitle : '_' . $sName);
        }

        return array(
            'type' => 'select',
            'name' => $sName,
            'caption' => $sTitle,
            'value' => $sValue,
            'values' => $aValues,
            'checker' => array(
                'func' => 'avail',
                'error' => _t('_sys_ps_ferr_incorrect_select')
            ),
            'db' => array(
                'pass' => 'Int'
            )
        );
    }

    public function addDynamicGroups($aValues, $iOwnerId, $aParams)
    {
        if (isset($aParams['dynamic_groups']) && is_array($aParams['dynamic_groups']))
            $aValues = array_merge($aValues, $aParams['dynamic_groups']);

        return $aValues;
    }

    public function addSpaces($aValues, $iOwnerId, $aParams)
    {
        if (!$this->_aObject['spaces'])
            return $aValues;

        if (!($oProfile = BxDolProfile::getInstance($iOwnerId)))
            return $aValues;

        if (!($aModules = BxDolModuleQuery::getInstance()->getModules()))
            return $aValues;

        $bProfileProcessed = true; // don't allow to post to profiles for now
        foreach ($aModules as $aModule) {
            if (!$aModule['enabled'])
                continue;

            if ('all' != $this->_aObject['spaces'] && false === stripos($this->_aObject['spaces'], $aModule['name']))
                continue;

            if (!BxDolRequest::serviceExists($aModule['name'], 'act_as_profile'))
                continue;

            $bActAsProfile = BxDolService::call($aModule['name'], 'act_as_profile');
            if ($bActAsProfile && $bProfileProcessed)
                continue;
            if ($bActAsProfile)
                $bProfileProcessed = true;

            $a = BxDolService::call($aModule['name'], 'get_participating_profiles', array($oProfile->id()));
            $aSpaces = array();            
            foreach ($a as $iProfileId) {
                if (!($o = BxDolProfile::getInstance($iProfileId)))
                    continue;
                $aSpaces[-$iProfileId] = array('key' => -$iProfileId, 'value' => $o->getDisplayName());
            }

            if ($aSpaces) {
                $aItemStart = array(array('type' => 'group_header', 'value' => mb_strtoupper(BxDolService::call($aModule['name'], 'get_space_title'))));
                $aItemEnd = array(array('type' => 'group_end'));
                $aValues = array_merge($aValues, $aItemStart, array_values($aSpaces), $aItemEnd);
            }
        }
        
        return $aValues;
    }
    
    /**
     * Get database field name for action.
     *
     * @param  string $sObject privacy object name.
     * @param  string $sAction action name.
     * @return string with field name.
     */
    public static function getFieldName($sObject, $sAction = '')
    {
    	$oPrivacy = BxDolPrivacy::getObjectInstance($sObject);
        if(empty($oPrivacy))
            return '';

		if(empty($sAction))
			$sAction = $oPrivacy->_aObject['action'];

        return $oPrivacy->convertActionToField($sAction);
    }

    /**
     * Get necessary condition array to use privacy in search classes
     * @param $mixedGroupId group ID or array of group IDs
     * @return array of conditions, for now with 'restriction' part only is returned
     */
    public function getContentByGroupAsCondition($mixedGroupId)
    {
        $aResult = array(
            'restriction' => array (
                'privacy_' . $this->_sObject => array(
                    'value' => $mixedGroupId,
                    'field' => $this->convertActionToField($this->_aObject['action']),
                    'operator' => is_array($mixedGroupId) ? 'in' : '=',
                    'table' => $this->_aObject['table'],
                ),
            ),
        );      
        bx_alert('system', 'privacy_condition', 0, false, array(
            'group_id' => $mixedGroupId,
            'field' => $this->convertActionToField($this->_aObject['action']),
            'object' => $this->_aObject,
            'privacy_object' => $this,
            'result' => &$aResult
            )
        );
        return $aResult;
    }

    /**
     * Get necessary condition array to use privacy in search classes
     * @param $iProfileIdOwner owner profile ID
     * @return array of conditions, for now with 'restriction' part only is returned
     */
    public function getContentPublicAsCondition($iProfileIdOwner = 0, $aCustomGroups = array())
    {
        $mixedPrivacyGroups = $this->getPrivacyGroupsForContentPublic($iProfileIdOwner, $aCustomGroups);
        if($mixedPrivacyGroups === true)
        	return array();

        return $this->getContentByGroupAsCondition($mixedPrivacyGroups);
    }

    /**
     * Get necessary parts of SQL query to use privacy in other queries
     * @param $mixedGroupId group ID or array of group IDs
     * @return array of SQL string parts, for now 'where' part only is returned
     */
    public function getContentByGroupAsSQLPart($mixedGroupId)
    {
        $sField = $this->convertActionToField($this->_aObject['action']);
        return $this->_oDb->getContentByGroupAsSQLPart($sField, $mixedGroupId);
    }

	/**
     * Get necessary parts of SQL query to use privacy in other queries
     * @param $iProfileIdOwner owner profile ID
     * @return array of SQL string parts, for now 'where' part only is returned
     */
    public function getContentPublicAsSQLPart($iProfileIdOwner = 0, $aCustomGroups = array())
    {
        $mixedPrivacyGroups = $this->getPrivacyGroupsForContentPublic($iProfileIdOwner, $aCustomGroups);
		if($mixedPrivacyGroups === true)
        	return array();

        return $this->getContentByGroupAsSQLPart($mixedPrivacyGroups);
    }

    /**
     * Check whether the viewer can make requested action.
     *
     * @param  integer $iObjectId object ID the action to be performed with.
     * @param  integer $iViewerId viewer ID.
     * @return boolean result of operation.
     */
    function check($iObjectId, $iViewerId = 0)
    {
        if(empty($iViewerId))
            $iViewerId = (int)bx_get_logged_profile_id();

        $aObject = $this->getObjectInfo($this->convertActionToField($this->_aObject['action']), $iObjectId);
        if(empty($aObject) || !is_array($aObject))
            return false;

        if($aObject['group_id'] == BX_DOL_PG_HIDDEN)
            return false;

        if(isAdmin() || $iViewerId == $aObject['owner_id'])
            return true;

        if ($aObject['group_id'] < 0)
            return $this->checkSpace($aObject, $iViewerId);

        $aGroup = $this->_oDb->getGroupsBy(array('type' => 'id', 'id' => $aObject['group_id']));
        if(!empty($aGroup) && is_array($aGroup) && (int)$aGroup['active'] == 1 && !empty($aGroup['check'])) {
            $sCheckMethod = $this->getCheckMethod($aGroup['check']);
            if(method_exists($this, $sCheckMethod) && $this->$sCheckMethod($aObject['owner_id'], $iViewerId))
                return true;
        }

        return $this->isDynamicGroupMember($aObject['group_id'], $aObject['owner_id'], $iViewerId, $iObjectId);
    }

    public function checkSpace($aObject, $iViewerId)
    {
        $oProfile = BxDolProfile::getInstance(-$aObject['group_id']);
        if (!$oProfile)
            return false;

        return CHECK_ACTION_RESULT_ALLOWED === BxDolService::call($oProfile->getModule(), 'check_space_privacy', array($oProfile->getContentId()));
    }

    public function checkMeOnly($iOwnerId, $iViewerId)
    {
        return false;
    }

    public function checkPublic($iOwnerId, $iViewerId)
    {
        return true;
    }

    public function checkMembers($iOwnerId, $iViewerId)
    {
        return isMember();
    }

    public function checkFriends($iOwnerId, $iViewerId)
    {
        return BxDolConnection::getObjectInstance('sys_profiles_friends')->isConnected($iOwnerId, $iViewerId, true);
    }

    public function setTableFieldAuthor($sValue)
    {
        $this->_aObject['table_field_author'] = $sValue;

        $this->_oDb->init($this->_aObject);
    }

    protected function getObjectInfo($sAction, $iObjectId)
    {
        return $this->_oDb->getObjectInfo($sAction, $iObjectId);
    }

	protected function getPrivacyGroupsForContentPublic($iProfileIdOwner = 0, $aCustomGroups = array())
    {
    	$aGroups = array(BX_DOL_PG_ALL);
        if(isLogged()) {
            $iProfileIdLogged = bx_get_logged_profile_id();
            if($iProfileIdLogged == $iProfileIdOwner)
                return true;

			$aGroups[] = BX_DOL_PG_MEMBERS;
            if($iProfileIdOwner && $this->checkFriends($iProfileIdOwner, $iProfileIdLogged))
                $aGroups[] = BX_DOL_PG_FRIENDS;
        }

        return array_merge($aGroups, $aCustomGroups);
    }

    protected function getCheckMethod($s)
    {
        if(substr($s, 0, 1) != '@')
            return false;

        return bx_gen_method_name(str_replace('@', 'check_', $s));
    }

    protected function convertActionToField($sAction)
    {
    	return 'allow_' . strtolower(str_replace(' ', '-', $sAction)) . '_to';
    }

    /**
     * Check whethere viewer is a member of dynamic group.
     *
     * @param  mixed   $mixedGroupId   dynamic group ID.
     * @param  integer $iObjectOwnerId object owner ID.
     * @param  integer $iViewerId      viewer ID.
     * @return boolean result of operation.
     */
    protected function isDynamicGroupMember($mixedGroupId, $iObjectOwnerId, $iViewerId, $iObjectId)
    {
        return false;
    }

    /**
     * get privacy groups for getGroupChooser
     */ 
    protected function getGroups() 
    {
        $aValues = array();
        $aGroups = $this->_oDb->getGroupsBy(array('type' => 'active'));
        foreach($aGroups as $aGroup) {
            if((int)$aGroup['active'] == 0 || in_array($aGroup['id'], $this->_aGroupsExclude))
               continue;

            $aValues[] = array('key' => $aGroup['id'], 'value' => _t($aGroup['title']));
        }
        return $aValues;
    }
}

/** @} */
