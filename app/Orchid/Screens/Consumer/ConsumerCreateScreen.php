<?php

namespace App\Orchid\Screens\Consumer;

use App\Models\User;
use Orchid\Platform\Models\Role;
use Illuminate\Http\Request;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Password;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Toast;

class ConsumerCreateScreen extends Screen
{
    /**
     * @var User|null
     */
    public ?User $user = null;

    public function query(): iterable
    {
        $this->user = new User();
        return [];
    }

    public function name(): ?string
    {
        return 'Create Consumer';
    }

    public function description(): ?string
    {
        return 'Create a new consumer in the system';
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Save')
                ->icon('check')
                ->method('save')
                ->post()
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                Input::make('user.name')
                    ->title('Name')
                    ->required(),
                    
                Input::make('user.email')
                    ->title('Email')
                    ->type('email')
                    ->required(),
                    
                Password::make('user.password')
                    ->title('Password')
                    ->required()
                    ->min(6),
            ]),
        ];
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'user.name' => 'required|string|max:255',
            'user.email' => 'required|email|unique:users,email',
            'user.password' => 'required|string|min:6',
        ])['user'];

        // Создаем пользователя
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);

        // Получаем роль consumer
        $consumerRole = Role::where('slug', 'consumer')->first();
        
        if ($consumerRole) {
            // Назначаем роль consumer через отношение many-to-many
            $user->roles()->attach($consumerRole);
        } else {
            // Если роль не существует, создаем её
            $consumerRole = Role::create([
                'name' => 'Consumer',
                'slug' => 'consumer',
                'description' => 'Can view and manage their own data',
                'permissions' => []
            ]);
            
            $user->roles()->attach($consumerRole);
        }

        Toast::success('Consumer created successfully');
        return redirect()->route('platform.consumers.list');
    }
}