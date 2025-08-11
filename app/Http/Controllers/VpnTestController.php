<?php

namespace App\Http\Controllers;

use App\Services\VpnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VpnTestController extends Controller
{
    public function listUsers(VpnService $vpn)
    {
        try {
            $users = $vpn->getUsers();
            Log::info('Список пользователей получен', ['count' => count($users)]);
            return view('vpn.test', [
                'action' => 'Список пользователей',
                'result' => $users,
                'type' => 'users'
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения списка', ['error' => $e->getMessage()]);
            return view('vpn.test', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function addUser(Request $request, VpnService $vpn)
    {
        $request->validate([
            'username' => 'required|string|max:50',
            'password' => 'required|string',
        ]);

        $vpn->addUser($request->input('username'), $request->input('password'));

        return view('vpn.test', [
            'action' => 'Успех!',
            'result' => 'Пользователь добавлен',
            'type' => 'success'
        ]);
    }

    public function removeUser(Request $request, VpnService $vpn)
    {
        $request->validate([
            'username' => 'required|string',
        ]);

        try {
            $vpn->removeUser($request->username);
            Log::info('Пользователь удалён', ['username' => $request->username]);
            return $this->successResponse('Пользователь удалён');
        } catch (\Exception $e) {
            Log::error('Ошибка удаления', [
                'username' => $request->username,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($e->getMessage());
        }
    }

    private function successResponse(string $message)
    {
        return view('vpn.test', [
            'action' => 'Операция выполнена',
            'result' => $message,
            'type' => 'success'
        ]);
    }

    private function errorResponse(string $message)
    {
        return view('vpn.test', [
            'error' => $message,
            'type' => 'error'
        ]);
    }
}
