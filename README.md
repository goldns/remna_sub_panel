# remna_sub_panel

PHP пользовательская панель для подписок [Remnawave](https://github.com/remnawave).

Открывается в браузере — показывает карточку с информацией о подписке (трафик, срок, устройства).  
Открывается в Happ — проксирует подписку с поддержкой HWID, слияния с WL подпиской(отдельный пользователь с префиксом _WL) и кастомных заголовков.

## Возможности

- Браузерная панель: трафик, срок, статус, HWID-устройства, белые списки
- Проксирование подписки для Happ с фильтрацией и переопределением заголовков
- Слияние основной подписки и WL-подписки (`{uuid}_WL`) в один ответ
- Шифрование ссылок через [crypto.happ.su](https://crypto.happ.su)
- Debug-панель для диагностики (доступна только с заданного IP)
- Поддержка Apache (`.htaccess`) и Nginx

## Требования

- PHP **8.1+** с расширением `ext-curl`
- Apache или Nginx
- Доступ к панели [Remnawave](https://github.com/remnawave)

## Установка

### 1. Скопируй файлы на сервер

```bash
git clone https://github.com/goldns/remna_sub_panel.git /var/www/sub
```

### 2. Создай конфиг

```bash
cp config.php.example config.php
```

Открой `config.php` и заполни обязательные поля:

```php
'remnawave_url' => 'https://your-remnawave-panel.com',
'api_token'     => 'ваш_api_токен',  // Remnawave → Settings → API Tokens
```

### 3. Настрой веб-сервер

**Nginx** — отредактируй `nginx.conf`, замени `server_name` и путь `root`, затем подключи:

```bash
cp nginx.conf /etc/nginx/sites-available/sub
ln -s /etc/nginx/sites-available/sub /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

> Путь к сокету PHP-FPM по умолчанию: `unix:/run/php/php8.4-fpm.sock`  
> Для другой версии PHP замени на `php8.x-fpm.sock`

**Apache** — `.htaccess` уже лежит в корне, mod_rewrite должен быть включён:

```bash
a2enmod rewrite
systemctl reload apache2
```

## Конфигурация

Все настройки — в файле `config.php`. Основные параметры:

| Параметр | Описание |
|---|---|
| `remnawave_url` | URL панели Remnawave (без слеша в конце) |
| `api_token` | API-токен из Remnawave Dashboard |
| `project_name` | Название в шапке страницы (`null` = скрыть) |
| `show_qr` | Показывать кнопку QR-кода |
| `copyright` | Текст копирайта в футере (`{year}` = текущий год) |
| `encrypt_sub_link` | Шифровать deeplink через crypto.happ.su |
| `support_url` | Ссылка на поддержку (Telegram и др.) |
| `lang` | Язык интерфейса (`ru`) |
| `debug_ip` | IP для доступа к debug-панели (пусто = отключено) |

### Переопределение заголовков Happ

Параметры `profile_title`, `support_url`, `announce`, `profile_update_interval`, `content_disposition_name`:
- `null` — пропустить значение Remnawave без изменений
- `'строка'` — заменить своим значением
- `''` — удалить заголовок полностью

### Кастомные заголовки

```php
'custom_headers' => [
    'ping-type'    => 'proxy',
    'hide-settings'=> 1,
    // ...
],
```

Отправляются **только Happ-клиентам**, браузер их не получает.

## Структура URL

```
https://your-domain.com/{shortUuid}
```

- Браузер → панель пользователя
- Happ + X-HWID → подписка (JSON)
- Happ без X-HWID → 403

## Лицензия

MIT
