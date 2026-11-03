Feature: ee dash init site sync
  When the server is already known to EasyDash, `ee dash init` posts every EasyEngine site to add_site (unless EasyDash already lists it). Fixture sites are created on first use and shared by the scenarios; the AfterFeature hook deletes every `bh-dash-command-*` site. `ee dash init` walks ALL sites on the host, so assertions are per domain, and the suite expects no non-test sites (see COVERAGE.md).

  Background:
    Given an EasyDash stub is running
    And the EasyDash stub knows the server '203.0.113.10'

  # --hostname is the machine name, so the payload is the same whether or not `--hostname` is still discarded.
  Scenario: An HTML site with self-signed SSL is posted with its details
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname={HOSTNAME}" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Success: Site bh-dash-command-1.test integrated with EasyEngine Dashboard successfully.
      """
    And the EasyDash stub should have received no add_server requests
    And the sandboxed authorized_keys file should contain the EasyEngine key 0 times
    And the EasyDash stub should have received 1 add_site request for 'bh-dash-command-1.test'
    And the last EasyDash add_site request should be a POST to '/api/method/easydash.easydash.doctype.site.site.add_site'
    And the last EasyDash add_site request should have the header 'Token' set to 'bh-dash-{RUN_ID}'
    And the last EasyDash add_site request should have the header 'Content-Type' set to 'application/json'
    And the last EasyDash get_site_list request should be a POST to '/api/method/easydash.easydash.doctype.site.site.get_site_list'
    And the last EasyDash get_site_list request body should contain JSON:
      """
      {"organization": "bh-dash-org"}
      """
    And the last EasyDash add_site request for 'bh-dash-command-1.test' should contain JSON:
      """
      {
        "domain": "bh-dash-command-1.test",
        "server": "{HOSTNAME}",
        "site_type": "html",
        "organization": "bh-dash-org",
        "enabled": true,
        "alias_domains": "",
        "ssl": true,
        "http_basic_auth": false,
        "public_directory": "",
        "enable_database": false,
        "multisite": 0,
        "table_prefix": ""
      }
      """
    And the last EasyDash add_site request for 'bh-dash-command-1.test' should have 'admin_tools' loosely equal to '0'
    And the last EasyDash add_site request for 'bh-dash-command-1.test' should have 'mailhog' loosely equal to '0'
    And the last EasyDash add_site request for 'bh-dash-command-1.test' should have 'redis_cache' loosely equal to '0'

  Scenario: A PHP site with a database and a public dir is posted with its details
    Given the test site 'bh-dash-command-2.test' exists, created with '--type=php --php=8.2 --with-db --public-dir=public'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the EasyDash stub should have received 1 add_site request for 'bh-dash-command-2.test'
    And the last EasyDash add_site request for 'bh-dash-command-2.test' should contain JSON:
      """
      {
        "domain": "bh-dash-command-2.test",
        "site_type": "php",
        "enabled": true,
        "ssl": false,
        "php_version": "8.2",
        "public_directory": "public",
        "enable_database": true,
        "table_prefix": ""
      }
      """

  Scenario: A WordPress site is posted with its table prefix and cache setting
    Given the test site 'bh-dash-command-3.test' exists, created with '--type=wp --dbprefix=bhd_ --cache'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash add_site request for 'bh-dash-command-3.test' should contain JSON:
      """
      {
        "domain": "bh-dash-command-3.test",
        "site_type": "wp",
        "enable_database": true,
        "multisite": 0,
        "table_prefix": "bhd_",
        "public_directory": ""
      }
      """
    And the last EasyDash add_site request for 'bh-dash-command-3.test' should have 'redis_cache' loosely equal to '1'
    And the last EasyDash add_site request for 'bh-dash-command-3.test' should have 'php_version' matching '/^\d+\.\d+$/'

  Scenario: A subdirectory multisite is posted as such
    Given the test site 'bh-dash-command-8.test' exists, created with '--type=wp --mu=subdir'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash add_site request for 'bh-dash-command-8.test' should contain JSON:
      """
      {"site_type": "wp", "multisite": "subdir", "table_prefix": "wp_"}
      """

  Scenario: A site on PHP "latest" is posted with the version it really runs
    Given the test site 'bh-dash-command-4.test' exists, created with '--type=php --php=latest'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the site 'bh-dash-command-4.test' should have 'php_version' set to 'latest' in the EasyEngine database
    And the last EasyDash add_site request for 'bh-dash-command-4.test' should have 'php_version' matching '/^\d+\.\d+$/'

  Scenario: Alias domains are posted without the main domain
    Given the test site 'bh-dash-command-5.test' exists, created with '--type=html --alias-domains=bh-dash-command-5-alias.test,bh-dash-command-5-alt.test'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash add_site request for 'bh-dash-command-5.test' should contain JSON:
      """
      {"alias_domains": "bh-dash-command-5-alias.test,bh-dash-command-5-alt.test"}
      """

  Scenario: HTTP basic auth on a site is reported
    Given the test site 'bh-dash-command-5.test' exists, created with '--type=html --alias-domains=bh-dash-command-5-alias.test,bh-dash-command-5-alt.test'
    And the command 'bin/ee auth delete bh-dash-command-5.test --user=bhdash' is run after the scenario
    And the setup command 'bin/ee auth create bh-dash-command-5.test --user=bhdash --pass=bhdash-pass-{RUN_ID}' succeeds
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Checking for existing auths for site: bh-dash-command-5.test
      """
    And STDOUT should not contain:
      """
      bhdash-pass-{RUN_ID}
      """
    And the last EasyDash add_site request for 'bh-dash-command-5.test' should contain JSON:
      """
      {"http_basic_auth": true}
      """
    Given the setup command 'bin/ee auth delete bh-dash-command-5.test --user=bhdash' succeeds
    And the EasyDash stub forgets all sites
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash add_site request for 'bh-dash-command-5.test' should contain JSON:
      """
      {"http_basic_auth": false}
      """

  Scenario: Mailhog on a site is reported
    Given the test site 'bh-dash-command-2.test' exists, created with '--type=php --php=8.2 --with-db --public-dir=public'
    And the command 'bin/ee mailhog disable bh-dash-command-2.test' is run after the scenario
    And the setup command 'bin/ee mailhog enable bh-dash-command-2.test --force' succeeds
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash add_site request for 'bh-dash-command-2.test' should have 'mailhog' loosely equal to '1'

  Scenario: A site EasyDash already lists is skipped while the others are posted
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    And the test site 'bh-dash-command-2.test' exists, created with '--type=php --php=8.2 --with-db --public-dir=public'
    And the EasyDash stub knows the site 'bh-dash-command-1.test'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Site bh-dash-command-1.test already exists on EasyEngine Dashboard. Skipping site addition.
      """
    And the EasyDash stub should have received no add_site requests for 'bh-dash-command-1.test'
    And the EasyDash stub should have received 1 add_site request for 'bh-dash-command-2.test'

  Scenario: Running init twice posts each site only once
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    And the test site 'bh-dash-command-2.test' exists, created with '--type=php --php=8.2 --with-db --public-dir=public'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    And I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Site bh-dash-command-1.test already exists on EasyEngine Dashboard. Skipping site addition.
      """
    And STDOUT should contain:
      """
      Site bh-dash-command-2.test already exists on EasyEngine Dashboard. Skipping site addition.
      """
    And the EasyDash stub should have received 1 add_site request for 'bh-dash-command-1.test'
    And the EasyDash stub should have received 1 add_site request for 'bh-dash-command-2.test'
    And the EasyDash stub should have received no add_server requests

  Scenario: A failing site lookup is treated as "not on EasyDash"
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    And the EasyDash stub answers 'get_site_list' with status 503 and body:
      """
      {"exc_type": "ServiceUnavailable"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Failed to retrieve site list.
      """
    And the EasyDash stub should have received 1 add_site request for 'bh-dash-command-1.test'

  # Whether the remaining sites are still sent is left to a held-back scenario: one rejected site stops the sync today.
  Scenario: A rejected site is reported with the response body and fails the command
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    And the test site 'bh-dash-command-2.test' exists, created with '--type=php --php=8.2 --with-db --public-dir=public'
    And the EasyDash stub answers 'add_site' with status 500 and body:
      """
      {"exc":"bh-dash site refused"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should match '/Failed to integrate site \S+ with EasyEngine Dashboard\. Response: \{"exc":"bh-dash site refused"\}/'

  Scenario: A site registration that times out aborts the sync with an error
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    And the EasyDash stub answers 'add_site' after 12 seconds
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Failed to get url 'http://127.0.0.1:{STUB_PORT}/api/method/easydash.easydash.doctype.site.site.add_site'
      """

  Scenario: A site on PHP older than 8.1 is skipped with a warning
    Given the test site 'bh-dash-command-7.test' exists, created with '--type=php --php=8.4'
    # Reverted after the scenario.
    And I set the column 'php_version' to '8.0' for the site 'bh-dash-command-7.test' in the EasyEngine database
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: Skipping site bh-dash-command-7.test integration with EasyEngine Dashboard as PHP version is less than 8.1.
      """
    And the EasyDash stub should have received no add_site requests for 'bh-dash-command-7.test'

  Scenario: A site without docker-compose.yml is skipped with a warning
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    # Moved back after the scenario.
    And I move the file 'docker-compose.yml' to 'docker-compose.yml.bh-dash' in the site 'bh-dash-command-1.test'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then STDERR should contain:
      """
      Warning: docker-compose.yml file not found for site: bh-dash-command-1.test. Skipping.
      """
    And the EasyDash stub should have received no add_site requests for 'bh-dash-command-1.test'

  Scenario: A disabled site is posted as disabled and left disabled
    Given the test site 'bh-dash-command-6.test' exists, created with '--type=html'
    And the command 'bin/ee site enable bh-dash-command-6.test' is run after the scenario
    And the setup command 'bin/ee site disable bh-dash-command-6.test' succeeds
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the site 'bh-dash-command-6.test' should have 'site_enabled' set to '0' in the EasyEngine database
    And the last EasyDash add_site request for 'bh-dash-command-6.test' should contain JSON:
      """
      {"domain": "bh-dash-command-6.test", "enabled": false}
      """

  Scenario: A disabled site EasyDash already lists is still left disabled
    Given the test site 'bh-dash-command-6.test' exists, created with '--type=html'
    And the command 'bin/ee site enable bh-dash-command-6.test' is run after the scenario
    And the setup command 'bin/ee site disable bh-dash-command-6.test' succeeds
    And the EasyDash stub knows the site 'bh-dash-command-6.test'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Site bh-dash-command-6.test already exists on EasyEngine Dashboard. Skipping site addition.
      """
    And the EasyDash stub should have received no add_site requests for 'bh-dash-command-6.test'
    And the site 'bh-dash-command-6.test' should have 'site_enabled' set to '0' in the EasyEngine database

  Scenario: Enabled sites are not restarted by the sync
    Given the test site 'bh-dash-command-1.test' exists, created with '--type=html --ssl=self'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the nested ee command 'site enable bh-dash-command-1.test' should not have been run
    And the nested ee command 'site disable bh-dash-command-1.test' should not have been run
    And the site 'bh-dash-command-1.test' should have 'site_enabled' set to '1' in the EasyEngine database
