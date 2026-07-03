<?php
/**
 * Adwise - https://www.adwise.nl
 * Copyright © Adwise 2026-present. All rights reserved.
 * This module is distributed under the MIT license
 * See LICENSE.md
 */
declare(strict_types=1);

namespace Adwise\PublicDashboard\Controller\Adminhtml\Token;

use Adwise\PublicDashboard\Model\TokenManager;
use Hyva\AdminDashboardFramework\Model\View\ViewAccessControl;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Adwise_PublicDashboard::manage';

    public function __construct(
        Context $context,
        private readonly TokenManager $tokenManager,
        private readonly ViewAccessControl $viewAccessControl,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        if (!$this->_formKeyValidator->validate($this->getRequest())) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('Invalid form key. Please refresh the page and try again.'),
            ]);
        }

        if (!($viewId = (int)$this->getRequest()->getParam('view_id'))) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('Invalid dashboard view.'),
            ]);
        }

        $adminUser = $this->_auth->getUser();

        if (!$this->viewAccessControl->canViewById($viewId, (int)$adminUser->getId(), $adminUser->getRoles() ?: [])) {
            return $result->setHttpResponseCode(403)->setData([
                'success' => false,
                'error_code' => ViewAccessControl::ERROR_CODE_ACCESS_REVOKED,
                'view_id' => $viewId,
                'message' => __('You no longer have access to this view.'),
            ]);
        }

        $this->tokenManager->generate((int)$adminUser->getId(), $viewId);

        return $result->setData([
            'success' => true,
            'url' => $this->tokenManager->getPublicUrl(),
            'view_id' => $viewId,
        ]);
    }
}
