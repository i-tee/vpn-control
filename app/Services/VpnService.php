<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class VpnService
{
    protected string $baseUrl;
    protected string $secretKey;
    protected string $serverName;

    public function __construct(string $serverName = null)
    {
        $this->initConfig($serverName ?? config('vpn.default_server'));
    }

    private function initConfig(string $serverName): void
    {
        $this->serverName = $serverName;
        $servers = config('vpn.servers');

        if (!isset($servers[$serverName])) {
            throw new RuntimeException("Сервер '{$serverName}' не найден в конфиге");
        }

        $server = $servers[$serverName];
        $this->baseUrl = "{$server['protocol']}://{$server['host']}:{$server['port']}";
        $this->secretKey = $server['secret_key'];
    }

    // Добавляем метод для получения текущего сервера
    public function getCurrentServer(): string
    {
        return $this->serverName;
    }

    public function getUsers(): array
    {
        return $this->request('get_users', []);
    }

    public function addUser(string $username, string $password): void
    {
        \Log::debug("Adding user to server: {$this->serverName}", [
            'baseUrl' => $this->baseUrl,
            'server' => $this->serverName
        ]);

        $this->request('add_user', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function removeUser(string $username): void
    {
        $this->request('remove_user', [
            'username' => $username,
        ]);
    }

    private function request(string $endpoint, array $data): mixed
    {
        $url = "{$this->baseUrl}/{$endpoint}";
        $payload = array_merge(['key' => $this->secretKey], $data);

        \Log::debug('📤 VPN: Отправка запроса', compact('url', 'payload'));

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(10)
                ->post($url, $payload);

            \Log::debug('📥 VPN: Получен ответ', [
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException("HTTP {$response->status()}: " . $response->body());
            }

            $result = $response->json();

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('JSON parse error: ' . json_last_error_msg());
            }

            if (!isset($result['status'])) {
                throw new \RuntimeException('Invalid response format: no status');
            }

            if ($result['status'] === 'error') {
                $msg = $result['message'] ?? 'Unknown error';
                \Log::error('❌ API вернул ошибку', ['message' => $msg]);
                throw new \RuntimeException($msg);
            }

            return $result['users'] ?? null;
        } catch (\Exception $e) {
            \Log::error('💥 Ошибка в VPN-запросе', [
                'endpoint' => $endpoint,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
