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

class ConsumerEditScreen extends Screen
{
    /**
     * @var User|null
     */
    public ?User $user = null;

    public function query($id): iterable
    {
        $this->user = User::findOrFail($id);
        return [
            'user' => $this->user,
        ];
    }

    public function name(): ?string
    {
        return $this->user->exists ? 'Edit Consumer #' . $this->user->id : 'Create Consumer';
    }

    public function description(): ?string
    {
        return 'Manage consumer in the system';
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Save')
                ->icon('check')
                ->method('save')
                ->action(route('platform.consumers.edit', $this->user->id))
                ->post(),
                
            Button::make('Back')
                ->icon('left')
                ->route('platform.consumers.list'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                Input::make('user.name')
                    ->title('Name')
                    ->required()
                    ->value($this->user->name),
                    
                Input::make('user.email')
                    ->title('Email')
                    ->type('email')
                    ->required()
                    ->value($this->user->email),
                    
                Password::make('user.password')
                    ->title('New Password')
                    ->placeholder('Leave empty to keep current password')
                    ->value(''),
            ]),
        ];
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'user.name' => 'required|string|max:255',
            'user.email' => 'required|email|unique:users,email,' . $this->user->id,
            'user.password' => 'nullable|string|min:6',
        ])['user'];

        // Обновляем данные пользователя
        $this->user->name = $data['name'];
        $this->user->email = $data['email'];
        
        if (!empty($data['password'])) {
            $this->user->password = bcrypt($data['password']);
        }
        
        $this->user->save();

        // Убедимся, что у пользователя есть роль consumer
        $consumerRole = Role::where('slug', 'consumer')->first();
        
        if ($consumerRole) {
            // Проверяем, есть ли у пользователя эта роль
            $hasRole = $this->user->roles->contains($consumerRole);
            
            if (!$hasRole) {
                $this->user->roles()->attach($consumerRole);
            }
        } else {
            // Создаем роль, если её нет
            $consumerRole = Role::create([
                'name' => 'Consumer',
                'slug' => 'consumer',
                'description' => 'Can view and manage their own data',
                'permissions' => []
            ]);
            
            $this->user->roles()->attach($consumerRole);
        }

        Toast::info('Consumer updated successfully');
        return redirect()->route('platform.consumers.list');
    }
}