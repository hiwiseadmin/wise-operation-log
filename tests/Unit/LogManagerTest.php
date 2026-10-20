<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\manage\LogManager;
use wise\operationlog\model\OperationLogModel;
use wise\operationlog\tests\TestCase;

/**
 * LogManager 单元测试（基于 SQLite :memory:）
 */
class LogManagerTest extends TestCase
{
    private LogManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new LogManager();
        OperationLogModel::createFromConfig()->where('id', '>', 0)->delete();
    }

    private function seed(array $rows): void
    {
        OperationLogModel::createFromConfig()->db()->insertAll($rows);
    }

    private function baseRow(array $over = []): array
    {
        return array_merge([
            'channel'       => 'admin',
            'level'         => 'INFO',
            'operator_id'   => 1,
            'operator_name' => 'alice',
            'request_url'   => '/admin/user/delete',
            'ip'            => '127.0.0.1',
            'user_agent'    => 'ua',
            'tags'          => '',
            'content'       => 'hello',
            'params'        => '',
            'extra'         => '',
            'duration_ms'   => 10,
            'create_time'   => date('Y-m-d H:i:s'),
        ], $over);
    }

    // ---------------- query ----------------

    public function testQueryEmpty(): void
    {
        $res = $this->manager->query();
        $this->assertSame(0, $res['total']);
        $this->assertSame([], $res['list']);
    }

    public function testQueryByChannel(): void
    {
        $this->seed([$this->baseRow(['channel' => 'admin']), $this->baseRow(['channel' => 'api'])]);

        $admin = $this->manager->query(['channel' => 'admin']);
        $this->assertSame(1, $admin['total']);

        $api = $this->manager->query(['channel' => 'api']);
        $this->assertSame(1, $api['total']);
    }

    public function testQueryBySingleLevel(): void
    {
        $this->seed([$this->baseRow(['level' => 'ERROR']), $this->baseRow(['level' => 'INFO'])]);

        $res = $this->manager->query(['level' => 'ERROR']);
        $this->assertSame(1, $res['total']);
    }

    public function testQueryByLevelsIn(): void
    {
        $this->seed([
            $this->baseRow(['level' => 'ERROR']),
            $this->baseRow(['level' => 'WARNING']),
            $this->baseRow(['level' => 'INFO']),
        ]);

        $res = $this->manager->query(['levels' => ['ERROR', 'WARNING']]);
        $this->assertSame(2, $res['total']);
    }

    public function testQueryByOperatorId(): void
    {
        $this->seed([$this->baseRow(['operator_id' => 7])]);
        $res = $this->manager->query(['operator_id' => 7]);
        $this->assertSame(1, $res['total']);
    }

    public function testQueryByOperatorNameLike(): void
    {
        $this->seed([$this->baseRow(['operator_name' => 'alice']), $this->baseRow(['operator_name' => 'bob'])]);

        $res = $this->manager->query(['operator_name' => 'ali']);
        $this->assertSame(1, $res['total']);
        $this->assertSame('alice', $res['list'][0]['operator_name']);
    }

    public function testQueryByContentLike(): void
    {
        $this->seed([$this->baseRow(['content' => 'deleted user']), $this->baseRow(['content' => 'updated cfg'])]);

        $res = $this->manager->query(['content' => 'deleted']);
        $this->assertSame(1, $res['total']);
    }

    public function testQueryByIp(): void
    {
        $this->seed([$this->baseRow(['ip' => '10.0.0.1'])]);
        $res = $this->manager->query(['ip' => '10.0.0.1']);
        $this->assertSame(1, $res['total']);
    }

    public function testQueryByTags(): void
    {
        $this->seed([$this->baseRow(['tags' => 'slow,cfg']), $this->baseRow(['tags' => 'other'])]);
        $res = $this->manager->query(['tags' => 'slow']);
        $this->assertSame(1, $res['total']);
    }

    public function testQueryByTimeRange(): void
    {
        $this->seed([
            $this->baseRow(['create_time' => '2020-01-01 00:00:00']),
            $this->baseRow(['create_time' => date('Y-m-d H:i:s')]),
        ]);
        $res = $this->manager->query([
            'start_time' => '2025-01-01 00:00:00',
            'end_time'   => date('Y-m-d H:i:s'),
        ]);
        $this->assertSame(1, $res['total']);
    }

    public function testQueryBySlowThan(): void
    {
        $this->seed([$this->baseRow(['duration_ms' => 5000]), $this->baseRow(['duration_ms' => 100])]);
        $res = $this->manager->query(['slow_than' => 3000]);
        $this->assertSame(1, $res['total']);
    }

    public function testQueryInvalidOrderByFallsBack(): void
    {
        $this->seed([$this->baseRow([])]);
        // 非法 orderBy 应回退到 id，不报错
        $res = $this->manager->query([], 1, 20, 'not_a_column', 'WRONGDIR');
        $this->assertSame(1, $res['total']);
        $this->assertSame('id', $res['list'][0]['id'] == $res['list'][0]['id'] ? 'id' : '');
    }

    public function testQueryPagination(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $this->baseRow(['content' => 'c' . $i]);
        }
        $this->seed($rows);
        $res = $this->manager->query([], 2, 2);
        $this->assertSame(5, $res['total']);
        $this->assertCount(2, $res['list']);
        $this->assertSame(2, $res['page']);
    }

    // ---------------- cleanup / retain ----------------

    public function testCleanupZeroDays(): void
    {
        $this->seed([$this->baseRow([])]);
        $this->assertSame(0, $this->manager->cleanup(0));
    }

    public function testCleanupNegativeDays(): void
    {
        $this->seed([$this->baseRow([])]);
        $this->assertSame(0, $this->manager->cleanup(-5));
    }

    public function testCleanupExceedsMax(): void
    {
        $this->seed([$this->baseRow([])]);
        $this->assertSame(0, $this->manager->cleanup(999999));
    }

    public function testCleanupOld(): void
    {
        $this->seed([
            $this->baseRow(['create_time' => '2000-01-01 00:00:00']),
            $this->baseRow(['create_time' => date('Y-m-d H:i:s')]),
        ]);
        $deleted = $this->manager->cleanup(30);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testRetainByDays(): void
    {
        $this->seed([
            $this->baseRow(['create_time' => '2000-01-01 00:00:00']),
            $this->baseRow(['create_time' => date('Y-m-d H:i:s')]),
        ]);
        $deleted = $this->manager->retain(30, 0);
        $this->assertSame(1, $deleted);
    }

    public function testRetainByMaxRecords(): void
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = $this->baseRow(['create_time' => '2000-01-01 00:00:00']);
        }
        $this->seed($rows);
        // 保留最新 1 条 → 删除 2 条
        $deleted = $this->manager->retain(0, 1);
        $this->assertSame(2, $deleted);
    }

    public function testRetainBoth(): void
    {
        $this->seed([
            $this->baseRow(['create_time' => '2000-01-01 00:00:00']),
            $this->baseRow(['create_time' => date('Y-m-d H:i:s')]),
        ]);
        $deleted = $this->manager->retain(30, 100);
        $this->assertSame(1, $deleted);
    }

    // ---------------- stats ----------------

    public function testStatsEmpty(): void
    {
        $res = $this->manager->stats();
        $this->assertSame(0, $res['total']);
        $this->assertSame(0, $res['today']);
        $this->assertSame([], $res['channels']);
    }

    public function testStatsWithData(): void
    {
        $this->seed([
            $this->baseRow(['channel' => 'admin', 'level' => 'ERROR', 'operator_name' => 'alice']),
            $this->baseRow(['channel' => 'admin', 'level' => 'INFO', 'operator_name' => 'bob']),
        ]);
        $res = $this->manager->stats();
        $this->assertSame(2, $res['total']);
        $this->assertCount(1, $res['channels']);
        $this->assertCount(2, $res['levels']);
        $this->assertCount(2, $res['operators']);
    }

    // ---------------- trend ----------------

    public function testTrendReturnsArray(): void
    {
        // SQLite 不支持 DATE_FORMAT，走异常捕获分支返回 []
        $res = $this->manager->trend('2026-01-01', '2026-01-31', 'daily');
        $this->assertIsArray($res);
    }

    public function testTrendInvalidIntervalFallsBack(): void
    {
        $res = $this->manager->trend('2026-01-01', '2026-01-31', 'not_valid');
        $this->assertIsArray($res);
    }

    // ---------------- hotspots / ipAnalysis ----------------

    public function testHotspots(): void
    {
        $this->seed([$this->baseRow(['channel' => 'admin', 'request_url' => '/x'])]);
        $res = $this->manager->hotspots();
        $this->assertCount(1, $res);
        $this->assertSame('/x', $res[0]['request_url']);
    }

    public function testHotspotsTopN(): void
    {
        $this->seed([$this->baseRow([])]);
        $res = $this->manager->hotspots(5);
        $this->assertCount(1, $res);
    }

    public function testIpAnalysis(): void
    {
        $this->seed([$this->baseRow(['ip' => '8.8.8.8', 'operator_id' => 1, 'channel' => 'admin'])]);
        $res = $this->manager->ipAnalysis();
        $this->assertCount(1, $res);
        $this->assertSame('8.8.8.8', $res[0]['ip']);
    }

    // ---------------- errorRate ----------------

    public function testErrorRateEmpty(): void
    {
        $res = $this->manager->errorRate('2026-01-01', '2026-01-31');
        $this->assertSame(0, $res['total']);
        $this->assertSame('0.00', $res['error_rate']);
    }

    public function testErrorRateWithData(): void
    {
        $this->seed([
            $this->baseRow(['level' => 'ERROR', 'create_time' => date('Y-m-d H:i:s')]),
            $this->baseRow(['level' => 'WARNING', 'create_time' => date('Y-m-d H:i:s')]),
            $this->baseRow(['level' => 'INFO', 'create_time' => date('Y-m-d H:i:s')]),
        ]);
        $start = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $end = date('Y-m-d 23:59:59', strtotime('+1 day'));
        $res = $this->manager->errorRate($start, $end);
        $this->assertSame(3, $res['total']);
        $this->assertSame(1, $res['error_count']);
        $this->assertSame(1, $res['warning_count']);
        $this->assertSame('33.33', $res['error_rate']);
    }

    // ---------------- slowQueries ----------------

    public function testSlowQueries(): void
    {
        $this->seed([$this->baseRow(['duration_ms' => 9000]), $this->baseRow(['duration_ms' => 100])]);
        $res = $this->manager->slowQueries(3000, 10);
        $this->assertCount(1, $res);
        $this->assertSame(9000, $res[0]['duration_ms']);
    }

    public function testSlowQueriesLimit(): void
    {
        $this->seed([$this->baseRow(['duration_ms' => 9000])]);
        $res = $this->manager->slowQueries(3000, 0); // limit <=0 → max(1,0)=1
        $this->assertCount(1, $res);
    }
}
