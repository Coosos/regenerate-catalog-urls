<?php

declare(strict_types=1);

namespace Iazel\RegenProductUrl\Service;

use Exception;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\EntityManager\EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\CacheContextFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateProductUrl
{
    /**
     * @var OutputInterface|null
     */
    private $output;
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;
    /**
     * @var ProductUrlRewriteGenerator\Proxy
     */
    private $urlRewriteGenerator;
    /**
     * @var UrlPersistInterface\Proxy
     */
    private $urlPersist;
    /**
     * @var StoreManagerInterface\Proxy
     */
    private $storeManager;
    /**
     * Counter for amount of urls regenerated.
     * @var int
     */
    private $regeneratedCount = 0;

    /**
     * @var EventManager\Proxy
     */
    private $eventManager;

    /**
     * @var CacheContextFactory
     */
    private $cacheContextFactory;

    /**
     * @var int
     */
    private $limitProductInvalidateCache;

    public function __construct(
        CollectionFactory $collectionFactory,
        ProductUrlRewriteGenerator\Proxy $urlRewriteGenerator,
        UrlPersistInterface\Proxy $urlPersist,
        StoreManagerInterface\Proxy $storeManager,
        EventManager\Proxy $eventManager,
        CacheContextFactory $cacheContextFactory,
        int $limitProductInvalidateCache = 10000
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->urlRewriteGenerator = $urlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->storeManager = $storeManager;
        $this->eventManager = $eventManager;
        $this->cacheContextFactory = $cacheContextFactory;
        $this->limitProductInvalidateCache = $limitProductInvalidateCache;
    }

    /**
     * @param int[] $productIds
     * @param int $storeId
     * @return void
     * @throws NoSuchEntityException
     */
    public function execute(array $productIds, int $storeId)
    {
        $this->regeneratedCount = 0;

        $stores = $this->storeManager->getStores(false);
        foreach ($stores as $store) {
            $regeneratedForStore = 0;
            // If store has been given through option, skip other stores
            if ($storeId !== Store::DEFAULT_STORE_ID and (int) $store->getId() !== $storeId) {
                continue;
            }

            $this->collection = $this->collectionFactory->create();
            $this->collection
                ->setStoreId($store->getId())
                ->addStoreFilter($store->getId())
                ->addAttributeToSelect('name')
                ->addFieldToFilter('status', ['eq' => Status::STATUS_ENABLED])
                ->addFieldToFilter('visibility', ['gt' => Visibility::VISIBILITY_NOT_VISIBLE]);

            if (!empty($productIds)) {
                $this->collection->addIdFilter($productIds);
            }

            $this->collection->addAttributeToSelect(['url_path', 'url_key']);
            $list = $this->collection->load();
            $productIdsInvalidateCache = [];

            /** @var Product $product */
            foreach ($list as $product) {
                $this->log('Regenerating urls for ' . $product->getSku() . ' (' . $product->getId() . ') in store ' . $store->getName());
                $product->setStoreId($store->getId());

                $this->urlPersist->deleteByData([
                    UrlRewrite::ENTITY_ID => $product->getId(),
                    UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                    UrlRewrite::REDIRECT_TYPE => 0,
                    UrlRewrite::STORE_ID => $store->getId()
                ]);

                $newUrls = $this->urlRewriteGenerator->generate($product);
                try {
                    $this->urlPersist->replace($newUrls);
                    $regeneratedForStore += count($newUrls);
                } catch (Exception $e) {
                    $this->log(sprintf('<error>Duplicated url for store ID %d, product %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store->getId(), $product->getId(), $product->getSku(), $e->getMessage(), implode(PHP_EOL, array_keys($newUrls))));
                }

                $productIdsInvalidateCache[] = $product->getId();
                if (count($productIdsInvalidateCache) > $this->limitProductInvalidateCache) {
                    $this->invalidateCache($productIdsInvalidateCache);
                    $productIdsInvalidateCache = [];
                }
            }

            $this->log('Done regenerating. Regenerated ' . $regeneratedForStore . ' urls for store ' . $store->getName());
            $this->regeneratedCount += $regeneratedForStore;
            if (count($productIdsInvalidateCache) > 0) {
                $this->invalidateCache($productIdsInvalidateCache);
            }
        }
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function getRegeneratedCount(): int
    {
        return $this->regeneratedCount;
    }

    /**
     * Invalidate product cache
     *
     * @param array $productIds Id list
     *
     * @return bool
     */
    public function invalidateCache(array $productIds)
    {
        $cacheContext = $this->cacheContextFactory->create();
        $cacheContext->registerEntities(Product::CACHE_TAG, $productIds);

        try {
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $cacheContext]);

            return true;
        } catch (Exception $e) {
            $this->log(sprintf('<error>Invalidate cache error : %s</error>' . PHP_EOL, $e->getMessage()));
        }

        return false;
    }

    private function log(string $message)
    {
        if ($this->output !== null) {
            $this->output->writeln($message);
        }
    }
}
