<?php

namespace go1\util\tests;

use go1\clients\MqClient;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use PDO;
use ReflectionClass;

class EdgeTest extends UtilTestCase
{
    /** @var MqClient */
    protected $mqClient;

    public function setUp()
    {
        parent::setUp();

        $this->mqClient = $this->getMockBuilder(MqClient::class)->setMethods(['publish'])->disableOriginalConstructor()->getMock();

        // User has 3 accounts
        EdgeHelper::link($this->db, $this->mqClient, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 2, $weight = 0);
        EdgeHelper::link($this->db, $this->mqClient, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 3, $weight = 1);
        EdgeHelper::link($this->db, $this->mqClient, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 4, $weight = 2);

        // Course has 3 modules
        EdgeHelper::link($this->db, $this->mqClient, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 2, $weight = 0);
        EdgeHelper::link($this->db, $this->mqClient, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 3, $weight = 1);
        EdgeHelper::link($this->db, $this->mqClient, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 4, $weight = 2);
    }

    public function testNoDuplication()
    {
        $rClass = new ReflectionClass(EdgeTypes::class);

        $values = [];
        foreach ($rClass->getConstants() as $key => $value) {
            if (is_scalar($value)) {
                $this->assertNotContains($value, $values, "Duplication: {$key}");
                $values[] = $value;
            }
        }
    }

    public function testHasLink()
    {
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 2));
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 3));
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 4));
        $this->assertEquals(false, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 5));
    }

    public function testUnlinkBadCall()
    {
        $this->expectException(\BadFunctionCallException::class);
        EdgeHelper::unlink($this->db, $this->mqClient, EdgeTypes::HAS_ACCOUNT);
    }

    public function testUnlinkBySource()
    {
        EdgeHelper::unlink($this->db, $this->mqClient, EdgeTypes::HAS_ACCOUNT, $userId = 1);

        // All accounts are removed
        $this->assertFalse(EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId, $accountId = 2));
        $this->assertFalse(EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId, $accountId = 3));
        $this->assertFalse(EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId, $accountId = 4));

        // Other relationships should not be removed by accident.
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 2));
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 3));
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 4));
    }

    public function unlinkByTarget()
    {
        EdgeHelper::unlink($this->db, $this->mqClient, EdgeTypes::HAS_ACCOUNT, null, $accountId = 2);

        // Only one account is removed.
        $this->assertEquals(false, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 2));
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 3));
        $this->assertEquals(true, EdgeHelper::hasLink($this->db, EdgeTypes::HAS_ACCOUNT, $userId = 1, $accountId = 4));

        // Other relationships should not be removed by accident.
        $this->assertFalse(EdgeHelper::hasLink($this->db, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 2));
        $this->assertFalse(EdgeHelper::hasLink($this->db, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 3));
        $this->assertFalse(EdgeHelper::hasLink($this->db, EdgeTypes::HAS_MODULE, $courseId = 1, $moduleId = 4));
    }

    public function testEdgesFromSource()
    {
        $edges = EdgeHelper::edgesFromSource($this->db, $userId = 1, [EdgeTypes::HAS_ACCOUNT]);

        $this->assertCount(3, $edges);
        array_map(
            function ($edge) use ($userId) {
                $this->assertEquals(EdgeTypes::HAS_ACCOUNT, $edge->type);
                $this->assertEquals($userId, $edge->source_id);
            },
            $edges
        );
    }

    public function testCustomSelect()
    {
        $targetIds = EdgeHelper
            ::select('target_id')
            ->get($this->db, [$userId = 1], [], [EdgeTypes::HAS_ACCOUNT], PDO::FETCH_COLUMN);

        $this->assertCount(3, $targetIds);
        $this->assertEquals($accountId = 2, $targetIds[0]);
        $this->assertEquals($accountId = 3, $targetIds[1]);
        $this->assertEquals($accountId = 4, $targetIds[2]);
    }

    public function testCustomSelectSingle()
    {
        $select = EdgeHelper::select('target_id');
        $source = [$userId = 1];
        $hasAcc = EdgeTypes::HAS_ACCOUNT;

        $this->assertEquals($accountId = 2, $select->getSingle($this->db, $source, [2], [$hasAcc], PDO::FETCH_COLUMN));
        $this->assertEquals($accountId = 3, $select->getSingle($this->db, $source, [3], [$hasAcc], PDO::FETCH_COLUMN));
        $this->assertEquals($accountId = 4, $select->getSingle($this->db, $source, [4], [$hasAcc], PDO::FETCH_COLUMN));
    }
}
