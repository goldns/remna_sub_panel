<?php
declare(strict_types=1);

/**
 * @brief Defines persistent subscription-cache and HWID-deletion audit operations independently of SQL dialect.
 */
interface StorageRepositoryInterface
{
    /**
     * @brief Returns a fresh normalized subscription state for one hashed subscription and account role.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     * @param maxAge Maximum accepted state age in seconds.
     * @return Normalized state or null when no fresh record exists.
     */
    public function getSubscriptionState(string $subscriptionHash, string $role, int $maxAge): ?array;

    /**
     * @brief Creates or replaces the normalized state obtained from a successful subscription info response.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     * @param user Normalized public user fields from the info response.
     * @param responseHeaders Normalized upstream headers; only the persistent safe allowlist is stored.
     */
    public function saveSubscriptionState(string $subscriptionHash, string $role, array $user, array $responseHeaders = []): void;

    /**
     * @brief Updates device-related fields after a successful user/HWID API refresh.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     * @param userId Numeric upstream user identifier.
     * @param hwidLimit Configured device limit or null for unlimited/unknown.
     * @param hwidCount Current number of registered devices.
     */
    public function updateSubscriptionDevices(string $subscriptionHash, string $role, int $userId, ?int $hwidLimit, int $hwidCount): void;

    /**
     * @brief Records the time of an attempted upstream refresh without extending cached-state freshness.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     */
    public function markSubscriptionChecked(string $subscriptionHash, string $role): void;

    /**
     * @brief Calculates remaining rolling deletion quotas for one API username.
     * @param username Exact API username used as the quota identity.
     * @param limits Non-negative day, week, and month limits; zero means unlimited.
     * @param isAdmin Whether the trusted debug address bypasses all quotas.
     * @return Quota state containing allowed, unlimited, counts, limits, and remaining values.
     */
    public function getDeletionQuota(string $username, array $limits, bool $isAdmin): array;

    /**
     * @brief Atomically checks quotas, reserves a concurrency slot, and records the deletion attempt.
     * @param event Sanitized deletion metadata including identity, target, and request attributes.
     * @param limits Non-negative day, week, and month limits; zero means unlimited.
     * @param isAdmin Whether the trusted debug address bypasses all quotas.
     * @return Reservation result containing allowed, event_id, and quota state.
     */
    public function reserveDeletion(array $event, array $limits, bool $isAdmin): array;

    /**
     * @brief Finalizes a reserved deletion and returns the updated rolling quota state.
     * @param eventId Positive deletion-event identifier returned by reserveDeletion().
     * @param success Whether upstream confirmed the deletion.
     * @param upstreamCode Upstream HTTP status, or zero for a transport failure.
     * @param errorCategory Non-sensitive result category; empty for success.
     * @param device Sanitized metadata describing the deleted device when available.
     * @param limits Non-negative day, week, and month limits; zero means unlimited.
     * @return Updated quota state for the event username.
     */
    public function finishDeletion(int $eventId, bool $success, int $upstreamCode, string $errorCategory, array $device, array $limits): array;

    /**
     * @brief Returns unresolved deletion reservations old enough to reconcile against upstream device state.
     * @param username Exact API username used as the quota identity.
     * @param olderThan Inclusive Unix timestamp threshold for the reservation creation time.
     * @return Pending event records with decoded authoritative device metadata.
     */
    public function getPendingDeletions(string $username, int $olderThan): array;
}

/**
 * @brief Implements persistent caching and atomic HWID-deletion accounting with a protected SQLite database.
 */
final class SqliteStorageRepository implements StorageRepositoryInterface
{
    private PDO $pdo;

    /**
     * @brief Opens the configured SQLite database, applies safe pragmas, and migrates its schema.
     * @param absolutePath Validated absolute path located below the project data directory.
     */
    public function __construct(string $absolutePath)
    {
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the storage directory');
        }

        $previousUmask = DIRECTORY_SEPARATOR === '/' ? umask(0077) : null;
        try {
            $this->pdo = new PDO('sqlite:' . $absolutePath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->migrate();
            $this->synchronizePersonalFieldPolicy();
            if (DIRECTORY_SEPARATOR === '/') {
                foreach ([$absolutePath, $absolutePath . '-wal', $absolutePath . '-shm'] as $storageFile) {
                    if (is_file($storageFile) && !chmod($storageFile, 0600)) {
                        throw new RuntimeException('Unable to restrict storage file permissions');
                    }
                }
            }
        } finally {
            if (is_int($previousUmask)) umask($previousUmask);
        }
    }

