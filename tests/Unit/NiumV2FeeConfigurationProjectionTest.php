<?php

declare(strict_types=1);

namespace Tests\Unit;

use NiumFeeEvidence\V2FeeConfigurationProjection;
use NiumFeeEvidence\V2JsonNumber;
use NiumFeeEvidence\V2StrictJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

require_once __DIR__.'/../../tools/nium-fee-evidence/V2StrictJson.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/V2FeeConfigurationProjection.php';

/** Pure DTO/grammar tests. Literal numeric JSON, never float-built fixtures. */
final class NiumV2FeeConfigurationProjectionTest extends TestCase
{
    public static function payload(): string
    {
        return <<<'JSON'
[
  {"default":true,"segment":"private synthetic segment","status":"Active","fees":[
    {"authCurrency":"USD","feeCurrency":"USD","feeName":"FX_MARKUP","feeValue":0.123456789012345678901234567890,"fixed":false,"slabFrom":0.0000,"slabTo":999999999999999999999999.999999,"thresholdFeeValue":0.00000000000000000001,"status":"ACTIVE","transactionCurrency":"HKD"},
    {"authCurrency":"USD","feeCurrency":"USD","feeName":"REMIT_BANK_FEE_SWIFT_OUR","feeValue":20.0000,"fixed":true,"slabFrom":100.0000,"slabTo":1000.0000,"thresholdFeeValue":5.0000,"status":"Inactive","transactionCurrency":"HKD"},
    {"authCurrency":"HKD","feeCurrency":"HKD","feeName":"REMIT_BANK_FEE_SWIFT_BEN","feeValue":30,"fixed":true,"slabFrom":1000.0000,"slabTo":9000.0000,"thresholdFeeValue":10,"status":"Active","transactionCurrency":"USD"}
  ]},
  {"default":false,"segment":"another private segment","status":"INACTIVE","fees":[
    {"authCurrency":"SGD","feeCurrency":"SGD","feeName":"REMIT_BANK_FEE","feeValue":0,"fixed":true,"slabFrom":0,"slabTo":100,"thresholdFeeValue":0,"status":"Active","transactionCurrency":"SGD"}
  ]}
]
JSON;
    }

    public function test_all_templates_fees_and_original_conditions_survive_without_selection(): void
    {
        $output = (new V2FeeConfigurationProjection)->project(self::payload());
        $this->assertSame([], $output['missing_fields']);
        $this->assertSame([], $output['unsupported_fields']);
        $this->assertCount(2, $output['plans']);
        $this->assertCount(3, $output['plans'][0]['fees']);
        $this->assertCount(1, $output['plans'][1]['fees']);
        $fees = $output['plans'][0]['fees'];
        $this->assertSame('FX_MARKUP', $fees[0]['feeName']);
        $this->assertFalse($fees[0]['fixed']);
        $this->assertSame('REMIT_BANK_FEE_SWIFT_OUR', $fees[1]['feeName']);
        $this->assertSame('Inactive', $fees[1]['status']);
        $this->assertSame('100.0000', $fees[1]['slabFrom']);
        $this->assertSame('1000.0000', $fees[1]['slabTo']);
        $this->assertSame('5.0000', $fees[1]['thresholdFeeValue']);
        $this->assertSame('HKD', $fees[2]['authCurrency']);
        $this->assertSame('UNVERIFIED', $fees[2]['applicability']);
        $this->assertTrue($output['plans'][0]['default']);
        $this->assertFalse($output['plans'][1]['default']);
        $this->assertSame('0', $output['plans'][1]['fees'][0]['feeValue']);
        $this->assertStringNotContainsString('private', json_encode($output, JSON_THROW_ON_ERROR));
    }

    public function test_numbers_are_exact_tokens_and_quoted_numbers_remain_strings(): void
    {
        $fees = (new V2FeeConfigurationProjection)->project(self::payload())['plans'][0]['fees'];
        $this->assertSame('0.123456789012345678901234567890', $fees[0]['feeValue']);
        $this->assertSame('0.0000', $fees[0]['slabFrom']);
        $this->assertSame('999999999999999999999999.999999', $fees[0]['slabTo']);
        $this->assertSame('0.00000000000000000001', $fees[0]['thresholdFeeValue']);
        $this->assertSame('20.0000', $fees[1]['feeValue']);
        $values = (new V2StrictJson)->decode('[123456789012345678901234567890,"20.0000",1e400,-0.00]');
        $this->assertInstanceOf(V2JsonNumber::class, $values[0]);
        $this->assertSame('123456789012345678901234567890', $values[0]->token);
        $this->assertSame('20.0000', $values[1]);
        $this->assertSame('1e400', $values[2]->token);
        $this->assertSame('-0.00', $values[3]->token);
    }

