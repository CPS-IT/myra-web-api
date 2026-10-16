<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Command;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Myracloud\WebApi\Endpoint\AbstractEndpoint;
use Myracloud\WebApi\Endpoint\CacheClear;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CacheClearCommand
 *
 * @package Myracloud\API\Command
 */
class CacheClearCommand extends AbstractCrudCommand
{
    /**
     *
     */
    protected function configure(): void
    {
        parent::configure();
        $this->setName('myracloud:api:cacheClear');
        $this->addOption('cleanupRule', 'c', InputOption::VALUE_REQUIRED, 'Rule that describes which files should be removed from the cache.', '*');
        $this->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Should the rule applied recursively.');
        $this->setDescription('CacheClear commands allows you to do a cache clear via Myra API.');
        $this->setHelp(<<<'TAG'
<fg=yellow>Example usage:</>
bin/console myracloud:api:cacheClear <fqdn>

<fg=yellow>Example Clearing all jpg files recursively:</>
bin/console myracloud:api:cacheClear <fqdn> -r -c *.jpg
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

            $options = $this->resolveOptions($input, $output);
            /** @var CacheClear $endpoint */
            $endpoint = $this->getEndpoint();
            $return   = $endpoint->clear($options['fqdn'], $options['fqdn'], $options['cleanupRule'], $options['recursive']);
        } catch (GuzzleException $e) {
            $this->handleTransferException($e, $output);

            return self::FAILURE;
        } catch (Exception $e) {
            $output->writeln('<fg=red;options=bold>Error:</>' . $e->getMessage());

            return self::FAILURE;
        }
        $this->checkResult($return, $output);

        return self::SUCCESS;
    }

    /**
     * @return AbstractEndpoint
     */
    protected function getEndpoint(): AbstractEndpoint
    {
        return $this->webapi->getCacheClearEndpoint();
    }

    /**
     * @param                 $data
     * @param OutputInterface $output
     */
    protected function writeTable($data, OutputInterface $output): void
    {
    }

    /**
     * @param array           $options
     * @param OutputInterface $output
     */
    protected function OpCreate(array $options, OutputInterface $output): void
    {
    }

    /**
     * @param array           $options
     * @param OutputInterface $output
     */
    protected function OpUpdate(array $options, OutputInterface $output): void
    {
    }
}
