<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdkTest\Shared\Blackfire\Middleware;

use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;

class XDebugGuzzleMiddleware
{
    /**
     * @var string
     */
    protected $xDebugSessionName;

    /**
     * @param string $xDebugSessionName
     */
    public function __construct($xDebugSessionName)
    {
        $this->xDebugSessionName = $xDebugSessionName;
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    public function __invoke(RequestInterface $request)
    {
        $request = $request->withUri(
            Uri::withQueryValue($request->getUri(), 'XDEBUG_SESSION_START', $this->xDebugSessionName)
        );

        return $request;
    }

    /**
     * @param string $xdebugSessionName
     *
     * @return \SprykerSdkTest\Shared\Blackfire\Middleware\XDebugGuzzleMiddleware
     */
    public static function create($xdebugSessionName)
    {
        return Middleware::mapRequest(new static($xdebugSessionName));
    }
}
