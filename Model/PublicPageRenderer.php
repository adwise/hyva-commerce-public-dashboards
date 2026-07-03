<?php
/**
 * Adwise - https://www.adwise.nl
 * Copyright © Adwise 2026-present. All rights reserved.
 * This module is distributed under the MIT license
 * See LICENSE.md
 */
declare(strict_types=1);

namespace Adwise\PublicDashboard\Model;

use Hyva\AdminDashboardFramework\Api\V1\View\ViewRepositoryInterface;
use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterface;
use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceRepositoryInterface;
use Hyva\AdminDashboardFramework\Api\V1\WidgetType\WidgetTypeInterface;
use Hyva\AdminDashboardFramework\Model\Config\Widget\Converter;
use Hyva\AdminDashboardFramework\Model\WidgetConfig;
use Hyva\AdminDashboardFramework\Model\WidgetType\WidgetTypeDispatcher;
use Hyva\AdminDashboardFramework\Util\Widget\Instance\Content as WidgetContent;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Result\LayoutFactory as ResultLayoutFactory;

/**
 * Renders the shared dashboard view as a standalone public HTML page. Widget content is rendered
 * server side under adminhtml area emulation because the regular dashboard hydrates widgets
 * through authenticated admin AJAX calls, which are unavailable to anonymous visitors.
 *
 * The Hyvä dashboard services are resolved lazily through the object manager inside the emulated
 * area: their DI preferences only exist in the adminhtml area configuration, so they cannot be
 * constructor-injected into a class that is built during a frontend request.
 */
