<?php

declare(strict_types=1);

use App\Security\Ipc\BrokerKeySource;
use App\Security\Ipc\IpcKeyUnavailableException;
use App\Security\Secrets\ArraySecretStore;
use App\Security\Secrets\Redactor;
use App\Security\Secrets\SecretRef;
use App\Security\Secrets\SecretsServiceProvider;
use App\Security\Secrets\SecretUnavailableException;
use App\Security\Secrets\SecretValue;
use App\Security\Secrets\SupervisorSecretStore;
use Tests\Support\FakeSecretsBroker;

/*
 * ADR-003 D3 (PHP side, Sprint 1 minimum): the socket client speaks the broker frame
 * protocol against a fake broker process, APP_KEY and K are parsed from their envelopes,
 * and the SecretValue posture holds. Nothing here touches the Supervisor or the Keychain.
 */

const TEST_APP_KEY_ENVELOPE = '{"v":1,"kind":"laravel_app_key","current":"base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=","previous":["base64:cHJldmlvdXNwcmV2aW91c3ByZXZpb3VzcHJldmlvdXM="],"issued_at":"2026-10-10T16:00:00Z"}';
const TEST_IPC_KEY_ENVELOPE = '{"v":1,"kind":"hmac_key","key":"QkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkI=","issued_at":"2026-10-10T16:00:00Z"}';

function brokerItems(): array
{
    return [
        SecretRef::APP_KEY.'/default' => TEST_APP_KEY_ENVELOPE,
        SecretRef::IPC_MENUBAR.'/default' => TEST_IPC_KEY_ENVELOPE,
        SecretRef::EMAIL.'/01M4G0T1P8X3M2K7Q9R5V4W6YB' => '{"v":1,"kind":"oauth2"}',
    ];
}

it('reads an envelope over the broker socket and registers it with the redactor', function (): void {
    $broker = FakeSecretsBroker::start(brokerItems());
    $redactor = new Redactor;
    $store = new SupervisorSecretStore($broker->socketPath, 2000, $redactor);

    $value = $store->get(SecretRef::appKey());

    expect($value)->toBeInstanceOf(SecretValue::class)
        ->and($value->reveal())->toBe(TEST_APP_KEY_ENVELOPE)
        ->and($value->envelope()['kind'])->toBe('laravel_app_key')
        ->and($redactor->redactString('leaked '.TEST_APP_KEY_ENVELOPE))->not->toContain('MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=')
        ->and($broker->requests())->toBe([['op' => 'get', 'service' => SecretRef::APP_KEY, 'account' => 'default']]);

    $broker->stop();
});

it('lists accounts, reports health and maps broker errors to SecretUnavailableException', function (): void {
    $broker = FakeSecretsBroker::start(brokerItems());
    $store = new SupervisorSecretStore($broker->socketPath);

    expect($store->list(SecretRef::EMAIL))->toBe(['01M4G0T1P8X3M2K7Q9R5V4W6YB'])
        ->and($store->health())->toBe(['locked' => false]);

    expect(fn () => $store->get(new SecretRef(SecretRef::GITHUB, '01M4G0T1P8X3M2K7Q9R5V4W6YB')))
        ->toThrow(SecretUnavailableException::class, 'not_found');

    $broker->setItems(brokerItems(), locked: true);

    expect(fn () => $store->get(SecretRef::appKey()))->toThrow(SecretUnavailableException::class, 'locked')
        ->and($store->health())->toBe(['locked' => true]);

    $broker->stop();

    expect(fn () => $store->get(SecretRef::appKey()))->toThrow(SecretUnavailableException::class, 'unavailable');
});

