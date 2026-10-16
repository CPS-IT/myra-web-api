<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Command;

use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use Myracloud\WebApi\Command\Enum\ExecutionTypeEnum;
use Myracloud\WebApi\Exception\CommandException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractCrudCommand extends AbstractCommand
{
    protected bool $fqdnOptional = false;

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('operation', 'o', InputOption::VALUE_REQUIRED, '', ExecutionTypeEnum::List->value);
        $this->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page to show when listing objects.', 1);
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Id to Update/Delete');
        $this->addArgument('fqdn', ($this->fqdnOptional ? InputArgument::OPTIONAL : InputArgument::REQUIRED), 'Domain that should be used.', null);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = $this->resolveOptions($input, $output);
            match (ExecutionTypeEnum::tryFrom($options['operation']??'')) {
                ExecutionTypeEnum::List => $this->OpList($options, $output),
                ExecutionTypeEnum::Delete => $this->OpDelete($options, $output),
                ExecutionTypeEnum::Create => $this->OpCreate($options, $output),
                ExecutionTypeEnum::Update => $this->OpUpdate($options, $output),
                ExecutionTypeEnum::Export => $this->OpExport($options, $output),
                default => throw new CommandException('--operation must be one of ' . implode(',', ExecutionTypeEnum::values()))
            };

        } catch (GuzzleException $e) {
            $this->handleTransferException($e, $output);
            return Command::FAILURE;
        } catch (CommandException $e) {
            $output->writeln('<fg=red;options=bold>Error:</> ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array $options
     * @param OutputInterface $output
     * @return void
     * @throws GuzzleException
     */
    protected function OpList(array $options, OutputInterface $output): void
    {
        $return   = $this->getEndpoint()->getList($options['fqdn'], $options['page']);
        $this->checkResult($return, $output);
        $this->writeTable($return['list'], $output);
        if ($output->isVerbose()) {
            print_r($return);
        }
    }

    /**
     * @param                 $data
     * @param OutputInterface $output
     */
    abstract protected function writeTable($data, OutputInterface $output): void;

    /**
     * @param array           $options
     * @param OutputInterface $output
     */
    abstract protected function OpCreate(array $options, OutputInterface $output): void;

    /**
     * @param array           $options
     * @param OutputInterface $output
     */
    abstract protected function OpUpdate(array $options, OutputInterface $output): void;

    /**
     * @param array $options
     * @param OutputInterface $output
     * @return void
     * @throws GuzzleException
     */
    protected function OpDelete(array $options, OutputInterface $output): void
    {
        if ($options['id'] == null) {
            throw new RuntimeException('You need to define the id of the object to delete via --id');
        }
        $existing = $this->findById($options);
        $return = $this->getEndpoint()->delete($options['fqdn'], $options['id'], new DateTimeImmutable($existing['modified']));
        $this->handleDeleteReturn($return, $output);
    }

    /**
     * @param array $options
     * @return array
     * @throws GuzzleException
     */
    protected function findById(array $options): array
    {
        if ($options['id'] == null) {
            throw new RuntimeException('You need to define the id of the object via --id');
        }
        $return   = $this->getEndpoint()->getList($options['fqdn'], $options['page']);
        foreach ($return['list'] as $item) {
            if ($item['id'] == $options['id']) {
                return $item;
            }
        }
        throw new RuntimeException('Could not find an object with the passed id.');
    }

    /**
     * @param                 $return
     * @param OutputInterface $output
     */
    protected function handleDeleteReturn($return, OutputInterface $output): void
    {
        $this->checkResult($return, $output);
        $this->writeTable($return['targetObject'], $output);

        if (count($return['targetObject']) == 0) {
            $output->writeln('<fg=yellow;options=bold>Notice:</> No objects where deleted. Did you pass a valid Id?');
        }

        if ($output->isVerbose()) {
            print_r($return);
        }
    }

    /**
     * @param array $options
     * @param OutputInterface $output
     * @return void
     * @throws GuzzleException
     */
    protected function OpExport(array $options, OutputInterface $output): void
    {
        $date     = new DateTimeImmutable();
        $endpoint = $this->getEndpoint();
        $return   = $endpoint->getList($options['fqdn'], $options['page']);
        $this->checkResult($return, $output);
        $yaml     = Yaml::dump($return['list']);
        $header   = '# ' . $options['fqdn'] . "\n";
        $header   .= '# ' . $endpoint->getEndPoint() . "\n";
        $header   .= '# ' . $date->format(DATE_RFC3339) . "\n";
        $filename = 'export_' . $endpoint->getEndPoint() . '_' . str_replace(':', '-', $options['fqdn']) . '_' . $date->format('Ymd_His') . '.yml';
        file_put_contents($filename, $header . $yaml);
        $output->writeln('Exported ' . count($return['list']) . ' entries to ' . $filename);
    }

    /**
     * @param                 $return
     * @param OutputInterface $output
     */
    protected function handleTableReturn($return, OutputInterface $output): void
    {
        $this->checkResult($return, $output);
        $this->writeTable($return['targetObject'], $output);
        if ($output->isVerbose()) {
            print_r($return);
        }
    }
}
