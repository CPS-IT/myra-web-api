<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Command;

use GuzzleHttp\Exception\GuzzleException;
use Myracloud\WebApi\Endpoint\AbstractEndpoint;
use Myracloud\WebApi\Exception\CommandException;
use Myracloud\WebApi\WebApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class AbstractCommand
 *
 * @package Myracloud\WebApi\Command
 */
abstract class AbstractCommand extends Command
{
    protected ?WebApi $webapi = null;

    protected function configure(): void
    {
        $this->addOption('apiKey', 'k', InputOption::VALUE_REQUIRED, 'Api key to authenticate against Myra API.', null);
        $this->addOption('secret', 's', InputOption::VALUE_REQUIRED, 'Secret to authenticate against Myra API.', null);
        $this->addOption('endpoint', 'e', InputOption::VALUE_OPTIONAL, 'API endpoint.', 'api.myracloud.com');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $confPath = ROOTDIR . DIRECTORY_SEPARATOR . 'config.php';
        $config   = [];
        if (file_exists($confPath)) {
            $configData = include $confPath;
            if (empty($input->getOption('apiKey')) && isset($configData['apikey'])) {
                $input->setOption('apiKey', $configData['apikey']);
            }
            if (empty($input->getOption('secret')) && isset($configData['secret'])) {
                $input->setOption('secret', $configData['secret']);
            }
            if (empty($input->getOption('endpoint')) && isset($configData['endpoint'])) {
                $input->setOption('endpoint', $configData['endpoint']);
            }
        }
    }

    /**
     * Resolve given options
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return array
     * @throws CommandException
     */
    protected function resolveOptions(InputInterface $input, OutputInterface $output): array
    {
        $options = array_merge($input->getArguments(), $input->getOptions());
        if (empty($options['apiKey']) || empty($options['secret'])) {
            throw new CommandException('apiKey and secret have to be provided either by parameter or config file.');
        }
        $this->webapi ??= new WebApi($options['apiKey'], $options['secret'], $options['endpoint']);

        return $options;
    }


    /**
     * @return AbstractEndpoint
     */
    abstract protected function getEndpoint(): AbstractEndpoint;

    /**
     * @param                 $data
     * @param OutputInterface $output
     */
    protected function checkResult($data, OutputInterface $output): void
    {
        if (isset($data['error'])) {
            if (!empty($data['error'])) {
                if (array_key_exists('exception', $data)) {
                    $output->writeln('<fg=red;options=bold>API Exception:</> ' . $data['exception']['type'] . ' ' . $data['exception']['message']);
                }
                foreach ($data['violationList'] as $violation) {
                    $output->writeln('<fg=red;options=bold>API Error:</> ' . (array_key_exists('propertyPath', $violation) ? ($violation['propertyPath'] . ' ') : '') . $violation['message']);
                }
            } else {
                $output->writeln('<fg=green;options=bold>Request Successful</> ');
            }
        }
        if ($output->isVerbose()) {
            print_r($data);
        }
    }


    /**
     * @param GuzzleException $e
     * @param OutputInterface   $output
     */
    protected function handleTransferException(GuzzleException $e, OutputInterface $output): void
    {
        $output->writeln('<fg=red;options=bold>Error:</> ' . $e->getMessage());
        $output->writeln('<fg=red;options=bold>Error:</> Are you using the correct key/secret?');
        $output->writeln('<fg=red;options=bold>Error:</> Is the domain attached to the account associated with this key/secret combination?');
    }
}
