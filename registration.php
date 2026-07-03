<?php
/**
 * Adwise - https://www.adwise.nl
 * Copyright © Adwise 2026-present. All rights reserved.
 * This module is distributed under the MIT license
 * See LICENSE.md
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Adwise_PublicDashboard',
    __DIR__
);
