<?php

namespace RemotelyLiving\PHPDNS\Resolvers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Psr7\Response;
use RemotelyLiving\PHPDNS\Entities\DNSRecordCollection;
use RemotelyLiving\PHPDNS\Entities\DNSRecordType;
use RemotelyLiving\PHPDNS\Entities\Hostname;
use RemotelyLiving\PHPDNS\Mappers\CloudFlare as CloudFlareMapper;
use RemotelyLiving\PHPDNS\Resolvers\Exceptions\QueryFailure;

final class CloudFlare extends ResolverAbstract
{
    protected const BASE_URI = 'https://cloudflare-dns.com';
    protected const DEFAULT_TIMEOUT = 5.0;
    public const DEFAULT_OPTIONS = [
        'base_uri' => self::BASE_URI,
        'connect_timeout' => self::DEFAULT_TIMEOUT,
        'strict' => true,
        'allow_redirects' => false,
        'protocols' => ['https'],
        'headers' => [
            'Accept' => 'application/dns-json',
        ],
    ];

    private \GuzzleHttp\ClientInterface $http;

    private CloudFlareMapper $mapper;

    /**
     * @var array<string, mixed>
     */
    private array $options;

    public function __construct(
        ClientInterface $http = null,
        CloudFlareMapper $mapper = null,
        array $options = self::DEFAULT_OPTIONS
    ) {
        $this->http = $http ?? new Client();
        $this->mapper = $mapper ?? new CloudFlareMapper();
        $this->options = $options;
    }

    protected function doQuery(Hostname $hostname, DNSRecordType $recordType): DNSRecordCollection
    {
        try {
            return (!$recordType->isA(DNSRecordType::TYPE_ANY))
                ? $this->doApiQuery($hostname, $recordType)
                : $this->doAnyApiQuery($hostname);
        } catch (\Throwable $e) {
            throw new QueryFailure("Unable to query CloudFlare API", 0, $e);
        }
    }

    /**
     * Cloudflare does not support ANY queries, so we must ask for all record types individually
     */
    private function doAnyApiQuery(Hostname $hostname): DNSRecordCollection
    {
        $results = [];
        $eachPromise = new EachPromise($this->generateEachTypeQuery($hostname), [
            'concurrency' => 4,
            'fulfilled' => function (Response $response) use (&$results) {
                $results = \array_merge(
                    $results,
                    $this->parseResult((array) \json_decode((string)$response->getBody(), true))
                );
            },
            'rejected' => function (\Throwable $e): void {
                throw $e;
            },
        ]);

        $eachPromise->promise()->wait(true);

        return $this->mapResults($this->mapper, $results);
    }

    private function generateEachTypeQuery(Hostname $hostname): \Generator
    {
        foreach (DNSRecordType::VALID_TYPES as $type) {
            if ($type === DNSRecordType::TYPE_ANY) {
                continue 1;
            }

            yield $this->http->requestAsync(
                'GET',
                '/dns-query?' . \http_build_query(['name' => (string)$hostname, 'type' => $type]),
                $this->options
            );
        }
    }

    private function doApiQuery(Hostname $hostname, DNSRecordType $type): DNSRecordCollection
    {
        $url = '/dns-query?' . \http_build_query(['name' => (string)$hostname, 'type' => (string)$type]);
        $decoded = (array)\json_decode(
            (string)$this->http->requestAsync('GET', $url, $this->options)->wait(true)->getBody(),
            true
        );

        return $this->mapResults($this->mapper, $this->parseResult($decoded));
    }

    private function parseResult(array $result): array
    {
        if (isset($result['Answer'])) {
            return $result['Answer'];
        }

        if (isset($result['Authority'])) {
            return $result['Authority'];
        }

        return [];
    }
}
