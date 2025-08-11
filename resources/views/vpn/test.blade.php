<!DOCTYPE html>
<html>

<head>
    <title>Тестирование VPN API</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-6">
    <div class="max-w-3xl mx-auto bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-6 text-center">Тестирование VPN API</h1>

        @if(isset($instructions))
            <div class="mb-8">
                <h2 class="text-lg font-semibold mb-2">Инструкция:</h2>
                <ol class="list-decimal pl-5 space-y-1">
                    @foreach($instructions as $instruction)
                        <li>{{ $instruction }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if(isset($error))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ $error }}
            </div>
        @endif

        @if(isset($action) && isset($result))
            <div class="mb-6">
                <h2 class="text-lg font-semibold mb-2">{{ $action }}:</h2>
                @if($type === 'users')
                    <ul class="list-disc pl-5">
                        @forelse($result as $user)
                            <li class="py-1">{{ $user }}</li>
                        @empty
                            <li class="text-gray-500">Пользователи не найдены</li>
                        @endforelse
                    </ul>
                @else
                    <div class="p-3 bg-green-100 text-green-800 rounded font-medium">
                        {{ $result }}
                    </div>
                @endif
            </div>
        @endif

        @if(isset($error))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ $error }}
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <a href="{{ route('vpn.users') }}"
                class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded text-center">
                Получить список
            </a>

            <!-- ЗАМЕНИТЕ ЭТУ ЧАСТЬ -->
            <form action="{{ route('vpn.add') }}" method="POST" class="col-span-1">
                @csrf
                <div class="flex flex-col space-y-2">
                    <input type="text" name="username" placeholder="Имя пользователя" class="border rounded px-2 py-1"
                        required value="{{ old('username') }}">
                    <input type="password" name="password" placeholder="Пароль" class="border rounded px-2 py-1"
                        required>
                    <button type="submit"
                        class="bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded">
                        Добавить
                    </button>
                </div>
            </form>

            <form action="{{ route('vpn.remove') }}" method="POST" class="col-span-1">
                @csrf
                <div class="flex flex-col space-y-2">
                    <input type="text" name="username" placeholder="Имя пользователя" class="border rounded px-2 py-1"
                        required>
                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded">
                        Удалить
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 pt-6 border-t">
            <h2 class="text-lg font-semibold mb-2">Как это работает:</h2>
            <ul class="list-disc pl-5 space-y-1 text-gray-700">
                <li>GET /vpn-test/users → вызывает метод получения списка</li>
                <li>POST /vpn-test/add → добавляет пользователя через API</li>
                <li>POST /vpn-test/remove → удаляет пользователя через API</li>
            </ul>
        </div>

        @if($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded text-sm mb-2">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

    </div>
</body>

</html>