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
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Disable extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Adwise_PublicDashboard::manage';

    public function __construct(
        Context $context,
        private readonly TokenManager $tokenManager,
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

        $this->tokenManager->disable();

        return $result->setData(['success' => true]);
    }
}
