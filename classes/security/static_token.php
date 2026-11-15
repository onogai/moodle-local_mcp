<?php
// Previa homelab extension. Keep upstream OAuth unchanged.
namespace local_mcp\security;

/**
 * Resolve an explicitly configured static Bearer token for a dedicated Moodle user.
 *
 * Disabled unless all three environment settings are present. The credential must
 * be supplied via the Authorization header; Moodle's existing HTTP helper rejects
 * query-string tokens. Moodle user eligibility and tool capability checks still apply.
 */
final class static_token {
    public static function resolve(string $supplied): ?authenticated_identity {
        $secret = getenv('PREVIA_MCP_STATIC_TOKEN');
        $userid = getenv('PREVIA_MCP_STATIC_USER_ID');
        $scopes = getenv('PREVIA_MCP_STATIC_SCOPES');

        if (!is_string($secret) || strlen($secret) < 32 ||
            !is_string($userid) || !preg_match('/^[1-9][0-9]*$/D', $userid) ||
            !is_string($scopes) || trim($scopes) === '') {
            return null;
        }

        // Preserve the upstream OAuth token namespace. Static credentials cannot
        // impersonate OAuth access tokens, even if a configuration is mistaken.
        if (str_starts_with($supplied, 'mcp_at_') || str_starts_with($secret, 'mcp_at_')) {
            return null;
        }
        if (!hash_equals($secret, $supplied)) {
            return null;
        }
        // Reject invalid or duplicated scopes instead of silently granting a subset.
        $requested = preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parsed = scope::parse($scopes);
        if (!$parsed || count($parsed) !== count($requested)) {
            return null;
        }
        return new authenticated_identity((int)$userid, 'static', $parsed);
    }
}
