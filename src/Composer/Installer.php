<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer;

use Composer\Autoload\AutoloadGenerator;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Downloader\DownloadManager;
use Composer\Installer\InstallationManager;
use Composer\Config;
use Composer\Installer\NoopInstaller;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\EventDispatcher;
use Composer\Script\ScriptEvents;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Beau Simensen <beau@dflydev.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class Installer
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var RootPackageInterface
     */
    protected $package;

    /**
     * @var DownloadManager
     */
    protected $downloadManager;

    /**
     * @var RepositoryManager
     */
    protected $repositoryManager;

    /**
     * @var Locker
     */
    protected $locker;

    /**
     * @var InstallationManager
     */
    protected $installationManager;

    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var AutoloadGenerator
     */
    protected $autoloadGenerator;

    protected $preferSource = false;
    protected $preferDist = false;
    protected $optimizeAutoloader = false;
    protected $devMode = false;
    protected $dryRun = false;
    protected $verbose = false;
    protected $update = false;
    protected $runScripts = true;
    protected $updateWhitelist = null;

    /**
     * @var array
     */
    protected $suggestedPackages;

    /**
     * @var RepositoryInterface
     */
    protected $additionalInstalledRepository;

    /**
     * Constructor
     *
     * @param IOInterface          $io
     * @param Config               $config
     * @param RootPackageInterface $package
     * @param DownloadManager      $downloadManager
     * @param RepositoryManager    $repositoryManager
     * @param Locker               $locker
     * @param InstallationManager  $installationManager
     * @param EventDispatcher      $eventDispatcher
     * @param AutoloadGenerator    $autoloadGenerator
     */
    public function __construct(IOInterface $io, Config $config, RootPackageInterface $package, DownloadManager $downloadManager, RepositoryManager $repositoryManager, Locker $locker, InstallationManager $installationManager, EventDispatcher $eventDispatcher, AutoloadGenerator $autoloadGenerator)
    {
        $this->io = $io;
        $this->config = $config;
        $this->package = $package;
        $this->downloadManager = $downloadManager;
        $this->repositoryManager = $repositoryManager;
        $this->locker = $locker;
        $this->installationManager = $installationManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->autoloadGenerator = $autoloadGenerator;
    }

    /**
     * Run installation (or update)
     */
    public function run()
    {
        if ($this->dryRun) {
            $this->verbose = true;
            $this->runScripts = false;
            $this->installationManager->addInstaller(new NoopInstaller);
            $this->mockLocalRepositories($this->repositoryManager);
        }

        // TODO remove this BC feature at some point
        // purge old require-dev packages to avoid conflicts with the new way of handling dev requirements
        $devRepo = new InstalledFilesystemRepository(new JsonFile($this->config->get('vendor-dir').'/composer/installed_dev.json'));
        if ($devRepo->getPackages()) {
            $this->io->write('<warning>BC Notice: Removing old dev packages to migrate to the new require-dev handling.</warning>');
            foreach ($devRepo->getPackages() as $package) {
                if ($this->installationManager->isPackageInstalled($devRepo, $package)) {
                    $this->installationManager->uninstall($devRepo, new UninstallOperation($package));
                }
            }
            unlink($this->config->get('vendor-dir').'/composer/installed_dev.json');
        }
        unset($devRepo, $package);
        // end BC

        if ($this->preferSource) {
            $this->downloadManager->setPreferSource(true);
        }
        if ($this->preferDist) {
            $this->downloadManager->setPreferDist(true);
        }

        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $installedRootPackage = clone $this->package;
        $installedRootPackage->setRequires(array());
        $installedRootPackage->setDevRequires(array());

        $localRepo = $this->repositoryManager->getLocalRepository();
        $platformRepo = new PlatformRepository();
        $repos = array(
            $localRepo,
            new InstalledArrayRepository(array($installedRootPackage)),
            $platformRepo,
        );
        $installedRepo = new CompositeRepository($repos);
        if ($this->additionalInstalledRepository) {
            $installedRepo->addRepository($this->additionalInstalledRepository);
        }

        $aliases = $this->getRootAliases();
        $this->aliasPlatformPackages($platformRepo, $aliases);

        if ($this->runScripts) {
            // dispatch pre event
            $eventName = $this->update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $this->eventDispatcher->dispatchCommandEvent($eventName, $this->devMode);
        }

        try {
            $this->suggestedPackages = array();
            if (!$this->doInstall($localRepo, $installedRepo, $platformRepo, $aliases, $this->devMode)) {
                return false;
            }
        } catch (\Exception $e) {
            $this->installationManager->notifyInstalls();

            throw $e;
        }
        $this->installationManager->notifyInstalls();

        // output suggestions
        foreach ($this->suggestedPackages as $suggestion) {
            $target = $suggestion['target'];
            foreach ($installedRepo->getPackages() as $package) {
                if (in_array($target, $package->getNames())) {
                    continue 2;
                }
            }

            $this->io->write($suggestion['source'].' suggests installing '.$suggestion['target'].' ('.$suggestion['reason'].')');
        }

        if (!$this->dryRun) {
            // write lock
            if ($this->update || !$this->locker->isLocked()) {
                $localRepo->reload();

                // if this is not run in dev mode and the root has dev requires, the lock must
                // contain null to prevent dev installs from a non-dev lock
                $devPackages = ($this->devMode || !$this->package->getDevRequires()) ? array() : null;

                // split dev and non-dev requirements by checking what would be removed if we update without the dev requirements
                if ($this->devMode && $this->package->getDevRequires()) {
                    $policy = $this->createPolicy();
                    $pool = $this->createPool();
                    $pool->addRepository($installedRepo, $aliases);

                    // creating requirements request
                    $request = $this->createRequest($pool, $this->package, $platformRepo);
                    $request->updateAll();
                    foreach ($this->package->getRequires() as $link) {
                        $request->install($link->getTarget(), $link->getConstraint());
                    }

                    $solver = new Solver($policy, $pool, $installedRepo);
                    $ops = $solver->solve($request);
                    foreach ($ops as $op) {
                        if ($op->getJobType() === 'uninstall') {
                            $devPackages[] = $op->getPackage();
                        }
                    }
                }

                $platformReqs = $this->extractPlatformRequirements($this->package->getRequires());
                $platformDevReqs = $this->devMode ? $this->extractPlatformRequirements($this->package->getDevRequires()) : array();

                $updatedLock = $this->locker->setLockData(
                    array_diff($localRepo->getPackages(), (array) $devPackages),
                    $devPackages,
                    $platformReqs,
                    $platformDevReqs,
                    $aliases,
                    $this->package->getMinimumStability(),
                    $this->package->getStabilityFlags()
                );
                if ($updatedLock) {
                    $this->io->write('<info>Writing lock file</info>');
                }
            }

            // write autoloader
            $this->io->write('<info>Generating autoload files</info>');
            $this->autoloadGenerator->dump($this->config, $localRepo, $this->package, $this->installationManager, 'composer', $this->optimizeAutoloader);

            if ($this->runScripts) {
                // dispatch post event
                $eventName = $this->update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
                $this->eventDispatcher->dispatchCommandEvent($eventName, $this->devMode);
            }
        }

        return true;
    }

    protected function doInstall($localRepo, $installedRepo, $platformRepo, $aliases, $withDevReqs)
    {
        // init vars
        $lockedRepository = null;
        $repositories = null;

        // initialize locker to create aliased packages
        $installFromLock = false;
        if (!$this->update && $this->locker->isLocked()) {
            $installFromLock = true;
            $lockedRepository = $this->locker->getLockedRepository($withDevReqs);
        }

        $this->whitelistUpdateDependencies(
            $localRepo,
            $withDevReqs,
            $this->package->getRequires(),
            $this->package->getDevRequires()
        );

        $this->io->write('<info>Loading composer repositories with package information</info>');

        // creating repository pool
        $policy = $this->createPolicy();
        $pool = $this->createPool();
        $pool->addRepository($installedRepo, $aliases);
        if ($installFromLock) {
            $pool->addRepository($lockedRepository, $aliases);
        }

        if (!$installFromLock) {
            $repositories = $this->repositoryManager->getRepositories();
            foreach ($repositories as $repository) {
                $pool->addRepository($repository, $aliases);
            }
        }

        // creating requirements request
        $request = $this->createRequest($pool, $this->package, $platformRepo);

        if (!$installFromLock) {
            // remove unstable packages from the localRepo if they don't match the current stability settings
            $removedUnstablePackages = array();
            foreach ($localRepo->getPackages() as $package) {
                if (
                    !$pool->isPackageAcceptable($package->getName(), $package->getStability())
                    && $this->installationManager->isPackageInstalled($localRepo, $package)
                ) {
                    $removedUnstablePackages[$package->getName()] = true;
                    $request->remove($package->getName(), new VersionConstraint('=', $package->getVersion()));
                }
            }
        }

        if ($this->update) {
            $this->io->write('<info>Updating dependencies'.($withDevReqs?' (including require-dev)':'').'</info>');

            $request->updateAll();

            if ($withDevReqs) {
                $links = array_merge($this->package->getRequires(), $this->package->getDevRequires());
            } else {
                $links = $this->package->getRequires();
            }

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }

            // if the updateWhitelist is enabled, packages not in it are also fixed
            // to the version specified in the lock, or their currently installed version
            if ($this->updateWhitelist) {
                if ($this->locker->isLocked()) {
                    try {
                        $currentPackages = $this->locker->getLockedRepository($withDevReqs)->getPackages();
                    } catch (\RuntimeException $e) {
                        // fetch only non-dev packages from lock if doing a dev update fails due to a previously incomplete lock file
                        $currentPackages = $this->locker->getLockedRepository()->getPackages();
                    }
                } else {
                    $currentPackages = $installedRepo->getPackages();
                }

                // collect packages to fixate from root requirements as well as installed packages
                $candidates = array();
                foreach ($links as $link) {
                    $candidates[$link->getTarget()] = true;
                }
                foreach ($localRepo->getPackages() as $package) {
                    $candidates[$package->getName()] = true;
                }

                // fix them to the version in lock (or currently installed) if they are not updateable
                foreach ($candidates as $candidate => $dummy) {
                    foreach ($currentPackages as $curPackage) {
                        if ($curPackage->getName() === $candidate) {
                            if (!$this->isUpdateable($curPackage) && !isset($removedUnstablePackages[$curPackage->getName()])) {
                                $constraint = new VersionConstraint('=', $curPackage->getVersion());
                                $request->install($curPackage->getName(), $constraint);
                            }
                            break;
                        }
                    }
                }
            }
        } elseif ($installFromLock) {
            $this->io->write('<info>Installing dependencies'.($withDevReqs?' (including require-dev)':'').' from lock file</info>');

            if (!$this->locker->isFresh()) {
                $this->io->write('<warning>Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. Run update to update them.</warning>');
            }

            foreach ($lockedRepository->getPackages() as $package) {
                $version = $package->getVersion();
                if (isset($aliases[$package->getName()][$version])) {
                    $version = $aliases[$package->getName()][$version]['alias_normalized'];
                }
                $constraint = new VersionConstraint('=', $version);
                $constraint->setPrettyString($package->getPrettyVersion());
                $request->install($package->getName(), $constraint);
            }

            foreach ($this->locker->getPlatformRequirements($withDevReqs) as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        } else {
            $this->io->write('<info>Installing dependencies'.($withDevReqs?' (including require-dev)':'').'</info>');

            if ($withDevReqs) {
                $links = array_merge($this->package->getRequires(), $this->package->getDevRequires());
            } else {
                $links = $this->package->getRequires();
            }

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }

        // force dev packages to have the latest links if we update or install from a (potentially new) lock
        $this->processDevPackages($localRepo, $pool, $policy, $repositories, $lockedRepository, $installFromLock, 'force-links');

        // solve dependencies
        $solver = new Solver($policy, $pool, $installedRepo);
        try {
            $operations = $solver->solve($request);
        } catch (SolverProblemsException $e) {
            $this->io->write('<error>Your requirements could not be resolved to an installable set of packages.</error>');
            $this->io->write($e->getMessage());

            return false;
        }

        // force dev packages to be updated if we update or install from a (potentially new) lock
        $operations = $this->processDevPackages($localRepo, $pool, $policy, $repositories, $lockedRepository, $installFromLock, 'force-updates', $operations);

        // execute operations
        if (!$operations) {
            $this->io->write('Nothing to install or update');
        }

        foreach ($operations as $operation) {
            // collect suggestions
            if ('install' === $operation->getJobType()) {
                foreach ($operation->getPackage()->getSuggests() as $target => $reason) {
                    $this->suggestedPackages[] = array(
                        'source' => $operation->getPackage()->getPrettyName(),
                        'target' => $target,
                        'reason' => $reason,
                    );
                }
            }

            $event = 'Composer\Script\ScriptEvents::PRE_PACKAGE_'.strtoupper($operation->getJobType());
            if (defined($event) && $this->runScripts) {
                $this->eventDispatcher->dispatchPackageEvent(constant($event), $this->devMode, $operation);
            }

            // not installing from lock, force dev packages' references if they're in root package refs
            if (!$installFromLock) {
                $package = null;
                if ('update' === $operation->getJobType()) {
                    $package = $operation->getTargetPackage();
                } elseif ('install' === $operation->getJobType()) {
                    $package = $operation->getPackage();
                }
                if ($package && $package->isDev()) {
                    $references = $this->package->getReferences();
                    if (isset($references[$package->getName()])) {
                        $package->setSourceReference($references[$package->getName()]);
                        $package->setDistReference($references[$package->getName()]);
                    }
                }
            }

            // output alias operations in verbose mode, or all ops in dry run
            if ($this->dryRun || ($this->verbose && false !== strpos($operation->getJobType(), 'Alias'))) {
                $this->io->write('  - ' . $operation);
            }

            $this->installationManager->execute($localRepo, $operation);

            $event = 'Composer\Script\ScriptEvents::POST_PACKAGE_'.strtoupper($operation->getJobType());
            if (defined($event) && $this->runScripts) {
                $this->eventDispatcher->dispatchPackageEvent(constant($event), $this->devMode, $operation);
            }

            if (!$this->dryRun) {
                $localRepo->write();
            }
        }

        return true;
    }

    private function createPool()
    {
        $minimumStability = $this->package->getMinimumStability();
        $stabilityFlags = $this->package->getStabilityFlags();

        if (!$this->update && $this->locker->isLocked()) {
            $minimumStability = $this->locker->getMinimumStability();
            $stabilityFlags = $this->locker->getStabilityFlags();
        }

        return new Pool($minimumStability, $stabilityFlags);
    }

    private function createPolicy()
    {
        return new DefaultPolicy($this->package->getPreferStable());
    }

    private function createRequest(Pool $pool, RootPackageInterface $rootPackage, PlatformRepository $platformRepo)
    {
        $request = new Request($pool);

        $constraint = new VersionConstraint('=', $rootPackage->getVersion());
        $constraint->setPrettyString($rootPackage->getPrettyVersion());
        $request->install($rootPackage->getName(), $constraint);

        // fix the version of all platform packages to prevent the solver trying to remove those
        foreach ($platformRepo->getPackages() as $package) {
            $constraint = new VersionConstraint('=', $package->getVersion());
            $constraint->setPrettyString($package->getPrettyVersion());

            if (!($provided = $rootPackage->getProvides())
                || !isset($provided[$package->getName()])
                || !$provided[$package->getName()]->getConstraint()->matches($constraint)
            ) {
                $request->install($package->getName(), $constraint);
            }
        }

        return $request;
    }

    private function processDevPackages($localRepo, $pool, $policy, $repositories, $lockedRepository, $installFromLock, $task, array $operations = null)
    {
        if ($task === 'force-updates' && null === $operations) {
            throw new \InvalidArgumentException('Missing operations argument');
        }
        if ($task === 'force-links') {
            $operations = array();
        }

        foreach ($localRepo->getPackages() as $package) {
            // skip non-dev packages
            if (!$package->isDev()) {
                continue;
            }

            if ($package instanceof AliasPackage) {
                continue;
            }

            // skip packages that will be updated/uninstalled
            foreach ($operations as $operation) {
                if (('update' === $operation->getJobType() && $operation->getInitialPackage()->equals($package))
                    || ('uninstall' === $operation->getJobType() && $operation->getPackage()->equals($package))
                ) {
                    continue 2;
                }
            }

            // force update to locked version if it does not match the installed version
            if ($installFromLock) {
                foreach ($lockedRepository->findPackages($package->getName()) as $lockedPackage) {
                    if ($lockedPackage->isDev() && $lockedPackage->getVersion() === $package->getVersion()) {
                        if ($task === 'force-links') {
                            $package->setRequires($lockedPackage->getRequires());
                            $package->setConflicts($lockedPackage->getConflicts());
                            $package->setProvides($lockedPackage->getProvides());
                            $package->setReplaces($lockedPackage->getReplaces());
                        } elseif ($task === 'force-updates') {
                            if (($lockedPackage->getSourceReference() && $lockedPackage->getSourceReference() !== $package->getSourceReference())
                                || ($lockedPackage->getDistReference() && $lockedPackage->getDistReference() !== $package->getDistReference())
                            ) {
                                $operations[] = new UpdateOperation($package, $lockedPackage);
                            }
                        }

                        break;
                    }
                }
            } else {
                // force update to latest on update
                if ($this->update) {
                    // skip package if the whitelist is enabled and it is not in it
                    if ($this->updateWhitelist && !$this->isUpdateable($package)) {
                        continue;
                    }

                    // find similar packages (name/version) in all repositories
                    $matches = $pool->whatProvides($package->getName(), new VersionConstraint('=', $package->getVersion()));
                    foreach ($matches as $index => $match) {
                        // skip local packages
                        if (!in_array($match->getRepository(), $repositories, true)) {
                            unset($matches[$index]);
                            continue;
                        }

                        // skip providers/replacers
                        if ($match->getName() !== $package->getName()) {
                            unset($matches[$index]);
                            continue;
                        }

                        $matches[$index] = $match->getId();
                    }

                    // select prefered package according to policy rules
                    if ($matches && $matches = $policy->selectPreferedPackages($pool, array(), $matches)) {
                        $newPackage = $pool->literalToPackage($matches[0]);

                        if ($task === 'force-links' && $newPackage) {
                            $package->setRequires($newPackage->getRequires());
                            $package->setConflicts($newPackage->getConflicts());
                            $package->setProvides($newPackage->getProvides());
                            $package->setReplaces($newPackage->getReplaces());
                        }

                        if ($task === 'force-updates' && $newPackage && (
                            (($newPackage->getSourceReference() && $newPackage->getSourceReference() !== $package->getSourceReference())
                                || ($newPackage->getDistReference() && $newPackage->getDistReference() !== $package->getDistReference())
                            )
                        )) {
                            $operations[] = new UpdateOperation($package, $newPackage);
                        }
                    }
                }

                if ($task === 'force-updates') {
                    // force installed package to update to referenced version if it does not match the installed version
                    $references = $this->package->getReferences();

                    if (isset($references[$package->getName()]) && $references[$package->getName()] !== $package->getSourceReference()) {
                        // changing the source ref to update to will be handled in the operations loop below
                        $operations[] = new UpdateOperation($package, clone $package);
                    }
                }
            }
        }

        return $operations;
    }

    private function getRootAliases()
    {
        if (!$this->update && $this->locker->isLocked()) {
            $aliases = $this->locker->getAliases();
        } else {
            $aliases = $this->package->getAliases();
        }

        $normalizedAliases = array();

        foreach ($aliases as $alias) {
            $normalizedAliases[$alias['package']][$alias['version']] = array(
                'alias' => $alias['alias'],
                'alias_normalized' => $alias['alias_normalized']
            );
        }

        return $normalizedAliases;
    }

    private function aliasPlatformPackages(PlatformRepository $platformRepo, $aliases)
    {
        foreach ($aliases as $package => $versions) {
            foreach ($versions as $version => $alias) {
                $packages = $platformRepo->findPackages($package, $version);
                foreach ($packages as $package) {
                    $package->setAlias($alias['alias_normalized']);
                    $package->setPrettyAlias($alias['alias']);
                    $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
                    $aliasPackage->setRootPackageAlias(true);
                    $platformRepo->addPackage($aliasPackage);
                }
            }
        }
    }

    private function isUpdateable(PackageInterface $package)
    {
        if (!$this->updateWhitelist) {
            throw new \LogicException('isUpdateable should only be called when a whitelist is present');
        }

        foreach ($this->updateWhitelist as $whiteListedPattern => $void) {
            $cleanedWhiteListedPattern = str_replace('\\*', '.*', preg_quote($whiteListedPattern));

            if (preg_match("{^".$cleanedWhiteListedPattern."$}i", $package->getName())) {
                return true;
            }
        }

        return false;
    }

    private function extractPlatformRequirements($links) {
        $platformReqs = array();
        foreach ($links as $link) {
            if (preg_match('{^(?:php(?:-64bit)?|(?:ext|lib)-[^/]+)$}i', $link->getTarget())) {
                $platformReqs[$link->getTarget()] = $link->getPrettyConstraint();
            }
        }

        return $platformReqs;
    }

    /**
     * Adds all dependencies of the update whitelist to the whitelist, too.
     *
     * Packages which are listed as requirements in the root package will be
     * skipped including their dependencies, unless they are listed in the
     * update whitelist themselves.
     *
     * @param RepositoryInterface $localRepo
     * @param boolean             $devMode
     * @param array               $rootRequires    An array of links to packages in require of the root package
     * @param array               $rootDevRequires An array of links to packages in require-dev of the root package
     */
    private function whitelistUpdateDependencies($localRepo, $devMode, array $rootRequires, array $rootDevRequires)
    {
        if (!$this->updateWhitelist) {
            return;
        }

        $requiredPackageNames = array();
        foreach (array_merge($rootRequires, $rootDevRequires) as $require) {
            $requiredPackageNames[] = $require->getTarget();
        }

        if ($devMode) {
            $rootRequires = array_merge($rootRequires, $rootDevRequires);
        }

        $skipPackages = array();
        foreach ($rootRequires as $require) {
            $skipPackages[$require->getTarget()] = true;
        }

        $pool = new Pool;
        $pool->addRepository($localRepo);

        $seen = array();

        foreach ($this->updateWhitelist as $packageName => $void) {
            $packageQueue = new \SplQueue;

            $depPackages = $pool->whatProvides($packageName);
            if (count($depPackages) == 0 && !in_array($packageName, $requiredPackageNames) && !in_array($packageName, array('nothing', 'lock'))) {
                $this->io->write('<warning>Package "' . $packageName . '" listed for update is not installed. Ignoring.<warning>');
            }

            foreach ($depPackages as $depPackage) {
                $packageQueue->enqueue($depPackage);
            }

            while (!$packageQueue->isEmpty()) {
                $package = $packageQueue->dequeue();
                if (isset($seen[$package->getId()])) {
                    continue;
                }

                $seen[$package->getId()] = true;
                $this->updateWhitelist[$package->getName()] = true;

                $requires = $package->getRequires();
                if ($devMode) {
                    $requires = array_merge($requires, $package->getDevRequires());
                }

                foreach ($requires as $require) {
                    $requirePackages = $pool->whatProvides($require->getTarget());

                    foreach ($requirePackages as $requirePackage) {
                        if (isset($skipPackages[$requirePackage->getName()])) {
                            continue;
                        }
                        $packageQueue->enqueue($requirePackage);
                    }
                }
            }
        }
    }

    /**
     * Replace local repositories with InstalledArrayRepository instances
     *
     * This is to prevent any accidental modification of the existing repos on disk
     *
     * @param RepositoryManager $rm
     */
    private function mockLocalRepositories(RepositoryManager $rm)
    {
        $packages = array_map(function ($p) {
            return clone $p;
        }, $rm->getLocalRepository()->getPackages());
        foreach ($packages as $key => $package) {
            if ($package instanceof AliasPackage) {
                unset($packages[$key]);
            }
        }
        $rm->setLocalRepository(
            new InstalledArrayRepository($packages)
        );
    }

    /**
     * Create Installer
     *
     * @param  IOInterface       $io
     * @param  Composer          $composer
     * @return Installer
     */
    public static function create(IOInterface $io, Composer $composer)
    {
        return new static(
            $io,
            $composer->getConfig(),
            $composer->getPackage(),
            $composer->getDownloadManager(),
            $composer->getRepositoryManager(),
            $composer->getLocker(),
            $composer->getInstallationManager(),
            $composer->getEventDispatcher(),
            $composer->getAutoloadGenerator()
        );
    }

    public function setAdditionalInstalledRepository(RepositoryInterface $additionalInstalledRepository)
    {
        $this->additionalInstalledRepository = $additionalInstalledRepository;

        return $this;
    }

    /**
     * Whether to run in drymode or not
     *
     * @param  boolean   $dryRun
     * @return Installer
     */
    public function setDryRun($dryRun = true)
    {
        $this->dryRun = (boolean) $dryRun;

        return $this;
    }

    /**
     * prefer source installation
     *
     * @param  boolean   $preferSource
     * @return Installer
     */
    public function setPreferSource($preferSource = true)
    {
        $this->preferSource = (boolean) $preferSource;

        return $this;
    }

    /**
     * prefer dist installation
     *
     * @param  boolean   $preferDist
     * @return Installer
     */
    public function setPreferDist($preferDist = true)
    {
        $this->preferDist = (boolean) $preferDist;

        return $this;
    }

    /**
     * Whether or not generated autoloader are optimized
     *
     * @param  bool      $optimizeAutoloader
     * @return Installer
     */
    public function setOptimizeAutoloader($optimizeAutoloader = false)
    {
        $this->optimizeAutoloader = (boolean) $optimizeAutoloader;

        return $this;
    }

    /**
     * update packages
     *
     * @param  boolean   $update
     * @return Installer
     */
    public function setUpdate($update = true)
    {
        $this->update = (boolean) $update;

        return $this;
    }

    /**
     * enables dev packages
     *
     * @param  boolean   $devMode
     * @return Installer
     */
    public function setDevMode($devMode = true)
    {
        $this->devMode = (boolean) $devMode;

        return $this;
    }

    /**
     * set whether to run scripts or not
     *
     * @param  boolean   $runScripts
     * @return Installer
     */
    public function setRunScripts($runScripts = true)
    {
        $this->runScripts = (boolean) $runScripts;

        return $this;
    }

    /**
     * set the config instance
     *
     * @param  Config    $config
     * @return Installer
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * run in verbose mode
     *
     * @param  boolean   $verbose
     * @return Installer
     */
    public function setVerbose($verbose = true)
    {
        $this->verbose = (boolean) $verbose;

        return $this;
    }

    /**
     * restrict the update operation to a few packages, all other packages
     * that are already installed will be kept at their current version
     *
     * @param  array     $packages
     * @return Installer
     */
    public function setUpdateWhitelist(array $packages)
    {
        $this->updateWhitelist = array_flip(array_map('strtolower', $packages));

        return $this;
    }

    /**
     * Disables custom installers.
     *
     * Call this if you want to ensure that third-party code never gets
     * executed. The default is to automatically install, and execute
     * custom third-party installers.
     *
     * @return Installer
     */
    public function disableCustomInstallers()
    {
        $this->installationManager->disableCustomInstallers();

        return $this;
    }
}
