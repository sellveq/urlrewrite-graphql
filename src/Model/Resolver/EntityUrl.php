<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_UrlrewriteGraphQl
 * @copyright   Copyright 2018 Adobe. All Rights Reserved.
 * @copyright   Copyright © 2019 Scandiweb, Ltd (http://scandiweb.com)
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\UrlrewriteGraphQl\Model\Resolver;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewriteGraphQl\Model\Resolver\UrlRewrite\CustomUrlLocatorInterface;
use Psr\Log\LoggerInterface;

class EntityUrl implements ResolverInterface
{
    private const string PRODUCT_TARGET_PATH = 'catalog/product/view/id/';

    // a product request path carries the category it was browsed from; the rewrite is keyed on the id alone
    private const int PRODUCT_TARGET_PATH_SEGMENTS = 5;

    /**
     * @param UrlFinderInterface $urlFinder
     * @param CustomUrlLocatorInterface $customUrlLocator
     * @param CollectionFactory $productCollectionFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param Uid $idEncoder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly UrlFinderInterface $urlFinder,
        private readonly CustomUrlLocatorInterface $customUrlLocator,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly Uid $idEncoder,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * {@inheritdoc}
     * @throws GraphQlInputException
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!isset($args['url']) || empty(trim($args['url']))) {
            throw new GraphQlInputException(__('"url" argument should be specified and not empty'));
        }

        $urlParts = $this->parseUrl($args['url']);

        if ($urlParts === null) {
            return null;
        }

        // the framework's ContextInterface hides getExtensionAttributes(); the graphql area always passes this one
        /** @var ContextInterface $context */
        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int)$store->getId();
        $url = $this->locateUrl($urlParts['path']);
        $redirectType = 0;
        $urlRewrite = $this->findUrlFromRequestPath($url, $storeId);

        if ($urlRewrite) {
            $redirectType = (int)$urlRewrite->getRedirectType();
        } else {
            $urlRewrite = $this->findUrlFromTargetPath($url, $storeId);
        }

        if (!$urlRewrite) {
            return null;
        }

        $finalUrlRewrite = $this->findFinalUrl($urlRewrite);

        if ($finalUrlRewrite === null) {
            return null;
        }

        $entity = $this->findEntity($finalUrlRewrite, $storeId);

        if ($entity === null) {
            return null;
        }

        $relativeUrl = $redirectType > 0
            ? $this->getRedirectPath($finalUrlRewrite)
            : $urlRewrite->getRequestPath();

        if (!empty($urlParts['query'])) {
            $relativeUrl .= '?' . $urlParts['query'];
        }

        $result = [
            'id' => $entity['id'],
            'entity_uid' => $this->idEncoder->encode((string)$entity['id']),
            'type' => $this->sanitizeType($entity['type']),
            // the storefront routes on the target path, where core answers the request path here
            'canonical_url' => $finalUrlRewrite->getTargetPath(),
            'relative_url' => $relativeUrl,
            'redirectCode' => $redirectType
        ];

        return match ($result['type']) {
            'PRODUCT' => $this->addProductData($result, $entity['id'], $store),
            'CATEGORY' => $this->addCategoryData($result, $entity['id'], $storeId),
            default => $result
        };
    }

    /**
     * split a url into the parts the lookup needs, or null when it carries no path
     * @param string $url
     * @return array|null
     */
    private function parseUrl(string $url): ?array
    {
        // parse_url answers false on a malformed url and omits path on one that is only a query or a fragment
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $urlParts = parse_url($url);

        if ($urlParts === false) {
            $urlParts = ['path' => $url];
        }

        if (!isset($urlParts['path'])) {
            return null;
        }

        if (str_starts_with($urlParts['path'], '/') && $urlParts['path'] !== '/') {
            $urlParts['path'] = ltrim($urlParts['path'], '/');
        }

        return $urlParts;
    }

    /**
     * resolve a path to the request path the rewrite table is keyed on
     * @param string $path
     * @return string
     */
    private function locateUrl(string $path): string
    {
        // a product target path names the category it was browsed from, which no rewrite row records
        if (str_contains($path, self::PRODUCT_TARGET_PATH)) {
            return implode('/', array_slice(explode('/', $path), 0, self::PRODUCT_TARGET_PATH_SEGMENTS));
        }

        return $this->customUrlLocator->locateUrl($path) ?: $path;
    }

    /**
     * follow target_path to the next request_path until a row has no successor, or null on a cycle
     * @param UrlRewrite $urlRewrite
     * @return UrlRewrite|null
     */
    private function findFinalUrl(UrlRewrite $urlRewrite): ?UrlRewrite
    {
        $seen = [(int)$urlRewrite->getUrlRewriteId() => true];

        while (true) {
            $nextUrlRewrite = $this->findUrlFromRequestPath(
                $urlRewrite->getTargetPath(),
                (int)$urlRewrite->getStoreId()
            );

            if (!$nextUrlRewrite) {
                return $urlRewrite;
            }

            $nextId = (int)$nextUrlRewrite->getUrlRewriteId();

            if (isset($seen[$nextId])) {
                // row ids, never paths: the path came from the caller and would forge log lines
                $this->logger->warning(sprintf(
                    '%s: url_rewrite redirect cycle, row %d repeats in chain %s',
                    self::class,
                    $nextId,
                    implode(',', array_keys($seen))
                ));

                return null;
            }

            $seen[$nextId] = true;
            $urlRewrite = $nextUrlRewrite;
        }
    }

    /**
     * read the entity off the final rewrite, re-resolving a row that names none
     * @param UrlRewrite $finalUrlRewrite
     * @param int $storeId
     * @return array|null
     */
    private function findEntity(UrlRewrite $finalUrlRewrite, int $storeId): ?array
    {
        $entityId = (int)$finalUrlRewrite->getEntityId();
        $entityType = $finalUrlRewrite->getEntityType();

        if (!$entityId) {
            $entityUrlRewrite = $this->findUrlFromTargetPath($finalUrlRewrite->getTargetPath(), $storeId);

            if (!$entityUrlRewrite) {
                // core dereferences this answer without a null check and fatals
                $this->logger->warning(sprintf(
                    '%s: url_rewrite row %d names no entity and its target answers no rewrite',
                    self::class,
                    (int)$finalUrlRewrite->getUrlRewriteId()
                ));

                return null;
            }

            $entityId = (int)$entityUrlRewrite->getEntityId();
            $entityType = $entityUrlRewrite->getEntityType();
        }

        if (!$entityId) {
            // core raises GraphQlNoSuchEntityException here; null is this resolver's not-found answer everywhere else
            $this->logger->warning(sprintf(
                '%s: url_rewrite row %d resolves to no entity',
                self::class,
                (int)$finalUrlRewrite->getUrlRewriteId()
            ));

            return null;
        }

        return ['id' => $entityId, 'type' => $entityType];
    }

    /**
     * @param array $result
     * @param int $entityId
     * @param StoreInterface $store
     * @return array|null
     */
    private function addProductData(array $result, int $entityId, StoreInterface $store): ?array
    {
        // one select carrying the status and website filters, where a repository load reads every attribute for one sku
        $product = $this->productCollectionFactory->create()
            ->setStoreId($store->getId())
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addWebsiteFilter($store->getWebsiteId())
            ->addIdFilter($entityId)
            ->getFirstItem();

        if (!$product->getId()) {
            return null;
        }

        $result['sku'] = $product->getSku();

        return $result;
    }

    /**
     * @param array $result
     * @param int $entityId
     * @param int $storeId
     * @return array|null
     */
    private function addCategoryData(array $result, int $entityId, int $storeId): ?array
    {
        try {
            // the repository answers the model, whose getDisplayMode() and getDefaultSortBy() CategoryInterface hides
            /** @var Category $category */
            $category = $this->categoryRepository->get($entityId, $storeId);
        } catch (NoSuchEntityException) {
            return null;
        }

        if (!$category->getIsActive()) {
            return null;
        }

        $result['display_mode'] = $category->getDisplayMode();
        $result['sort_by'] = $category->getDefaultSortBy();

        return $result;
    }

    /**
     * find a url from a request url on the current store
     * @param string $requestPath
     * @param int $storeId
     * @return UrlRewrite|null
     */
    private function findUrlFromRequestPath(string $requestPath, int $storeId): ?UrlRewrite
    {
        return $this->urlFinder->findOneByData(
            [
                'request_path' => $requestPath,
                'store_id' => $storeId
            ]
        );
    }

    /**
     * find a url from a target url on the current store
     * @param string $targetPath
     * @param int $storeId
     * @return UrlRewrite|null
     */
    private function findUrlFromTargetPath(string $targetPath, int $storeId): ?UrlRewrite
    {
        $urlRewrites = $this->urlFinder->findAllByData(
            [
                'target_path' => $targetPath,
                'store_id' => $storeId
            ]
        );

        // several rows can share a target path, and a lookup by target means the one naming an entity
        foreach ($urlRewrites as $urlRewrite) {
            if ((int)$urlRewrite->getEntityId() > 0) {
                return $urlRewrite;
            }
        }

        return $urlRewrites[0] ?? null;
    }

    /**
     * get path to redirect to
     * @param UrlRewrite $urlRewrite
     * @return string
     */
    private function getRedirectPath(UrlRewrite $urlRewrite): string
    {
        return $urlRewrite->getRedirectType() > 0
            ? $urlRewrite->getTargetPath()
            : $urlRewrite->getRequestPath();
    }

    /**
     * sanitize the type to fit schema specifications
     * @param string $type
     * @return string
     */
    private function sanitizeType(string $type): string
    {
        return strtoupper(str_replace('-', '_', $type));
    }
}
