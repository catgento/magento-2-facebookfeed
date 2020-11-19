<?php

namespace Catgento\FacebookFeed\Console\Command;

use Magento\Framework\App\State;
use Magento\Store\Model\Store;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Helper\Image as ImageFactory;
use Magento\CatalogInventory\Model\Stock\StockItemRepository;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class SomeCommand
 */
class FacebookFeed extends Command
{
    /* @var ProductRepository */
    private $productRepository;
    /* var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;
    /* var DirectoryList */
    private $directoryList;
    /* var Filesystem */
    private $filesystem;
    /* @var ImageFactory */
    protected $imageHelperFactory;
    /* @var StockItemRepository */
    protected $stockItemRepository;
    /* @var LinkManagementInterface */
    protected $linkManagement;
    /* @var StoreManagerInterface */
    protected $storeManager;

    private $columns = array(
        'id',
        'title',
        'description',
        'image_link',
        'link',
        'availability',
        'inventory',
        'price',
        'sale_price',
        'brand',
        'google_product_category',
        'condition'
    );

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('catalog:facebookfeed');
        $this->setDescription('Generates a product feed for facebook');

        parent::configure();
    }

    /**
     * FacebookFeed constructor.
     * @param State $state
     * @param ProductRepository $productRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param DirectoryList $directoryList
     * @param Filesystem $filesystem
     * @param ImageFactory $imageHelperFactory
     * @param StockItemRepository $stockItemRepository
     * @param LinkManagementInterface $linkManagement
     */
    public function __construct(
        State $state,
        ProductRepository $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        DirectoryList $directoryList,
        Filesystem $filesystem,
        ImageFactory $imageHelperFactory,
        StockItemRepository $stockItemRepository,
        LinkManagementInterface $linkManagement,
        StoreManagerInterface $storeManager
    )
    {
        $this->state = $state;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->directoryList = $directoryList;
        $this->filesystem = $filesystem;
        $this->imageHelperFactory = $imageHelperFactory;
        $this->stockItemRepository = $stockItemRepository;
        $this->linkManagement = $linkManagement;
        $this->storeManager = $storeManager;

        parent::__construct();
    }


    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return null|int
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    )
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('status', '1', 'eq')
            ->addFilter('visibility', '4', 'eq')
            ->create();

        $products = $this->productRepository->getList($criteria)->getItems();

        $productRows['header'] = $this->generateRow($this->columns);

        foreach ($products as $key => $product) {
            $_product = $this->productRepository->getById($product->getId());
            $data = $this->generateProductData($_product);
            if (count($data) > 0) {
                $productRows[$key] = $this->generateRow($data);
            }
        }

        $feedContent = '';
        foreach ($productRows as $productRow) {
            $feedContent .= $productRow . "\n";
        }

        $media = $this->filesystem->getDirectoryWrite($this->directoryList::MEDIA);
        $media->writeFile("productfeed/facebook.csv", $feedContent);

        $output->writeln('<info>Facebook Feed generated in /pub/media/productfeed/facebook.csv</info>');
    }

    /**
     * @param $productId
     * @return mixed
     */
    public function getStockItem($productId)
    {
        return $this->stockItemRepository->get($productId);
    }

    /**
     * @param $rowData
     * @return string
     */
    public function generateRow($rowData)
    {
        $row = '';
        foreach ($rowData as $column) {
            $row .= '"' . $column . '",';
        }

        return substr($row, 0, -1);
    }

    /**
     * @param $product
     * @return array
     */
    public function generateProductData($product)
    {
        $data = array();
        $flag0price = false;
        if ($product) {
            $data['id'] = $product->getSku();
            $data['title'] = str_replace('"', '\'', $product->getName());
            $data['description'] = strip_tags(str_replace('"', '\'', $product->getShortDescription()));

            $currentStore = $this->storeManager->getStore();
            $mediaUrl = $currentStore->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
            $data['image_link'] = $mediaUrl . 'catalog/product' . $product->getImage();
            $data['link'] = $product->getProductUrl();

            // Stock
            $stockInformation = $this->getStockItem($product->getId());
            $data['availability'] = ($stockInformation->getIsInStock()) ? 'In Stock' : 'Out of Stock';
            $data['inventory'] = 0;
            if ($stockInformation->getIsInStock()) {
                $data['inventory'] = 10;
            }

            if ($product->getTypeId() == 'configurable') {
                $prices = $this->getLowestPrices($product);

                $regular_price = $prices['regular_price'];
                $finalPriceAmt = $prices['final_price'];
            } else {
                $regular_price = $product->getPriceInfo()->getPrice('regular_price')->getValue();
                $finalPriceAmt = $product->getPriceInfo()->getPrice('final_price')->getValue();
            }

            $data['price'] = $regular_price . ' EUR'; // hardcoded, to be moved to a store config
            if ($regular_price < 0.01) {
                $flag0price = true;
            }

            $data['sale_price'] = '';
            if ($finalPriceAmt < $regular_price) {
                $data['sale_price'] = $finalPriceAmt . ' EUR'; // hardcoded, to be moved to a store config
            }
            $data['sale_price'] = $finalPriceAmt . ' EUR'; // hardcoded, to be moved to a store config

            $data['brand'] = $this->getStoreName();
            $data['google_product_category'] = '529'; // hardcoded, to be moved to a store config
            $data['condition'] = 'new'; // hardcoded, to be moved to a store config
        }

        if ($flag0price) {
            $data = array();
        }

        return $data;
    }

    /**
     * @param $product
     * @return array
     */
    public function getLowestPrices($product)
    {
        $childProducts = $this->linkManagement->getChildren($product->getSku());

        foreach ($childProducts as $childProduct) {
            $regularPrices[$childProduct->getSku()] = $childProduct->getPriceInfo()->getPrice('regular_price')->getValue();
            $finalPrices[$childProduct->getSku()] = $childProduct->getPriceInfo()->getPrice('final_price')->getValue();
        }

        $index = array_search(min($finalPrices), $finalPrices);
        $regularPrice = $regularPrices[$index];
        $finalPrice = $finalPrices[$index];

        return array('regular_price' => $regularPrice, 'final_price' => $finalPrice);
    }

    /**
     * Get Store name
     *
     * @return string
     */
    public function getStoreName()
    {
        return $this->storeManager->getStore()->getName();
    }
}
