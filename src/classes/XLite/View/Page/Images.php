<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XLite\View\Page;

/**
 * Images page
 *
 * @ListChild (list="admin.center", zone="admin")
 */
class Images extends \XLite\View\AView
{
    /**
     * Return list of allowed targets
     *
     * @return array
     */
    public static function getAllowedTargets()
    {
        return array_merge(parent::getAllowedTargets(), ['images']);
    }

    /**
     * Returns CSS style files
     *
     * @return string
     */
    public function getCSSFiles()
    {
        $list = parent::getCSSFiles();
        $list[] = 'images_settings/style.css';

        return $list;
    }

    /**
     * Return widget default template
     *
     * @return string
     */
    protected function getDefaultTemplate()
    {
        return 'images_settings/body.twig';
    }
}