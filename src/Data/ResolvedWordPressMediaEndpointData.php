<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Data;

final readonly class ResolvedWordPressMediaEndpointData
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $address,
    ) {}

    public function curlResolveEntry(): string
    {
        return sprintf('%s:%d:%s', $this->host, $this->port, $this->curlAddress());
    }

    public function hostHeader(): string
    {
        if (($this->scheme === 'https' && $this->port === 443) || ($this->scheme === 'http' && $this->port === 80)) {
            return $this->host;
        }

        return sprintf('%s:%d', $this->host, $this->port);
    }

    private function curlAddress(): string
    {
        if (filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $this->address;
        }

        return sprintf('[%s]', $this->address);
    }
}
