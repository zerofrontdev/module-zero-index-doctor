<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Controller\Adminhtml\Doctor;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * System > Tools > Index Doctor: the findings and the reindex times. Read-only: fixes run from the CLI
 * (bin/magento zero:index:doctor --repair), never in a web request.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_IndexDoctor::doctor';

    public function execute()
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Zero_IndexDoctor::doctor');
        $page->getConfig()->getTitle()->prepend(__('Index Doctor'));

        return $page;
    }
}
