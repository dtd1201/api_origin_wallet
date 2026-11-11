<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\NiumEntitlementEvidenceScope;
use App\Services\Nium\NiumEntitlementEvidenceTransport;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use NiumFeeEvidence\FeeConfigurationProjection;
use NiumFeeEvidence\StagingFeeInspection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__.'/../../tools/nium-fee-evidence/FeeConfigurationProjection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/StagingFeeInspection.php';

/** Entirely synthetic settings and HTTP fakes; never reads operator environment. */
final class NiumStagingFeeInspectionTest extends TestCase
{
    private const CLIENT = '11111111-1111-4111-8111-111111111111';

    private const KEY = 'synthetic-staging-fee-key-never-real';

    private \Closure $autoloadGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoloadGuard = static function (string $class): void {
            if (str_starts_with($class, 'App\\Models\\') || str_starts_with($class, 'App\\Providers\\')
                || str_starts_with($class, 'Illuminate\\Database\\')
                || in_array($class, ['App\\Services\\Nium\\NiumService', 'App\\Services\\Integrations\\ProviderHttpClient'], true)) {
                throw new LogicException('Offline fee inspection must not load application or persistence runtime.');
            }
        };
        spl_autoload_register($this->autoloadGuard, true, true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloadGuard);
        parent::tearDown();
    }

    private static function environment(): array
    {
        return ['APP_ENV' => 'staging', 'NIUM_BASE_URL' => 'https://gateway.nium.com',
            'NIUM_CLIENT_ID' => self::CLIENT, 'NIUM_API_KEY' => self::KEY, 'NIUM_REGULATORY_REGION' => 'HK',
            'NIUM_AUTH_MODE' => 'header', 'NIUM_AUTH_HEADER_NAME' => 'x-api-key'];
    }

    private static function approval(): array
    {
        return ['version' => 1, 'app_environment' => 'staging', 'provider_environment' => 'sandbox',
            'origin' => 'https://gateway.nium.com', 'client_sha256' => hash('sha256', self::CLIENT),
            'api_key_sha256' => hash('sha256', self::KEY), 'regulatory_region' => 'HK',
            'approval_reference_sha256' => str_repeat('a', 64)];
    }

    private static function payload(): array
    {
        return ['feePlans' => [['planName' => 'synthetic-private-plan', 'isDefault' => true, 'products' => [[
            'name' => 'payout', 'fees' => [['fee' => 'remit_bank_fee', 'rules' => [[
                'type' => 'nontier', 'condition' => ['operator' => 'and', 'operands' => [
                    ['sourceCurrency' => 'USD'], ['destinationCurrency' => 'HKD'], ['destinationCountry' => 'HK'],
                ]], 'price' => ['type' => 'flat', 'currency' => 'USD', 'value' => '20.0000'],
            ]]]],
        ]]]]];
    }

    private function fake(\Closure $callback): array
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake($callback);
        $transport = new NiumEntitlementEvidenceTransport;
        (new ReflectionProperty($transport, 'http'))->setValue($transport, $http);

        return [$http, $transport];
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'production app' => [['APP_ENV' => 'production'], []],
            'testing app is not staging' => [['APP_ENV' => 'testing'], []],
            'missing environment' => [['APP_ENV' => ''], []],
            'missing key' => [['NIUM_API_KEY' => ''], []],
            'different staging key' => [['NIUM_API_KEY' => 'another-synthetic-staging-key'], []],
            'key header injection' => [['NIUM_API_KEY' => "synthetic-staging\r\nheader"], []],
            'different client' => [['NIUM_CLIENT_ID' => '22222222-2222-4222-8222-222222222222'], []],
            'different origin' => [['NIUM_BASE_URL' => 'https://api.nium.com'], []],
            'insecure origin' => [['NIUM_BASE_URL' => 'http://gateway.nium.com'], ['origin' => 'http://gateway.nium.com']],
            'foreign origin' => [['NIUM_BASE_URL' => 'https://nium.com.attacker.test'], ['origin' => 'https://nium.com.attacker.test']],
            'origin path' => [['NIUM_BASE_URL' => 'https://gateway.nium.com/private'], ['origin' => 'https://gateway.nium.com/private']],
            'origin userinfo' => [['NIUM_BASE_URL' => 'https://user@gateway.nium.com'], ['origin' => 'https://user@gateway.nium.com']],
            'origin port' => [['NIUM_BASE_URL' => 'https://gateway.nium.com:443'], ['origin' => 'https://gateway.nium.com:443']],
            'wrong auth mode' => [['NIUM_AUTH_MODE' => 'bearer_token'], []],
            'wrong header' => [['NIUM_AUTH_HEADER_NAME' => 'Authorization'], []],
            'wrong region' => [['NIUM_REGULATORY_REGION' => 'SG'], []],
            'production approval' => [[], ['provider_environment' => 'production']],
            'wrong approved app' => [[], ['app_environment' => 'production']],
            'malformed identity hash' => [[], ['client_sha256' => 'invalid']],
            'wrong approval version type' => [[], ['version' => '1']],
            'unknown approval extension' => [[], ['fallback_origin' => 'https://api.nium.com']],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_configuration_is_rejected_before_network_or_container_changes(array $environment, array $approval): void
    {
        [$http, $transport] = $this->fake(static function (): never { throw new LogicException('No request authorized.'); });
        $previous = Container::getInstance();
        try {
            StagingFeeInspection::collect(array_replace(self::environment(), $environment), array_replace(self::approval(), $approval), $transport);
            $this->fail('Unsafe staging configuration was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('staging_configuration_rejected', $exception->getMessage());
        }
        $http->assertNothingSent();
        $this->assertSame($previous, Container::getInstance());
    }

    public function test_exactly_one_get_has_safe_options_and_preserves_existing_container_configuration(): void
    {
        $ids = [];
        [$http, $transport] = $this->fake(function (Request $request, array $options) use (&$ids) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://gateway.nium.com/api/v3/client/'.self::CLIENT.'/fees', $request->url());
            $this->assertSame([], $request->data());
            $this->assertTrue($request->hasHeader('x-api-key', self::KEY));
            $ids[] = $request->header('x-request-id')[0];
            $this->assertTrue(Str::isUuid(end($ids)));
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['debug']);
            $this->assertFalse($options['cookies']);
            $this->assertTrue($options['verify']);
            $this->assertSame(30, $options['timeout']);
            $this->assertSame(10, $options['connect_timeout']);

            return Factory::response(self::payload(), 200, ['x-client-id' => self::CLIENT, 'Authorization' => self::KEY]);
        });
        $previous = Container::getInstance();
        $before = $previous->bound('config') ? $previous->make('config')->all() : null;
        $one = StagingFeeInspection::collect(self::environment(), self::approval(), $transport);
        $http->assertSentCount(1);
        $two = StagingFeeInspection::collect(self::environment(), self::approval(), $transport);
        $http->assertSentCount(2); // One request per independently invoked collection, no retries.
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame($ids[0], $one['request_id']);
        $this->assertSame($ids[1], $two['request_id']);
        $this->assertSame('OBSERVED', $one['status']);
        $this->assertSame(200, $one['http_status']);
        $this->assertSame('20.0000', $one['fee_configuration'][0]['products'][0]['fees'][0]['rules'][0]['price']['value']);
        $this->assertSame(['status' => 'UNVERIFIED'], $one['fee_collection']);
        $this->assertSame('UNKNOWN', $one['financial_authority']['origin_wallet_debit']);
        $this->assertSame('DENIED', $one['financial_authority']['admission']);
        $this->assertSame('DENIED', $one['financial_authority']['dispatch']);
        foreach ([self::CLIENT, self::KEY, 'synthetic-private-plan', 'Authorization', 'x-client-id'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($one, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($previous, Container::getInstance());
        $this->assertSame($before, $previous->bound('config') ? $previous->make('config')->all() : null);
    }

    public static function failedReads(): array
    {
        return [[302], [403], [429], [500], ['timeout'], ['invalid_json'], ['wrong_shape']];
    }

    #[DataProvider('failedReads')]
    public function test_redirect_http_transport_and_parser_failures_never_retry_or_leak(int|string $failure): void
    {
        $attempts = 0;
        [$http, $transport] = $this->fake(static function () use ($failure, &$attempts) {
            $attempts++;
            if ($failure === 'timeout') {
                throw new ConnectionException(self::KEY.' https://gateway.nium.com/client/'.self::CLIENT);
            }

            return Factory::response(match ($failure) {
                'invalid_json' => self::KEY.' malformed json',
                'wrong_shape' => '{"feePlans":{}}',
                default => ['private' => self::KEY, 'client' => self::CLIENT],
            }, is_int($failure) ? $failure : 200, ['Location' => 'https://attacker.test/'.self::KEY]);
        });
        $previous = Container::getInstance();
        $result = StagingFeeInspection::collect(self::environment(), self::approval(), $transport);

        $this->assertSame(1, $attempts);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame(is_int($failure) ? $failure : ($failure === 'timeout' ? null : 200), $result['http_status']);
        if ($failure === 302) {
            $this->assertSame('unexpected_redirect', $result['failure']);
        }
        $this->assertSame([], $result['fee_configuration']);
        $this->assertStringNotContainsString(self::KEY, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::CLIENT, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('attacker', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($previous, Container::getInstance());
        // Connection failures may not be recorded as sent by Laravel's fake.
        if ($failure !== 'timeout') { $http->assertSentCount(1); }
    }

    public static function invalidJsonStructures(): array
    {
        $valid = json_encode(self::payload(), JSON_THROW_ON_ERROR);
        $badCondition = self::payload();
        $badCondition['feePlans'][0]['products'][0]['fees'][0]['rules'][0]['condition'] = [];

        return [
            ['{'], ['[]'], ['{}'], ['{"feePlans":{}}'], ['{"feePlans":null}'],
            ['{"feePlans":[],"feePlans":[]}'], ['{"feePlans":[],"\u0066eePlans":[]}'],
            [str_replace('"value":"20.0000"', '"value":20.0000', $valid)],
            [str_replace('"value":"20.0000"', '"value":"2e1"', $valid)],
            [str_replace('"value":"20.0000"', '"value":"-1"', $valid)],
            [str_replace('"value":"20.0000"', '"value":"20","value":"0"', $valid)],
            [str_replace('"isDefault":true', '"isDefault":"true"', $valid)],
            [str_replace('"products":[', '"products":{"bad":[', $valid)],
            [str_replace('"price":{"type"', '"price":[{"type"', $valid)],
            [json_encode($badCondition, JSON_THROW_ON_ERROR)],
            [str_repeat(' ', FeeConfigurationProjection::MAX_BYTES + 1)],
        ];
    }

    #[DataProvider('invalidJsonStructures')]
    public function test_malformed_or_ambiguous_json_and_non_string_money_are_rejected(string $raw): void
    {
        $failure = null;
        try {
            (new FeeConfigurationProjection)->project($raw);
        } catch (JsonException|RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertNotNull($failure, 'Malformed JSON or fee structure was accepted.');
    }

    public function test_unknown_metadata_conditions_and_collection_are_redacted_and_incomplete(): void
    {
        $payload = self::payload();
        $payload['secret-'.self::KEY] = self::CLIENT;
        $payload['feeCollectionMode'] = 'invoiced-private-metadata'; // Not a documented v3 field.
        $payload['feePlans'][0]['products'][0]['fees'][0]['rules'][0]['condition']['partnerBank'] = 'private-bank-reference';
        $payload['feePlans'][0]['products'][0]['fees'][0]['rules'][0]['price']['currency'] = null;
        [$http, $transport] = $this->fake(static fn () => Factory::response($payload));
        $result = StagingFeeInspection::collect(self::environment(), self::approval(), $transport);

        $this->assertSame('INCOMPLETE', $result['status']);
        $this->assertNotEmpty($result['unsupported_fields']);
        $this->assertSame(['status' => 'UNVERIFIED'], $result['fee_collection']);
        foreach ([self::CLIENT, self::KEY, 'private-bank-reference', 'invoiced-private-metadata', 'feeCollectionMode'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result, JSON_THROW_ON_ERROR));
        }
        $http->assertSentCount(1);
    }

    public function test_missing_prices_do_not_become_zero_and_decimal_strings_keep_all_digits(): void
    {
        $payload = self::payload();
        $rule = &$payload['feePlans'][0]['products'][0]['fees'][0]['rules'][0];
        $rule['price']['value'] = '12345678901234567890.123456789012345678';
        $projector = new FeeConfigurationProjection;
        $result = $projector->project(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame($rule['price']['value'], $result['plans'][0]['products'][0]['fees'][0]['rules'][0]['price']['value']);
        $rule['price']['value'] = '0.0000';
        $this->assertSame('0.0000', $projector->project(json_encode($payload, JSON_THROW_ON_ERROR))['plans'][0]['products'][0]['fees'][0]['rules'][0]['price']['value']);
        unset($rule['price']);
        $missing = $projector->project(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertContains('feePlans[0].products[0].fees[0].rules[0].price', $missing['missing_fields']);
        $this->assertArrayNotHasKey('price', $missing['plans'][0]['products'][0]['fees'][0]['rules'][0]);
    }

    public function test_tier_and_fx_rules_are_observed_in_order_without_evaluation_or_extra_markup(): void
    {
        $payload = self::payload();
        $payload['feePlans'][0]['products'][] = ['name' => 'fx', 'fees' => [['fee' => 'fx_markup_lock_15mins', 'rules' => [[
            'type' => 'nontier', 'price' => ['currency' => null, 'type' => 'percentage', 'percentageOf' => 'source_amount', 'value' => '0.1300'],
        ]]]]];
        $rules = &$payload['feePlans'][0]['products'][0]['fees'][0]['rules'];
        $rules[] = ['type' => 'tier', 'tier' => ['on' => ['aggregatedBy' => 'aggregated_volume', 'currency' => 'USD', 'parameter' => 'source_amount'],
            'ranges' => [['start' => '0', 'end' => '100', 'price' => ['type' => 'flat', 'currency' => 'USD', 'value' => '20']],
                ['start' => '101', 'end' => '1000', 'price' => ['type' => 'flat', 'currency' => 'USD', 'value' => '19']]]]];

        $result = (new FeeConfigurationProjection)->project(json_encode($payload, JSON_THROW_ON_ERROR));

        $observed = $result['plans'][0]['products'][0]['fees'][0]['rules'];
        $this->assertSame(['nontier', 'tier'], array_column($observed, 'type'));
        $this->assertSame(['20', '19'], array_column(array_column($observed[1]['tier']['ranges'], 'price'), 'value'));
        $this->assertSame('UNVERIFIED', $observed[1]['applicability']);
        $this->assertSame('0.1300', $result['plans'][0]['products'][1]['fees'][0]['rules'][0]['price']['value']);
        $this->assertNotEmpty($result['unsupported_fields']); // Null fee currency remains unresolved.
    }

    public function test_unknown_product_or_fee_does_not_export_its_unverified_values(): void
    {
        $payload = self::payload();
        $payload['feePlans'][0]['products'][0]['fees'][0]['fee'] = 'private-fee-name';
        $payload['feePlans'][0]['products'][0]['fees'][0]['rules'][0]['price']['value'] = '999999999999';
        $result = (new FeeConfigurationProjection)->project(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertNotEmpty($result['unsupported_fields']);
        $this->assertSame([], $result['plans'][0]['products'][0]['fees'][0]['rules']);
        $this->assertStringNotContainsString('999999999999', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-fee-name', json_encode($result, JSON_THROW_ON_ERROR));
        $payload['feePlans'][0]['products'][0]['name'] = 'private-product-name';
        $payload['feePlans'][0]['products'][0]['fees'][0]['fee'] = 'remit_bank_fee';
        $result = (new FeeConfigurationProjection)->project(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame([], $result['plans'][0]['products'][0]['fees'][0]['rules']);
        $this->assertStringNotContainsString('999999999999', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-product-name', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_final_redaction_also_catches_a_numeric_key_in_a_price_string(): void
    {
        $key = '1234567890123456';
        $this->assertSame(['value' => '[REDACTED].0000'], StagingFeeInspection::redact(['value' => $key.'.0000'], ['NIUM_API_KEY' => $key]));
        $safe = StagingFeeInspection::redact(['status' => 'OBSERVED', 'value' => $key, 'unsupported_fields' => []], ['NIUM_API_KEY' => $key]);
        $this->assertSame('INCOMPLETE', $safe['status']);
        $this->assertSame('secret_value_redacted', $safe['unsupported_fields'][0]['reason']);
    }

    public function test_approval_file_must_be_private_and_outside_the_checkout(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fee-approval-offline-');
        try {
            file_put_contents($path, json_encode(self::approval(), JSON_THROW_ON_ERROR));
            chmod($path, 0600);
            $this->assertSame(self::approval(), StagingFeeInspection::readApproval($path, dirname(__DIR__, 2)));
            chmod($path, 0644);
            $this->expectException(RuntimeException::class);
            StagingFeeInspection::readApproval($path, dirname(__DIR__, 2));
        } finally {
            unlink($path);
        }
    }

    public function test_existing_transport_default_decoder_is_unchanged(): void
    {
        [$http, $transport] = $this->fake(static fn () => Factory::response(self::payload()));
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(StagingFeeInspection::configuration(self::environment(), self::approval())));
        Container::setInstance($container);
        try {
            $scope = NiumEntitlementEvidenceScope::resolve('scope-00000001', 'sandbox', []);
            $result = $transport->read($scope, 'fee_plans');
            $this->assertNull($result['failure']);
            $this->assertSame(self::payload(), $result['body']);
            $http->assertSentCount(1);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_approval_file_inside_the_checkout_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        StagingFeeInspection::readApproval(__FILE__, dirname(__DIR__, 2));
    }
}