it('never caches: a rotated K is returned on the very next call', function (): void {
    $broker = FakeSecretsBroker::start(brokerItems());
    $source = new BrokerKeySource(new SupervisorSecretStore($broker->socketPath));

    expect($source->key()->bytes())->toBe(str_repeat('B', 32));

    $items = brokerItems();
    $items[SecretRef::IPC_MENUBAR.'/default'] = '{"v":1,"kind":"hmac_key","key":"'.base64_encode(str_repeat('C', 32)).'","issued_at":"2026-10-10T17:00:00Z"}';
    $broker->setItems($items);

    expect($source->key()->bytes())->toBe(str_repeat('C', 32))
        ->and(count($broker->requests()))->toBe(2);

    $broker->stop();
});

it('refuses an all-zero or malformed K envelope and a missing broker', function (): void {
    $zero = new ArraySecretStore([SecretRef::IPC_MENUBAR.'/default' => '{"v":1,"kind":"hmac_key","key":"'.base64_encode(str_repeat("\0", 32)).'"}']);
    expect(fn () => (new BrokerKeySource($zero))->key())->toThrow(IpcKeyUnavailableException::class);

    $wrongKind = new ArraySecretStore([SecretRef::IPC_MENUBAR.'/default' => '{"v":1,"kind":"oauth2","key":"'.base64_encode(str_repeat('B', 32)).'"}']);
    expect(fn () => (new BrokerKeySource($wrongKind))->key())->toThrow(IpcKeyUnavailableException::class);

    $short = new ArraySecretStore([SecretRef::IPC_MENUBAR.'/default' => '{"v":1,"kind":"hmac_key","key":"'.base64_encode('short').'"}']);
    expect(fn () => (new BrokerKeySource($short))->key())->toThrow(IpcKeyUnavailableException::class);

    $down = new SupervisorSecretStore(sys_get_temp_dir().'/lx-nobody-'.bin2hex(random_bytes(4)).'.sock');
    expect(fn () => (new BrokerKeySource($down))->key())->toThrow(IpcKeyUnavailableException::class);
});

it('parses the laravel_app_key envelope into app.key and app.previous_keys', function (): void {
    $keys = SecretsServiceProvider::applicationKeys(json_decode(TEST_APP_KEY_ENVELOPE, true));

    expect($keys['current'])->toBe('base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=')
        ->and($keys['previous'])->toBe(['base64:cHJldmlvdXNwcmV2aW91c3ByZXZpb3VzcHJldmlvdXM=']);

    expect(fn () => SecretsServiceProvider::applicationKeys(['kind' => 'hmac_key', 'current' => 'base64:AAAA']))
        ->toThrow(SecretUnavailableException::class);
    expect(fn () => SecretsServiceProvider::applicationKeys(['kind' => 'laravel_app_key', 'current' => 'base64:c2hvcnQ=']))
        ->toThrow(SecretUnavailableException::class, '32');
});

it('keeps secret values out of every sink', function (): void {
    $value = new SecretValue('{"v":1,"kind":"api_key","api_key":"sk-not-a-real-key-0123456789abcdef"}');

    expect(fn () => (string) $value)->toThrow(LogicException::class)
        ->and(fn () => serialize($value))->toThrow(LogicException::class)
        ->and(fn () => json_encode([$value], JSON_THROW_ON_ERROR))->toThrow(LogicException::class)
        ->and(fn () => clone $value)->toThrow(LogicException::class)
        ->and(print_r($value, true))->not->toContain('sk-not-a-real-key')
        ->and(var_export($value->__debugInfo(), true))->toContain('[REDACTED]');
});

it('validates secret references', function (): void {
    expect((string) new SecretRef(SecretRef::EMAIL, '01M4G0T1P8X3M2K7Q9R5V4W6YB'))->toBe(SecretRef::EMAIL.'/01M4G0T1P8X3M2K7Q9R5V4W6YB')
        ->and((string) SecretRef::appKey())->toBe('se.stafe.executive-ai.core.app-key/default');

    expect(fn () => new SecretRef('com.other.service'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SecretRef(SecretRef::EMAIL, 'owner@example.se'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SecretRef(SecretRef::EMAIL, '../x'))->toThrow(InvalidArgumentException::class);
});