    /**
     * @brief Applies sequential schema migrations and refuses databases created by a newer application version.
     */
    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_meta ('
            . 'meta_key TEXT PRIMARY KEY, meta_value TEXT NOT NULL)'
        );
        $statement = $this->pdo->prepare('SELECT meta_value FROM schema_meta WHERE meta_key = :key');
        $statement->execute(['key' => 'schema_version']);
        $storedVersion = $statement->fetchColumn();
        $version = $storedVersion === false ? 0 : filter_var($storedVersion, FILTER_VALIDATE_INT);
        if ($version === false || $version < 0 || $version > 3) {
            throw new UnexpectedValueException('Unsupported storage schema version');
        }
        while ($version < 3) {
            if ($version === 0) {
                $this->migrateZeroToOne();
                $version = 1;
                continue;
            }
            if ($version === 1) {
                $this->migrateOneToTwo();
                $version = 2;
                continue;
            }
            if ($version === 2) {
                $this->migrateTwoToThree();
                $version = 3;
            }
        }
    }

    /**
     * @brief Creates the legacy schema as the first deterministic migration step.
     */
    private function migrateZeroToOne(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS subscription_states ('
                . 'subscription_hash TEXT NOT NULL, role TEXT NOT NULL, username TEXT NOT NULL, '
                . 'user_id INTEGER NULL, user_status TEXT NOT NULL, expires_at TEXT NOT NULL, days_left INTEGER NOT NULL, '
                . 'traffic_used TEXT NOT NULL, traffic_used_bytes INTEGER NOT NULL, traffic_limit TEXT NOT NULL, '
                . 'traffic_limit_bytes INTEGER NOT NULL, hwid_limit INTEGER NULL, hwid_count INTEGER NULL, '
                . 'user_data_json TEXT NOT NULL, last_success_at INTEGER NOT NULL, last_checked_at INTEGER NOT NULL, '
                . 'PRIMARY KEY (subscription_hash, role))'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_subscription_states_username '
                . 'ON subscription_states(username)'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS hwid_deletion_events ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, user_id INTEGER NULL, '
                . 'subscription_hash TEXT NOT NULL, account_role TEXT NOT NULL, hwid TEXT NOT NULL, '
                . 'device_json TEXT NOT NULL, request_ip TEXT NOT NULL, request_user_agent TEXT NOT NULL, '
                . 'requested_at INTEGER NOT NULL, reservation_expires_at INTEGER NULL, is_admin INTEGER NOT NULL, '
                . 'outcome TEXT NOT NULL, error_category TEXT NOT NULL, upstream_http_code INTEGER NOT NULL, '
                . 'completed_at INTEGER NULL)'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_hwid_events_quota '
                . 'ON hwid_deletion_events(username, outcome, requested_at)'
            );
            $statement = $this->pdo->prepare(
                'INSERT INTO schema_meta(meta_key, meta_value) VALUES(:key, :value) '
                . 'ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value'
            );
            $statement->execute(['key' => 'schema_version', 'value' => '1']);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * @brief Migrates hash-keyed cache rows to canonical username accounts while retaining all aliases and events.
     */
    private function migrateOneToTwo(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(
                'CREATE TABLE subscription_accounts_v2 ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, role TEXT NOT NULL, '
                . 'user_id INTEGER NULL, user_status TEXT NOT NULL, expires_at TEXT NOT NULL, days_left INTEGER NOT NULL, '
                . 'traffic_used TEXT NOT NULL, traffic_used_bytes INTEGER NOT NULL, traffic_limit TEXT NOT NULL, '
                . 'traffic_limit_bytes INTEGER NOT NULL, hwid_limit INTEGER NULL, hwid_count INTEGER NULL, '
                . 'user_data_json TEXT NOT NULL, last_success_at INTEGER NOT NULL, last_checked_at INTEGER NOT NULL, '
                . 'UNIQUE(username, role))'
            );
            $this->pdo->exec(
                'CREATE TABLE subscription_aliases_v2 ('
                . 'subscription_hash TEXT NOT NULL, role TEXT NOT NULL, account_id INTEGER NOT NULL, '
                . 'PRIMARY KEY(subscription_hash, role), '
                . 'FOREIGN KEY(account_id) REFERENCES subscription_accounts_v2(id) ON DELETE CASCADE)'
            );

            $rows = $this->pdo->query(
                'SELECT * FROM subscription_states ORDER BY username, role, last_success_at DESC, last_checked_at DESC'
            )->fetchAll();
            $groups = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) continue;
                $key = (string) $row['username'] . "\0" . normalizeAccountRole((string) $row['role']);
                if (!isset($groups[$key])) {
                    $groups[$key] = ['state' => $row, 'device' => null, 'aliases' => []];
                }
                $groups[$key]['aliases'][(string) $row['subscription_hash']] = true;
                $hasDeviceState = $row['user_id'] !== null || $row['hwid_limit'] !== null || $row['hwid_count'] !== null;
                if ($hasDeviceState && ($groups[$key]['device'] === null
                    || (int) $row['last_checked_at'] > (int) $groups[$key]['device']['last_checked_at'])) {
                    $groups[$key]['device'] = $row;
                }
            }

            $insertAccount = $this->pdo->prepare(
                'INSERT INTO subscription_accounts_v2('
                . 'username, role, user_id, user_status, expires_at, days_left, traffic_used, traffic_used_bytes, '
                . 'traffic_limit, traffic_limit_bytes, hwid_limit, hwid_count, user_data_json, last_success_at, last_checked_at'
                . ') VALUES('
                . ':username, :role, :user_id, :user_status, :expires_at, :days_left, :traffic_used, :traffic_used_bytes, '
                . ':traffic_limit, :traffic_limit_bytes, :hwid_limit, :hwid_count, :user_data_json, :last_success_at, :last_checked_at)'
            );
            $insertAlias = $this->pdo->prepare(
                'INSERT INTO subscription_aliases_v2(subscription_hash, role, account_id) '
                . 'VALUES(:subscription_hash, :role, :account_id)'
            );
            foreach ($groups as $group) {
                $state = $group['state'];
                $device = is_array($group['device']) ? $group['device'] : $state;
                $insertAccount->execute([
                    'username' => (string) $state['username'],
                    'role' => normalizeAccountRole((string) $state['role']),
                    'user_id' => $device['user_id'],
                    'user_status' => (string) $state['user_status'],
                    'expires_at' => (string) $state['expires_at'],
                    'days_left' => (int) $state['days_left'],
                    'traffic_used' => (string) $state['traffic_used'],
                    'traffic_used_bytes' => (int) $state['traffic_used_bytes'],
                    'traffic_limit' => (string) $state['traffic_limit'],
                    'traffic_limit_bytes' => (int) $state['traffic_limit_bytes'],
                    'hwid_limit' => $device['hwid_limit'],
                    'hwid_count' => $device['hwid_count'],
                    'user_data_json' => (string) $state['user_data_json'],
                    'last_success_at' => (int) $state['last_success_at'],
                    'last_checked_at' => max((int) $state['last_checked_at'], (int) $device['last_checked_at']),
                ]);
                $accountId = (int) $this->pdo->lastInsertId();
                foreach (array_keys($group['aliases']) as $aliasHash) {
                    $insertAlias->execute([
                        'subscription_hash' => $aliasHash,
                        'role' => normalizeAccountRole((string) $state['role']),
                        'account_id' => $accountId,
                    ]);
                }
            }

            $this->pdo->exec("UPDATE hwid_deletion_events SET reservation_expires_at = NULL WHERE outcome = 'pending'");
            $this->pdo->exec('DROP TABLE subscription_states');
            $this->pdo->exec('ALTER TABLE subscription_accounts_v2 RENAME TO subscription_accounts');
            $this->pdo->exec('ALTER TABLE subscription_aliases_v2 RENAME TO subscription_aliases');
            $this->pdo->exec('CREATE INDEX idx_subscription_accounts_username ON subscription_accounts(username)');
            $this->pdo->exec('CREATE INDEX idx_subscription_aliases_account ON subscription_aliases(account_id)');
            $statement = $this->pdo->prepare(
                'INSERT INTO schema_meta(meta_key, meta_value) VALUES(:key, :value) '
                . 'ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value'
            );
            $statement->execute(['key' => 'schema_version', 'value' => '2']);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * @brief Adds the safe cached response-header payload required for source-independent info responses.
     */
    private function migrateTwoToThree(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(
                "ALTER TABLE subscription_accounts ADD COLUMN response_headers_json TEXT NOT NULL DEFAULT '{}'"
            );
            $rows = $this->pdo->query('SELECT id, user_data_json FROM subscription_accounts')->fetchAll();
            $updateUserData = $this->pdo->prepare(
                'UPDATE subscription_accounts SET user_data_json = :user_data_json WHERE id = :id'
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) continue;
                $user = json_decode((string) $row['user_data_json'], true);
                if (!is_array($user)) $user = [];
                $updateUserData->execute([
                    'user_data_json' => json_encode(
                        normalizeSubscriptionUserData($user),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'id' => (int) $row['id'],
                ]);
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO schema_meta(meta_key, meta_value) VALUES(:key, :value) '
                . 'ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value'
            );
            $statement->execute(['key' => 'schema_version', 'value' => '3']);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * @brief Rewrites cached user JSON only when the configured optional-personal-field policy changes.
     */
    private function synchronizePersonalFieldPolicy(): void
    {
        $config = is_array($GLOBALS['__storage_config'] ?? null) ? $GLOBALS['__storage_config'] : [];
        $policy = implode(',', array_keys(configuredPersistentPersonalFields($config)));
        $statement = $this->pdo->prepare('SELECT meta_value FROM schema_meta WHERE meta_key = :key');
        $statement->execute(['key' => 'personal_fields_policy']);
        if ($statement->fetchColumn() === $policy) return;

        $this->pdo->beginTransaction();
        try {
            $rows = $this->pdo->query('SELECT id, user_data_json FROM subscription_accounts')->fetchAll();
            $update = $this->pdo->prepare(
                'UPDATE subscription_accounts SET user_data_json = :user_data_json WHERE id = :id'
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) continue;
                $user = json_decode((string) $row['user_data_json'], true);
                $update->execute([
                    'user_data_json' => json_encode(
                        normalizeSubscriptionUserData(is_array($user) ? $user : []),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'id' => (int) $row['id'],
                ]);
            }
            $savePolicy = $this->pdo->prepare(
                'INSERT INTO schema_meta(meta_key, meta_value) VALUES(:key, :value) '
                . 'ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value'
            );
            $savePolicy->execute(['key' => 'personal_fields_policy', 'value' => $policy]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * @brief Returns a fresh normalized subscription state for one hashed subscription and account role.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     * @param maxAge Maximum accepted state age in seconds.
     * @return Normalized state or null when no fresh record exists.
     */
    public function getSubscriptionState(string $subscriptionHash, string $role, int $maxAge): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.* FROM subscription_aliases AS x '
            . 'JOIN subscription_accounts AS a ON a.id = x.account_id '
            . 'WHERE x.subscription_hash = :subscription_hash AND x.role = :role '
            . 'AND a.last_success_at >= :fresh_after'
        );
        $statement->execute([
            'subscription_hash' => $subscriptionHash,
            'role'              => $role,
            'fresh_after'       => time() - max(1, $maxAge),
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) return null;

        $user = json_decode((string) $row['user_data_json'], true);
        if (!is_array($user)) return null;
        $cachedHeaders = json_decode((string) ($row['response_headers_json'] ?? '{}'), true);
        if ($row['user_id'] !== null) $user['id'] = (int) $row['user_id'];
        if ($row['hwid_limit'] !== null) $user['hwidDeviceLimit'] = (int) $row['hwid_limit'];
        if ($row['hwid_count'] !== null) $user['hwidDeviceCount'] = (int) $row['hwid_count'];

        return [
            'user'            => $user,
            'headers'         => normalizeCachedInfoHeaders(is_array($cachedHeaders) ? $cachedHeaders : []),
            'last_success_at' => (int) $row['last_success_at'],
            'last_checked_at' => (int) $row['last_checked_at'],
        ];
    }

    /**
     * @brief Creates or replaces the normalized state obtained from a successful subscription info response.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     * @param user Normalized public user fields from the info response.
     * @param responseHeaders Normalized upstream headers; only the persistent safe allowlist is stored.
     */
    public function saveSubscriptionState(string $subscriptionHash, string $role, array $user, array $responseHeaders = []): void
    {
        $now = time();
        $userData = normalizeSubscriptionUserData($user);
        $normalizedRole = normalizeAccountRole($role);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
            'INSERT INTO subscription_accounts('
            . 'username, role, user_id, user_status, expires_at, days_left, '
            . 'traffic_used, traffic_used_bytes, traffic_limit, traffic_limit_bytes, hwid_limit, hwid_count, '
            . 'user_data_json, response_headers_json, last_success_at, last_checked_at) VALUES('
            . ':username, :role, :user_id, :user_status, :expires_at, :days_left, '
            . ':traffic_used, :traffic_used_bytes, :traffic_limit, :traffic_limit_bytes, NULL, NULL, '
            . ':user_data_json, :response_headers_json, :last_success_at, :last_checked_at) '
            . 'ON CONFLICT(username, role) DO UPDATE SET '
            . 'user_id = COALESCE(excluded.user_id, subscription_accounts.user_id), '
            . 'user_status = excluded.user_status, expires_at = excluded.expires_at, days_left = excluded.days_left, '
            . 'traffic_used = excluded.traffic_used, traffic_used_bytes = excluded.traffic_used_bytes, '
            . 'traffic_limit = excluded.traffic_limit, traffic_limit_bytes = excluded.traffic_limit_bytes, '
            . 'user_data_json = excluded.user_data_json, response_headers_json = excluded.response_headers_json, '
            . 'last_success_at = excluded.last_success_at, '
            . 'last_checked_at = excluded.last_checked_at'
            );
            $statement->execute([
                'role'               => $normalizedRole,
                'username'           => (string) $userData['username'],
                'user_id'            => is_int($userData['id'] ?? null) ? $userData['id'] : null,
                'user_status'        => (string) $userData['userStatus'],
                'expires_at'         => (string) $userData['expiresAt'],
                'days_left'          => (int) $userData['daysLeft'],
                'traffic_used'       => (string) $userData['trafficUsed'],
                'traffic_used_bytes' => (int) $userData['trafficUsedBytes'],
                'traffic_limit'      => (string) $userData['trafficLimit'],
                'traffic_limit_bytes'=> (int) $userData['trafficLimitBytes'],
                'user_data_json'     => json_encode($userData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'response_headers_json' => json_encode(normalizeCachedInfoHeaders($responseHeaders), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'last_success_at'    => $now,
                'last_checked_at'    => $now,
            ]);
            $lookup = $this->pdo->prepare(
                'SELECT id FROM subscription_accounts WHERE username = :username AND role = :role'
            );
            $lookup->execute(['username' => (string) $userData['username'], 'role' => $normalizedRole]);
            $accountId = (int) $lookup->fetchColumn();
            if ($accountId <= 0) throw new RuntimeException('Canonical subscription account was not found');
            $alias = $this->pdo->prepare(
                'INSERT INTO subscription_aliases(subscription_hash, role, account_id) '
                . 'VALUES(:subscription_hash, :role, :account_id) '
                . 'ON CONFLICT(subscription_hash, role) DO UPDATE SET account_id = excluded.account_id'
            );
            $alias->execute([
                'subscription_hash' => $subscriptionHash,
                'role' => $normalizedRole,
                'account_id' => $accountId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * @brief Updates device-related fields after a successful user/HWID API refresh.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     * @param userId Numeric upstream user identifier.
     * @param hwidLimit Configured device limit or null for unlimited/unknown.
     * @param hwidCount Current number of registered devices.
     */
    public function updateSubscriptionDevices(string $subscriptionHash, string $role, int $userId, ?int $hwidLimit, int $hwidCount): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE subscription_accounts SET user_id = :user_id, hwid_limit = :hwid_limit, '
            . 'hwid_count = :hwid_count, last_checked_at = :now '
            . 'WHERE id = (SELECT account_id FROM subscription_aliases '
            . 'WHERE subscription_hash = :subscription_hash AND role = :role)'
        );
        $statement->execute([
            'user_id'           => $userId,
            'hwid_limit'        => $hwidLimit,
            'hwid_count'        => max(0, $hwidCount),
            'now'               => time(),
            'subscription_hash' => $subscriptionHash,
            'role'              => normalizeAccountRole($role),
        ]);
    }

    /**
     * @brief Records the time of an attempted upstream refresh without extending cached-state freshness.
     * @param subscriptionHash SHA-256 hash of the effective shortUuid.
     * @param role Account role, either "main" or "wl".
     */
    public function markSubscriptionChecked(string $subscriptionHash, string $role): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE subscription_accounts SET last_checked_at = :now '
            . 'WHERE id = (SELECT account_id FROM subscription_aliases '
            . 'WHERE subscription_hash = :subscription_hash AND role = :role)'
        );
        $statement->execute([
            'now'               => time(),
            'subscription_hash' => $subscriptionHash,
            'role'              => normalizeAccountRole($role),
        ]);
    }

    /**
     * @brief Calculates remaining rolling deletion quotas for one API username.
     * @param username Exact API username used as the quota identity.
     * @param limits Non-negative day, week, and month limits; zero means unlimited.
     * @param isAdmin Whether the trusted debug address bypasses all quotas.
     * @return Quota state containing allowed, unlimited, counts, limits, and remaining values.
     */
    public function getDeletionQuota(string $username, array $limits, bool $isAdmin): array
    {
        return $this->calculateQuota($username, normalizeDeletionLimits($limits), $isAdmin, time());
    }

    /**
     * @brief Atomically checks quotas, reserves a concurrency slot, and records the deletion attempt.
     * @param event Sanitized deletion metadata including identity, target, and request attributes.
     * @param limits Non-negative day, week, and month limits; zero means unlimited.
     * @param isAdmin Whether the trusted debug address bypasses all quotas.
     * @return Reservation result containing allowed, event_id, and quota state.
     */
    public function reserveDeletion(array $event, array $limits, bool $isAdmin): array
    {
        $normalizedLimits = normalizeDeletionLimits($limits);
        $now = time();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $quota = $this->calculateQuota((string) $event['username'], $normalizedLimits, $isAdmin, $now);
            $outcome = $quota['allowed'] ? 'pending' : 'rejected_limit';
            $statement = $this->pdo->prepare(
                'INSERT INTO hwid_deletion_events('
                . 'username, user_id, subscription_hash, account_role, hwid, device_json, request_ip, '
                . 'request_user_agent, requested_at, reservation_expires_at, is_admin, outcome, error_category, '
                . 'upstream_http_code, completed_at) VALUES('
                . ':username, :user_id, :subscription_hash, :account_role, :hwid, :device_json, :request_ip, '
                . ':request_user_agent, :requested_at, :reservation_expires_at, :is_admin, :outcome, '
                . ':error_category, 0, :completed_at)'
            );
            $statement->execute([
                'username'               => (string) $event['username'],
                'user_id'                => is_int($event['user_id'] ?? null) ? $event['user_id'] : null,
                'subscription_hash'      => (string) $event['subscription_hash'],
                'account_role'           => normalizeAccountRole((string) $event['account_role']),
                'hwid'                   => (string) $event['hwid'],
                'device_json'            => json_encode(
                    normalizeDeviceAuditData(is_array($event['device'] ?? null) ? $event['device'] : []),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'request_ip'             => (string) $event['request_ip'],
                'request_user_agent'     => (string) $event['request_user_agent'],
                'requested_at'           => $now,
                'reservation_expires_at' => null,
                'is_admin'               => $isAdmin ? 1 : 0,
                'outcome'                => $outcome,
                'error_category'         => $quota['allowed'] ? '' : 'limit_exceeded',
                'completed_at'           => $quota['allowed'] ? null : $now,
            ]);
            $eventId = (int) $this->pdo->lastInsertId();
            $after = $this->calculateQuota((string) $event['username'], $normalizedLimits, $isAdmin, $now);
            $this->pdo->exec('COMMIT');
            return ['allowed' => (bool) $quota['allowed'], 'event_id' => $eventId, 'quota' => $after];
        } catch (Throwable $error) {
            $this->rollbackImmediateTransaction();
            throw $error;
        }
    }

    /**
     * @brief Finalizes a reserved deletion and returns the updated rolling quota state.
     * @param eventId Positive deletion-event identifier returned by reserveDeletion().
     * @param success Whether upstream confirmed the deletion.
     * @param upstreamCode Upstream HTTP status, or zero for a transport failure.
     * @param errorCategory Non-sensitive result category; empty for success.
     * @param device Sanitized metadata describing the deleted device when available.
     * @param limits Non-negative day, week, and month limits; zero means unlimited.
     * @return Updated quota state for the event username.
     */
    public function finishDeletion(int $eventId, bool $success, int $upstreamCode, string $errorCategory, array $device, array $limits): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $select = $this->pdo->prepare('SELECT username, is_admin FROM hwid_deletion_events WHERE id = :id');
            $select->execute(['id' => $eventId]);
            $event = $select->fetch();
            if (!is_array($event)) throw new RuntimeException('Deletion event was not found');

            $statement = $this->pdo->prepare(
                'UPDATE hwid_deletion_events SET outcome = :outcome, error_category = :error_category, '
                . 'upstream_http_code = :upstream_http_code, device_json = :device_json, completed_at = :completed_at, '
                . 'user_id = COALESCE(:user_id, user_id), reservation_expires_at = NULL '
                . 'WHERE id = :id AND outcome = :pending'
            );
            $statement->execute([
                'outcome'            => $success ? 'success' : 'failed',
                'error_category'     => $success ? '' : $errorCategory,
                'upstream_http_code' => $upstreamCode,
                'device_json'        => json_encode(normalizeDeviceAuditData($device), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'completed_at'       => time(),
                'user_id'            => is_int($device['user_id'] ?? null) ? $device['user_id'] : null,
                'id'                 => $eventId,
                'pending'            => 'pending',
            ]);
            $quota = $this->calculateQuota(
                (string) $event['username'],
                normalizeDeletionLimits($limits),
                (int) $event['is_admin'] === 1,
                time()
            );
            $this->pdo->exec('COMMIT');
            return $quota;
        } catch (Throwable $error) {
            $this->rollbackImmediateTransaction();
            throw $error;
        }
    }

    /**
     * @brief Returns unresolved deletion reservations old enough to reconcile against upstream device state.
     * @param username Exact API username used as the quota identity.
     * @param olderThan Inclusive Unix timestamp threshold for the reservation creation time.
     * @return Pending event records with decoded authoritative device metadata.
     */
    public function getPendingDeletions(string $username, int $olderThan): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, subscription_hash, account_role, hwid, device_json, requested_at "
            . "FROM hwid_deletion_events WHERE username = :username AND outcome = 'pending' "
            . 'AND requested_at <= :older_than ORDER BY requested_at, id'
        );
        $statement->execute(['username' => $username, 'older_than' => $olderThan]);
        $pending = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) continue;
            $device = json_decode((string) $row['device_json'], true);
            $pending[] = [
                'event_id' => (int) $row['id'],
                'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'subscription_hash' => (string) $row['subscription_hash'],
                'account_role' => normalizeAccountRole((string) $row['account_role']),
                'hwid' => (string) $row['hwid'],
                'device' => is_array($device) ? $device : [],
                'requested_at' => (int) $row['requested_at'],
            ];
        }
        return $pending;
    }

    /**
     * @brief Rolls back a manually started IMMEDIATE transaction and logs only a generic rollback failure.
     */
    private function rollbackImmediateTransaction(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (Throwable $error) {
            logStorageFailure('transaction_rollback', $error);
        }
    }

    /**
     * @brief Calculates non-admin quota counts inside the connection and includes live pending reservations.
     * @param username Exact API username used as the quota identity.
     * @param limits Normalized non-negative period limits.
     * @param isAdmin Whether quota enforcement is bypassed.
     * @param now Current Unix timestamp used for all rolling-window boundaries.
     * @return Quota state containing allowed, unlimited, counts, limits, and remaining values.
     */
    private function calculateQuota(string $username, array $limits, bool $isAdmin, int $now): array
    {
        $windows = ['day' => 86400, 'week' => 604800, 'month' => 2592000];
        $counts = [];
        $remaining = [];
        $allowed = true;
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM hwid_deletion_events WHERE username = :username AND is_admin = 0 "
            . "AND requested_at >= :cutoff "
            . "AND (outcome = 'success' OR outcome = 'pending')"
        );
        foreach ($windows as $period => $seconds) {
            $statement->execute(['username' => $username, 'cutoff' => $now - $seconds]);
            $counts[$period] = (int) $statement->fetchColumn();
            $limit = $limits[$period];
            $remaining[$period] = $limit === 0 ? null : max(0, $limit - $counts[$period]);
            if (!$isAdmin && $limit > 0 && $counts[$period] >= $limit) $allowed = false;
        }

        return [
            'allowed'   => $isAdmin || $allowed,
            'unlimited' => $isAdmin,
            'limits'    => $limits,
            'counts'    => $counts,
            'remaining' => $remaining,
        ];
    }
}

