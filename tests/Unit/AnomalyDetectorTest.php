<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\detector\AnomalyDetector;
use wise\operationlog\model\OperationLogModel;
use wise\operationlog\tests\TestCase;

/**
 * AnomalyDetector 单元测试（基于 SQLite :memory:）
 */
class AnomalyDetectorTest extends TestCase
{
    private AnomalyDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AnomalyDetector();
        OperationLogModel::createFromConfig()->where('id', '>', 0)->delete();
    }

    private function baseRow(array $over = []): array
    {
        return array_merge([
            'channel'       => 'admin',
            'level'         => 'INFO',
            'operator_id'   => 1,
            'operator_name' => 'alice',
            'request_url'   => '/x',
            'ip'            => '1.1.1.1',
            'user_agent'    => 'ua',
            'tags'          => '',
            'content'       => 'hello',
            'params'        => '',
            'extra'         => '',
            'duration_ms'   => 10,
            'create_time'   => date('Y-m-d H:i:s'),
        ], $over);
    }

    private function seed(array $rows): void
    {
        OperationLogModel::createFromConfig()->db()->insertAll($rows);
    }


    public function testDetectFrequencyAnomalyReturnsArray(): void
    {
        $this->assertIsArray($this->detector->detectFrequencyAnomaly());
    }

    public function testDetectFrequencyAnomalyPositive(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $this->baseRow(['operator_id' => 1, 'ip' => '1.1.1.1', 'operator_name' => 'alice']);
        }
        $this->seed($rows);

        $anomalies = $this->detector->detectFrequencyAnomaly(5, 3.0, 'both');
        $this->assertNotEmpty($anomalies);
        $this->assertArrayHasKey('type', $anomalies[0]);
        $this->assertSame('frequency', $anomalies[0]['type']);
        $this->assertSame(1, $anomalies[0]['operator_id']);
    }

    public function testDetectFrequencyAnomalyGroupByOperator(): void
    {
        $this->seed([$this->baseRow(['operator_id' => 2, 'ip' => '9.9.9.9'])]);
        $anomalies = $this->detector->detectFrequencyAnomaly(5, 3.0, 'operator');
        $this->assertIsArray($anomalies);
    }

    public function testDetectFrequencyAnomalyGroupByIp(): void
    {
        $this->seed([$this->baseRow(['operator_id' => 3, 'ip' => '9.9.9.8'])]);
        $anomalies = $this->detector->detectFrequencyAnomaly(5, 3.0, 'ip');
        $this->assertIsArray($anomalies);
    }


    public function testDetectLevelAnomalyReturnsArray(): void
    {
        $this->assertIsArray($this->detector->detectLevelAnomaly());
    }

    public function testDetectLevelAnomalyPositive(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = $this->baseRow(['level' => 'ERROR', 'operator_id' => 5, 'ip' => '5.5.5.5', 'operator_name' => 'eve']);
        }
        $this->seed($rows);

        $anomalies = $this->detector->detectLevelAnomaly(10, 10);
        $this->assertNotEmpty($anomalies);
        $this->assertSame('level_spike', $anomalies[0]['type']);
        $this->assertGreaterThanOrEqual(10, $anomalies[0]['count']);
    }

    public function testDetectLevelAnomalyBelowThreshold(): void
    {
        $this->seed([$this->baseRow(['level' => 'ERROR'])]);
        $anomalies = $this->detector->detectLevelAnomaly(10, 100);
        $this->assertEmpty($anomalies);
    }


    public function testDetectNewIpInvalidOperator(): void
    {
        $this->assertFalse($this->detector->detectNewIpActivity(0, '1.1.1.1'));
    }

    public function testDetectNewIpTrueForUnknown(): void
    {
        $this->assertTrue($this->detector->detectNewIpActivity(1, '2.2.2.2'));
    }

    public function testDetectNewIpFalseForKnown(): void
    {
        $this->seed([$this->baseRow(['operator_id' => 1, 'ip' => '1.1.1.1'])]);
        $this->assertFalse($this->detector->detectNewIpActivity(1, '1.1.1.1'));
    }


    public function testDetectOffHourReturnsArray(): void
    {
        $this->assertIsArray($this->detector->detectOffHourActivity());
    }

    public function testDetectOffHourPositive(): void
    {
        $night = date('Y-m-d 23:00:00');
        $this->seed([$this->baseRow(['level' => 'WARNING', 'create_time' => $night, 'content' => 'night op'])]);
        $rows = $this->detector->detectOffHourActivity();
        $this->assertNotEmpty($rows);
    }

    public function testDetectOffHourExcludesDaytimeActivity(): void
    {
        $this->seed([$this->baseRow([
            'level'       => 'WARNING',
            'create_time' => date('Y-m-d 12:00:00'),
            'content'     => 'daytime op',
        ])]);

        $this->assertEmpty($this->detector->detectOffHourActivity());
    }

    public function testDetectOffHourIncludesEarlyMorningActivity(): void
    {
        $this->seed([$this->baseRow([
            'level'       => 'ERROR',
            'create_time' => date('Y-m-d 03:00:00'),
            'content'     => 'early morning op',
        ])]);

        $this->assertNotEmpty($this->detector->detectOffHourActivity());
    }


    public function testRunAll(): void
    {
        $this->seed([$this->baseRow(['operator_id' => 1, 'ip' => '1.1.1.1'])]);
        $res = $this->detector->runAll();
        $this->assertArrayHasKey('frequency_anomalies', $res);
        $this->assertArrayHasKey('level_anomalies', $res);
        $this->assertArrayHasKey('off_hour_activities', $res);
        $this->assertArrayHasKey('new_ip_anomalies', $res);
    }

    public function testDetectNewIpBatchReturnsRecentUnknownCombos(): void
    {
        $this->seed([$this->baseRow(['operator_id' => 7, 'ip' => '7.7.7.7', 'operator_name' => 'mallory'])]);

        $rows = $this->detector->detectNewIpBatch(60, 30);
        $this->assertNotEmpty($rows);
        $this->assertSame('new_ip', $rows[0]['type']);
        $this->assertSame(7, $rows[0]['operator_id']);
        $this->assertSame('7.7.7.7', $rows[0]['ip']);
    }

    public function testDetectNewIpBatchExcludesKnownCombos(): void
    {
        $this->seed([
            $this->baseRow([
                'operator_id' => 8,
                'ip'          => '8.8.8.8',
                'create_time' => date('Y-m-d H:i:s', strtotime('-2 days')),
            ]),
            $this->baseRow(['operator_id' => 8, 'ip' => '8.8.8.8']),
        ]);

        $this->assertEmpty($this->detector->detectNewIpBatch(60, 30));
    }
}
