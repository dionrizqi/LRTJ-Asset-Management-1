<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterRoleMenu;
use App\Models\User;
use App\Services\LdapAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class AuthController extends Controller
{
    public function __construct(private LdapAuth $ldap) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'username'    => ['required', 'string'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $username = trim((string) $data['username']);
        $password = (string) $data['password'];
        $device = trim((string) ($data['device_name'] ?? 'api')) ?: 'api';

        $key = 'api-login:' . Str::lower($username) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        RateLimiter::hit($key, 60);

        /* ------------------------------------------------------------------
         * 1) STATIC ADMIN
         * ------------------------------------------------------------------ */
        $staticUser = Str::lower((string) config('auth.static_admin.username', ''));
        $staticPass = (string) config('auth.static_admin.password', '');

        if (
            $staticUser !== '' &&
            $staticPass !== '' &&
            hash_equals($staticUser, Str::lower($username)) &&
            hash_equals($staticPass, $password)
        ) {
            $user = User::findForDirectoryIdentity($staticUser, 'admin@example.com');

            if (! $user) {
                $user = User::create([
                    'username' => $staticUser,
                    'name'     => 'Administrator',
                    'email'    => 'admin@example.com',
                    'ou'       => 'local',
                    'password' => Hash::make($staticPass),
                ]);
            }

            $user->roles()->syncWithoutDetaching(['SYSADMIN']);

            if (empty($user->role_kode)) {
                $user->role_kode = 'SYSADMIN';
                $user->save();
            }

            return $this->issueTokenResponse($user, $device, $key, 'static');
        }

        /* ------------------------------------------------------------------
         * 2) LOCAL DB LOGIN
         * ------------------------------------------------------------------ */
        try {
            $localUser = User::query()
                ->whereRaw('LOWER(username) = ?', [Str::lower($username)])
                ->first();

            if (
                $localUser &&
                ! empty($localUser->password) &&
                Hash::check($password, $localUser->password)
            ) {
                return $this->issueTokenResponse($localUser, $device, $key, 'local');
            }
        } catch (\Throwable $e) {
            \Log::warning('API local login skipped, fallback to LDAP', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }

        /* ------------------------------------------------------------------
         * 3) LDAP CONFIG
         * config/ldap.php memakai struktur bawaan LdapRecord:
         * ldap.connections.{connection-name}.*
         * ------------------------------------------------------------------ */
        $connectionName = trim((string) config('ldap.default', 'default')) ?: 'default';
        $ldapConfig = (array) config("ldap.connections.{$connectionName}", []);

        $hosts = $ldapConfig['hosts'] ?? [];
        $host = is_array($hosts)
            ? trim((string) ($hosts[0] ?? ''))
            : trim((string) $hosts);

        $port = (int) ($ldapConfig['port'] ?? 389);
        $baseDn = trim((string) ($ldapConfig['base_dn'] ?? ''), " \t\n\r\0\x0B\"'");
        $bindDn = $ldapConfig['username'] ?? null;
        $bindPassword = $ldapConfig['password'] ?? null;
        $timeout = (int) ($ldapConfig['timeout'] ?? 5);
        $useSsl = filter_var($ldapConfig['use_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $useTls = filter_var($ldapConfig['use_tls'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $domain = trim((string) ($ldapConfig['domain'] ?? ''));
        $netbios = trim((string) ($ldapConfig['netbios'] ?? ''));

        if ($host === '' || $baseDn === '') {
            \Log::error('API LDAP configuration incomplete', [
                'connection' => $connectionName,
                'host_exists' => $host !== '',
                'base_dn_exists' => $baseDn !== '',
            ]);

            return response()->json([
                'message' => 'LDAP configuration is incomplete.',
            ], 503);
        }

        if ($useSsl && $useTls) {
            \Log::error('API LDAP SSL and TLS cannot both be enabled', [
                'connection' => $connectionName,
            ]);

            return response()->json([
                'message' => 'LDAP configuration is invalid.',
            ], 503);
        }

        /* ------------------------------------------------------------------
         * 4) LDAP AUTHENTICATION
         * ------------------------------------------------------------------ */
        $shortUsername = $this->shortDirectoryUsername($username);
        $candidateIdentities = [];

        // Input berupa email/UPN atau DOMAIN\\username dapat langsung di-bind.
        if (str_contains($username, '@') || str_contains($username, '\\')) {
            $candidateIdentities[] = $username;
        }

        if ($domain !== '' && ! str_contains($username, '@') && ! str_contains($username, '\\')) {
            $candidateIdentities[] = $shortUsername . '@' . $domain;
        }

        if ($netbios !== '' && ! str_contains($username, '@') && ! str_contains($username, '\\')) {
            $candidateIdentities[] = $netbios . '\\' . $shortUsername;
        }

        // Fallback untuk OpenLDAP.
        $candidateIdentities[] = "uid={$shortUsername},{$baseDn}";
        $candidateIdentities = array_values(array_unique(array_filter($candidateIdentities)));

        $authenticated = false;

        foreach ($candidateIdentities as $identity) {
            try {
                if ($this->ldap->bindDn(
                    $host,
                    $port,
                    $identity,
                    $password,
                    $timeout,
                    $useSsl,
                    $useTls,
                )) {
                    $authenticated = true;

                    \Log::info('API LDAP direct bind success', [
                        'username' => $username,
                        'identity_type' => $this->identityType($identity),
                    ]);

                    break;
                }
            } catch (\Throwable $e) {
                \Log::warning('API LDAP direct bind exception', [
                    'username' => $username,
                    'identity_type' => $this->identityType($identity),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Bila direct bind gagal, cari distinguishedName user menggunakan akun bind.
        if (! $authenticated) {
            try {
                $foundDn = $this->ldap->findUserDn(
                    $host,
                    $port,
                    $bindDn,
                    $bindPassword,
                    $baseDn,
                    $username,
                    $timeout,
                    $useSsl,
                    $useTls,
                    $domain !== '' ? $domain : null,
                );

                if ($foundDn && $this->ldap->bindDn(
                    $host,
                    $port,
                    $foundDn,
                    $password,
                    $timeout,
                    $useSsl,
                    $useTls,
                )) {
                    $authenticated = true;

                    \Log::info('API LDAP DN bind success', [
                        'username' => $username,
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('API LDAP DN lookup/bind exception', [
                    'username' => $username,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $authenticated) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        /* ------------------------------------------------------------------
         * 5) FETCH ATTRIBUTES + SYNC LOCAL USER
         * Dibuat sama dengan perilaku web: username input dipertahankan.
         * ------------------------------------------------------------------ */
        try {
            $attrs = $this->ldap->fetchAttributes(
                $host,
                $port,
                $bindDn,
                $bindPassword,
                $baseDn,
                $username,
                $timeout,
                $useSsl,
                $useTls,
                $domain !== '' ? $domain : null,
            );
        } catch (\Throwable $e) {
            \Log::warning('API LDAP attribute fetch failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            $attrs = [];
        }

        // ldap_get_entries() menurunkan nama key atribut menjadi lowercase.
        $name = $attrs['displayname'][0]
            ?? $attrs['cn'][0]
            ?? $attrs['name'][0]
            ?? $username;

        $email = $attrs['mail'][0]
            ?? $attrs['userprincipalname'][0]
            ?? (str_contains($username, '@') ? $username : null);

        $ou = $attrs['ou'][0]
            ?? $attrs['department'][0]
            ?? null;

        $user = User::findForDirectoryIdentity($username, $email);
        $isNew = $user === null;

        if (! $user) {
            $user = User::create([
                'username' => $username,
                'name'     => $name,
                'email'    => $email,
                'ou'       => $ou,
                'password' => Hash::make(Str::random(64)),
            ]);
        }

        if (
            strcasecmp((string) $user->username, $username) !== 0 &&
            ! User::query()
                ->whereKeyNot($user->getKey())
                ->whereRaw('LOWER(username) = ?', [Str::lower($username)])
                ->exists()
        ) {
            $user->username = $username;
        }

        $user->name = $name;

        if (
            $email &&
            ! User::query()
                ->whereKeyNot($user->getKey())
                ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                ->exists()
        ) {
            $user->email = $email;
        }

        if ($ou) {
            $user->ou = $ou;
        }

        $user->save();

        if ($isNew && empty($user->role_kode)) {
            $user->roles()->syncWithoutDetaching(['AUDITOR']);
            $user->role_kode = 'AUDITOR';
            $user->save();
        }

        \Log::info('API LDAP login success', [
            'username' => $username,
            'email_exists' => ! empty($email),
        ]);

        return $this->issueTokenResponse($user, $device, $key, 'ldap');
    }

    public function me(Request $request)
    {
        return response()->json(
            $this->buildUserPayload($request->user())
        );
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return response()->json(['message' => 'Logged out (token revoked)']);
        }

        if ($token instanceof TransientToken) {
            auth()->guard('web')->logout();
            $request->session()?->invalidate();
            $request->session()?->regenerateToken();

            return response()->json(['message' => 'Logged out (cookie mode)']);
        }

        return response()->json(['message' => 'No active token']);
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        auth()->guard('web')->logout();
        $request->session()?->invalidate();
        $request->session()?->regenerateToken();

        return response()->json(['message' => 'Logged out from all devices']);
    }

    private function issueTokenResponse(
        User $user,
        string $device,
        string $rateLimitKey,
        string $authType,
    ) {
        $token = $user->createToken($device)->plainTextToken;
        RateLimiter::clear($rateLimitKey);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->buildUserPayload($user, [
                'auth' => $authType,
            ]),
        ], 201);
    }

    private function shortDirectoryUsername(string $username): string
    {
        $username = trim($username);

        if (str_contains($username, '\\')) {
            $username = substr($username, strrpos($username, '\\') + 1);
        }

        if (str_contains($username, '@')) {
            return strstr($username, '@', true) ?: $username;
        }

        return $username;
    }

    private function identityType(string $identity): string
    {
        if (str_contains($identity, '@')) {
            return 'upn';
        }

        if (str_contains($identity, '\\')) {
            return 'netbios';
        }

        return 'dn';
    }

    private function buildUserPayload(User $user, array $extra = []): array
    {
        $roles = $user->roles()
            ->pluck('kode')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $permissionsMap = [];

        if (! empty($roles)) {
            MasterRoleMenu::query()
                ->whereIn('role_kode', $roles)
                ->where('status', true)
                ->get(['menu_kode', 'actions'])
                ->each(function (MasterRoleMenu $rm) use (&$permissionsMap) {
                    $menu = $rm->menu_kode;
                    $permissionsMap[$menu] ??= [];

                    $actions = is_array($rm->actions) ? $rm->actions : [];

                    foreach ($actions as $action) {
                        if ($action !== null && ! in_array($action, $permissionsMap[$menu], true)) {
                            $permissionsMap[$menu][] = $action;
                        }
                    }
                });
        }

        $permissions = [];

        foreach ($permissionsMap as $menuKode => $actions) {
            sort($actions);
            $permissions[] = [
                'menu_kode' => $menuKode,
                'actions' => array_values($actions),
            ];
        }

        return array_merge([
            'id'              => $user->id,
            'username'        => $user->username,
            'name'            => $user->name,
            'email'           => $user->email,
            'kode_department' => $user->kode_department,
            'roles'           => $roles,
            'permissions'     => $permissions,
        ], $extra);
    }
}
