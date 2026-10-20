<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\sensitive\SensitiveDataMasker;
use wise\operationlog\tests\TestCase;

/**
 * SensitiveDataMasker 单元测试
 *
 * 覆盖：mask（数组递归/通配符/嵌套）、maskContent（组合正则）、各单项脱敏策略、matchPattern。
 */
class SensitiveDataMaskerTest extends TestCase
{
    private SensitiveDataMasker $masker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masker = new SensitiveDataMasker();
    }

    // ---------------- mask（数组） ----------------

    public function testMaskReplace(): void
    {
        $result = $this->masker->mask(['password' => 'secret123'], ['password' => 'replace']);
        $this->assertSame('***', $result['password']);
    }

    public function testMaskPhone(): void
    {
        $result = $this->masker->mask(['phone' => '13812345678'], ['phone' => 'phone']);
        $this->assertSame('138****5678', $result['phone']);
    }

    public function testMaskPhoneShort(): void
    {
        // 长度 < 7 走短号分支
        $result = $this->masker->mask(['phone' => '12345'], ['phone' => 'phone']);
        $this->assertSame('123***', $result['phone']);
    }

    public function testMaskIdCard(): void
    {
        $result = $this->masker->mask(['id_card' => '320123199001011234'], ['id_card' => 'id_card']);
        $this->assertSame('320***********1234', $result['id_card']);
    }

    public function testMaskIdCardShort(): void
    {
        // 长度 < 8 走短号分支
        $result = $this->masker->mask(['id_card' => '1234567'], ['id_card' => 'id_card']);
        $this->assertSame('123***', $result['id_card']);
    }

    public function testMaskEmail(): void
    {
        $result = $this->masker->mask(['email' => 'zhangsan@example.com'], ['email' => 'email']);
        $this->assertSame('z***@example.com', $result['email']);
    }

    public function testMaskEmailInvalidFallbackToReplace(): void
    {
        $result = $this->masker->mask(['email' => 'notanemail'], ['email' => 'email']);
        $this->assertSame('***', $result['email']);
    }

    public function testMaskBankCard(): void
    {
        $result = $this->masker->mask(['bank_card' => '6222021234567890123'], ['bank_card' => 'bank_card']);
        $this->assertSame('6222 **** **** 0123', $result['bank_card']);
    }

    public function testMaskBankCardShort(): void
    {
        // 长度 < 8 走短号分支
        $result = $this->masker->mask(['bank_card' => '12345'], ['bank_card' => 'bank_card']);
        $this->assertSame('1234****', $result['bank_card']);
    }

    public function testMaskName(): void
    {
        $result = $this->masker->mask(['real_name' => '张三'], ['real_name' => 'name']);
        $this->assertSame('张*', $result['real_name']);
    }

    public function testMaskNameSingleCharUnchanged(): void
    {
        $result = $this->masker->mask(['real_name' => '王'], ['real_name' => 'name']);
        $this->assertSame('王', $result['real_name']);
    }

    public function testMaskUnknownStrategyReturnsOriginal(): void
    {
        $result = $this->masker->mask(['x' => 'val'], ['x' => 'no_such_strategy']);
        $this->assertSame('val', $result['x']);
    }

    public function testMaskEmptyStringSkipped(): void
    {
        $result = $this->masker->mask(['password' => ''], ['password' => 'replace']);
        $this->assertSame('', $result['password']);
    }

    public function testMaskNonStringValueUnchanged(): void
    {
        $result = $this->masker->mask(['count' => 5], ['count' => 'replace']);
        $this->assertSame(5, $result['count']);
    }

    public function testMaskNestedArrayRecursive(): void
    {
        $data = [
            'user' => [
                'password' => 'p',
                'profile'  => ['email' => 'a@b.com'],
            ],
        ];
        $patterns = ['password' => 'replace', 'email' => 'email'];
        $result = $this->masker->mask($data, $patterns);
        $this->assertSame('***', $result['user']['password']);
        $this->assertSame('a***@b.com', $result['user']['profile']['email']);
    }

    public function testMaskWildcardByFieldName(): void
    {
        $result = $this->masker->mask(['access_token' => 'tok'], ['*token*' => 'replace']);
        $this->assertSame('***', $result['access_token']);
    }

    public function testMaskWildcardByFullPath(): void
    {
        $data = ['a' => ['secret_key' => 'x']];
        $result = $this->masker->mask($data, ['a.*secret*' => 'replace']);
        $this->assertSame('***', $result['a']['secret_key']);
    }

    public function testMaskNoMatchLeavesOriginal(): void
    {
        $result = $this->masker->mask(['name' => 'alice'], ['password' => 'replace']);
        $this->assertSame('alice', $result['name']);
    }

    // ---------------- maskContent（组合正则） ----------------

    public function testMaskContentPhone(): void
    {
        $this->assertSame('手机138****5678', $this->masker->maskContent('手机13812345678'));
    }

    public function testMaskContentIdCard(): void
    {
        $this->assertSame('证320***********1234', $this->masker->maskContent('证320123199001011234'));
    }

    public function testMaskContentEmail(): void
    {
        $this->assertSame('联系z***@example.com', $this->masker->maskContent('联系zhangsan@example.com'));
    }

    public function testMaskContentIdCardNotTruncatedByPhone(): void
    {
        // 18位身份证应优先被手机号正则前匹配，不被截断
        $content = 'id=11010519900307651X';
        $out = $this->masker->maskContent($content);
        $this->assertStringContainsString('110', $out);
        $this->assertStringContainsString('651X', $out);
        $this->assertStringNotContainsString('11010519900307651X', $out);
    }

    public function testMaskContentEmpty(): void
    {
        $this->assertSame('', $this->masker->maskContent(''));
    }

    public function testMaskContentMixed(): void
    {
        $content = 'user 13812345678 mail a@b.com id 11010519900307651X';
        $out = $this->masker->maskContent($content);
        $this->assertStringContainsString('138****5678', $out);
        $this->assertStringContainsString('a***@b.com', $out);
        $this->assertStringNotContainsString('11010519900307651X', $out);
    }
}
