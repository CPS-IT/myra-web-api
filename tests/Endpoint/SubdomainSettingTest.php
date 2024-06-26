<?php
declare(strict_types=1);

namespace Myracloud\Tests\Endpoint;

use GuzzleHttp\Exception\GuzzleException;
use Myracloud\WebApi\Endpoint\SubdomainSetting;

class SubdomainSettingTest extends AbstractEndpointTest
{
    protected SubdomainSetting $subdomainSettingEndpoint;
    protected array $someKeys = [
        'accept_encoding',
        'access_log',
        'antibot_post_flood',
        'antibot_post_flood_threshold',
        'antibot_proof_of_work',
        'antibot_proof_of_work_threshold',
        'balancing_method',
        'block_ip_by_header',
        'block_not_whitelisted',
        'block_tor_network',
        'cache_enabled',
        'cache_revalidate',
        'cache_sort_active',
        'cdn',
        'diffie_hellman_exchange',
        'enable_origin_sni',
        'enforce_cache_ttl',
        'extended_stats',
        'forwarded_for_replacement',
        'host_header',
        'hsts',
        'hsts_include_subdomains',
        'hsts_max_age',
        'hsts_preload',
        'http_origin_port',
        'image_optimization',
        'ipv6_active',
        'limit_allowed_http_method',
        'log_format',
        'monitoring_alert_threshold',
        'monitoring_contact_email',
        'monitoring_send_alert',
        'myra_ssl_header',
        'next_upstream',
        'nginx_filter',
        'nginx_filter_inject_type',
        'nginx_server_inject',
        'nginx_server_inject_type',
        'nginx_ssl_server_inject',
        'nginx_ssl_server_inject_type',
        'nginx_ssl_upstream_inject',
        'nginx_ssl_upstream_inject_type',
        'nginx_upstream_inject',
        'nginx_upstream_inject_type',
        'objectType',
        'only_https',
        'origin_connection_header',
        'proxy_cache_bypass',
        'proxy_cache_key',
        'proxy_cache_lock_age',
        'proxy_cache_min_uses',
        'proxy_cache_stale',
        'proxy_connect_timeout',
        'proxy_read_timeout',
        'proxy_ssl_name',
        'proxy_ssl_session_reuse',
        'proxy_ssl_trusted_certificate',
        'proxy_ssl_verify',
        'proxy_ssl_verify_depth',
        'request_limit_block',
        'request_limit_level',
        'request_limit_report',
        'request_limit_report_email',
        'request_limit_timeframe',
        'rewrite',
        'source_port',
        'source_protocol',
        'spdy',
        'ssl_origin_port',
        'waf_enable',
        'waf_levels_enable',
        'waf_policy',
    ];

    /**
     *
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->subdomainSettingEndpoint = $this->api->getSubdomainSettingsEndpoint();
        $this->assertThat($this->subdomainSettingEndpoint, $this->isInstanceOf('Myracloud\WebApi\Endpoint\SubdomainSetting'));
    }

    public function testGet(): void
    {
        $result = $this->subdomainSettingEndpoint->getList(self::TESTDOMAIN);
        var_dump($result);
        $this->verifyNoError($result);
        $this->assertArrayHasKey('targetObject', $result);
        $this->assertGreaterThan(0, count($result['targetObject']));

        $this->verifyTargetObject($result, 'SubdomainSettingVO');
        foreach ($this->someKeys as $item) {
            $this->assertArrayHasKey($item, $result['targetObject'][0]);
        }
    }

    public function testSet(): void
    {
        $data = [
            'cdn' => true,
        ];

        $result = $this->subdomainSettingEndpoint->set(self::TESTDOMAIN, $data);
        var_dump($result);
        $this->verifyNoError($result);
    }


}
