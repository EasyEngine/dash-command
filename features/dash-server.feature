Feature: ee dash init server registration
  Server-level behaviour of `ee dash init` against the local EasyDash stub: the server lookup, registration, the EasyEngine SSH key, the hostname prompts and API error handling. The stub is stateful: add_server/add_site calls are returned by later get_server_list/get_site_list calls. These scenarios expect no site on the host (the site sync is covered in dash-sites.feature).

  Background:
    Given an EasyDash stub is running

  Scenario: A server already known to EasyDash is not registered again
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Server with IP 203.0.113.10 already exists on EasyEngine Dashboard. Skipping server addition.
      """
    And the EasyDash stub should have received 1 get_server_list request
    And the EasyDash stub should have received no add_server requests
    And the sandboxed authorized_keys file should contain the EasyEngine key 0 times
    And hostnamectl should not have been called

  Scenario: The server lookup posts the organization with the API token
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the last EasyDash get_server_list request should be a POST to '/api/method/easydash.easydash.doctype.server.server.get_server_list'
    And the last EasyDash get_server_list request should have the header 'Token' set to 'bh-dash-{RUN_ID}'
    And the last EasyDash get_server_list request should have the header 'Content-Type' set to 'application/json'
    And the last EasyDash get_server_list request body should contain JSON:
      """
      {"organization": "bh-dash-org"}
      """

  # --hostname is the machine name, so the payload is the same whether or not `--hostname` is still discarded.
  Scenario: A new server is registered, the EasyEngine key is authorised and the run stops there
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname={HOSTNAME}" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Success: Server integrated with EasyEngine Dashboard successfully. Please wait for sometime for site data to populate on EasyEngine Dashboard.
      """
    And the EasyDash stub should have received 1 get_server_list request
    And the EasyDash stub should have received 1 add_server request
    And the last EasyDash add_server request should be a POST to '/api/method/easydash.easydash.doctype.server.server.add_server'
    And the last EasyDash add_server request should have the header 'Token' set to 'bh-dash-{RUN_ID}'
    And the last EasyDash add_server request should have the header 'Content-Type' set to 'application/json'
    And the last EasyDash add_server request body should contain JSON:
      """
      {"hostname": "{HOSTNAME}", "public_ipv4": "203.0.113.10", "organization": "bh-dash-org"}
      """
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time
    # A successful registration exits before the site sync; EasyDash re-runs init later to sync sites.
    And the EasyDash stub should have received no get_site_list requests
    And the EasyDash stub should have received no add_site requests

  Scenario: Existing authorized_keys entries are kept when the key is added
    Given the sandboxed authorized_keys file contains:
      """
      ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBhdashExistingKeyForBehatOnly bh-dash@example.test
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the sandboxed authorized_keys file should contain:
      """
      ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBhdashExistingKeyForBehatOnly bh-dash@example.test
      {EASYENGINE_SSH_KEY}
      """
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time

  Scenario: The EasyEngine key is not duplicated when it is already authorised
    Given the sandboxed authorized_keys file contains:
      """
      {EASYENGINE_SSH_KEY}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the EasyDash stub should have received 1 add_server request
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time

  Scenario: Running init twice registers the server only once
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    And I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Server with IP 203.0.113.10 already exists on EasyEngine Dashboard. Skipping server addition.
      """
    And the EasyDash stub should have received 2 get_server_list requests
    And the EasyDash stub should have received 1 add_server request
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time

  Scenario: Option values are trimmed before use
    When I run "bin/ee dash init --api='  bh-dash-{RUN_ID}  ' --org='  bh-dash-org  ' --ip='  203.0.113.10  ' --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash add_server request should have the header 'Token' set to 'bh-dash-{RUN_ID}'
    And the last EasyDash add_server request body should contain JSON:
      """
      {"public_ipv4": "203.0.113.10", "organization": "bh-dash-org"}
      """

  Scenario: A server list that only holds other IPs leads to a registration
    Given the EasyDash stub knows the server '198.51.100.7'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the EasyDash stub should have received 1 add_server request
    And the last EasyDash add_server request body should contain JSON:
      """
      {"public_ipv4": "203.0.113.10"}
      """

  Scenario: A failing server lookup is treated as an empty organization
    Given the EasyDash stub answers 'get_server_list' with status 500 and body:
      """
      {"exc_type": "InternalServerError"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      There are no servers in the org right now. Adding new one...
      """
    And the EasyDash stub should have received 1 add_server request

  Scenario: A server lookup answering non-JSON leads to a registration
    Given the EasyDash stub answers 'get_server_list' with status 200 and body:
      """
      <html>maintenance</html>
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the EasyDash stub should have received 1 add_server request

  # The exit code is left to a held-back scenario: a failed add_server only warns and the run exits 0.
  Scenario: A rejected registration is reported with the response body and status
    Given the EasyDash stub answers 'add_server' with status 500 and body:
      """
      {"exc":"bh-dash registration refused"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then STDERR should contain:
      """
      Warning: Failed to integrate server with EasyEngine Dashboard. Response: {"exc":"bh-dash registration refused"}
      """
    And STDERR should contain:
      """
      Warning: Status code: 500
      """
    And STDOUT should not contain:
      """
      Server integrated with EasyEngine Dashboard successfully
      """

  # Fails at the server lookup today and at add_server once the lookup's fallback works, so the key count isn't asserted.
  Scenario: An unreachable EasyDash API fails the run
    Given the EasyDash API URL is 'http://127.0.0.1:{CLOSED_PORT}/api/method/'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Failed to get url 'http://127.0.0.1:{CLOSED_PORT}/api/method/easydash.easydash.doctype.server.server.
      """

  Scenario: A registration that times out aborts the run with an error
    Given the EasyDash stub answers 'add_server' after 12 seconds
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Failed to get url 'http://127.0.0.1:{STUB_PORT}/api/method/easydash.easydash.doctype.server.server.add_server'
      """
    And STDOUT should not contain:
      """
      Server integrated with EasyEngine Dashboard successfully
      """

  Scenario: An ed-api-url without a trailing slash is normalised
    Given the EasyDash API URL is '{STUB_URL}/api/method'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash get_server_list request should be a POST to '/api/method/easydash.easydash.doctype.server.server.get_server_list'
    And the last EasyDash add_server request should be a POST to '/api/method/easydash.easydash.doctype.server.server.add_server'

  Scenario: An EasyDash instance under a sub-path gets every request under that path
    Given the EasyDash API URL is '{STUB_URL}/easydash/api/method/'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash get_server_list request should be a POST to '/easydash/api/method/easydash.easydash.doctype.server.server.get_server_list'
    And the last EasyDash add_server request should be a POST to '/easydash/api/method/easydash.easydash.doctype.server.server.add_server'

  Scenario: A valid --hostname skips the hostname confirmation
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should not contain:
      """
      Is the hostname
      """
    And hostnamectl should not have been called

  Scenario: Without --hostname, confirming the machine hostname keeps it
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10" in the EasyDash sandbox with STDIN:
      """
      y
      """
    Then the return code should be 0
    And STDOUT should contain:
      """
      Is the hostname {HOSTNAME} correct? [y/n]
      """
    And hostnamectl should not have been called
    And the last EasyDash add_server request body should contain JSON:
      """
      {"hostname": "{HOSTNAME}"}
      """

  Scenario: An invalid --hostname falls back to the confirmation prompt
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bad_host" in the EasyDash sandbox with STDIN:
      """
      y
      """
    Then the return code should be 0
    And STDOUT should contain:
      """
      Is the hostname {HOSTNAME} correct? [y/n]
      """
    And hostnamectl should not have been called

  Scenario: Declining the hostname asks for a valid FQDN, sets it and registers it
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10" in the EasyDash sandbox with STDIN:
      """
      n
      not_a_host!
      BH-Dash-New.example.test
      """
    Then the return code should be 0
    And STDOUT should contain:
      """
      Please enter the FQDN hostname of the server:
      """
    And STDERR should contain:
      """
      Warning: Invalid hostname. Please enter a valid FQDN hostname.
      """
    # EE::input() lower-cases the answer.
    And hostnamectl should have been called with 'set-hostname bh-dash-new.example.test'
    And the last EasyDash add_server request body should contain JSON:
      """
      {"hostname": "bh-dash-new.example.test"}
      """

  # Fixed in v4.13.2 by core masking (EasyEngine/easyengine#1944), regression check: server_exists_on_easydash() logs the request headers, token included, at debug level, which always reaches ee.log.
  Scenario: The server lookup does not log the API token
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-secret-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the file '/opt/easyengine/logs/ee.log' should not contain '"Token":"bh-dash-secret-{RUN_ID}"'

  # Fixed in v4.13.2 (EasyEngine/easyengine#1944), regression check: core used to log the full argv at debug level, so --api=<token> landed in ee.log.
  Scenario: The API token is not written to ee.log at all
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-secret-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the file '/opt/easyengine/logs/ee.log' should not contain 'bh-dash-secret-{RUN_ID}'

  # The sandbox's proxy guard makes every request not aimed at 127.0.0.1 fail at once, so the lookup never leaves the host.
  # The run aborts there today; once the IP prompt fallback works, the IP on STDIN is a known server, so nothing is registered either way.
  Scenario: Without --ip the public IP is looked up at api.ipify.org before EasyDash is contacted
    Given the EasyDash stub knows the server '203.0.113.20'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --hostname=bh-dash.example.test" in the EasyDash sandbox with STDIN:
      """
      203.0.113.20
      """
    Then STDERR should contain:
      """
      https://api.ipify.org
      """
    And the EasyDash stub should have received no add_server requests
