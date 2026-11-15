# Previa homelab changes

This fork retains upstream OAuth and adds an **opt-in** static Bearer authentication mode for the private homelab MCP Gateway. Upstream remains the source for new plugin functionality.

## Configuration

Pass these environment variables to the Moodle **web** container:

- `PREVIA_MCP_STATIC_TOKEN`: a random token of at least 32 characters (suggested: `openssl rand -hex 32`).
- `PREVIA_MCP_STATIC_USER_ID`: numeric ID of an existing active, non-guest Moodle user dedicated to Gateway access.
- `PREVIA_MCP_STATIC_SCOPES`: `mcp:read mcp:write`, or `mcp:read` for read-only access.
- `PREVIA_MCP_ALLOW_HTTP=1`: explicitly permits MCP endpoints on a non-HTTPS Moodle `wwwroot`. Leave unset for HTTPS-only.

Without a valid token, user ID and scopes, the static mode is disabled. The existing `mcp_at_` OAuth tokens continue through the original resolver. OAuth login/token endpoints remain HTTPS-only; the internal HTTP exception does not authenticate OAuth access tokens.

Configure the upstream in the Gateway as Streamable HTTP with an `Authorization: Bearer <PREVIA_MCP_STATIC_TOKEN>` header. For example, if Moodle is reachable at `http://moodle:80` on a shared Docker network, the MCP endpoint is `http://moodle/local/mcp/server.php`. Otherwise, use the homelab LAN address/port.

**Do not expose HTTP MCP beyond the trusted Docker network.** A static token sent over HTTP can be read by anyone who can observe the traffic. Bind container ports to trusted interfaces, restrict network access, do not log Authorization headers, and never commit tokens to Git. Rotate the token by changing the configured environment secret and recreating the Moodle container.

Permissions are those of the dedicated Moodle user (Moodle capability checks remain in effect), plus `mcp:read`/`mcp:write`. WRITE tools retain upstream preview/confirmation. The static identity has no OAuth client/connection ID, as already supported by the upstream confirmation tables.

## Upstream sync

Keep these edits isolated so upstream can be merged periodically. This fork changes only:
- `classes/security/transport.php` (explicit internal HTTP opt-in for MCP only);
- `classes/security/token_resolver.php` (static Bearer alternative);
- `classes/security/static_token.php` (new implementation);
- `server.php`, `read.php`, `write.php` (mark MCP endpoints for HTTP opt-in);
- tests and this document.

After merging upstream, rerun static-token and OAuth tests, verify transport behavior, then update the pinned fork commit in Previa. No Moodle core patch is necessary.
