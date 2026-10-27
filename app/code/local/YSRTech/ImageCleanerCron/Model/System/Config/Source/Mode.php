<?php
/**
 * @category  YSRTech
 * @package   YSRTech_ImageCleanerCron
 */
class YSRTech_ImageCleanerCron_Model_System_Config_Source_Mode
{
    const MOVE   = 'move';
    const REPORT = 'report';

    public function toOptionArray()
    {
        $helper = Mage::helper('ysrtech_imagecleanercron');
        return array(
            array('value' => self::MOVE, 'label' => $helper->__('Move unused images to the trash')),
            array('value' => self::REPORT, 'label' => $helper->__('Report only (log what would be moved)')),
        );
    }
}
