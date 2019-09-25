<?php

namespace Pimgento\Channel\Model\Factory;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\App\Cache\TypeListInterface;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Magento\Staging\Model\VersionManager;
use \Magento\Catalog\Model\CategoryManagement;
use \Magento\Catalog\Model\Category;
use \Magento\Catalog\Model\CategoryFactory;
use \Zend_Db_Expr as Expr;

class Import extends Factory
{
    const CONFIG_GENERAL_LOCALE_CODE               = 'general/locale/code';
    const CONFIG_GENERAL_SCOPE_CODE                = 'stores';
    const CONFIG_GENERAL_PIM_TEXT                  = 'Pim';
    const CONFIG_GENERAL_STORE_TEXT                = 'Store';

    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * @var TypeListInterface
     */
    protected $_cacheTypeList;

    /**
     * @var categoryManagement
     */
    protected $categoryManagement;

    /**
     * @var category
     */
    protected $category;

    /**
     * @var categoryFactory
     */
    protected $categoryFactory;

    /**
     * @param \Pimgento\Entities\Model\Entities $entities
     * @param \Pimgento\Import\Helper\Config $helperConfig
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param \Magento\Catalog\Model\CategoryManagement $categoryManagement
     * @param \Magento\Catalog\Model\Category $category
     * @param \Magento\Catalog\Model\CategoryFactory $categoryFactory
     * @param array $data
     */
    public function __construct(
        Entities $entities,
        helperConfig $helperConfig,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        ManagerInterface $eventManager,
        TypeListInterface $cacheTypeList,
        CategoryManagement $categoryManagement,
        Category $category,
        CategoryFactory $categoryFactory,
        array $data = []
    )
    {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);
        $this->_entities = $entities;
        $this->_cacheTypeList = $cacheTypeList;
        $this->categoryManagement = $categoryManagement;
        $this->category = $category;
        $this->categoryFactory = $categoryFactory;
    }

    /**
     * Create temporary table
     */
    public function createTable()
    {
        $file = $this->getFileFullPath();

        if (!is_file($file)) {
            $this->setContinue(false);
            $this->setStatus(false);
            $this->setMessage($this->getFileNotFoundErrorMessage());
        } else {
            $this->_entities->createTmpTableFromFile($file, $this->getCode(), array('code', 'locales'));
        }
    }

    /**
     * Insert data into temporary table
     */
    public function insertData()
    {
        $file = $this->getFileFullPath();

        $count = $this->_entities->insertDataFromFile($file, $this->getCode());

        $this->setMessage(
            __('%1 line(s) found', $count)
        );
    }

    /**
     * create Website
     */
    public function createWebsite()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());
        $import = $connection->select()->from($tmpTable);
        $query  = $connection->query($import);

        while (($row = $query->fetch())) {
            //create website
            $store_code = strtolower(self::CONFIG_GENERAL_PIM_TEXT.'_'.$row['code']);
            $store_website_values = array(
                'code' => $store_code,
                'name' => self::CONFIG_GENERAL_PIM_TEXT.' '.$row['code'],
                'sort_order' => 0,
                'default_group_id' => 0,
                'is_default' => 0,
            );
            $connection->insertOnDuplicate(
                  $resource->getTable('store_website'), $store_website_values, array()
            );
            $websiteLastInsertId = $connection->lastInsertId($resource->getTable('store_website'));
            if (empty($websiteLastInsertId) || $websiteLastInsertId == 0) {
                $websiteLastInsertId = $connection->fetchOne(
                    $connection->select()
                    ->from($resource->getTable('store_website'))
                    ->where('code = ?', $store_code)
                    ->limit(1)
                );
            }

            $code = $row['tree'];
            $category = $this->checkCategoryExists($code);
            if (!$category->getId()) {
                $rootCategoryId = $this->createCategory($code);
                $this->createEntities($code, $rootCategoryId);
            } else {
                $collection = $this->categoryFactory->create()->getCollection()->addAttributeToFilter('name',$code)->setPageSize(1);
                if ($collection->getSize()) {
                    $rootCategoryId = $collection->getFirstItem()->getId();
                }
            }

            //create store group
            $store_group_code = strtolower(self::CONFIG_GENERAL_PIM_TEXT.'_'.$row['code'].' '.self::CONFIG_GENERAL_STORE_TEXT);
            $store_group_values = array(
                'website_id' => $websiteLastInsertId,
                'code' => $store_group_code,
                'name' => self::CONFIG_GENERAL_PIM_TEXT.' '.$row['code'].' '.self::CONFIG_GENERAL_STORE_TEXT,
                'root_category_id' => $rootCategoryId,
                'default_store_id' => 0,
            );
            $connection->insertOnDuplicate(
                 $resource->getTable('store_group'), $store_group_values, array()
            );
            $groupLastInsertId = $connection->lastInsertId($resource->getTable('store_group'));
            if (empty($groupLastInsertId) || $groupLastInsertId == 0) {
                $groupLastInsertId = $connection->fetchOne(
                    $connection->select()
                    ->from($resource->getTable('store_group'))
                    ->where('code = ?', $store_group_code)
                    ->limit(1)
                );
            }

            if ($groupLastInsertId) { //update website with store group id
                $values = array(
                'default_group_id' => $groupLastInsertId
                );
                $where = array(
                'website_id = ?' => $websiteLastInsertId
                );
                $connection->update($resource->getTable('store_website'), $values, $where);
            }

            //create store
            $languages = explode(',', $row['locales']);
            if( !empty($languages)) {
                $pos=0;
                foreach ($languages as $key=>$lang) {
                    $store_values = array(
                        'code' => strtolower($row['code']).'_'.$lang,
                        'website_id' => $websiteLastInsertId,
                        'group_id' => $groupLastInsertId,
                        'name' => $row['label-' . $lang].' '.$lang,
                        'sort_order' => $pos++,
                        'is_active' => 1,
                    );
                    $connection->insertOnDuplicate(
                    $resource->getTable('store'), $store_values, array()
                    );
                    $storeLastInsertId = $connection->lastInsertId($resource->getTable('store'));

                    //Update store locales
                    $this->updateStoreLocales($storeLastInsertId, $lang);
                }

                $this->setMessage(
                    __('Set locales for each store views.')
                );
            }
        }
    }

    /**
     * Check category exists
     */
    public function checkCategoryExists($code)
    {
        return $this->category->getCollection()->addAttributeToFilter('name',$code)->getFirstItem();;
    }

    /**
     * Create entities
     */
    public function createEntities($code, $entityId)
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();

        $data = [
            'import' => 'category',
            'code' => $code,
            'entity_id' => $entityId,
            'created_at' => new Expr('now()')
        ];
        $check = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTable('pimgento_entities'))
                ->where('code = ?', $code)
                ->limit(1)
        );

        if ($check) {
            $where = array(
                'code = ?' => $code
            );
            $connection->update($resource->getTable('pimgento_entities'), $data, $where);
        } else {
            $connection->insertOnDuplicate(
                $resource->getTable('pimgento_entities'), $data, array()
            );
        }
    }

    /**
     * Create category
     */
    public function createCategory($code)
    {
        $parentId = \Magento\Catalog\Model\Category::TREE_ROOT_ID;
        $rootCat = $this->category->load($parentId); /// Add a new sub category under root category
        $category = $this->categoryFactory->create();
        $category->setName($code);
        $category->setIsActive(true);
        $category->setUrlKey($code);
        $category->setData('description', 'description');
        $category->setParentId($rootCat->getId());
        $category->setStoreId(0);
        $category->setPath($rootCat->getPath());
        $category->save();

        return $category->getId();
    }

    /**
     * Update store locales
     */
    public function updateStoreLocales($storeId, $locale)
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();

        if( !empty($storeId) && !empty($locale)) {
            $values = array(
                'scope' => self::CONFIG_GENERAL_SCOPE_CODE,
                'scope_id' => $storeId,
                'path' => self::CONFIG_GENERAL_LOCALE_CODE,
                'value' => $locale
            );

            $connection->insertOnDuplicate(
                $resource->getTable('core_config_data'), $values, array()
            );

            $this->setMessage(
                __('Update locales for all store views')
            );
        }
    }

    /**
     * Drop temporary table
     */
    public function dropTable()
    {
        $this->_entities->dropTable($this->getCode());
    }

    /**
     * Clean cache
     */
    public function cleanCache()
    {
        $types = array(
            \Magento\Framework\App\Cache\Type\Block::TYPE_IDENTIFIER,
            \Magento\PageCache\Model\Cache\Type::TYPE_IDENTIFIER
        );

        foreach ($types as $type) {
            $this->_cacheTypeList->cleanType($type);
        }

        $this->setMessage(
            __('Cache cleaned for: %1', join(', ', $types))
        );
    }
}