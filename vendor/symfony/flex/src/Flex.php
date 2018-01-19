<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Command\CreateProjectCommand;
use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Pool;
use Composer\Downloader\FileDownloader;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\PackageInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository as BaseComposerRepository;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Thanks\Thanks;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Flex implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $config;
    private $options;
    private $configurator;
    private $downloader;
    private $postInstallOutput = [''];
    private $operations = [];
    private $lock;
    private $cacheDirPopulated = false;
    private $displayThanksReminder = false;
    private $rfs;
    private static $activated = true;
    private static $repoReadingCommands = [
        'create-project' => true,
        'outdated' => true,
        'require' => true,
        'update' => true,
        'install' =>true,
    ];

    public function activate(Composer $composer, IOInterface $io)
    {
        if (!extension_loaded('openssl')) {
            self::$activated = false;
            $io->writeError('<warning>Symfony Flex has been disabled. You must enable the openssl extension in your "php.ini" file.</warning>');

            return;
        }

        // to avoid issues when Flex is upgraded, we load all PHP classes now
        // that way, we are sure to use all files from the same version
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('.php' === substr($file, -4)) {
                require_once $file;
            }
        }

        $this->composer = $composer;
        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->options = $this->initOptions();

        $rfs = Factory::createRemoteFilesystem($this->io, $this->config);
        $this->rfs = new ParallelDownloader($this->io, $this->config, $rfs->getOptions(), $rfs->isTlsDisabled());
        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->downloader = new Downloader($composer, $io, $this->rfs);
        $this->downloader->setFlexId($this->getFlexId());
        $this->lock = new Lock(str_replace(Factory::getComposerFile(), 'composer.json', 'symfony.lock'));

        $populateRepoCacheDir = __CLASS__ === self::class;
        if ($composer->getPluginManager()) {
            foreach ($composer->getPluginManager()->getPlugins() as $plugin) {
                if (0 === strpos(get_class($plugin), 'Hirak\Prestissimo\Plugin')) {
                    if (method_exists($rfs, 'getRemoteContents')) {
                        $plugin->disable();
                    } else {
                        $this->cacheDirPopulated = true;
                    }
                    $populateRepoCacheDir = false;
                    break;
                }
            }
        }

        $backtrace = debug_backtrace();
        foreach ($backtrace as $trace) {
            if (isset($trace['object']) && $trace['object'] instanceof Installer) {
                $trace['object']->setSuggestedPackagesReporter(new SuggestedPackagesReporter(new NullIO()));
                break;
            }
        }

        foreach ($backtrace as $trace) {
            if (!isset($trace['object']) || !isset($trace['args'][0])) {
                continue;
            }

            if (!$trace['object'] instanceof Application || !$trace['args'][0] instanceof ArgvInput) {
                continue;
            }

            $app = $trace['object'];
            $resolver = new PackageResolver($this->downloader);
            $app->add(new Command\RequireCommand($resolver));
            $app->add(new Command\UpdateCommand($resolver));
            $app->add(new Command\RemoveCommand($resolver));
            $app->add(new Command\UnpackCommand($resolver));

            try {
                $command = $trace['args'][0]->getFirstArgument();
                $command = $command ? $app->find($command)->getName() : null;
            } catch (\InvalidArgumentException $e) {
            }

            if ('create-project' === $command) {
                $trace['args'][0]->setInteractive(false);
            }

            if ($populateRepoCacheDir && isset(self::$repoReadingCommands[$command]) && ('install' !== $command || (file_exists('composer.json') && !file_exists('composer.lock')))) {
                $this->populateRepoCacheDir();
            }

            break;
        }
    }

    public function configureProject(Event $event)
    {
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        // new projects are most of the time proprietary
        $manipulator->addProperty('license', 'proprietary');
        // 'name' and 'description' are only required for public packages
        $manipulator->removeProperty('name');
        $manipulator->removeProperty('description');
        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    public function record(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if ($this->shouldRecordOperation($operation)) {
            if ($operation instanceof InstallOperation && in_array($packageName = $operation->getPackage()->getName(), ['symfony/framework-bundle', 'symfony/flex'])) {
                if ('symfony/flex' === $packageName) {
                    array_unshift($this->operations, $operation);
                } else {
                    if ($this->operations && $this->operations[0] instanceof InstallOperation && 'symfony/flex' === $this->operations[0]->getPackage()->getName()) {
                        // framework-bundle should be *after* flex
                        $flexOperation = $this->operations[0];
                        unset($this->operations[0]);
                        array_unshift($this->operations, $operation);
                        array_unshift($this->operations, $flexOperation);
                    } else {
                        array_unshift($this->operations, $operation);
                    }
                }
            } else {
                $this->operations[] = $operation;
            }
        }
    }

    public function install(Event $event)
    {
        $this->update($event);
    }

    public function update(Event $event)
    {
        if (!file_exists(getcwd().'/.env') && file_exists(getcwd().'/.env.dist')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }

        list($recipes, $vulnerabilities) = $this->fetchRecipes();
        if ($vulnerabilities) {
            $this->io->writeError(sprintf('<info>Vulnerabilities: %d package%s</>', count($vulnerabilities), count($recipes) > 1 ? 's' : ''));
        }
        foreach ($vulnerabilities as $name => $vulns) {
            foreach ($vulns as $v) {
                $this->io->writeError(sprintf('  - <error>Vulnerability on %s</>: %s', $name, $v));
            }
        }

        if ($this->displayThanksReminder) {
            $love = '\\' === DIRECTORY_SEPARATOR ? 'love' : '💖 ';
            $star = '\\' === DIRECTORY_SEPARATOR ? 'star' : '★ ';

            $this->io->writeError('');
            $this->io->writeError('What about running <comment>composer global require symfony/thanks && composer thanks</> now?');
            $this->io->writeError(sprintf('This will spread some %s by sending a %s to the GitHub repositories of your fellow package maintainers.', $love, $star));
            $this->io->writeError('');
        }

        if (!$recipes) {
            $this->lock->write();

            return;
        }

        $this->io->writeError(sprintf('<info>Symfony operations: %d recipe%s (%s)</>', count($recipes), count($recipes) > 1 ? 's' : '', $this->downloader->getSessionId()));
        $installContribs = $this->composer->getPackage()->getExtra()['symfony']['allow-contrib'] ?? false;
        $manifest = null;
        foreach ($recipes as $recipe) {
            if ('install' === $recipe->getJob() && !$installContribs && $recipe->isContrib()) {
                $warning = $this->io->isInteractive() ? 'WARNING' : 'IGNORING';
                $this->io->writeError(sprintf('  - <warning> %s </> %s', $warning, $this->formatOrigin($recipe->getOrigin())));
                $question = '    The recipe for this package comes from the "contrib" repository, which is open to community contributions.
    Do you want to execute this recipe?
    [<comment>y</>] Yes
    [<comment>n</>] No
    [<comment>a</>] Yes for all packages, only for the current installation session
    [<comment>p</>] Yes permanently, never ask again for this project
    (defaults to <comment>n</>): ';
                $answer = $this->io->askAndValidate(
                    $question,
                    function ($value) {
                        if (null === $value) {
                            return 'n';
                        }
                        $value = strtolower($value[0]);
                        if (!in_array($value, ['y', 'n', 'a', 'p'])) {
                            throw new \InvalidArgumentException('Invalid choice');
                        }
                        return $value;
                    },
                    null,
                    'n'
                );
                if ('n' === $answer) {
                    continue;
                }
                if ('a' === $answer) {
                    $installContribs = true;
                }
                if ('p' === $answer) {
                    $installContribs = true;
                    $json = new JsonFile(Factory::getComposerFile());
                    $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
                    $manipulator->addProperty('extra.symfony.allow-contrib', true);
                    file_put_contents($json->getPath(), $manipulator->getContents());
                }
            }

            switch ($recipe->getJob()) {
                case 'install':
                    $this->io->writeError(sprintf('  - Configuring %s', $this->formatOrigin($recipe->getOrigin())));
                    $this->configurator->install($recipe);
                    $manifest = $recipe->getManifest();
                    if (isset($manifest['post-install-output'])) {
                        foreach ($manifest['post-install-output'] as $line) {
                            $this->postInstallOutput[] = $this->options->expandTargetDir($line);
                        }
                        $this->postInstallOutput[] = '';
                    }
                    break;
                case 'update':
                    break;
                case 'uninstall':
                    $this->io->writeError(sprintf('  - Unconfiguring %s', $this->formatOrigin($recipe->getOrigin())));
                    $this->configurator->unconfigure($recipe);
                    break;
            }
        }

        if (null !== $manifest) {
            array_unshift(
                $this->postInstallOutput,
                '',
                '<info>Some files may have been created or updated to configure your new packages.</>',
                'Please <comment>review</>, <comment>edit</> and <comment>commit</> them: these files are <comment>yours</>.'
            );
        }

        $this->lock->write();
    }

    public function inspectCommand(CommandEvent $event)
    {
        if ('update' === $event->getCommandName() && !class_exists(Thanks::class, false)) {
            $this->displayThanksReminder = true;
        }
    }

    public function executeAutoScripts(Event $event)
    {
        $event->stopPropagation();

        // force reloading scripts as we might have added and removed during this run
        $json = new JsonFile(Factory::getComposerFile());
        $jsonContents = $json->read();

        $executor = new ScriptExecutor($this->composer, $this->io, $this->options);
        foreach ($jsonContents['scripts']['auto-scripts'] as $cmd => $type) {
            $executor->execute($type, $cmd);
        }

        $this->io->write($this->postInstallOutput);
    }

    public function populateProvidersCacheDir(InstallerEvent $event)
    {
        $listed = [];
        $packages = [];
        $pool = $event->getPool();
        $pool = \Closure::bind(function () {
            foreach ($this->providerRepos as $k => $repo) {
                $this->providerRepos[$k] = new class($repo) extends BaseComposerRepository {
                    private $repo;

                    public function __construct($repo)
                    {
                        $this->repo = $repo;
                    }

                    public function whatProvides(Pool $pool, $name, $bypassFilters = false)
                    {
                        $packages = [];
                        foreach ($this->repo->whatProvides($pool, $name, $bypassFilters) as $k => $p) {
                            $packages[$k] = clone $p;
                        }

                        return $packages;
                    }
                };
            }

            return $this;
        }, clone $pool, $pool)();

        foreach ($event->getRequest()->getJobs() as $job) {
            if ('install' !== $job['cmd'] || false === strpos($job['packageName'], '/')) {
                continue;
            }

            $listed[$job['packageName']] = true;
            $packages[] = [$job['packageName'], $job['constraint']];
        }

        $this->rfs->download($packages, function ($packageName, $constraint) use (&$listed, &$packages, $pool) {
            foreach ($pool->whatProvides($packageName, $constraint, true) as $package) {
                foreach (array_merge($package->getRequires(), $package->getConflicts(), $package->getReplaces()) as $link) {
                    if (isset($listed[$link->getTarget()]) || false === strpos($link->getTarget(), '/')) {
                        continue;
                    }
                    $listed[$link->getTarget()] = true;
                    $packages[] = [$link->getTarget(), $link->getConstraint()];
                }
            }
        });
    }

    public function populateFilesCacheDir(InstallerEvent $event)
    {
        if ($this->cacheDirPopulated) {
            return;
        }
        $this->cacheDirPopulated = true;

        $downloads = [];
        $cacheDir = rtrim($this->config->get('cache-files-dir'), '\/').DIRECTORY_SEPARATOR;
        $getCacheKey = function (PackageInterface $package, $processedUrl) { return $this->getCacheKey($package, $processedUrl); };
        $getCacheKey = \Closure::bind($getCacheKey, new FileDownloader($this->io, $this->config), FileDownloader::class);

        foreach ($event->getOperations() as $op) {
            if ('install' === $op->getJobType()) {
                $package = $op->getPackage();
            } elseif ('update' === $op->getJobType()) {
                $package = $op->getTargetPackage();
            } else {
                continue;
            }

            if (!$fileUrl = $package->getDistUrl()) {
                continue;
            }

            if ($package->getDistMirrors()) {
                $fileUrl = current($package->getDistUrls());
            }

            if (!preg_match('/^https?:/', $fileUrl) || !$originUrl = parse_url($fileUrl, PHP_URL_HOST)) {
                continue;
            }

            if (file_exists($file = $cacheDir.$getCacheKey($package, $fileUrl))) {
                continue;
            }

            @mkdir(dirname($file), 0775, true);

            if (!is_dir(dirname($file))) {
                continue;
            }

            if (preg_match('#^https://github\.com/#', $package->getSourceUrl()) && preg_match('#^https://api\.github\.com/repos(/[^/]++/[^/]++/)zipball(.++)$#', $fileUrl, $m)) {
                $fileUrl = sprintf('https://codeload.github.com%slegacy.zip%s', $m[1], $m[2]);
            }

            $downloads[] = [$originUrl, $fileUrl, [], $file, false];
        }

        if (1 < count($downloads)) {
            $this->rfs->download($downloads, [$this->rfs, 'get'], false);
        }
    }

    public function onFileDownload(PreFileDownloadEvent $event)
    {
        if ($event->getRemoteFilesystem() !== $this->rfs) {
            $event->setRemoteFilesystem($this->rfs->setNextOptions($event->getRemoteFilesystem()->getOptions()));
        }
    }

    private function fetchRecipes(): array
    {
        $devPackages = null;
        $data = $this->downloader->getRecipes($this->operations);
        $manifests = $data['manifests'] ?? [];
        $locks = $data['locks'] ?? [];
        $recipes = [];
        foreach ($this->operations as $i => $operation) {
            if ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
            } else {
                $package = $operation->getPackage();
            }

            // FIXME: getNames() can return n names
            $name = $package->getNames()[0];
            $job = $operation->getJobType();

            if ($operation instanceof InstallOperation && isset($locks[$name])) {
                $this->lock->add($name, $locks[$name]);
            } elseif ($operation instanceof UninstallOperation) {
                $this->lock->remove($name);
            }

            if (isset($manifests[$name])) {
                $recipes[] = new Recipe($package, $name, $job, $manifests[$name]);
            }

            $noRecipe = !isset($manifests[$name]) || (isset($manifests[$name]['not_installable']) && $manifests[$name]['not_installable']);
            if ($noRecipe && 'symfony-bundle' === $package->getType()) {
                $manifest = [];
                $bundle = new SymfonyBundle($this->composer, $package, $job);
                if (null === $devPackages) {
                    $devPackages = array_column($this->composer->getLocker()->getLockData()['packages-dev'], 'name');
                }
                $envs = in_array($name, $devPackages) ? ['dev', 'test'] : ['all'];
                foreach ($bundle->getClassNames() as $class) {
                    $manifest['manifest']['bundles'][$class] = $envs;
                }
                if ($manifest) {
                    $manifest['origin'] = sprintf('%s:%s@auto-generated recipe', $name, $package->getPrettyVersion());
                    $recipes[] = new Recipe($package, $name, $job, $manifest);
                }
            }
        }
        $this->operations = [];

        return [$recipes, $data['vulnerabilities'] ?? []];
    }

    private function initOptions(): Options
    {
        $options = array_merge([
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'config-dir' => 'config',
            'src-dir' => 'src',
            'var-dir' => 'var',
            'public-dir' => 'public',
        ], $this->composer->getPackage()->getExtra());

        return new Options($options);
    }

    private function getFlexId()
    {
        $extra = $this->composer->getPackage()->getExtra();

        // don't want to be registered
        if (getenv('SYMFONY_SKIP_REGISTRATION') || !isset($extra['symfony']['id'])) {
            return null;
        }

        // already registered
        if ($extra['symfony']['id']) {
            return $extra['symfony']['id'];
        }

        // get a new ID
        $id = $this->downloader->get('/ulid')->getBody()['ulid'];

        // update composer.json
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addProperty('extra.symfony.id', $id);
        file_put_contents($json->getPath(), $manipulator->getContents());

        return $id;
    }

    private function formatOrigin(string $origin): string
    {
        // symfony/translation:3.3@github.com/symfony/recipes:master
        if (!preg_match('/^([^\:]+?)\:([^\@]+)@(.+)$/', $origin, $matches)) {
            return $origin;
        }

        return sprintf('<info>%s</> (<comment>>=%s</>): From %s', $matches[1], $matches[2], 'auto-generated recipe' === $matches[3] ? '<comment>'.$matches[3].'</>' : $matches[3]);
    }

    private function shouldRecordOperation(OperationInterface $operation): bool
    {
        if ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            $package = $operation->getPackage();
        }

        // FIXME: getNames() can return n names
        $name = $package->getNames()[0];
        if ($operation instanceof InstallOperation) {
            if (!$this->lock->has($name)) {
                return true;
            }
        } elseif ($operation instanceof UninstallOperation) {
            return true;
        }

        return false;
    }

    private function populateRepoCacheDir()
    {
        $repos = [];

        foreach ($this->composer->getPackage()->getRepositories() as $name => $repo) {
            if (!isset($repo['type']) || 'composer' !==  $repo['type'] || !empty($repo['force-lazy-providers'])) {
                continue;
            }

            if (!preg_match('#^https\?://#', $repo['url'])) {
                continue;
            }

            $repo = new ComposerRepository($repo, $this->io, $this->config, null, $this->rfs);

            $repos[] = [$repo];
        }

        $this->rfs->download($repos, function ($repo) {
            ParallelDownloader::$cacheNext = true;
            $repo->getProviderNames();
        });
    }

    public static function getSubscribedEvents(): array
    {
        if (!self::$activated) {
            return [];
        }

        return [
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => [['populateProvidersCacheDir', PHP_INT_MAX]],
            InstallerEvents::POST_DEPENDENCIES_SOLVING => [['populateFilesCacheDir', PHP_INT_MAX]],
            PackageEvents::PRE_PACKAGE_INSTALL => [['populateFilesCacheDir', ~PHP_INT_MAX]],
            PackageEvents::PRE_PACKAGE_UPDATE => [['populateFilesCacheDir', ~PHP_INT_MAX]],
            PackageEvents::POST_PACKAGE_INSTALL => 'record',
            PackageEvents::POST_PACKAGE_UPDATE => 'record',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'record',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'configureProject',
            ScriptEvents::POST_INSTALL_CMD => 'install',
            ScriptEvents::POST_UPDATE_CMD => 'update',
            PluginEvents::COMMAND => 'inspectCommand',
            PluginEvents::PRE_FILE_DOWNLOAD => 'onFileDownload',
            'auto-scripts' => 'executeAutoScripts',
        ];
    }
}
