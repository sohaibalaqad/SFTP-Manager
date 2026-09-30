<?php

namespace Tests\Feature;

use App\Services\TransferLog;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

/**
 * These tests run without any database and without a real SFTP server.
 */
class ExampleTest extends TestCase
{
    public function test_connect_page_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('Remote Path');
    }

    public function test_file_manager_requires_a_connection(): void
    {
        $this->get('/files')->assertRedirect('/');
    }

    public function test_api_returns_json_401_when_not_connected(): void
    {
        $this->getJson('/api/list?path=')
            ->assertStatus(401)
            ->assertJson(['reconnect' => true]);
    }

    public function test_connect_validates_input(): void
    {
        $this->postJson('/connect', ['host' => 'bad host; rm -rf', 'port' => 99999, 'username' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host', 'port', 'username']);
    }

    public function test_connect_failure_shows_friendly_message(): void
    {
        $this->postJson('/connect', ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'])
            ->assertStatus(502)
            ->assertJson(['message' => 'فشل الاتصال بالسيرفر. تحقق من Host أو Username أو Password.'])
            ->assertJsonMissing(['trace']);
    }

    public function test_transfer_log_requires_a_connection(): void
    {
        $this->getJson('/api/log')->assertStatus(401)->assertJson(['reconnect' => true]);
    }

    public function test_transfer_log_merges_events_and_is_deleted(): void
    {
        $log = TransferLog::forId(TransferLog::newId());
        $log->add(['id' => 'dl-1', 'type' => 'download', 'name' => 'a.txt', 'size' => 5, 'status' => 'started']);
        $log->add(['id' => 'dl-1', 'type' => 'download', 'name' => 'a.txt', 'size' => 5, 'status' => 'success']);
        $log->add(['id' => 'up-1', 'type' => 'upload', 'name' => 'b.txt', 'status' => 'failed', 'message' => 'x']);

        $entries = $log->entries();
        $this->assertCount(2, $entries);
        $this->assertSame(['up-1', 'failed'], [$entries[0]['id'], $entries[0]['status']]);
        $this->assertSame('success', $entries[1]['status']);
        $this->assertNotNull($entries[1]['duration']);

        $log->delete();
        $this->assertSame([], $log->entries());
    }

    public function test_ssh_key_is_required_in_key_mode_and_fingerprint_is_validated(): void
    {
        $this->postJson('/connect', [
            'host' => '127.0.0.1', 'port' => 22, 'username' => 'x',
            'auth' => 'key', 'host_fingerprint' => 'not-a-fingerprint',
        ])->assertStatus(422)->assertJsonValidationErrors(['private_key', 'host_fingerprint']);
    }

    public function test_invalid_ssh_key_fails_before_any_network_connection(): void
    {
        $this->postJson('/connect', [
            'host' => '127.0.0.1', 'port' => 1, 'username' => 'x',
            'auth' => 'key', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ngarbage\n-----END OPENSSH PRIVATE KEY-----\n",
        ])->assertStatus(422)->assertJson(['message' => 'تعذر قراءة مفتاح SSH. تأكد من الملف ومن كلمة سر المفتاح (Passphrase) إن وُجدت.']);
    }

    public function test_unreachable_server_keeps_the_session_instead_of_looping_to_the_connect_page(): void
    {
        $key = random_bytes(32);
        $session = ['sftp' => [
            'name' => 'x', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'auth' => 'password', 'root' => '/',
            'secret' => (new Encrypter($key, 'aes-256-gcm'))->encryptString(json_encode(['password' => 'y'])),
            'fingerprint' => 'SHA256:abc', 'log' => str_repeat('a', 32),
        ]];

        $this->withSession($session)
            ->withCookie('sftp_key', base64_encode($key))
            ->withCredentials() // JSON test requests only send cookies with this
            ->getJson('/api/list?path=')
            ->assertStatus(503)
            ->assertJsonMissing(['reconnect' => true])
            ->assertSessionHas('sftp');
    }
}