class PublicPageRenderer
{
    public function __construct(
        private readonly AppState $appState,
        private readonly AreaList $areaList,
        private readonly DesignInterface $design,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    /**
     * @param array $shareData token data as stored by {@see TokenManager}
     * @throws NoSuchEntityException when the shared view no longer exists
     */
    public function render(array $shareData): string
    {
        $this->areaList->getArea(Area::AREA_ADMINHTML)->load(AreaInterface::PART_CONFIG);

        return $this->appState->emulateAreaCode(
            Area::AREA_ADMINHTML,
            fn () => $this->doRender($shareData)
        );
    }

    private function doRender(array $shareData): string
    {
        // resolve the active admin theme the same way MageOS_ThemeAdminhtmlSwitcher does; that
        // plugin only runs in adminhtml-scoped requests, and the shared design instance was built
        // with frontend DI arguments where the configured adminhtml theme is unknown
        $configuredTheme = $this->scopeConfig->getValue('admin/system_admin_design/active_theme')
            ?: $this->objectManager->create(DesignInterface::class)
                ->getConfigurationDesignTheme(Area::AREA_ADMINHTML);

        $this->design->setArea(Area::AREA_ADMINHTML);
        $this->design->setDesignTheme($configuredTheme, Area::AREA_ADMINHTML);

        $viewId = $shareData[TokenManager::KEY_VIEW_ID] !== null
            ? (int)$shareData[TokenManager::KEY_VIEW_ID]
            : null;

        $widgetConfig = $this->objectManager->get(WidgetConfig::class);
        $widgetTypeDispatcher = $this->objectManager->get(WidgetTypeDispatcher::class);
        $widgetContent = $this->objectManager->get(WidgetContent::class);
        $assetRepository = $this->objectManager->get(AssetRepository::class);

        $instances = $this->loadInstances($viewId, $widgetConfig);

        // a result layout attaches a builder to the shared layout, like the dashboard's GetHtml controller
        $layout = $this->objectManager->get(ResultLayoutFactory::class)->create()->getLayout();
        $update = $layout->getUpdate();
        $update->addHandle('hyva_dashboard_widget');
        $update->addHandle('hyva_dashboard_widget_instance');

        foreach (array_unique(array_map(fn ($instance) => $instance->getWidgetTypeId(), $instances)) as $typeId) {
            $update->addHandle('hyva_dashboard_widget_instance_' . $typeId);
        }

        // added last so its referenceBlock removals win over the vendor handles
        $update->addHandle('adwise_public_dashboard');

        $widgets = [];

        foreach ($instances as $instance) {
            if (!($widgetType = $widgetConfig->getWidgetObjectById($instance->getWidgetTypeId()))) {
                continue;
            }

            $displayProperties = $instance->getPropertyValues(WidgetTypeInterface::KEY_DISPLAY_PROPERTIES);
            $minHeight = $widgetTypeDispatcher->getOption($widgetType, 'min_height') ?: 4;
            $minWidth = $widgetTypeDispatcher->getOption($widgetType, 'min_width') ?: 1;

            $widgets[] = [
                'instance_id' => $instance->getInstanceId(),
                'type_id' => $instance->getWidgetTypeId(),
                'title' => (string)$widgetTypeDispatcher->getTitle($widgetType, $instance),
                'x' => isset($displayProperties['x_pos']) ? (int)$displayProperties['x_pos'] : null,
                'y' => isset($displayProperties['y_pos']) ? (int)$displayProperties['y_pos'] : null,
                'width' => isset($displayProperties['current_width'])
                    ? (int)$displayProperties['current_width']
                    : (int)$minWidth,
                'height' => isset($displayProperties['current_height'])
                    ? (int)$displayProperties['current_height']
                    : (int)$minHeight,
                'content' => $widgetContent->getContentHtml($instance),
            ];
        }

        $widgets = $this->packWidgets($widgets);

        /** @var Template $pageBlock */
        $pageBlock = $layout->getBlock('adwise.public-dashboard.page');

        if (!$pageBlock) {
            throw new NoSuchEntityException(__('The public dashboard page could not be rendered.'));
        }

        $designParams = [
            'area' => Area::AREA_ADMINHTML,
            'theme' => $this->design->getDesignTheme()->getThemePath(),
        ];

        $pageBlock->setData('widgets', $widgets);
        $pageBlock->setData('styles_url', $assetRepository->getUrlWithParams('css/styles.css', $designParams));
        $pageBlock->setData('gridstack_css_url', $assetRepository->getUrlWithParams(
            'Hyva_AdminDashboardFramework::css/gridstack.min.css',
            $designParams
        ));
        $pageBlock->setData('apexcharts_url', $assetRepository->getUrlWithParams(
            'Hyva_AdminDashboardFramework::js/lib/apexcharts-4.7.0.js',
            $designParams
        ));
        // the admin dashboard runs Alpine v3; load it directly instead of through Hyvä's
        // theme-config dependent alpinejs.phtml, which resolves to v2 outside the admin
        $pageBlock->setData('alpine_url', $assetRepository->getUrlWithParams(
            'Hyva_Theme::js/alpine3.min.js',
            $designParams
        ));

        return $pageBlock->toHtml();
    }

    /**
     * Assign grid coordinates to widgets that have none stored. The admin dashboard lets gridstack
     * pack those client side; on the static public page they are packed here instead, first-fit
     * on the same 4-column grid, in dashboard order.
     */
    private function packWidgets(array $widgets): array
    {
        $columns = 4;
        $occupied = [];

        $isFree = function (int $x, int $y, int $width, int $height) use (&$occupied): bool {
            for ($row = $y; $row < $y + $height; $row++) {
                for ($col = $x; $col < $x + $width; $col++) {
                    if (isset($occupied[$row . ':' . $col])) {
                        return false;
                    }
                }
            }

            return true;
        };

        $claim = function (int $x, int $y, int $width, int $height) use (&$occupied): void {
            for ($row = $y; $row < $y + $height; $row++) {
                for ($col = $x; $col < $x + $width; $col++) {
                    $occupied[$row . ':' . $col] = true;
                }
            }
        };

        foreach ($widgets as &$widget) {
            if ($widget['x'] !== null && $widget['y'] !== null) {
                $claim($widget['x'], $widget['y'], $widget['width'], $widget['height']);
            }
        }

        foreach ($widgets as &$widget) {
            if ($widget['x'] !== null && $widget['y'] !== null) {
                continue;
            }

            $width = min($widget['width'], $columns);

            for ($row = 0; $widget['x'] === null; $row++) {
                for ($col = 0; $col <= $columns - $width; $col++) {
                    if ($isFree($col, $row, $width, $widget['height'])) {
                        $claim($col, $row, $width, $widget['height']);
                        $widget['x'] = $col;
                        $widget['y'] = $row;
                        break;
                    }
                }
            }
        }

        return $widgets;
    }

    /**
     * Load the shared view's widget instances the same way the admin dashboard does: limited to
     * widget types available in the pool, ordered by the positions stored on the view. Widget-type
     * ACL checks are intentionally skipped — the admin explicitly published this view, and there is
     * no admin session on the public request to evaluate ACL against.
     *
     * @return WidgetInstanceInterface[]
     * @throws NoSuchEntityException when the shared view no longer exists
     */
    private function loadInstances(?int $viewId, WidgetConfig $widgetConfig): array
    {
        $availableTypeIds = array_keys($widgetConfig->get(Converter::CONFIG_KEY_POOL) ?? []);

        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilderFactory::class)->create();
        $searchCriteriaBuilder->addFilter(
            WidgetInstanceInterface::VIEW_ID,
            $viewId,
            $viewId !== null ? 'eq' : 'null'
        );
        $searchCriteriaBuilder->addFilter(WidgetInstanceInterface::WIDGET_TYPE_ID, $availableTypeIds, 'in');

        $instances = $this->objectManager->get(WidgetInstanceRepositoryInterface::class)
            ->getList($searchCriteriaBuilder->create())
            ->getItems();

        if ($viewId === null) {
            return $instances;
        }

        $positions = array_flip(
            $this->objectManager->get(ViewRepositoryInterface::class)->getById($viewId)->getPositionsAsArray()
        );

        usort($instances, function ($a, $b) use ($positions) {
            $posA = $positions[$a->getInstanceId()] ?? PHP_INT_MAX;
            $posB = $positions[$b->getInstanceId()] ?? PHP_INT_MAX;

            return $posA <=> $posB;
        });

        return $instances;
    }
}
