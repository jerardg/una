<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) BoonEx Pty Limited - http://www.boonex.com/
 * CC-BY License - http://creativecommons.org/licenses/by/3.0/
 *
 * @defgroup    Timeline Timeline
 * @ingroup     DolphinModules
 *
 * @{
 */

bx_import('BxBaseModNotificationsInstaller');

class BxTimelineInstaller extends BxBaseModNotificationsInstaller
{
    function __construct($aConfig)
    {
        parent::__construct($aConfig);
    }
}

/** @} */