/**
 * @brief Validates storage configuration and creates the configured repository without exposing its path.
 * @param config Application configuration containing storage_driver and storage_path.
 * @return Ready repository, or null when storage is disabled, invalid, or unavailable.
 */
function createStorageRepository(array $config): ?StorageRepositoryInterface
{
    $GLOBALS['__storage_error'] = '';
    $driver = is_string($config['storage_driver'] ?? null) ? strtolower(trim($config['storage_driver'])) : '';
    if ($driver !== 'sqlite') {
        $GLOBALS['__storage_error'] = 'unsupported_driver';
        logOperationalFailure('storage', 'repository_disabled', ['reason' => 'unsupported_driver']);
        return null;
    }
    if (!extension_loaded('pdo_sqlite')) {
        $GLOBALS['__storage_error'] = 'pdo_sqlite_unavailable';
        logOperationalFailure('storage', 'repository_disabled', ['reason' => 'pdo_sqlite_unavailable']);
        return null;
    }

    $relativePath = is_string($config['storage_path'] ?? null)
        ? str_replace('\\', '/', trim($config['storage_path']))
        : '';
    if (!preg_match('#^data/cache_[0-9]{12,}\.sqlite$#', $relativePath)) {
        $GLOBALS['__storage_error'] = 'invalid_storage_path';
        logOperationalFailure('storage', 'repository_disabled', ['reason' => 'invalid_storage_path']);
        return null;
    }

    try {
        return new SqliteStorageRepository(__DIR__ . '/' . $relativePath);
    } catch (Throwable $error) {
        logStorageFailure('repository_open', $error);
        $GLOBALS['__storage_error'] = 'storage_unavailable';
        return null;
    }
}

