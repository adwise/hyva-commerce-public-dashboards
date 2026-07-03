<?php
/**
 * Adwise - https://www.adwise.nl
 * Copyright © Adwise 2026-present. All rights reserved.
 * This module is distributed under the MIT license
 * See LICENSE.md
 */
declare(strict_types=1);

namespace Adwise\PublicDashboard\Controller\Index;

use Adwise\PublicDashboard\Model\PublicPageRenderer;
use Adwise\PublicDashboard\Model\TokenManager;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly TokenManager $tokenManager,
        private readonly PublicPageRenderer $publicPageRenderer,
    ) {
    }

    /**
     * @throws NotFoundException
     */
    public function execute(): Raw
    {
        $shareData = $this->tokenManager->validate($this->request->getParam('token'));

        if (!$shareData) {
            throw new NotFoundException(__('Page not found.'));
        }

        try {
            $html = $this->publicPageRenderer->render($shareData);
        } catch (NoSuchEntityException) {
            // the shared dashboard view was deleted after the link was generated
            throw new NotFoundException(__('Page not found.'));
        }

        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        // the emulated adminhtml layout marks the response publicly cacheable; caching this page
        // would keep serving it after the link is regenerated or disabled
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setContents($html);

        return $result;
    }
}
