<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Services\VpnService;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Сверка: кто должен быть на VPN-сервере по данным базы и кто там реально есть.
 *
 * Расхождения:
 *   - missing:  канал активен в базе, но на сервере его нет — юзер платит и не может войти;
 *   - leaked:   канал заблокирован в базе, но на сервере остался — VPN бесплатно;
 *   - unknown:  логин есть на сервере, а в базе такого канала нет вовсе.
 *
 * С --fix первые два чинятся: missing добавляются (у должников вместо этого
 * канал блокируется в базе), leaked удаляются.
 * unknown только показываются: это могут быть юзеры, заведённые руками.
 */
class ReconcileVpnUsers extends Command
{
    protected $signature = 'vpn:reconcile
        {--fix : Add missing and remove leaked users on the server}
        {--server= : Server name from config/vpn.php (default: all)}';

    protected $description = 'Compare VPN server users with active clients in the database';

    public function handle(): int
    {
        $servers = $this->option('server')
            ? [$this->option('server')]
            : array_keys(config('vpn.servers', []));

        $report = [];
        $failed = false;

        foreach ($servers as $serverName) {
            try {
                $report[$serverName] = $this->reconcileServer($serverName, (bool) $this->option('fix'));
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("{$serverName}: {$e->getMessage()}");
                Log::error('[Reconcile] Сервер недоступен', ['server' => $serverName, 'error' => $e->getMessage()]);
                $report[$serverName] = ['error' => $e->getMessage()];
            }
        }

        $this->notifyAdminIfNeeded($report);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array{missing: string[], leaked: string[], unknown: string[], fixed: string[], errors: string[]}
     */
    private function reconcileServer(string $serverName, bool $fix): array
    {
        $vpn = app()->makeWith(VpnService::class, ['serverName' => $serverName]);
        $onServer = collect($vpn->getUsers());

        $clients = Client::where('server_name', $serverName)->get()->keyBy('name');
        $active = $clients->where('is_active', true)->keys();
        $inactive = $clients->where('is_active', false)->keys();

        $result = [
            'missing' => $active->diff($onServer)->values()->all(),
            'leaked' => $onServer->intersect($inactive)->values()->all(),
            'unknown' => $onServer->diff($clients->keys())->values()->all(),
            'fixed' => [],
            'errors' => [],
        ];

        $this->line("{$serverName}: on server {$onServer->count()}, active in DB {$active->count()}");
        foreach (['missing', 'leaked', 'unknown'] as $kind) {
            if ($result[$kind]) {
                $this->warn("  {$kind}: " . implode(', ', $result[$kind]));
            }
        }

        if (!$fix) {
            return $result;
        }

        foreach ($result['missing'] as $name) {
            $client = $clients[$name];

            // Должник: блокировка на сервере прошла, а в базе канал остался активным
            // (так было 22.11.2026, когда API отвечал по таймауту). Возвращать логин
            // нельзя — это бесплатный VPN. Доводим блокировку в базе до конца.
            if (Transaction::balanceFor($client->user_id) < 0) {
                $client->is_active = false;
                $client->save();
                $result['fixed'][] = "⛔{$name}";
                continue;
            }

            try {
                $vpn->addUser($name, $client->password);
                $result['fixed'][] = "+{$name}";
            } catch (\Throwable $e) {
                $result['errors'][] = "{$name}: {$e->getMessage()}";
            }
        }

        foreach ($result['leaked'] as $name) {
            try {
                $vpn->removeUser($name);
                $result['fixed'][] = "-{$name}";
            } catch (\Throwable $e) {
                $result['errors'][] = "{$name}: {$e->getMessage()}";
            }
        }

        if ($result['fixed']) {
            $this->info('  fixed: ' . implode(', ', $result['fixed']));
            Log::warning('[Reconcile] Исправлены расхождения', ['server' => $serverName, 'fixed' => $result['fixed']]);
        }
        foreach ($result['errors'] as $error) {
            $this->error("  {$error}");
        }

        return $result;
    }

    private function notifyAdminIfNeeded(array $report): void
    {
        $lines = [];
        foreach ($report as $server => $r) {
            if (isset($r['error'])) {
                $lines[] = "❌ {$server}: сервер недоступен";
                continue;
            }
            if ($r['missing']) {
                $lines[] = "⚠️ {$server}: активны в базе, нет на сервере: " . implode(', ', $r['missing']);
            }
            if ($r['leaked']) {
                $lines[] = "⚠️ {$server}: заблокированы в базе, остались на сервере: " . implode(', ', $r['leaked']);
            }
            if ($r['unknown']) {
                $lines[] = "❓ {$server}: на сервере, но нет в базе: " . implode(', ', $r['unknown']);
            }
            if ($r['fixed']) {
                $lines[] = "🔧 исправлено: " . implode(', ', $r['fixed']);
            }
            foreach ($r['errors'] as $error) {
                $lines[] = "❌ {$error}";
            }
        }

        if ($lines) {
            AdminNotifier::telegram("🔍 Сверка VPN\n\n" . implode("\n", $lines), 'Reconcile');
        }
    }
}
