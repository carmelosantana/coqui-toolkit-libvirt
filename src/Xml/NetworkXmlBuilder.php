<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Xml;

/**
 * Builder for libvirt network XML definitions.
 *
 * Supports NAT, isolated, and bridged network configurations.
 */
final class NetworkXmlBuilder
{
    private string $name = '';
    private string $forwardMode = 'nat';
    private string $bridgeName = '';
    private string $ipAddress = '';
    private string $netmask = '';
    private string $dhcpStart = '';
    private string $dhcpEnd = '';
    private bool $dhcpEnabled = true;

    public function name(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;
        return $clone;
    }

    /**
     * Configure as a NAT network with the given subnet.
     *
     * @param string $subnet CIDR notation like "192.168.100.0/24"
     */
    public function nat(string $subnet = '192.168.122.0/24'): self
    {
        $clone = clone $this;
        $clone->forwardMode = 'nat';
        $clone->applySubnet($subnet);
        return $clone;
    }

    /**
     * Configure as an isolated network (no external access).
     */
    public function isolated(string $subnet = '192.168.200.0/24'): self
    {
        $clone = clone $this;
        $clone->forwardMode = 'isolated';
        $clone->applySubnet($subnet);
        return $clone;
    }

    /**
     * Configure as a bridged network using an existing host bridge.
     */
    public function bridged(string $bridgeName): self
    {
        $clone = clone $this;
        $clone->forwardMode = 'bridge';
        $clone->bridgeName = $bridgeName;
        return $clone;
    }

    public function dhcp(bool $enabled): self
    {
        $clone = clone $this;
        $clone->dhcpEnabled = $enabled;
        return $clone;
    }

    /**
     * Build the network XML string.
     */
    public function build(): string
    {
        if ($this->name === '') {
            throw new \LogicException('Network name is required. Call name() before build().');
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $network = $dom->createElement('network');
        $dom->appendChild($network);

        $network->appendChild($dom->createElement('name', $this->name));

        // Forward mode
        if ($this->forwardMode === 'nat') {
            $forward = $dom->createElement('forward');
            $forward->setAttribute('mode', 'nat');
            $network->appendChild($forward);
        } elseif ($this->forwardMode === 'bridge') {
            $forward = $dom->createElement('forward');
            $forward->setAttribute('mode', 'bridge');
            $network->appendChild($forward);

            $bridge = $dom->createElement('bridge');
            $bridge->setAttribute('name', $this->bridgeName);
            $network->appendChild($bridge);

            // Bridged networks don't need IP/DHCP config
            $xml = $dom->saveXML();
            return $xml !== false ? $xml : '';
        }
        // Isolated networks have no <forward> element

        // Bridge name (auto-generated for NAT/isolated)
        $bridge = $dom->createElement('bridge');
        $bridge->setAttribute('name', 'virbr-' . substr(md5($this->name), 0, 6));
        $bridge->setAttribute('stp', 'on');
        $bridge->setAttribute('delay', '0');
        $network->appendChild($bridge);

        // IP configuration
        if ($this->ipAddress !== '') {
            $ip = $dom->createElement('ip');
            $ip->setAttribute('address', $this->ipAddress);
            $ip->setAttribute('netmask', $this->netmask);

            if ($this->dhcpEnabled && $this->dhcpStart !== '' && $this->dhcpEnd !== '') {
                $dhcp = $dom->createElement('dhcp');
                $range = $dom->createElement('range');
                $range->setAttribute('start', $this->dhcpStart);
                $range->setAttribute('end', $this->dhcpEnd);
                $dhcp->appendChild($range);
                $ip->appendChild($dhcp);
            }

            $network->appendChild($ip);
        }

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    /**
     * Parse a CIDR subnet and compute gateway IP, netmask, and DHCP range.
     */
    private function applySubnet(string $subnet): void
    {
        $parts = explode('/', $subnet);
        $networkAddr = $parts[0];
        $prefix = (int) ($parts[1] ?? 24);

        $netmaskLong = $prefix > 0 ? (~0 << (32 - $prefix)) & 0xFFFFFFFF : 0;
        $this->netmask = long2ip($netmaskLong);

        $networkLong = ip2long($networkAddr);
        if ($networkLong === false) {
            return;
        }

        // Gateway is .1 in the subnet
        $this->ipAddress = long2ip($networkLong + 1);

        // DHCP range: .2 to .254
        $hostBits = 32 - $prefix;
        $maxHost = (1 << $hostBits) - 2; // -2 for network and broadcast
        $this->dhcpStart = long2ip($networkLong + 2);
        $this->dhcpEnd = long2ip($networkLong + min($maxHost, 254));
    }
}
