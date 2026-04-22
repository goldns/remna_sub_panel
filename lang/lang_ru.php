<?php
return [

    // HTML <html lang="..."> attribute value
    'html_lang' => 'ru',

    // User panel
    'panel' => [
        'page_title'    => '👤 Профиль',
        'card_title'    => 'Информация о подписке',
        'traffic_used'  => 'Использовано трафика',
        'traffic_limit' => 'Осталось трафика',
        'expires_at'    => 'Дата окончания',
        'days_left'     => 'Осталось дней',
        'traffic_reset' => 'Сброс трафика',
        'unlimited'     => '∞ Безлимитный',
        'today'         => 'Сегодня',
        'expired_days'  => 'Истёк',
        'days_suffix'   => ' д.',
    ],

    // User status badges
    'status' => [
        'ACTIVE'   => '✅ Активен',
        'DISABLED' => '⛔ Отключён',
        'LIMITED'  => '⚠️ Лимит',
        'EXPIRED'  => '⌛ Истёк',
    ],

    // Traffic reset strategy labels
    'strategy' => [
        'NO_RESET'      => '♾️ Без сброса',
        'DAY'           => '📅 Ежедневно',
        'WEEK'          => '📆 Еженедельно',
        'MONTH'         => '🗓️ Ежемесячно',
        'MONTH_ROLLING' => '🔄 Ежемесячно (от даты создания)',
    ],

    // HWID devices section
    'hwid' => [
        'title'        => '📱 Подключенные устройства',
        'count'        => 'Подключено',
        'limit'        => 'Лимит устройств',
        'unlimited'    => '∞',
        'show_devices' => 'показать',
        'hide_devices' => 'скрыть',
        'last_seen'    => 'активность',
        'no_model'     => 'Устройство',
    ],

    // Whitelist section
    'wl' => [
        'title'        => '🔒 Белые списки',
        'traffic_used' => 'Использовано трафика',
        'remaining'    => 'Осталось трафика',
    ],

    // Debug panel (browser, fixed bottom bar)
    'debug' => [
        'tab_request'     => '🔍 Запрос',
        'tab_raw_req'     => '📤 Raw Request',
        'tab_raw_resp'    => '📥 Raw Response',
        'tab_config'      => '⚙️ Config',
        'section_client'  => '👤 Клиент',
        'section_headers' => '📨 Входящие заголовки (браузер → прокси)',
        'section_api'     => '⚡ API',
        'section_wl_api'  => '⚡ API · 🔒 Белый список',
        'label_status'    => '📶 Статус',
        'label_ms'        => 'мс',
        'label_found'     => '✅ Найден',
        'label_not_found' => '❌ Не найден',
        'label_main'             => '📋 Основная подписка',
        'label_wl'               => '🔒 Белый список',
        'section_encrypt'        => '🔐 Шифрование ссылки',
        'label_encrypt_ok'       => '✅ Зашифровано',
        'label_encrypt_fallback' => '⚠️ Fallback (открытая ссылка)',
        'label_encrypt_disabled' => 'Шифрование отключено',
        'label_encrypt_result'   => 'Ссылка',
        'label_encrypt_api'      => '🔐 Ответ crypto.happ.su',
    ],

    // Installation guide
    'install' => [
        'title' => '🚀 Инструкция по установке',

        'platforms' => [
            'windows' => [
                'label' => '🖥️ Windows',
                'steps' => [
                    [
                        'icon'  => 'download',
                        'title' => '📥 Установка приложения',
                        'desc'  => 'Выберите подходящую версию для вашего устройства 💻, нажмите на кнопку ниже и установите приложение.',
                        'btns'  => [
                            ['text' => 'Windows', 'href' => 'https://github.com/Happ-proxy/happ-desktop/releases/latest/download/setup-Happ.x64.exe', 'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'cloud',
                        'title' => '🔗 Добавление подписки',
                        'desc'  => 'Нажмите кнопку ниже 👇 — приложение откроется, и подписка добавится автоматически ✨',
                        'btns'  => [['text' => 'Добавить подписку', 'type' => 'sub']],
                    ],
                    [
                        'icon'  => 'check',
                        'title' => '🌐 Подключение и использование',
                        'desc'  => 'В главном разделе нажмите большую кнопку включения в центре 🔘 для подключения к VPN 🔒. Не забудьте выбрать сервер 🌍 в списке серверов. При необходимости выберите другой сервер из списка.',
                        'btns'  => [],
                    ],
                ],
            ],

            'android' => [
                'label' => '🤖 Android',
                'steps' => [
                    [
                        'icon'  => 'download',
                        'title' => '📥 Установка приложения',
                        'desc'  => 'Откройте страницу в Google Play 🛒 и установите приложение. Или установите из APK файла 📦 напрямую, если Google Play не работает.',
                        'btns'  => [
                            ['text' => '▶️ Открыть в Google Play', 'href' => 'https://play.google.com/store/apps/details?id=com.happproxy', 'type' => 'ext'],
                            ['text' => '📥 Скачать APK',           'href' => 'https://github.com/Happ-proxy/happ-android/releases/latest/download/Happ.apk', 'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'cloud',
                        'title' => '🔗 Добавление подписки',
                        'desc'  => 'Нажмите кнопку ниже 👇, чтобы добавить подписку ✨',
                        'btns'  => [['text' => 'Добавить подписку', 'type' => 'sub']],
                    ],
                    [
                        'icon'  => 'check',
                        'title' => '🌐 Подключение и использование',
                        'desc'  => 'Откройте приложение 📱 и подключитесь к серверу 🔒',
                        'btns'  => [],
                    ],
                ],
            ],

            'ios' => [
                'label' => '🍎 iOS',
                'steps' => [
                    [
                        'icon'  => 'download',
                        'title' => '📥 Установка приложения',
                        'desc'  => 'Откройте страницу в App Store 🛍️ и установите приложение. Запустите его, в окне разрешения VPN-конфигурации нажмите Allow ✅ и введите свой пароль 🔐',
                        'btns'  => [
                            ['text' => '🍎 App Store (RU)',     'href' => 'https://apps.apple.com/ru/app/happ-proxy-utility-plus/id6746188973', 'type' => 'ext'],
                            ['text' => '🌍 App Store (Global)', 'href' => 'https://apps.apple.com/us/app/happ-proxy-utility/id6504287215',       'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'cloud',
                        'title' => '🔗 Добавление подписки',
                        'desc'  => 'Нажмите кнопку ниже 👇 — приложение откроется, и подписка добавится автоматически ✨',
                        'btns'  => [['text' => 'Добавить подписку', 'type' => 'sub']],
                    ],
                    [
                        'icon'  => 'check',
                        'title' => '🌐 Подключение и использование',
                        'desc'  => 'В главном разделе нажмите большую кнопку включения в центре 🔘 для подключения к VPN 🔒. Не забудьте выбрать сервер 🌍 в списке серверов. При необходимости выберите другой сервер из списка.',
                        'btns'  => [],
                    ],
                ],
            ],

            'macos' => [
                'label' => '🍏 macOS',
                'steps' => [
                    [
                        'icon'  => 'download',
                        'title' => '📥 Установка приложения',
                        'desc'  => 'Выберите подходящую версию для вашего устройства 💻, нажмите на кнопку ниже и установите приложение.',
                        'btns'  => [
                            ['text' => '🍎 App Store (RU)',     'href' => 'https://apps.apple.com/ru/app/happ-proxy-utility-plus/id6746188973', 'type' => 'ext'],
                            ['text' => '🌍 App Store (Global)', 'href' => 'https://apps.apple.com/us/app/happ-proxy-utility/id6504287215',       'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'cloud',
                        'title' => '🔗 Добавление подписки',
                        'desc'  => 'Нажмите кнопку ниже 👇 — приложение откроется, и подписка добавится автоматически ✨',
                        'btns'  => [['text' => 'Добавить подписку', 'type' => 'sub']],
                    ],
                    [
                        'icon'  => 'check',
                        'title' => '🌐 Подключение и использование',
                        'desc'  => 'В главном разделе нажмите большую кнопку включения в центре 🔘 для подключения к VPN 🔒. Не забудьте выбрать сервер 🌍 в списке серверов. При необходимости выберите другой сервер из списка.',
                        'btns'  => [],
                    ],
                ],
            ],

            'linux' => [
                'label' => '🐧 Linux',
                'steps' => [
                    [
                        'icon'  => 'download',
                        'title' => '📥 Установка приложения',
                        'desc'  => 'Выберите подходящую версию для вашего дистрибутива 🐧, нажмите на кнопку ниже и установите приложение.',
                        'btns'  => [
                            ['text' => '📦 deb', 'href' => 'https://github.com/Happ-proxy/happ-desktop/releases/latest/download/Happ.linux.x64.deb',         'type' => 'ext'],
                            ['text' => '📦 rpm', 'href' => 'https://github.com/Happ-proxy/happ-desktop/releases/latest/download/Happ.linux.x64.rpm',         'type' => 'ext'],
                            ['text' => '📦 pkg', 'href' => 'https://github.com/Happ-proxy/happ-desktop/releases/latest/download/Happ.linux.x64.pkg.tar.zst', 'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'cloud',
                        'title' => '🔗 Добавление подписки',
                        'desc'  => 'Нажмите кнопку ниже 👇 — приложение откроется, и подписка добавится автоматически ✨',
                        'btns'  => [['text' => 'Добавить подписку', 'type' => 'sub']],
                    ],
                    [
                        'icon'  => 'check',
                        'title' => '🌐 Подключение и использование',
                        'desc'  => 'В главном разделе нажмите большую кнопку включения в центре 🔘 для подключения к VPN 🔒. Не забудьте выбрать сервер 🌍 в списке серверов. При необходимости выберите другой сервер из списка.',
                        'btns'  => [],
                    ],
                ],
            ],

            'appletv' => [
                'label' => '📺 Apple TV',
                'steps' => [
                    [
                        'icon'  => 'download',
                        'title' => '📥 Установка приложения',
                        'desc'  => 'Откройте страницу в App Store 🛍️ на Apple TV и установите приложение. Запустите его, предоставьте разрешение на VPN-конфигурацию ✅, если потребуется, и введите свой пароль 🔐',
                        'btns'  => [
                            ['text' => '🍎 App Store', 'href' => 'https://apps.apple.com/us/app/happ-proxy-utility-for-tv/id6748297274', 'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'gear',
                        'title' => '📖 Инструкции по установке',
                        'desc'  => 'Подробные инструкции 📋, чтобы помочь вам настроить Happ на вашем устройстве.',
                        'btns'  => [
                            ['text' => '🇷🇺 На русском',   'href' => 'https://www.happ.su/main/ru/faq/android-tv', 'type' => 'ext'],
                            ['text' => '🇬🇧 На английском', 'href' => 'https://www.happ.su/main/faq/android-tv',    'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'cloud',
                        'title' => '🔗 Добавление подписки',
                        'desc'  => 'Нажмите кнопку ниже 👇, чтобы добавить подписку, если вы открыли страницу подписки на телевизоре 📺',
                        'btns'  => [['text' => 'Добавить подписку', 'type' => 'sub']],
                    ],
                    [
                        'icon'  => 'check',
                        'title' => '🌐 Подключение и использование',
                        'desc'  => 'Откройте приложение 📺 и подключитесь к серверу 🔒',
                        'btns'  => [],
                    ],
                ],
            ],

            'androidtv' => [
                'label' => '📺 Android TV',
                'steps' => [
                    [
                        'icon'  => 'download',
                        'title' => '📥 Установка приложения',
                        'desc'  => 'Откройте страницу в Google Play 🛒 и установите приложение. Или установите из APK файла 📦 напрямую, если Google Play не работает.',
                        'btns'  => [
                            ['text' => '▶️ Открыть в Google Play', 'href' => 'https://play.google.com/store/apps/details?id=com.happproxy', 'type' => 'ext'],
                            ['text' => '📥 Скачать APK',           'href' => 'https://github.com/Happ-proxy/happ-android/releases/latest/download/Happ.apk', 'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'gear',
                        'title' => '📖 Инструкции по установке',
                        'desc'  => 'Подробные инструкции 📋, чтобы помочь вам настроить Happ на вашем устройстве.',
                        'btns'  => [
                            ['text' => '🇷🇺 На русском',   'href' => 'https://www.happ.su/main/ru/faq/android-tv', 'type' => 'ext'],
                            ['text' => '🇬🇧 На английском', 'href' => 'https://www.happ.su/main/faq/android-tv',    'type' => 'ext'],
                        ],
                    ],
                    [
                        'icon'  => 'cloud',
                        'title' => '🔗 Добавление подписки',
                        'desc'  => 'Нажмите кнопку ниже 👇, чтобы добавить подписку, если вы открыли страницу подписки на телевизоре 📺',
                        'btns'  => [['text' => 'Добавить подписку', 'type' => 'sub']],
                    ],
                    [
                        'icon'  => 'check',
                        'title' => '🌐 Подключение и использование',
                        'desc'  => 'Откройте приложение 📺 и подключитесь к серверу 🔒',
                        'btns'  => [],
                    ],
                ],
            ],
        ],
    ],

    // Happ debug page (?happ)
    'happ_debug' => [
        'title'           => '🛠️ Happ Debug View',
        'label_status'    => '📶 Статус',
        'label_time'      => '⏱️ Время',
        'label_url'       => '🔗 URL',
        'tab_headers'     => '📋 Заголовки ответа',
        'tab_raw_req'     => '📤 Raw Request',
        'tab_raw_resp'    => '📥 Raw Response',
        'label_ms'        => 'мс',
        'wl_title'        => '🔒 Белый список (WL)',
        'wl_not_found'    => '❌ Подписка _WL не найдена',
    ],
];
