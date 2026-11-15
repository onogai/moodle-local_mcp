<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Homelab static bearer authentication tests.
 *
 * @package local_mcp
 */

namespace local_mcp;

use advanced_testcase;
use local_mcp\security\scope;
use local_mcp\security\static_token;

/**
 * @covers \local_mcp\security\static_token
 */
final class previa_static_token_test extends advanced_testcase {
    /** @var array<string, string|false> */
    private array $previousenv = [];

    protected function setUp(): void {
        parent::setUp();
        foreach (['PREVIA_MCP_STATIC_TOKEN', 'PREVIA_MCP_STATIC_USER_ID',
            'PREVIA_MCP_STATIC_SCOPES'] as $key) {
            $this->previousenv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void {
        foreach ($this->previousenv as $key => $value) {
            putenv($value === false ? $key : "$key=$value");
        }
        parent::tearDown();
    }

    private function configure(): string {
        $key = 'previa_' . bin2hex(random_bytes(32));
        putenv('PREVIA_MCP_STATIC_TOKEN=' . $key);
        putenv('PREVIA_MCP_STATIC_USER_ID=42');
        putenv('PREVIA_MCP_STATIC_SCOPES=mcp:read mcp:write');
        return $key;
    }

    public function test_disabled_without_configuration(): void {
        $this->assertNull(static_token::resolve('anything'));
    }

    public function test_valid_token_maps_to_configured_user_and_scopes(): void {
        $key = $this->configure();
        $identity = static_token::resolve($key);
        $this->assertNotNull($identity);
        $this->assertSame(42, $identity->userid);
        $this->assertSame('static', $identity->type);
        $this->assertSame([scope::READ, scope::WRITE], $identity->scopes);
        $this->assertNull($identity->clientid);
        $this->assertNull($identity->connectionid);
    }

    public function test_wrong_key_is_rejected(): void {
        $this->configure();
        $this->assertNull(static_token::resolve('wrong'));
    }

    public function test_short_secret_disables_static_authentication(): void {
        $key = $this->configure();
        putenv('PREVIA_MCP_STATIC_TOKEN=short');
        $this->assertNull(static_token::resolve($key));
    }

    public function test_invalid_moodle_user_disables_authentication(): void {
        $key = $this->configure();
        putenv('PREVIA_MCP_STATIC_USER_ID=0');
        $this->assertNull(static_token::resolve($key));
        putenv('PREVIA_MCP_STATIC_USER_ID=admin');
        $this->assertNull(static_token::resolve($key));
    }

    public function test_no_scope_disables_authentication(): void {
        $key = $this->configure();
        putenv('PREVIA_MCP_STATIC_SCOPES=unknown');
        $this->assertNull(static_token::resolve($key));
    }

    public function test_invalid_or_duplicate_scopes_disable_authentication(): void {
        $key = $this->configure();
        putenv('PREVIA_MCP_STATIC_SCOPES=mcp:read unexpected');
        $this->assertNull(static_token::resolve($key));
        putenv('PREVIA_MCP_STATIC_SCOPES=mcp:read mcp:read');
        $this->assertNull(static_token::resolve($key));
    }

    public function test_resolver_accepts_configured_bearer_and_rejects_wrong_one(): void {
        $key = $this->configure();
        $identity = token_resolver::from_bearer('Bearer ' . $key);
        $this->assertSame(42, $identity->userid);
        $this->assertSame('static', $identity->type);
        try {
            token_resolver::from_bearer('Bearer invalid_static_key');
            $this->fail('Wrong static token must not authenticate.');
        } catch (api_exception $e) {
            $this->assertSame(401, $e->httpstatus);
        }
    }

    public function test_http_transport_is_opt_in_for_mcp_only(): void {
        global $CFG;
        $originalroot = $CFG->wwwroot;
        $previoushttp = getenv('PREVIA_MCP_ALLOW_HTTP');
        try {
            $CFG->wwwroot = 'http://192.168.1.10:8095';
            putenv('PREVIA_MCP_ALLOW_HTTP=1');
            transport::require_secure(true);
            try {
                transport::require_secure(false);
                $this->fail('OAuth must not inherit the MCP HTTP exception.');
            } catch (api_exception $e) {
                $this->assertSame('https_required', $e->machinecode);
            }
            putenv('PREVIA_MCP_ALLOW_HTTP=0');
            try {
                transport::require_secure(true);
                $this->fail('MCP HTTP must be rejected without explicit opt-in.');
            } catch (api_exception $e) {
                $this->assertSame('https_required', $e->machinecode);
            }
        } finally {
            $CFG->wwwroot = $originalroot;
            putenv($previoushttp === false ? 'PREVIA_MCP_ALLOW_HTTP' :
                'PREVIA_MCP_ALLOW_HTTP=' . $previoushttp);
        }
    }

    public function test_oauth_token_namespace_is_not_accepted(): void {
        $this->configure();
        $this->assertNull(static_token::resolve('mcp_at_example'));
    }
}
