<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Command;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use IPTools\IP;
use IPTools\Network;
use Myracloud\WebApi\Command\Enum\WhitelistFormatEnum;
use Myracloud\WebApi\Endpoint\Networks;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WhitelistToolCommand extends AbstractCommand
{
    /**
     *
     */
    protected function configure(): void
    {
        parent::configure();
        $this->setName('myracloud:tools:gen-whitelist');
        $this->setDescription('Export Firewall rules for an Origin Host, allowing access by the current Myracloud Hosts.');

        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format for export.', WhitelistFormatEnum::IPTables->value);

        $this->setHelp(<<<'TAG'
Will generate a Firewall Ruleset to whitelist all currently active Myracloud Hosts on the Origin server.

<fg=yellow>Example usage:</>
bin/console myracloud:tools:gen-whitelist -f iptables

<fg=yellow>Supported Formats:</>

iptables (only Ipv4)
ip6tables (only Ipv6)
ipset (Ipv4 and Ipv6)
nftables (Ipv4 and Ipv6)
TAG
        );

    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options  = $this->resolveOptions($input, $output);
            $endpoint = $this->getEndpoint();
            $data     = $endpoint->getList();
            $format = WhitelistFormatEnum::tryFrom($options['format']);
            $output->writeln(
                [
                    '######################################################',
                    '# Format: ' . $format->value,
                    '######################################################',
                ]
            );

            match ($format) {
                WhitelistFormatEnum::IPTables => $output->writeln($this->renderIpTables($data['list'])),
                WhitelistFormatEnum::IP6Tables => $output->writeln($this->renderIp6Tables($data['list'])),
                WhitelistFormatEnum::IPSet => $output->writeln($this->renderIpset($data['list'])),
                WhitelistFormatEnum::NFTables => $output->writeln($this->renderNFTSet($data['list']))
            };

        } catch (GuzzleException $e) {
            $this->handleTransferException($e, $output);

            return self::FAILURE;
        } catch (Exception $e) {
            $output->writeln('<fg=red;options=bold>Error:</>' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return Networks
     */
    protected function getEndpoint(): Networks
    {
        return $this->webapi->getNetworksEndpoint();
    }

    /**
     * @param array $data
     * @return array
     */
    private function renderIpTables(array $data): array
    {
        $lines = ['iptables -N myrawhite4'];
        foreach ($data as $entry) {
            $net = Network::parse($entry['network']);
            if ($entry['enabled'] && $net->getIP()->getVersion() == IP::IP_V4) {
                $lines[] = 'iptables -A myrawhite4 -s ' . $entry['network'] . ' -j ACCEPT';
            }
        }
        $lines[] = 'iptables -A myrawhite4 -j RETURN';
        $lines[] = ' ';

        return $lines;
    }

    /**
     * @param array $data
     * @return array
     */
    private function renderIp6Tables(array $data): array
    {
        $lines = ['ip6tables -N myrawhite6'];
        foreach ($data as $entry) {
            $net = Network::parse($entry['network']);
            if ($entry['enabled'] && $net->getIP()->getVersion() == IP::IP_V6) {
                $lines[] = 'ip6tables -A myrawhite6 -s ' . $entry['network'] . ' -j ACCEPT';
            }
        }
        $lines[] = 'ip6tables -A myrawhite6 -j RETURN';
        $lines[] = ' ';

        return $lines;
    }

    /**
     * @param array $data
     * @return array
     */
    private function renderIpSet(array $data): array
    {
        $v4 = ['create -exist myrawhite4 hash:net family inet hashsize 1024 maxelem 65536 comment'];
        $v6 = ['create -exist myrawhite6 hash:net family inet6 hashsize 1024 maxelem 65536 comment'];
        foreach ($data as $entry) {
            $net = Network::parse($entry['network']);
            if ($entry['enabled']) {
                if ($net->getIP()->getVersion() == IP::IP_V4) {
                    $v4[] = 'add myrawhite4 ' . $entry['network'];
                }
                if ($net->getIP()->getVersion() == IP::IP_V6) {
                    $v6[] = 'add myrawhite6 ' . $entry['network'];
                }
            }
        }

        return array_merge($v4, [''], $v6);
    }

    private function renderNFTSet(array $data): array
    {

        $v4   = ['nft add table ip filter'];
        $v4[] = 'nft add chain ip filter myrawhite4';
        $v6   = ['nft add table ip6 filter'];
        $v6[] = 'nft add chain ip6 filter myrawhite6';
        foreach ($data as $entry) {
            $net = Network::parse($entry['network']);
            if ($entry['enabled']) {
                if ($net->getIP()->getVersion() == IP::IP_V4) {
                    $v4[] = 'nft add rule ip filter myrawhite4 ip saddr ' . $entry['network'] . ' counter accept';
                }
                if ($net->getIP()->getVersion() == IP::IP_V6) {
                    $v6[] = 'nft add rule ip6 filter myrawhite6 ip6 saddr ' . $entry['network'] . ' counter accept';
                }
            }
        }

        $v4[] = 'nft add rule ip filter myrawhite4 counter return';
        $v6[] = 'nft add rule ip6 filter myrawhite6 counter return';

        return array_merge($v4, [''], $v6);
    }
}
