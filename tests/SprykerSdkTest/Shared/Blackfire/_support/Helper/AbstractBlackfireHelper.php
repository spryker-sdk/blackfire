<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdkTest\Shared\Blackfire\Helper;

use Blackfire\Bridge\Guzzle\Middleware;
use Blackfire\Client;
use Blackfire\ClientConfiguration;
use Blackfire\Profile\Configuration;
use Codeception\Module;
use GuzzleHttp\Client as GuzzleHttpClient;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractBlackfireHelper extends Module
{
    /**
     * @var array
     */
    protected $config = [
        'assertRecommendations' => true,
    ];

    /**
     * @var \Blackfire\Client|null
     */
    protected $blackfire;

    /**
     * @var array
     */
    protected $assertions = [];

    /**
     * @see https://blackfire.io/docs/reference-guide/metrics
     *
     * @param string $assertion
     * @param string $assertionName
     *
     * @return void
     */
    public function blackfireAssert(string $assertion, string $assertionName): void
    {
        $this->assertions[$assertion] = $assertionName;
    }

    /**
     * @param string $url
     * @param array $context
     *
     * @return void
     */
    public function blackfireGet(string $url, array $context = []): void
    {
        $requestContext = [
            'blackfire' => $this->getConfiguration(),
        ];
        $requestContext = array_merge($requestContext, $context);

        $response = $this->getClient()->get($this->buildUrl($url), $requestContext);

        $this->assertSame(200, $response->getStatusCode(), sprintf('The requested URL "%s" returned response code "%s"', $url, $response->getStatusCode()));

        $this->validate($response);
    }

    /**
     * @param string $url
     * @param array $context
     *
     * @return void
     */
    public function blackfirePost(string $url, array $context = []): void
    {
        $requestContext = [
            'blackfire' => $this->getConfiguration(),
        ];
        $requestContext = array_merge($requestContext, $context);

        $response = $this->getClient()->post($this->buildUrl($url), $requestContext);

        $this->assertSame(200, $response->getStatusCode(), sprintf('The requested URL "%s" returned response code "%s"', $url, $response->getStatusCode()));

        $this->validate($response);
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $response
     *
     * @return void
     */
    protected function validate(ResponseInterface $response): void
    {
        $response = $this->getBlackfireClient()->getProfile($response->getHeader('X-Blackfire-Profile-Uuid')[0]);

        foreach ($response->getTests() as $test) {
            $this->assertTrue(
                $test->isSuccessful(),
                sprintf(
                    'Test "%s" has failures. @see %s Expectations: %s',
                    $test->getName(),
                    $response->getUrl(),
                    implode("\n", $test->getFailures())
                )
            );
        }

        if (!$this->config['assertRecommendations']) {
            return;
        }

        foreach ($response->getRecommendations() as $recommendation) {
            $this->assertTrue(
                $recommendation->isSuccessful(),
                sprintf(
                    'Recommendation "%s" failed. @see %s  Recommendations: %s',
                    $recommendation->getName(),
                    $response->getUrl(),
                    implode("\n", $recommendation->getFailures())
                )
            );
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    protected function getClient(): GuzzleHttpClient
    {
        $guzzle = new GuzzleHttpClient(['cookies' => true]);
        $guzzle->getConfig('handler')->push(Middleware::create($this->getBlackfireClient()), 'blackfire');

        return $guzzle;
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
     * @return \Blackfire\Profile\Configuration
     */
    protected function getConfiguration(): Configuration
    {
        $config = new Configuration();
        foreach ($this->assertions as $assertion => $assertionName) {
            $config->assert($assertion, $assertionName);
        }

        $this->assertions = [];

        return $config;
    }

    /**
     * @return \Blackfire\ClientConfiguration
     */
    protected function getBlackfireClientConfiguration(): ClientConfiguration
    {
        return new ClientConfiguration();
    }

    /**
     * @param string $url
     *
     * @return string
     */
    abstract protected function buildUrl(string $url): string;
}
