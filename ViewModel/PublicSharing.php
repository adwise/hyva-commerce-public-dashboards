<?php
/**
 * Adwise - https://www.adwise.nl
 * Copyright © Adwise 2026-present. All rights reserved.
 * This module is distributed under the MIT license
 * See LICENSE.md
 */
declare(strict_types=1);

namespace Adwise\PublicDashboard\ViewModel;

use Adwise\PublicDashboard\Model\TokenManager;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class PublicSharing implements ArgumentInterface
{
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly TokenManager $tokenManager,
        private readonly BackendUrl $backendUrl,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->authorization->isAllowed('Adwise_PublicDashboard::manage');
    }

    public function getPublicUrl(): string
    {
        return $this->tokenManager->getPublicUrl() ?? '';
    }

    /**
     * The dashboard view the current public link is bound to, if a link exists.
     */
    public function getPublicViewId(): ?int
    {
        $data = $this->tokenManager->get();

        return isset($data[TokenManager::KEY_VIEW_ID]) ? (int)$data[TokenManager::KEY_VIEW_ID] : null;
    }

    public function getGenerateUrl(): string
    {
        return $this->backendUrl->getUrl('adwise_publicdashboard/token/generate');
    }

    public function getDisableUrl(): string
    {
        return $this->backendUrl->getUrl('adwise_publicdashboard/token/disable');
    }
}
