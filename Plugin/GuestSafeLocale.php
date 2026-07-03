<?php
/**
 * Adwise - https://www.adwise.nl
 * Copyright © Adwise 2026-present. All rights reserved.
 * This module is distributed under the MIT license
 * See LICENSE.md
 */
declare(strict_types=1);

namespace Adwise\PublicDashboard\Plugin;

use Hyva\AdminDashboardFramework\ViewModel\Locale;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\Locale\ResolverInterface;

/**
 * The dashboard's Locale view model reads the interface locale from the logged-in admin user.
 * On the public dashboard page there is no admin session, so fall back to the store locale.
 */
class GuestSafeLocale
{
    public function __construct(
        private readonly AdminSession $adminSession,
        private readonly ResolverInterface $localeResolver,
    ) {
    }

    public function aroundGetLocale(Locale $subject, callable $proceed): mixed
    {
        if (!$this->adminSession->getUser()) {
            return $this->localeResolver->getLocale();
        }

        return $proceed();
    }
}
