<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\Blackfire\Communication\Plugin\Application;

use Blackfire\Bridge\Guzzle\Middleware;
use Blackfire\Client;
use Blackfire\ClientConfiguration;
use Spryker\Service\Container\ContainerInterface;
use Spryker\Shared\ApplicationExtension\Dependency\Plugin\ApplicationPluginInterface;
use Spryker\Shared\ZedRequest\Client\HandlerStack\HandlerStackContainer;
use Spryker\Shared\ZedRequest\Client\Middleware\MiddlewareInterface;

/**
 * TODO This is not the right place to do it!
 */
class BlackfireMiddlewareApplicationPlugin implements ApplicationPluginInterface
{
    /**
     * @var \Blackfire\Client
     */
    protected $blackfire;

    /**
     * @api
     *
     * @param \Spryker\Service\Container\ContainerInterface $container
     *
     * @return \Spryker\Service\Container\ContainerInterface
     */
    public function provide(ContainerInterface $container): ContainerInterface
    {
        $middleware = Middleware::create($this->getBlackfireClient());

        $anonymousClass = new class ($middleware) implements MiddlewareInterface {

            /**
             * @var callable
             */
            protected $middleware;

            /**
             * @api
             *
             * @param callable $middleware
             */
            public function __construct(callable $middleware)
            {
                $this->middleware = $middleware;
            }

            /**
             * @api
             *
             * @return string
             */
            public function getName()
            {
                return 'blackfire';
            }

            /**
             * @api
             *
             * @return callable
             */
            public function getCallable()
            {
                return $this->middleware;
            }
        };

        $handlerStackContainer = new HandlerStackContainer();
        $handlerStackContainer->addMiddleware($anonymousClass);

        return $container;
    }

    /**
     * @return \Blackfire\Client
     */
    protected function getBlackfireClient(): Client
    {
        if ($this->blackfire === null) {
            $this->blackfire = new Client($this->getBlackfireClientConfiguration());
        }

        return $this->blackfire;
    }

    /**
     * @return \Blackfire\ClientConfiguration
     */
    protected function getBlackfireClientConfiguration(): ClientConfiguration
    {
        return new ClientConfiguration();
    }
}