/**
 * @brief Returns the request-scoped repository initialized by the application bootstrap.
 * @return Repository instance, or null when persistent storage is unavailable.
 */
function storageRepository(): ?StorageRepositoryInterface
{
    $repository = $GLOBALS['__storage_repository'] ?? null;
    return $repository instanceof StorageRepositoryInterface ? $repository : null;
}

/**
 * @brief Reopens the configured repository after a connection-level failure and replaces the request-scoped instance.
 * @return Fresh repository instance, or null when the configured storage remains unavailable.
 */
function reconnectStorageRepository(): ?StorageRepositoryInterface
{
    $config = $GLOBALS['__storage_config'] ?? null;
    if (!is_array($config)) return null;
    $repository = createStorageRepository($config);
    $GLOBALS['__storage_repository'] = $repository;
    return $repository;
}

/**
 * @brief Produces the non-reversible lookup key used instead of storing a raw subscription identifier.
 * @param shortUuid Validated effective subscription identifier.
 * @return Lowercase SHA-256 hash.
 */
function subscriptionHash(string $shortUuid): string
{
    return hash('sha256', $shortUuid);
}

/**
 * @brief Restricts an account role to the two values supported by the current schema.
 * @param role Candidate role value.
 * @return "wl" only for the explicit WL role; otherwise "main".
 */
