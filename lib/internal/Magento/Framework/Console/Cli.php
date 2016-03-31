<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Application as SymfonyApplication;
use Magento\Setup\Mvc\Bootstrap\InitParamListener;
use Magento\Setup\Console\CompilerPreparation;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\File\WriteFactory;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Shell\ComplexParameter;

/**
 * Magento 2 CLI Application. This is the hood for all command line tools supported by Magento
 *
 * {@inheritdoc}
 */
class Cli extends SymfonyApplication
{
    /**
     * Name of input option
     */
    const INPUT_KEY_BOOTSTRAP = 'bootstrap';

    /** @var \Zend\ServiceManager\ServiceManager */
    private $serviceManager;

    /**
     * Initialization exception
     *
     * @var \Exception
     */
    private $initException;

    /**
     * Process an error happened during initialization of commands, if any
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $exitCode = parent::doRun($input, $output);
        if ($this->initException) {
            $output->writeln(
                "<error>We're sorry, an error occurred. Try clearing the cache and code generation directories. "
                . "By default, they are: var/cache, var/di, var/generation, and var/page_cache.</error>"
            );
            throw $this->initException;
        }
        return $exitCode;
    }

    /**
     * @param string $name  application name
     * @param string $version application version
     */
    public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN')
    {
        $this->serviceManager = \Zend\Mvc\Application::init(require BP . '/setup/config/application.config.php')
            ->getServiceManager();
        if (!$this->checkGenerationDirectoryAccess()) {
            $output = new ConsoleOutput();
            $output->writeln(
                '<error>Command line user does not have read and write permissions on var/generation directory.  Please'
                . ' address this issue before using Magento command line.</error>'
            );
            exit(0);
        }
        /**
         * Temporary workaround until the compiler is able to clear the generation directory
         * @todo remove after MAGETWO-44493 resolved
         */
        if (class_exists(CompilerPreparation::class)) {
            $compilerPreparation = new CompilerPreparation($this->serviceManager, new ArgvInput(), new File());
            $compilerPreparation->handleCompilerEnvironment();
        }
        parent::__construct($name, $version);
    }

    /**
     * Check var/generation read and write access
     *
     * @return bool
     */
    private function checkGenerationDirectoryAccess()
    {
        $initParams = $this->serviceManager->get(InitParamListener::BOOTSTRAP_PARAM);
        $filesystemDirPaths = isset($initParams[Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS])
            ? $initParams[Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS]
            : [];
        $directoryList = new DirectoryList(BP, $filesystemDirPaths);
        $generationDirectoryPath = $directoryList->getPath(DirectoryList::GENERATION);
        $driverPool = new DriverPool();
        $fileWriteFactory = new WriteFactory($driverPool);
        /** @var \Magento\Framework\Filesystem\DriverInterface $driver */
        $driver = $driverPool->getDriver(DriverPool::FILE);
        $directoryWrite = new Write($fileWriteFactory, $driver, $generationDirectoryPath);
        if ($directoryWrite->isExist()) {
            if ($directoryWrite->isDirectory()
                || $directoryWrite->isReadable()
            ) {
                try {
                    $probeFilePath = $generationDirectoryPath . DIRECTORY_SEPARATOR . time();
                    $fileWriteFactory->create($probeFilePath, DriverPool::FILE, 'w');
                    $directoryWrite->delete($probeFilePath);
                } catch (\Exception $e) {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            try {
                $directoryWrite->create();
            } catch (\Exception $e) {
                return false;
            }
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultCommands()
    {
        return array_merge(parent::getDefaultCommands(), $this->getApplicationCommands());
    }

    /**
     * Gets application commands
     *
     * @return array
     */
    protected function getApplicationCommands()
    {
        $commands = [];
        try {
            $bootstrapParam = new ComplexParameter(self::INPUT_KEY_BOOTSTRAP);
            $params = $bootstrapParam->mergeFromArgv($_SERVER, $_SERVER);
            $params[Bootstrap::PARAM_REQUIRE_MAINTENANCE] = null;
            $bootstrap = Bootstrap::create(BP, $params);
            $objectManager = $bootstrap->getObjectManager();
            /** @var \Magento\Setup\Model\ObjectManagerProvider $omProvider */
            $omProvider = $this->serviceManager->get('Magento\Setup\Model\ObjectManagerProvider');
            $omProvider->setObjectManager($objectManager);

            if (class_exists('Magento\Setup\Console\CommandList')) {
                $setupCommandList = new \Magento\Setup\Console\CommandList($this->serviceManager);
                $commands = array_merge($commands, $setupCommandList->getCommands());
            }

            if ($objectManager->get('Magento\Framework\App\DeploymentConfig')->isAvailable()) {
                /** @var \Magento\Framework\Console\CommandList $commandList */
                $commandList = $objectManager->create('Magento\Framework\Console\CommandList');
                $commands = array_merge($commands, $commandList->getCommands());
            }

            $commands = array_merge($commands, $this->getVendorCommands($objectManager));
        } catch (\Exception $e) {
            $this->initException = $e;
        }
        return $commands;
    }

    /**
     * Gets vendor commands
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @return array
     */
    protected function getVendorCommands($objectManager)
    {
        $commands = [];
        foreach (CommandLocator::getCommands() as $commandListClass) {
            if (class_exists($commandListClass)) {
                $commands = array_merge(
                    $commands,
                    $objectManager->create($commandListClass)->getCommands()
                );
            }
        }
        return $commands;
    }
}
