<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdkTest\Shared\Blackfire\Helper;

use Blackfire\Bridge\Guzzle\Middleware;
use Blackfire\Bridge\PhpUnit\TestConstraint;
use Blackfire\Client;
use Blackfire\ClientConfiguration;
use Blackfire\Exception\ExceptionInterface;
use Blackfire\Probe;
use Blackfire\Profile;
use Blackfire\Profile\Configuration;
use Blackfire\Profile\Metric;
use Codeception\Module;
use Codeception\TestInterface;
use Exception;
use GuzzleHttp\Client as GuzzleHttpClient;
use PHPUnit\Framework\SkippedTestError;
use Psr\Http\Message\ResponseInterface;
use SprykerSdkTest\Shared\Blackfire\Helper\Exception\AssertionDimensionException;
use SprykerSdkTest\Shared\Blackfire\Helper\Exception\BranchNameException;
use SprykerSdkTest\Shared\Blackfire\Helper\Exception\ExternalIdException;
use SprykerSdkTest\Shared\Blackfire\Middleware\XDebugGuzzleMiddleware;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractBlackfireHelper extends Module
{
    /**
     * Specification:
     * - Identifier for the blackfire environment to be used.
     *
     * @see https://blackfire.io/docs/reference-guide/environments
     */
    protected const ENVIRONMENT_NAME = 'environment';

    /**
     * Specification:
     * - When set to true, a guzzle middleware will be added to use XDebug during the request.
     */
    protected const X_DEBUG_ENABLED = 'enableDebug';

    /**
     * Specification:
     * - The session name to be used when XDebug is enabled.
     *
     * @example XDEBUG_ECLIPSE
     */
    protected const X_DEBUG_SESSION_NAME = 'xDebugSessionName';

    /**
     * Specification:
     * - When set to true, guzzle will send debug information to STDOUT.
     */
    protected const GUZZLE_DEBUG_ENABLED = 'guzzleDebugEnabled';

    /**
     * Specification:
     * - Defines the number of samples taken fo a profile.
     */
    protected const SAMPLES = 'samples';

    /**
     * Specification:
     * - When set to false, the Blackfire middleware will be disabled and no profile will be analysed.
     * - Can be used to debug tests without the blackfire overhead.
     */
    protected const PROFILING_ENABLED = 'profilingEnabled';

    /**
     * Specification:
     * - The label for a metric is by default the $metricName, use this option to change it.
     */
    protected const METRIC_LABEL = 'label';

    /**
     * Specification:
     * - The $selector is the default callee, you can add more with this option. But `Argument capturing is only available in .blackfire.yml file.` for now.
     *
     * @see https://blackfire.io/docs/reference-guide/metrics#capturing-arguments
     */
    protected const METRIC_CALLEE = 'callee';

    /**
     * Specification:
     * - Use this context option to set the expected response code when using `blackfireGet()` or `blackfirePost()`.
     */
    protected const CONTEXT_RESPONSE_CODE = 'response-code';

    /**
     * Specification:
     * - List of all available dimensions for metrics.
     */
    protected const DIMENSIONS = [
        'count',
        'wall_time',
        'cpu_time',
        'memory',
        'peak_memory',
        'network_in',
        'network_out',
        'io',
    ];

    /**
     * @var array
     */
    protected $config = [
        self::SAMPLES => 1,
        self::ENVIRONMENT_NAME => null,
        self::X_DEBUG_ENABLED => false,
        self::X_DEBUG_SESSION_NAME => 'XDEBUG_ECLIPSE',
        self::GUZZLE_DEBUG_ENABLED => false,
        self::PROFILING_ENABLED => true,
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
     * @var array
     */
    protected $metrics = [];

    /**
     * @var string|null
     */
    protected $profileName;

    /**
     * Defines the number of calls for a profile to get more accurate results.
     *
     * @var int|null
     */
    protected $samples;

    /**
     * @var \Blackfire\Probe|null
     */
    protected $probe;

    /**
     * @var \Blackfire\Build\Build[]
     */
    protected $builds = [];

    /**
     * @var \Blackfire\Build\Scenario[]
     */
    protected $scenarios = [];

    /**
     * @var \Blackfire\Profile|null
     */
    protected $lastProfile;

    /**
     * @var \Blackfire\Report|null
     */
    protected $lastReport;

    /**
     * @param int $samples
     *
     * @return void
     */
    public function blackfireSetSamples(int $samples): void
    {
        $this->samples = $samples;
    }

    /**
     * The build is started before the test suite is running @see \SprykerSdkTest\Shared\Blackfire\Helper\AbstractBlackfireHelper::_beforeSuite().
     *
     * @param string $environmentName
     * @param array $options
     *
     * @return void
     */
    public function blackfireStartBuild(string $environmentName, array $options = []): void
    {
        $build = $this->getBlackfireClient()->startBuild($environmentName, $options);
        $this->builds[$environmentName] = $build;
    }

    /**
     * The build is closed after the test suite was running @see \SprykerSdkTest\Shared\Blackfire\Helper\AbstractBlackfireHelper::_afterSuite().
     *
     * @param string $environmentName
     *
     * @return void
     */
    public function blackfireEndBuild(string $environmentName): void
    {
        $this->getBlackfireClient()->closeBuild($this->builds[$environmentName]);
    }

    /**
     * The scenario is started before a test is running @see \SprykerSdkTest\Shared\Blackfire\Helper\AbstractBlackfireHelper::_before().
     *
     * @param string $environmentName
     * @param array $options
     *
     * @return void
     */
    public function blackfireStartScenario(string $environmentName, array $options = []): void
    {
        $scenario = $this->getBlackfireClient()->startScenario($this->builds[$environmentName], $options);
        $this->scenarios[$environmentName] = $scenario;
    }

    /**
     * The scenario is closed after a test was running @see \SprykerSdkTest\Shared\Blackfire\Helper\AbstractBlackfireHelper::_after().
     *
     * @param string $environmentName
     *
     * @return void
     */
    public function blackfireEndScenario(string $environmentName): void
    {
        $this->lastReport = $this->getBlackfireClient()->closeScenario($this->scenarios[$environmentName]);
    }

    /**
     * @throws \Exception
     *
     * @return \Blackfire\Probe
     */
    public function blackfireStartProbe(): Probe
    {
        if ($this->probe !== null) {
            throw new Exception('You can only have one probe at a time. Please end the previous one first before starting a new one.');
        }

        $this->probe = $this->getBlackfireClient()->createProbe($this->getConfiguration());

        return $this->probe;
    }

    /**
     * @return \Blackfire\Profile
     */
    public function blackfireEndProbe(): Profile
    {
        $profile = $this->getBlackfireClient()->endProbe($this->probe);
        $this->probe = null;

        return $profile;
    }

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
        $this->validateAssertion($assertion);
        $this->assertions[$assertion] = $assertionName;
    }

    /**
     * @param string $assertion
     *
     * @throws \SprykerSdkTest\Shared\Blackfire\Helper\Exception\AssertionDimensionException
     *
     * @return void
     */
    protected function validateAssertion(string $assertion): void
    {
        [$metric, $comparator, $assertionValue] = explode(' ', $assertion);
        $metricFragements = explode('.', $metric);
        $dimension = array_pop($metricFragements);

        if (!in_array($dimension, static::DIMENSIONS)) {
            throw new AssertionDimensionException(sprintf('The metric "%s" uses a wrong dimension or it is missing. Valid dimensions are: "%s"', $metric, implode(', ', static::DIMENSIONS)));
        }
    }

    /**
     * @see https://blackfire.io/docs/reference-guide/metrics
     *
     * @example 'cache.write_calls', (=Cache::write|[=Cache::write, =Cache::store])
     *
     * Assertions can use then 'metric.' + $metricName + '.' + dimension e.g 'metric.cache.write_calls.count'
     *
     * Dimensions available:
     * - count
     * - wall_time
     * - cpu_time
     * - memory
     * - peak_memory
     * - network_in
     * - network_out
     * - io
     *
     * @param string $metricName
     * @param string|array $selector
     * @param array $options
     *
     * @return void
     */
    public function blackfireAddMetric(string $metricName, $selector, array $options = []): void
    {
        $metric = new Metric($metricName, $selector);
        $metric = $this->addMetricLabel($metric, $options);
        $metric = $this->addMetricCallee($metric, $options);

        $this->metrics[$metricName] = $metric;
    }

    /**
     * @param \Blackfire\Profile\Metric $metric
     * @param array $options
     *
     * @return \Blackfire\Profile\Metric
     */
    protected function addMetricLabel(Metric $metric, array $options): Metric
    {
        if (isset($options[static::METRIC_LABEL])) {
            $metric->setLabel($options[static::METRIC_LABEL]);
        }

        return $metric;
    }

    /**
     * @param \Blackfire\Profile\Metric $metric
     * @param array $options
     *
     * @return \Blackfire\Profile\Metric
     */
    protected function addMetricCallee(Metric $metric, array $options): Metric
    {
        if (isset($options[static::METRIC_CALLEE])) {
            foreach ((array)$options[static::METRIC_CALLEE] as $callee) {
                $metric->addCallee($callee);
            }
        }

        return $metric;
    }

    /**
     * @param callable $callback
     *
     * @throws \PHPUnit\Framework\SkippedTestError
     *
     * @return mixed The result of the callback
     */
    public function blackfireCallback($callback)
    {
        $config = $this->getConfiguration();

        try {
            if ($this->config[static::PROFILING_ENABLED]) {
                $this->blackfireStartProbe();
            }

            $result = $callback();

            if ($this->config[static::PROFILING_ENABLED]) {
                $this->lastProfile = $this->blackfireEndProbe();

                if ($config->hasAssertions()) {
                    $this->assertThat($this->lastProfile, new TestConstraint());
                }
            }

            return $result;
        } catch (ExceptionInterface $e) {
            throw new SkippedTestError($e->getMessage());
        }
    }

    /**
     * @param string $url
     * @param array $context
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function blackfireGet(string $url, array $context = []): ResponseInterface
    {
        return $this->blackfireGuzzle('get', $url, $context);
    }

    /**
     * @param string $url
     * @param array $context
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function blackfirePost(string $url, array $context = []): ResponseInterface
    {
        return $this->blackfireGuzzle('post', $url, $context);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $context
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function blackfireGuzzle(string $method, string $url, array $context): ResponseInterface
    {
        $url = $this->buildUrl($url);

        $response = $this->getClient()->$method(
            $url,
            $this->buildRequestContext($context)
        );

        $this->validate($response, $url, $context);

        return $response;
    }

    /**
     * @param array $context
     *
     * @return array
     */
    protected function buildRequestContext(array $context): array
    {
        $requestContext = [
            'blackfire' => $this->getConfiguration(),
        ];
        $requestContext = array_merge($requestContext, $context);

        return $requestContext;
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $response
     * @param string $url
     * @param array $context
     *
     * @throws \PHPUnit\Framework\SkippedTestError
     *
     * @return void
     */
    protected function validate(ResponseInterface $response, string $url, array $context = []): void
    {
        try {
            $this->assertSame($context[static::CONTEXT_RESPONSE_CODE] ?? 200, $response->getStatusCode(), sprintf('The requested URL "%s" returned response code "%s"', $url, $response->getStatusCode()));
            if ($this->config[static::PROFILING_ENABLED]) {
                $profile = $this->getBlackfireClient()->getProfile($response->getHeader('X-Blackfire-Profile-Uuid')[0]);

                if ($this->getConfiguration()->hasAssertions()) {
                    $this->assertThat($profile, new TestConstraint());
                }
            }
        } catch (ExceptionInterface $e) {
            throw new SkippedTestError($e->getMessage());
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    protected function getClient(): GuzzleHttpClient
    {
        $guzzle = new GuzzleHttpClient(['cookies' => true, 'debug' => $this->config[static::GUZZLE_DEBUG_ENABLED]]);

        if ($this->config[static::PROFILING_ENABLED]) {
            $guzzle->getConfig('handler')->push(Middleware::create($this->getBlackfireClient()), 'blackfire');
        }

        if ($this->config[static::X_DEBUG_ENABLED]) {
            $guzzle->getConfig('handler')->push(XDebugGuzzleMiddleware::create($this->config[static::X_DEBUG_SESSION_NAME]), 'xdebug');
        }

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

        foreach ($this->metrics as $metric) {
            $config->defineMetric($metric);
        }

        if ($this->profileName) {
            $config->setTitle($this->profileName);
        }

        $config->setScenario($this->scenarios[$this->getEnvironmentName()]);

        $config->setSamples($this->samples ?? $this->config[static::SAMPLES]);

        $config = $this->mergeBlackfireYml($config);

        return $config;
    }

    /**
     * @param \Blackfire\Profile\Configuration $config
     *
     * @return \Blackfire\Profile\Configuration
     */
    protected function mergeBlackfireYml(Configuration $config): Configuration
    {
        $blackfireYmlConfig = $this->getBlackfireYmlConfig();
        $blackfireConfig = Yaml::parse($blackfireYmlConfig);

        $config = $this->addMetrics($config, $blackfireConfig);

        return $config;
    }

    /**
     * @param \Blackfire\Profile\Configuration $config
     * @param array $blackfireConfig
     *
     * @return \Blackfire\Profile\Configuration
     */
    protected function addMetrics(Configuration $config, array $blackfireConfig): Configuration
    {
        if (isset($blackfireConfig['metrics'])) {
            foreach ($blackfireConfig['metrics'] as $metricName => $metricDefinition) {
                $metric = new Metric($metricName, $this->getSelectors($metricDefinition['matching_calls']));
                $metric->setLabel($metricDefinition['label']);
                $config->defineMetric($metric);
            }
        }

        return $config;
    }

    /**
     * @param array $matchingCalls
     *
     * @return array
     */
    protected function getSelectors(array $matchingCalls): array
    {
        $selectors = [];
        foreach ($matchingCalls['php'] as $callees) {
            foreach ($callees['callee'] as $selector) {
                $selectors[] = $selector;
            }
        }

        return $selectors;
    }

    /**
     * @throws \Exception
     *
     * @return string
     */
    protected function getBlackfireYmlConfig(): string
    {
        $baseDir = $this->getBaseDir();
        $dir = realpath($baseDir);
        do {
            $prevDir = $dir;
            $rootFile = $dir . DIRECTORY_SEPARATOR . '.blackfire.yml';
            $dir = dirname($dir);
        } while (!(file_exists($rootFile) && is_file($rootFile)) && $prevDir !== $dir);

        if ($prevDir !== $dir) {
            return file_get_contents($rootFile);
        }

        throw new Exception('Could not find .blackfire.yml, please add one to the root of your project.');
    }

    /**
     * @return string
     */
    protected function getBaseDir(): string
    {
        if (PHP_SAPI === 'cli-server') {
            return $_SERVER['DOCUMENT_ROOT'];
        }

        return dirname($_SERVER['SCRIPT_FILENAME']);
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

    /**
     * @param array $settings
     *
     * @return void
     */
    public function _beforeSuite($settings = []): void
    {
        $this->blackfireStartBuild($this->getEnvironmentName(), [
            'title' => $this->getCurrentBranchName(),
            'external_id' => $this->getExternalId(),
            'external_parent_id' => $this->getExternalParentId(),
        ]);
    }

    /**
     * @return void
     */
    public function _afterSuite(): void
    {
        $this->blackfireEndBuild($this->getEnvironmentName());

        $this->builds = [];
        $this->scenarios = [];
    }

    /**
     * @return string
     */
    protected function getEnvironmentName(): string
    {
        if (getenv('BLACKFIRE_ENVIRONMENT_NAME')) {
            return getenv('BLACKFIRE_ENVIRONMENT_NAME');
        }

        if (isset($this->config[static::ENVIRONMENT_NAME])) {
            return $this->config[static::ENVIRONMENT_NAME];
        }

        return 'environment not available';
    }

    /**
     * @param \Codeception\TestInterface $test
     *
     * @return void
     */
    public function _before(TestInterface $test)
    {
        $this->profileName = sprintf('%s:%s', get_class($test), $test->getName());

        $this->blackfireStartScenario($this->getEnvironmentName(), ['title' => $this->profileName]);
    }

    /**
     * @param \Codeception\TestInterface $test
     *
     * @return void
     */
    public function _after(TestInterface $test)
    {
        $this->blackfireEndScenario($this->getEnvironmentName());

        $this->probe = null;
        $this->samples = 1;
        $this->assertions = [];
        $this->metrics = [];
        $this->profileName = null;
    }

    /**
     * @return string
     */
    protected function getExternalId(): string
    {
        if (getenv('TRAVIS_COMMIT')) {
            return getenv('TRAVIS_COMMIT');
        }

        return $this->getSha1ForBranch($this->getCurrentBranchName());
    }

    /**
     * Use origin/master SHA1 for comparison.
     *
     * @return string
     */
    protected function getExternalParentId(): string
    {
        if ($this->isTravisRun()) {
            return $this->getSha1ForMasterByLsRemote();
        }

        return $this->getSha1ForBranch('origin/master');
    }

    /**
     * @return bool
     */
    protected function isTravisRun(): bool
    {
        return (getenv('CI') === "true");
    }

    /**
     * @throws \SprykerSdkTest\Shared\Blackfire\Helper\Exception\ExternalIdException
     *
     * @return string
     */
    protected function getSha1ForMasterByLsRemote(): string
    {
        $process = Process::fromShellCommandline('git ls-remote $(git config --get remote.origin.url) | grep refs/heads/master | cut -c1-40', APPLICATION_ROOT_DIR);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ExternalIdException(sprintf('Wasn\'t able to get the SHA1 of "master". %s', $process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    /**
     * @param string $branchName
     *
     * @throws \SprykerSdkTest\Shared\Blackfire\Helper\Exception\ExternalIdException
     *
     * @return string
     */
    protected function getSha1ForBranch(string $branchName): string
    {
        $process = new Process(['git', 'rev-parse', $branchName], APPLICATION_ROOT_DIR);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ExternalIdException(sprintf('Wasn\'t able to get the SHA1 of "%s". %s', $branchName, $process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    /**
     * @throws \SprykerSdkTest\Shared\Blackfire\Helper\Exception\BranchNameException
     *
     * @return string
     */
    protected function getCurrentBranchName(): string
    {
        if (getenv('TRAVIS_BRANCH')) {
            return getenv('TRAVIS_BRANCH');
        }

        $process = Process::fromShellCommandline('git branch | grep \* | cut -d \' \' -f2', APPLICATION_ROOT_DIR);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new BranchNameException(sprintf('Wasn\'t able to get the current branch name. %s', $process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }
}
