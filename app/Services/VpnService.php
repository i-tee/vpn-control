<?php

namespace App\Services;

use App\Models\Client;
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

    public function getCurrentServer(): string
    {
        return $this->serverName;
    }

    public function getUsers(): array
    {
        return $this->request('get_users', []);
    }

    // Метод для добавления клиента на сервер и в БД
    public function createClient(string $username, string $password, int $userId, ?string $telegramNickname = null, bool $activate = true): Client
    {
        // Создаем клиента в БД
        $client = Client::create([
            'name' => $username,
            'password' => $password,
            'user_id' => $userId,
            'server_name' => $this->serverName,
            'telegram_nickname' => $telegramNickname,
            'is_active' => $activate
        ]);

        // Если нужно активировать, добавляем на сервер
        if ($activate) {
            $this->addUser($username, $password);
        }

        return $client;
    }

    // Метод для активации клиента
    public function activateClient(int $clientId): Client
    {
        $client = Client::findOrFail($clientId);
        
        if ($client->is_active) {
            return $client;
        }
        
        // Добавляем клиента на сервер
        $this->addUser($client->name, $client->password);
        
        // Обновляем статус в БД
        $client->update(['is_active' => true]);
        
        return $client;
    }

    // Метод для деактивации клиента
    public function deactivateClient(int $clientId, string $reason = 'Deactivated via admin panel'): Client
    {
        $client = Client::findOrFail($clientId);
        
        if (!$client->is_active) {
            return $client;
        }
        
        // Удаляем клиента с сервера
        $this->removeUser($client->name);
        
        // Обновляем статус в БД
        $client->update([
            'is_active' => false,
            'comment' => $client->comment 
                ? $client->comment . ' | ' . $reason 
                : $reason
        ]);
        
        return $client;
    }

    // Метод для удаления клиента
    public function deleteClient(int $clientId): void
    {
        $client = Client::findOrFail($clientId);
        
        // Если клиент активен, сначала удаляем его с сервера
        if ($client->is_active) {
            $this->removeUser($client->name);
        }
        
        // Удаляем клиента из БД
        $client->delete();
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