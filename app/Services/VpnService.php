<?php

namespace App\Services;

use App\Models\Client;
use App\Notifications\VpnClientCreated;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;

/**
 * HTTP-клиент к API StrongSwan-сервера.
 *
 * Вне production сетевые вызовы заглушены и только пишутся в лог, поэтому
 * локальная разработка и тесты не трогают боевой сервер.
 */
class VpnService
{
    /**
     * Что допустимо в логине и пароле. Всё это пишется в ipsec.secrets, поэтому
     * кавычки, пробелы и переводы строк запрещены: через них можно дописать
     * в файл чужую строку.
     */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';
    public const PASSWORD_PATTERN = '/^[A-Za-z0-9_.@!#%+=-]{4,128}$/';

    protected string $baseUrl;
    protected ?string $secretKey;
    protected ?string $socket;
    protected string $serverName;

    public function __construct(?string $serverName = null)
    {
        $this->initConfig($serverName ?? config('vpn.default_server'));
    }

    /**
     * Реально ходить на VPN-сервер только в production.
     */
    protected function shouldExecute(): bool
    {
        return app()->environment('production');
    }

    private function initConfig(string $serverName): void
    {
        $this->serverName = $serverName;
        $servers = config('vpn.servers');

        if (!isset($servers[$serverName])) {
            throw new RuntimeException("Сервер '{$serverName}' не найден в конфиге");
        }

        $server = $servers[$serverName];
        $this->secretKey = $server['secret_key'] ?? null;
        $this->socket = $server['socket'] ?? null;
        // Через unix-сокет хост в URL не важен, curl ходит в сокет
        $this->baseUrl = $this->socket
            ? 'http://localhost'
            : "{$server['protocol']}://{$server['host']}:{$server['port']}";
    }

    public function getCurrentServer(): string
    {
        return $this->serverName;
    }

    /**
     * Список логинов на сервере.
     *
     * @return string[]
     */
    public function getUsers(): array
    {
        if (!$this->shouldExecute()) {
            return [];
        }

        return $this->request('get_users', [])['users'] ?? [];
    }

    /**
     * Активные сессии на сервере: [['sa' => 'ikev2-vpn[30]', 'identity' => 'login'], ...]
     */
    public function getSessions(): array
    {
        if (!$this->shouldExecute()) {
            return [];
        }

        return $this->request('sessions', [])['sessions'] ?? [];
    }

    /**
     * Создать канал в БД и на сервере. Если сервер не принял юзера,
     * запись в БД удаляется, чтобы не было канала, которого нет на сервере.
     */
    public function createClient(
        string $username,
        string $password,
        int $userId,
        ?string $telegramNickname = null,
        bool $activate = true
    ): Client|false {
        self::assertValidCredentials($username, $password);

        if (Client::where('name', $username)->exists()) {
            return false;
        }

        $client = Client::create([
            'name' => $username,
            'password' => $password,
            'user_id' => $userId,
            'server_name' => $this->serverName,
            'telegram_nickname' => $telegramNickname,
            'is_active' => $activate,
        ]);

        if ($activate) {
            try {
                $this->addUser($username, $password);
            } catch (\Throwable $e) {
                Log::error('[VPN] Сервер не принял нового юзера, откатываем канал', [
                    'client_id' => $client->id,
                    'error' => $e->getMessage(),
                ]);
                $client->delete();
                return false;
            }
        }

        if ($adminEmail = config('gatekeeper.admin_email')) {
            try {
                Notification::route('mail', $adminEmail)->notify(new VpnClientCreated($client));
            } catch (\Throwable $e) {
                Log::error('[VPN] Не удалось отправить письмо VpnClientCreated', ['error' => $e->getMessage()]);
            }
        }

        return $client;
    }

    public function activateClient(int $clientId): Client
    {
        $client = Client::findOrFail($clientId);

        if ($client->is_active) {
            return $client;
        }

        $this->addUser($client->name, $client->password);

        $client->is_active = true;
        $client->save();

        return $client;
    }

    public function deactivateClient(int $clientId): Client
    {
        $client = Client::findOrFail($clientId);

        if (!$client->is_active) {
            return $client;
        }

        $this->removeUser($client->name);

        $client->is_active = false;
        $client->save();

        return $client;
    }

    public function deleteClient(int $clientId): void
    {
        $client = Client::findOrFail($clientId);

        if ($client->is_active) {
            $this->removeUser($client->name);
        }

        $client->delete();
    }

    public function addUser(string $username, string $password): void
    {
        self::assertValidCredentials($username, $password);

        if (!$this->shouldExecute()) {
            Log::info('[VPN] DEV: пропускаем add_user', ['username' => $username, 'server' => $this->serverName]);
            return;
        }

        $this->request('add_user', ['username' => $username, 'password' => $password]);
    }

    public function removeUser(string $username): void
    {
        self::assertValidUsername($username);

        if (!$this->shouldExecute()) {
            Log::info('[VPN] DEV: пропускаем remove_user', ['username' => $username, 'server' => $this->serverName]);
            return;
        }

        $this->request('remove_user', ['username' => $username]);
    }

    public static function assertValidUsername(string $username): void
    {
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new InvalidArgumentException("Недопустимый логин VPN: '{$username}'");
        }
    }

    public static function assertValidCredentials(string $username, string $password): void
    {
        self::assertValidUsername($username);

        if (!preg_match(self::PASSWORD_PATTERN, $password)) {
            throw new InvalidArgumentException("Недопустимый пароль VPN для '{$username}'");
        }
    }

    /**
     * @return array<string, mixed> разобранный JSON-ответ
     */
    private function request(string $endpoint, array $data): array
    {
        if (blank($this->secretKey)) {
            throw new RuntimeException('VPN_SECRET_KEY не задан');
        }

        $url = "{$this->baseUrl}/{$endpoint}";
        // В лог идут только имя метода и логин, без ключа и пароля
        $logContext = ['url' => $url, 'username' => $data['username'] ?? null];

        $http = Http::acceptJson()
            ->withHeaders(['X-Api-Key' => $this->secretKey])
            ->timeout(10);

        if ($this->socket) {
            $http = $http->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $this->socket]]);
        }

        $response = $http->post($url, array_merge(['key' => $this->secretKey], $data));

        $result = $response->json();

        if (!$response->successful() || !is_array($result) || ($result['status'] ?? null) !== 'success') {
            $message = is_array($result) ? ($result['message'] ?? 'unknown error') : 'invalid response';
            Log::error('[VPN] Запрос к серверу не удался', $logContext + [
                'status' => $response->status(),
                'message' => $message,
            ]);
            throw new RuntimeException("VPN API {$endpoint}: HTTP {$response->status()}, {$message}");
        }

        Log::debug('[VPN] Запрос выполнен', $logContext);

        return $result;
    }
}
