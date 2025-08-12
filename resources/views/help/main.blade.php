<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Подключение к VPN через IKEv2 | Универсальная инструкция</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <style>
        :root {
            --color-bg: #FDFDFC;
            --color-card: #ffffff;
            --color-text: #1b1b18;
            --color-accent: #4299e1;
            --color-border: #e3e3e0;
            --color-muted: #706f6c;
            --color-error: #f53003;
            
            --shadow-card: inset 0px 0px 0px 1px rgba(26, 26, 0, 0.16);
            --shadow-button: 0px 0px 1px 0px rgba(0, 0, 0, 0.03), 0px 1px 2px 0px rgba(0, 0, 0, 0.06);
            
            --radius-sm: 4px;
            --radius-lg: 8px;
            --radius-full: 9999px;
        }
        
        .dark {
            --color-bg: #0a0a0a;
            --color-card: #161615;
            --color-text: #EDEDEC;
            --color-border: #3E3E3A;
            --color-muted: #A1A09A;
            --color-error: #FF4433;
            
            --shadow-card: inset 0px 0px 0px 1px rgba(255, 250, 237, 0.18);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: var(--color-bg);
            color: var(--color-text);
            font-family: 'Instrument Sans', system-ui, sans-serif;
            line-height: 1.5;
            padding: 24px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .subtitle {
            color: var(--color-muted);
            font-size: 1.125rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .card {
            background-color: var(--color-card);
            box-shadow: var(--shadow-card);
            border-radius: var(--radius-lg);
            padding: 32px;
            margin-bottom: 24px;
        }
        
        h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 24px;
            color: var(--color-accent);
        }
        
        h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: var(--radius-full);
            background-color: rgba(66, 153, 225, 0.1);
            color: var(--color-accent);
        }
        
        .requirements {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .requirement-card {
            background-color: rgba(0, 0, 0, 0.03);
            border-radius: var(--radius-sm);
            padding: 20px;
            border: 1px solid var(--color-border);
        }
        
        .requirement-card h4 {
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .requirement-card p {
            color: var(--color-muted);
            font-size: 0.9rem;
        }
        
        ol, ul {
            margin-left: 24px;
            margin-bottom: 20px;
        }
        
        li {
            margin-bottom: 12px;
            padding-left: 8px;
        }
        
        strong {
            font-weight: 600;
        }
        
        code {
            background-color: rgba(0, 0, 0, 0.05);
            color: var(--color-text);
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            font-family: monospace;
            font-size: 0.9rem;
        }
        
        .note {
            background-color: rgba(66, 153, 225, 0.05);
            border-left: 3px solid var(--color-accent);
            padding: 16px;
            margin: 20px 0;
            border-radius: var(--radius-sm);
            display: flex;
            gap: 12px;
        }
        
        .note .icon {
            flex-shrink: 0;
        }
        
        .warn {
            background-color: rgba(245, 101, 101, 0.05);
            border-left: 3px solid var(--color-error);
            padding: 16px;
            margin: 20px 0;
            border-radius: var(--radius-sm);
            display: flex;
            gap: 12px;
        }
        
        .warn .icon {
            background-color: rgba(245, 101, 101, 0.1);
            color: var(--color-error);
        }
        
        .btn {
            display: inline-block;
            background-color: #1b1b18;
            color: white;
            border-radius: var(--radius-sm);
            border: 1px solid #000;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            box-shadow: var(--shadow-button);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .btn:hover {
            background-color: #000;
            border-color: #000;
        }
        
        .dark .btn {
            background-color: #eeeeec;
            border-color: #eeeeec;
            color: #1C1C1A;
        }
        
        .dark .btn:hover {
            background-color: white;
            border-color: white;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--color-border);
            color: var(--color-muted);
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 16px;
            }
            
            .card {
                padding: 24px;
            }
            
            h1 {
                font-size: 1.75rem;
            }
            
            h2 {
                font-size: 1.3rem;
            }
            
            .requirements {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script>
        // Проверка системных настроек темного режима
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>
<body>
    <div class="container">
        <header>
            <h1>Подключение к VPN через IKEv2</h1>
            <p class="subtitle">Универсальная инструкция для Android, iOS, Windows и macOS с использованием логина и пароля</p>
        </header>
        
        <div class="card">
            <div class="note">
                <div class="icon">ℹ️</div>
                <div>Эта инструкция подходит для подключения по протоколу IKEv2 с аутентификацией по логину и паролю. Сертификаты и ключи не требуются.</div>
            </div>
            
            <h2>Что потребуется</h2>
            <div class="requirements">
                <div class="requirement-card">
                    <h4>Адрес сервера</h4>
                    <p>Пример: <code>vpn.xab.su</code></p>
                </div>
                <div class="requirement-card">
                    <h4>Ваш логин</h4>
                    <p>Имя пользователя, выданное <a target="_blank" href="https://t.me/viptrafic_bot">нашим ботом</a></p>
                </div>
                <div class="requirement-card">
                    <h4>Пароль</h4>
                    <p>Выданной <a target="_blank" href="https://t.me/viptrafic_bot">нашим ботом</a></p>
                    <p>Пароль для доступа к VPN-серверу</p>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>Инструкция по платформам</h2>
            
            <div>
                <h3><span class="icon">🤖</span> Android</h3>
                <ol>
                    <li>Откройте <strong>Настройки</strong> → <strong>Сеть и интернет</strong> → <strong>VPN</strong></li>
                    <li>Нажмите <strong>+</strong> (Добавить VPN)</li>
                    <li>Заполните параметры:
                        <ul>
                            <li><strong>Имя</strong>: Произвольное название (например, <code>Мой VPN</code>)</li>
                            <li><strong>Тип</strong>: Выберите <strong>IKEv2/IPSec RSA</strong> или <strong>IKEv2/IPSec PSK</strong></li>
                            <li><strong>Адрес сервера</strong>: Введите ваш VPN-адрес</li>
                            <li><strong>IPSec-идентификатор сервера</strong>: Оставьте пустым</li>
                            <li><strong>IPSec предварительный ключ</strong>: Оставьте пустым</li>
                            <li><strong>Логин</strong>: Ваш username</li>
                            <li><strong>Пароль</strong>: Ваш пароль</li>
                        </ul>
                    </li>
                    <li>Нажмите <strong>Сохранить</strong> → <strong>Подключиться</strong></li>
                </ol>
                
                <div class="note">
                    <div class="icon">💡</div>
                    <div>Если в вашей версии Android нет поддержки IKEv2, используйте приложение <strong>StrongSwan</strong> и выберите тип подключения <strong>IKEv2 EAP</strong> для аутентификации по логину/паролю.</div>
                </div>
            </div>
            
            <div>
                <h3><span class="icon">📱</span> iOS (iPhone/iPad)</h3>
                <ol>
                    <li>Откройте <strong>Настройки</strong> → <strong>Основные</strong> → <strong>VPN и управление устройством</strong></li>
                    <li>Нажмите <strong>Добавить конфигурацию VPN...</strong></li>
                    <li>Выберите <strong>Тип: IKEv2</strong></li>
                    <li>Заполните параметры:
                        <ul>
                            <li><strong>Описание</strong>: Произвольное название (например, <code>Мой VPN</code>)</li>
                            <li><strong>Сервер</strong>: Адрес вашего VPN</li>
                            <li><strong>Удаленный идентификатор</strong>: Оставьте пустым</li>
                            <li><strong>Локальный идентификатор</strong>: Оставьте пустым</li>
                            <li><strong>Аутентификация</strong> → <strong>Имя пользователя</strong></li>
                            <li><strong>Логин</strong>: Ваш username</li>
                            <li><strong>Пароль</strong>: Ваш пароль</li>
                        </ul>
                    </li>
                    <li>Нажмите <strong>Готово</strong> → Активируйте переключатель VPN</li>
                </ol>
            </div>
            
            <div>
                <h3><span class="icon">💻</span> Windows (10/11)</h3>
                <ol>
                    <li>Откройте <strong>Пуск</strong> → <strong>Параметры</strong> → <strong>Сеть и Интернет</strong> → <strong>VPN</strong></li>
                    <li>Нажмите <strong>Добавить VPN-подключение</strong></li>
                    <li>Заполните параметры:
                        <ul>
                            <li><strong>Поставщик услуг VPN</strong>: Windows (встроенный)</li>
                            <li><strong>Имя подключения</strong>: Произвольное название (например, <code>Мой VPN</code>)</li>
                            <li><strong>Имя сервера</strong>: Адрес вашего VPN</li>
                            <li><strong>Тип VPN</strong>: IKEv2</li>
                            <li><strong>Тип данных для входа</strong>: Имя пользователя и пароль</li>
                            <li><strong>Логин</strong>: Ваш username</li>
                            <li><strong>Пароль</strong>: Ваш пароль</li>
                        </ul>
                    </li>
                    <li>Нажмите <strong>Сохранить</strong> → Вернитесь в список VPN → Нажмите <strong>Подключиться</strong></li>
                </ol>
                
                <div class="warn">
                    <div class="icon">⚠️</div>
                    <div>При возникновении ошибок:
                        <ul>
                            <li>Откройте <strong>Панель управления</strong> → <strong>Сеть и Интернет</strong> → <strong>Центр управления сетями</strong></li>
                            <li>Кликните правой кнопкой по VPN-подключению → <strong>Свойства</strong></li>
                            <li>Перейдите на вкладку <strong>Безопасность</strong></li>
                            <li>Установите: <strong>Тип VPN: IKEv2</strong></li>
                            <li>В разделе "Разрешить эти протоколы" отметьте <strong>CHAP</strong> и <strong>Microsoft CHAP Version 2 (MS-CHAP v2)</strong></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div>
                <h3><span class="icon">🍎</span> macOS</h3>
                <ol>
                    <li>Откройте <strong>Системные настройки</strong> → <strong>Сеть</strong></li>
                    <li>Нажмите <strong>+</strong> (плюс внизу списка)</li>
                    <li>Выберите:
                        <ul>
                            <li><strong>Интерфейс</strong>: VPN</li>
                            <li><strong>Тип VPN</strong>: IKEv2</li>
                            <li><strong>Имя службы</strong>: Произвольное название (например, <code>Мой VPN</code>)</li>
                        </ul>
                    </li>
                    <li>Нажмите <strong>Создать</strong></li>
                    <li>Заполните:
                        <ul>
                            <li><strong>Адрес сервера</strong>: Ваш VPN-адрес</li>
                            <li><strong>Удаленный идентификатор</strong>: Оставьте пустым</li>
                            <li><strong>Локальный идентификатор</strong>: Оставьте пустым</li>
                        </ul>
                    </li>
                    <li>Кликните <strong>Настройки аутентификации...</strong> → Выберите:
                        <ul>
                            <li><strong>Аутентификация</strong>: Имя пользователя</li>
                            <li><strong>Логин</strong>: Ваш username</li>
                            <li><strong>Пароль</strong>: Ваш пароль</li>
                        </ul>
                    </li>
                    <li>Нажмите <strong>ОК</strong> → <strong>Применить</strong> → <strong>Подключиться</strong></li>
                </ol>
            </div>
        </div>
        
        <div class="card">
            <h2>Решение проблем</h2>
            <div class="warn">
                <div class="icon">🔑</div>
                <div>
                    <p><strong>Если подключение не работает:</strong></p>
                    <ul>
                        <li>Проверьте интернет-соединение</li>
                        <li>Перезагрузите устройство</li>
                        <li>Убедитесь в правильности адреса сервера, логина и пароля</li>
                        <li>Попробуйте использовать IP-адрес вместо доменного имени</li>
                        <li>Отключите файервол и антивирус для проверки</li>
                        <li>Обновите операционную систему</li>
                    </ul>
                </div>
            </div>
            
            <div class="note">
                <div class="icon">🛡️</div>
                <div>
                    <p><strong>Рекомендации по безопасности:</strong></p>
                    <ul>
                        <li>Никому не сообщайте свои учетные данные</li>
                        <li>Используйте VPN при работе с публичными Wi-Fi сетями</li>
                        <li>Регулярно обновляйте пароль</li>
                        <li>При длительном использовании VPN выбирайте серверы ближе к вашему местоположению</li>
                    </ul>
                </div>
            </div>
            
            <a href="https://t.me/viptrafic_bot" class="btn">Начать использование VPN</a>
        </div>
        
        <div class="footer">
            <p>Универсальная инструкция по подключению к VPN через IKEv2</p>
            <p>Работает на всех современных устройствах</p>
        </div>
    </div>
</body>
</html>