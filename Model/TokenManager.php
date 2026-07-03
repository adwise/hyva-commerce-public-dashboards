<?php
/**
 * Adwise - https://www.adwise.nl
 * Copyright © Adwise 2026-present. All rights reserved.
 * This module is distributed under the MIT license
 * See LICENSE.md
 */
declare(strict_types=1);

namespace Adwise\PublicDashboard\Model;

use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stores the single public dashboard share token together with the admin user and dashboard view
 * it was generated for. Regenerating replaces the token, invalidating any previously shared URL.
 */
class TokenManager
{
    private const FLAG_CODE = 'adwise_public_dashboard';

    /** must match the frontName of the frontend route in etc/frontend/routes.xml */
    private const FRONT_NAME = 'public-dashboard';

    public const KEY_TOKEN = 'token';
    public const KEY_ADMIN_USER_ID = 'admin_user_id';
    public const KEY_VIEW_ID = 'view_id';
    public const KEY_CREATED_AT = 'created_at';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function generate(int $adminUserId, ?int $viewId): string
    {
        $token = bin2hex(random_bytes(32));

        $this->flagManager->saveFlag(self::FLAG_CODE, [
            self::KEY_TOKEN => $token,
            self::KEY_ADMIN_USER_ID => $adminUserId,
            self::KEY_VIEW_ID => $viewId,
            self::KEY_CREATED_AT => date('c'),
        ]);

        return $token;
    }

    public function disable(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_CODE);
    }

    public function get(): ?array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);

        return is_array($data) && !empty($data[self::KEY_TOKEN]) ? $data : null;
    }

    public function validate(?string $candidate): ?array
    {
        $data = $this->get();

        if (!$data || !$candidate || !hash_equals($data[self::KEY_TOKEN], $candidate)) {
            return null;
        }

        return $data;
    }

    public function getPublicUrl(): ?string
    {
        if (!($data = $this->get())) {
            return null;
        }

        $baseUrl = $this->storeManager->getDefaultStoreView()->getBaseUrl();

        return $baseUrl . self::FRONT_NAME . '?token=' . $data[self::KEY_TOKEN];
    }
}