    public function test_noncanonical_numeric_semantics_are_preserved_and_flagged(): void
    {
        $raw = str_replace('20.0000', '2e1', self::payload());
        $output = (new V2FeeConfigurationProjection)->project($raw);
        $this->assertSame('2e1', $output['plans'][0]['fees'][1]['feeValue']);
        $this->assertContains(['path' => 'response[0].fees[1].feeValue', 'reason' => 'numeric_semantics_unverified'], $output['unsupported_fields']);
    }

    public function test_unknown_values_are_never_normalized_and_extensions_are_sanitized(): void
    {
        $raw = str_replace(
            ['"USD"', '"HKD"', '"SGD"', '"ACTIVE"', '"FX_MARKUP"', '"default":true'],
            ['"ANY"', 'null', '"ZZZ"', '"Paused"', '"FUTURE_FEE"', '"private extension":{"sensitive":"omit me"},"default":true'],
            self::payload(),
        );
        $output = (new V2FeeConfigurationProjection)->project($raw);
        $fee = $output['plans'][0]['fees'][0];
        $this->assertSame('ANY', $fee['authCurrency']);
        $this->assertSame('ANY', $fee['feeCurrency']);
        $this->assertNull($fee['transactionCurrency']);
        $this->assertSame('Paused', $fee['status']);
        $this->assertSame('FUTURE_FEE', $fee['feeName']);
        $this->assertSame('ZZZ', $output['plans'][1]['fees'][0]['feeCurrency']);
        $this->assertNotEmpty($output['unsupported_fields']);
        $this->assertStringNotContainsString('private extension', json_encode($output, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('omit me', json_encode($output, JSON_THROW_ON_ERROR));
        $this->assertCount(3, $output['plans'][0]['fees']);
    }

    public function test_missing_fees_or_values_do_not_become_zero(): void
    {
        $projector = new V2FeeConfigurationProjection;
        foreach (['[]', '[{}]', '[{"fees":[]}]', '[{"fees":[{}]}]'] as $raw) {
            $output = $projector->project($raw);
            $this->assertNotEmpty($output['missing_fields']);
            $this->assertStringNotContainsString('"feeValue":"0"', json_encode($output, JSON_THROW_ON_ERROR));
        }
        $missing = $projector->project(str_replace('"feeValue":20.0000,', '', self::payload()));
        $this->assertArrayNotHasKey('feeValue', $missing['plans'][0]['fees'][1]);
        $this->assertContains('response[0].fees[1].feeValue', $missing['missing_fields']);
    }

    #[DataProvider('invalidResponses')]
    public function test_malformed_ambiguous_or_wrong_schema_responses_fail_closed(string $raw): void
    {
        $rejected = false;
        try {
            (new V2FeeConfigurationProjection)->project($raw);
        } catch (Throwable) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'The V2 projection must reject this response.');
    }

    public static function invalidResponses(): array
    {
        return [
            'V3 envelope' => ['{"feePlans":[]}'],
            'object' => ['{}'], 'null' => ['null'], 'scalar row' => ['[1]'],
            'fees object' => ['[{"fees":{}}]'], 'fees null' => ['[{"fees":null}]'],
            'list row' => ['[[]]'], 'list fee' => ['[{"fees":[[]]}]'],
            'boolean coercion' => ['[{"default":1}]'],
            'quoted amount' => [str_replace('20.0000', '"20.0000"', self::payload())],
            'null amount' => [str_replace('20.0000', 'null', self::payload())],
            'duplicate keys' => ['[{"fees":[],"fees":[]}]'],
            'escaped duplicate keys' => ['[{"fees":[],"f\\u0065es":[]}]'],
            'nested duplicate' => ['[{"extra":{"x":1,"x":2}}]'],
            'trailing comma' => ['[{},]'], 'trailing object comma' => ['[{"fees":[],}]'],
            'missing comma' => ['[{}{}]'], 'missing colon' => ['[{"fees"[]}]'],
            'garbage' => ['[oops]'], 'extra document' => ['[] []'],
            'trailing garbage' => ['[]x'], 'leading zero' => ['[{"n":01}]'],
            'bad fraction' => ['[{"n":1.}]'], 'NaN' => ['[{"n":NaN}]'],
            'Infinity' => ['[{"n":Infinity}]'], 'bad escape' => ['[{"segment":"\\q"}]'],
            'bad unicode' => ['[{"segment":"\\uD800"}]'],
            'control character' => ["[{\"segment\":\"bad\ntext\"}]"],
            'invalid UTF8' => ["[{\"segment\":\"\xff\"}]"],
            'oversize' => [str_repeat(' ', V2StrictJson::MAX_BYTES + 1)],
            'depth limit' => [str_repeat('[', 42).str_repeat(']', 42)],
            'list limit' => ['['.implode(',', array_fill(0, 501, '{}')).']'],
            'node limit' => ['[{"extra":['.implode(',', array_fill(0, 10001, 'null')).']}]'],
        ];
    }
}
