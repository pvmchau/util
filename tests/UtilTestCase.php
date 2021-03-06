<?php

namespace go1\util\tests;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\DriverManager;
use go1\clients\MqClient;
use go1\util\schema\InstallTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\Service;
use go1\util\UtilServiceProvider;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Pimple\Container;
use Psr\Log\LoggerInterface;

abstract class UtilTestCase extends TestCase
{
    use InstallTrait;
    use UserMockTrait;

    protected $db;
    protected $queue;
    protected $queueMessages;

    public function setUp()
    {
        $this->db = DriverManager::getConnection(['url' => 'sqlite://sqlite::memory:']);
        $this->installGo1Schema($this->db);

        $this->queue = $this->getMockBuilder(MqClient::class)->setMethods(['publish'])->disableOriginalConstructor()->getMock();
        $this
            ->queue
            ->method('publish')
            ->willReturnCallback(function ($body, $routingKey) {
                $this->queueMessages[$routingKey][] = $body;
            });
    }

    protected function getContainer()
    {
        $logger = $this
            ->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['error'])
            ->getMockForAbstractClass();

        return (new Container)
            ->register(new UtilServiceProvider, [
                    'logger'       => $logger,
                    'client'       => new Client,
                    'cache'        => new ArrayCache,
                    'queueOptions' => [
                        'host' => '172.31.11.129',
                        'port' => '5672',
                        'user' => 'go1',
                        'pass' => 'go1',
                    ],
                ] + Service::urls(['queue', 'user', 'mail', 'portal', 'rules', 'currency', 'lo', 'sms', 'graphin'], 'qa')
            );
    }
}