function normalizeAccountRole(string $role): string
{
    return strtolower($role) === 'wl' ? 'wl' : 'main';
}

/**
 * @brief Normalizes configured rolling deletion limits and applies secure project defaults.
 * @param limits Candidate day, week, and month values.
 * @return Non-negative integer limits where zero means unlimited.
 */
function normalizeDeletionLimits(array $limits): array
{
    $defaults = ['day' => 2, 'week' => 4, 'month' => 10];
    $normalized = [];
    foreach ($defaults as $period => $default) {
        $value = $limits[$period] ?? $default;
        $normalized[$period] = is_int($value) || (is_string($value) && ctype_digit($value))
            ? max(0, (int) $value)
            : $default;
    }
    return $normalized;
}

/**
 * @brief Keeps only scalar top-level user fields and normalizes fields required by the cache contract.
 * @param user User object from a validated info response.
 * @return Normalized scalar user data without raw nested API structures.
 */
function normalizeSubscriptionUserData(array $user): array
{
    $normalized = [];
    $allowedFields = [
        'id', 'username', 'userStatus', 'expiresAt', 'daysLeft',
        'trafficUsed', 'trafficUsedBytes', 'trafficLimit', 'trafficLimitBytes', 'hwidDeviceLimit',
    ];
    $config = is_array($GLOBALS['__storage_config'] ?? null) ? $GLOBALS['__storage_config'] : [];
    foreach (array_keys(configuredPersistentPersonalFields($config)) as $field) $allowedFields[] = $field;
    foreach ($allowedFields as $key) {
        $value = $user[$key] ?? null;
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            $normalized[$key] = $value;
        }
    }
    $normalized['username']          = is_string($normalized['username'] ?? null) ? $normalized['username'] : '';
    $normalized['userStatus']        = is_string($normalized['userStatus'] ?? null) ? $normalized['userStatus'] : 'UNKNOWN';
    $normalized['expiresAt']         = is_string($normalized['expiresAt'] ?? null) ? $normalized['expiresAt'] : '';
    $normalized['daysLeft']          = is_numeric($normalized['daysLeft'] ?? null) ? (int) $normalized['daysLeft'] : 0;
    $normalized['trafficUsed']       = is_string($normalized['trafficUsed'] ?? null) ? $normalized['trafficUsed'] : '';
    $normalized['trafficUsedBytes']  = is_numeric($normalized['trafficUsedBytes'] ?? null) ? (int) $normalized['trafficUsedBytes'] : 0;
    $normalized['trafficLimit']      = is_string($normalized['trafficLimit'] ?? null) ? $normalized['trafficLimit'] : '';
    $normalized['trafficLimitBytes'] = is_numeric($normalized['trafficLimitBytes'] ?? null) ? (int) $normalized['trafficLimitBytes'] : 0;
    if (!is_int($normalized['id'] ?? null)) unset($normalized['id']);
    return $normalized;
}

