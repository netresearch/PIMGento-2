<?php

namespace Pimgento\Channel\Observer;

use Magento\Framework\Event\ObserverInterface;
use Pimgento\Import\Observer\AbstractAddImportObserver;

class AddPimgentoImportObserver extends AbstractAddImportObserver implements ObserverInterface
{
    /**
     * Get the import code
     *
     * @return string
     */
    protected function getImportCode()
    {
        return 'channel';
    }

    /**
     * Get the import name
     *
     * @return string
     */
    protected function getImportName()
    {
        return __('Channel');
    }

    /**
     * Get the default import classname
     *
     * @return string
     */
    protected function getImportDefaultClassname()
    {
        return '\Pimgento\Channel\Model\Factory\Import';
    }

    /**
     * Get the sort order
     *
     * @return int
     */
    protected function getImportSortOrder()
    {
        return 0;
    }

    /**
     * get the steps definition
     *
     * @return array
     */
    protected function getStepsDefinition()
    {
        $stepsBefore = array(
            array(
                'comment' => __('Create temporary table'),
                'method'  => 'createTable',
            ),
            array(
                'comment' => __('Fill temporary table'),
                'method'  => 'insertData',
            ),
            array(
                'comment' => __('Create Website, store and store view'),
                'method'  => 'createWebsite',
            ),
        );

        $stepsAfter = array(
            array(
                'comment' => __('Drop temporary table'),
                'method'  => 'dropTable',
            ),
            array(
                'comment' => __('Clean cache'),
                'method'  => 'cleanCache',
            )
        );

        return array_merge(
            $stepsBefore,
            $this->getAdditionnalSteps(),
            $stepsAfter
        );
    }
}
