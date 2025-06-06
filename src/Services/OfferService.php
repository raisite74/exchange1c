<?php
/**
 * This file is part of bigperson/exchange1c package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Bigperson\Exchange1C\Services;

use Bigperson\Exchange1C\Config;
use Bigperson\Exchange1C\Events\AfterOffersSync;
use Bigperson\Exchange1C\Events\AfterUpdateOffer;
use Bigperson\Exchange1C\Events\BeforeOffersSync;
use Bigperson\Exchange1C\Events\BeforeUpdateOffer;
use Bigperson\Exchange1C\Exceptions\Exchange1CException;
use Bigperson\Exchange1C\Interfaces\EventDispatcherInterface;
use Bigperson\Exchange1C\Interfaces\ModelBuilderInterface;
use Bigperson\Exchange1C\Interfaces\OfferInterface;
use Bigperson\Exchange1C\Interfaces\ProductInterface;
use Bigperson\LaravelExchange1C\Jobs\CatalogServiceJob;
use Symfony\Component\HttpFoundation\Request;
use Zenwalker\CommerceML\CommerceML;
use Zenwalker\CommerceML\Model\Offer;

/**
 * Class OfferService.
 */
class OfferService
{
    /**
     * @var array Массив идентификаторов торговых предложений которые были добавлены и обновлены
     */
    private $_ids;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;
    /**
     * @var ModelBuilderInterface
     */
    private $modelBuilder;

    /**
     * CategoryService constructor.
     *
     * @param Request                  $request
     * @param Config                   $config
     * @param EventDispatcherInterface $dispatcher
     * @param ModelBuilderInterface    $modelBuilder
     */
    public function __construct(Request $request, Config $config, EventDispatcherInterface $dispatcher, ModelBuilderInterface $modelBuilder)
    {
        $this->request = $request;
        $this->config = $config;
        $this->dispatcher = $dispatcher;
        $this->modelBuilder = $modelBuilder;
    }

    /**
     * @throws Exchange1CException
     */
    public function import()
    {
        $filename = basename($this->request->get('filename'));
        $this->_ids = [];
        $commerce = new CommerceML();
        $commerce->loadOffersXml($this->config->getFullPath($filename));
        if ($offerClass = $this->getOfferClass()) {
            $offerClass::createPriceTypes1c($commerce->offerPackage->getPriceTypes());
        }
        $this->beforeOfferSync();
        foreach ($commerce->offerPackage->getOffers() as $offer) {
            $productId = $offer->getClearId();
            if ($product = $this->findProductModelById($productId)) {
                $model = $product->getOffer1c($offer);
                if($model)
                {
                    $this->parseProductOffer($model, $offer);
                    $this->_ids[] = $model->getPrimaryKey();
                }
            } else {
                #throw new Exchange1CException("Продукт $productId не найден в базе");
                continue;
            }
            unset($model);
        }
        $this->afterOfferSync();
    }
    // public function import()
    // {
    //     $batchSize = 2000;
    //     $startPosition = (int) ($this->request->get('position') ?? 0);
    //     $currentPosition = 0;
    //     $processedCount = 0;

    //     $filename = basename($this->request->get('filename'));
    //     $this->_ids = [];

    //     $commerce = new CommerceML();
    //     $commerce->loadOffersXml($this->config->getFullPath($filename));

    //     if ($offerClass = $this->getOfferClass()) {
    //         $offerClass::createPriceTypes1c($commerce->offerPackage->getPriceTypes());
    //     }

    //     $this->beforeOfferSync();

    //     $allOffers = $commerce->offerPackage->getOffers();
    //     $totalCount = count($allOffers);

    //     foreach ($allOffers as $offer) {
    //         if ($currentPosition < $startPosition) {
    //             $currentPosition++;
    //             continue;
    //         }

    //         if ($processedCount >= $batchSize) {
    //             break;
    //         }

    //         $productId = $offer->getClearId();
    //         if ($product = $this->findProductModelById($productId)) {
    //             $model = $product->getOffer1c($offer);
    //             if ($model) {
    //                 $this->parseProductOffer($model, $offer);
    //                 $this->_ids[] = $model->getPrimaryKey();
    //             }
    //         } else {
    //             // Пропускаем, если продукт не найден
    //             $currentPosition++;
    //             continue;
    //         }

    //         unset($model);
    //         $currentPosition++;
    //         $processedCount++;
    //     }

    //     $this->afterOfferSync();

    //     // Запуск следующей партии, если остались предложения
    //     if (($startPosition + $processedCount) < $totalCount) {
    //         $nextPosition = $startPosition + $processedCount;

    //         $data = $this->request->all();
    //         $data['position'] = $nextPosition;

    //         CatalogServiceJob::dispatch(
    //             $data,
    //             $this->request->session()->all()
    //         )->onQueue(config('exchange1c.queue'));
    //     }
    // }

    /**
     * @return OfferInterface|null
     */
    private function getOfferClass(): ?OfferInterface
    {
        return $this->modelBuilder->getInterfaceClass($this->config, OfferInterface::class);
    }

    /**
     * @param string $id
     *
     * @return ProductInterface|null
     */
    protected function findProductModelById(string $id): ?ProductInterface
    {
        /**
         * @var ProductInterface
         */
        $class = $this->modelBuilder->getInterfaceClass($this->config, ProductInterface::class);

        return $class::findProductBy1c($id);
    }

    /**
     * @param OfferInterface $model
     * @param Offer          $offer
     */
    protected function parseProductOffer(OfferInterface $model, Offer $offer): void
    {
        $this->beforeUpdateOffer($model, $offer);
        $this->parseSpecifications($model, $offer);
        $this->parsePrice($model, $offer);
        $this->afterUpdateOffer($model, $offer);
    }

    /**
     * @param OfferInterface $model
     * @param Offer          $offer
     */
    protected function parseSpecifications(OfferInterface $model, Offer $offer)
    {
        foreach ($offer->getSpecifications() as $specification) {
            $model->setSpecification1c($specification);
        }
    }

    /**
     * @param OfferInterface $model
     * @param Offer          $offer
     */
    protected function parsePrice(OfferInterface $model, Offer $offer)
    {
        foreach ($offer->getPrices() as $price) {
            $model->setPrice1c($price);
        }
    }

    public function beforeOfferSync(): void
    {
        $event = new BeforeOffersSync();
        $this->dispatcher->dispatch($event);
    }

    public function afterOfferSync(): void
    {
        $event = new AfterOffersSync($this->_ids);
        $this->dispatcher->dispatch($event);
    }

    /**
     * @param OfferInterface $model
     * @param Offer          $offer
     */
    public function beforeUpdateOffer(OfferInterface $model, Offer $offer)
    {
        $event = new BeforeUpdateOffer($model, $offer);
        $this->dispatcher->dispatch($event);
    }

    /**
     * @param OfferInterface $model
     * @param Offer          $offer
     */
    public function afterUpdateOffer(OfferInterface $model, Offer $offer)
    {
        $event = new AfterUpdateOffer($model, $offer);
        $this->dispatcher->dispatch($event);
    }
}
