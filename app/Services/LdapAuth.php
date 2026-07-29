<?php

namespace App\Services;

class LdapAuth
{
    public function connect(
        string $host,
        int $port,
        int $timeout,
        bool $useSsl = false,
        bool $useTls = false,
    ) {
        if ($useSsl && $useTls) {
            return null;
        }

        $host = trim($host);
        if ($host === '') {
            return null;
        }

        // Hindari URI ganda saat ENV sudah berisi ldap:// atau ldaps://.
        $hostWithoutScheme = preg_replace('#^ldaps?://#i', '', $host) ?: $host;
        $uri = ($useSsl ? 'ldaps://' : 'ldap://') . $hostWithoutScheme;

        $conn = @ldap_connect($uri, $port);
        if (! $conn) {
            return null;
        }

        @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);

        if ($useTls && ! @ldap_start_tls($conn)) {
            @ldap_unbind($conn);
            return null;
        }

        return $conn;
    }

    public function bindDn(
        string $host,
        int $port,
        string $dn,
        string $password,
        int $timeout,
        bool $useSsl = false,
        bool $useTls = false,
    ): bool {
        if ($dn === '' || $password === '') {
            return false;
        }

        $conn = $this->connect($host, $port, $timeout, $useSsl, $useTls);
        if (! $conn) {
            return false;
        }

        $ok = @ldap_bind($conn, $dn, $password);
        @ldap_unbind($conn);

        return (bool) $ok;
    }

    /**
     * Cari DN user. Mendukung Active Directory dan OpenLDAP.
     */
    public function findUserDn(
        string $host,
        int $port,
        ?string $bindDn,
        ?string $bindPass,
        string $baseDn,
        string $username,
        int $timeout,
        bool $useSsl = false,
        bool $useTls = false,
        ?string $domain = null,
    ): ?string {
        $conn = $this->connect($host, $port, $timeout, $useSsl, $useTls);
        if (! $conn) {
            return null;
        }

        if (! $this->bindSearchConnection($conn, $bindDn, $bindPass)) {
            @ldap_unbind($conn);
            return null;
        }

        $filter = $this->buildUserFilter($username, $domain);
        $attrs = ['distinguishedName', 'sAMAccountName', 'userPrincipalName', 'uid'];

        $search = @ldap_search($conn, $baseDn, $filter, $attrs, 0, 1);
        if (! $search) {
            @ldap_unbind($conn);
            return null;
        }

        $entries = @ldap_get_entries($conn, $search);
        @ldap_unbind($conn);

        if (! is_array($entries) || ($entries['count'] ?? 0) < 1) {
            return null;
        }

        return $entries[0]['dn']
            ?? $entries[0]['distinguishedname'][0]
            ?? null;
    }

    /**
     * Mengambil atribut dasar user dari Active Directory/OpenLDAP.
     */
    public function fetchAttributes(
        string $host,
        int $port,
        ?string $bindDn,
        ?string $bindPass,
        string $baseDn,
        string $username,
        int $timeout,
        bool $useSsl = false,
        bool $useTls = false,
        ?string $domain = null,
    ): array {
        $conn = $this->connect($host, $port, $timeout, $useSsl, $useTls);
        if (! $conn) {
            return [];
        }

        if (! $this->bindSearchConnection($conn, $bindDn, $bindPass)) {
            @ldap_unbind($conn);
            return [];
        }

        $filter = $this->buildUserFilter($username, $domain);
        $attrs = [
            'cn',
            'displayName',
            'name',
            'sAMAccountName',
            'userPrincipalName',
            'uid',
            'mail',
            'memberOf',
            'sn',
            'givenName',
            'ou',
            'department',
            'departmentNumber',
            'distinguishedName',
        ];

        $search = @ldap_search($conn, $baseDn, $filter, $attrs, 0, 1);
        if (! $search) {
            @ldap_unbind($conn);
            return [];
        }

        $entries = @ldap_get_entries($conn, $search);
        @ldap_unbind($conn);

        return is_array($entries) && ($entries['count'] ?? 0) > 0
            ? $entries[0]
            : [];
    }

    /**
     * Bind akun pencarian/read-only. Anonymous bind tetap menjadi fallback.
     */
    private function bindSearchConnection($conn, ?string $bindDn, ?string $bindPass): bool
    {
        if ($bindDn !== null && $bindDn !== '' && $bindPass !== null && $bindPass !== '') {
            return (bool) @ldap_bind($conn, $bindDn, $bindPass);
        }

        return (bool) @ldap_bind($conn);
    }

    /**
     * Filter gabungan:
     * - sAMAccountName untuk Active Directory
     * - userPrincipalName untuk UPN
     * - uid untuk OpenLDAP
     * - mail sebagai fallback bila input berupa email
     */
    private function buildUserFilter(string $username, ?string $domain = null): string
    {
        $identity = trim($username);

        // DOMAIN\\username -> username
        $account = str_contains($identity, '\\')
            ? (string) substr($identity, strrpos($identity, '\\') + 1)
            : $identity;

        // username@domain -> username untuk sAMAccountName/uid.
        $shortAccount = str_contains($account, '@')
            ? (string) strstr($account, '@', true)
            : $account;

        $upn = str_contains($account, '@')
            ? $account
            : (trim((string) $domain) !== '' ? $shortAccount . '@' . trim((string) $domain) : null);

        $filters = [];

        if ($shortAccount !== '') {
            $escapedShort = ldap_escape($shortAccount, '', LDAP_ESCAPE_FILTER);
            $filters[] = '(sAMAccountName=' . $escapedShort . ')';
            $filters[] = '(uid=' . $escapedShort . ')';
        }

        if ($upn !== null && $upn !== '') {
            $escapedUpn = ldap_escape($upn, '', LDAP_ESCAPE_FILTER);
            $filters[] = '(userPrincipalName=' . $escapedUpn . ')';
        }

        if (str_contains($identity, '@')) {
            $escapedIdentity = ldap_escape($identity, '', LDAP_ESCAPE_FILTER);
            $filters[] = '(mail=' . $escapedIdentity . ')';
        }

        if (count($filters) === 1) {
            return $filters[0];
        }

        return '(|' . implode('', $filters) . ')';
    }
}
