<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdkTest\Zed\Blackfire\Helper;

use Spryker\Shared\Application\ApplicationConstants;
use Spryker\Shared\Config\Config;
use SprykerSdkTest\Shared\Blackfire\Helper\AbstractBlackfireHelper;

class BlackfireHelper extends AbstractBlackfireHelper
{
    /**
     * @param string $url
     *
     * @return string
     */
    protected function buildUrl(string $url): string
    {
        return rtrim(Config::get(ApplicationConstants::BASE_URL_ZED), '/') . $url;
    }
}