/**
 * @brief Keeps only approved scalar device fields for the deletion audit log.
 * @param device Candidate device metadata.
 * @return Sanitized device metadata safe for JSON storage.
 */
function normalizeDeviceAuditData(array $device): array
{
    $normalized = [];
    foreach (['hwid', 'platform', 'deviceModel', 'osVersion', 'userAgent', 'updatedAt'] as $field) {
        $value = $device[$field] ?? '';
        $text = is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
        $normalized[$field] = mb_check_encoding($text, 'UTF-8')
            ? $text
            : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    return $normalized;
}

/**
 * @brief Keeps the fixed non-sensitive header allowlist persisted with subscription info state.
 * @param headers Normalized lowercase upstream response headers.
 * @return Safe lowercase header map currently limited to support-url.
 */
function normalizeCachedInfoHeaders(array $headers): array
{
    $normalized = [];
    foreach (['support-url'] as $name) {
        $value = $headers[$name] ?? null;
        if (is_string($value) && isSafeHeaderValue($value)) $normalized[$name] = $value;
    }
    return $normalized;
}

/**
 * @brief Loads subscription info from APCu or fresh SQLite state and refreshes both caches from upstream.
 * @param cacheKey APCu key scoped by the caller.
 * @param shortUuid Effective subscription identifier that is hashed before persistent storage.
 * @param role Account role, either "main" or "wl".
 * @param url Trusted upstream info endpoint.
 * @param headers Upstream request headers.
 * @param ttl Positive cache freshness in seconds.
 * @return Standard HTTP result compatible with apiGet(); refresh failures are never replaced with stale state.
 */
function cachedSubscriptionInfo(string $cacheKey, string $shortUuid, string $role, string $url, array $headers, int $ttl): array
{
    $cached = cacheGet($cacheKey);
    if (is_array($cached) && ($cached['code'] ?? 0) === 200) return $cached;

    $repository = storageRepository();
    $hash = subscriptionHash($shortUuid);
    if ($repository !== null) {
        try {
            $state = $repository->getSubscriptionState($hash, normalizeAccountRole($role), $ttl);
            if ($state !== null && is_array($state['user'] ?? null)) {
                $body = json_encode(
                    ['response' => ['isFound' => true, 'user' => $state['user']]],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if ($body !== false) {
                    $result = [
                        'code' => 200,
                        'headers' => ['content-type' => 'application/json']
                            + (is_array($state['headers'] ?? null) ? $state['headers'] : []),
                        'body' => $body,
                        'ms' => 0, 'error_no' => 0, 'error' => '', 'cache_source' => 'sqlite',
                    ];
                    $remainingTtl = ((int) $state['last_success_at'] + max(1, $ttl)) - time();
                    if ($remainingTtl > 0) {
                        cacheSet($cacheKey, $result, $remainingTtl);
                        return $result;
                    }
                }
            }
        } catch (Throwable $error) {
            logStorageFailure('cache_read', $error);
            $GLOBALS['__storage_error'] = 'storage_unavailable';
        }
    }

    $result = apiGet($url, $headers, 10, responseBodyLimit('info'), 'subscription_info');
    if (($result['code'] ?? 0) !== 200) {
        if ($repository !== null) {
            try {
                $repository->markSubscriptionChecked($hash, normalizeAccountRole($role));
            } catch (Throwable $error) {
                logStorageFailure('mark_refresh_failure', $error);
                $GLOBALS['__storage_error'] = 'storage_unavailable';
            }
        }
        return $result;
    }

    $data = json_decode((string) $result['body'], true);
    $response = is_array($data) && is_array($data['response'] ?? null) ? $data['response'] : [];
    $user = ($response['isFound'] ?? null) === true && is_array($response['user'] ?? null)
        ? $response['user']
        : null;
    if ($user !== null && is_string($user['username'] ?? null) && $user['username'] !== '') {
        cacheSet($cacheKey, $result, $ttl);
        if ($repository !== null) {
            try {
                $repository->saveSubscriptionState($hash, normalizeAccountRole($role), $user, $result['headers']);
            } catch (Throwable $error) {
                logStorageFailure('cache_write', $error);
                $GLOBALS['__storage_error'] = 'storage_unavailable';
            }
        }
    } elseif ($repository !== null) {
        try {
            $repository->markSubscriptionChecked($hash, normalizeAccountRole($role));
        } catch (Throwable $error) {
            logStorageFailure('mark_invalid_refresh', $error);
            $GLOBALS['__storage_error'] = 'storage_unavailable';
        }
    }
    return $result;
}
