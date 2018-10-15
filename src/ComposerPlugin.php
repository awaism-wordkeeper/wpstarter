<?php declare(strict_types=1);
/*
 * This file is part of the WP Starter package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WeCodeMore\WpStarter;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Util;
use WeCodeMore\WpStarter\Step;

final class ComposerPlugin implements
    PluginInterface,
    EventSubscriberInterface,
    Capable,
    CommandProvider
{

    const EXTRA_KEY = 'wpstarter';

    const STEP_CLASSES = [
        Step\CheckPathStep::NAME => Step\CheckPathStep::class,
        Step\WpConfigStep::NAME => Step\WpConfigStep::class,
        Step\IndexStep::NAME => Step\IndexStep::class,
        Step\MuLoaderStep::NAME => Step\MuLoaderStep::class,
        Step\EnvExampleStep::NAME => Step\EnvExampleStep::class,
        Step\DropinsStep::NAME => Step\DropinsStep::class,
        Step\MoveContentStep::NAME => Step\MoveContentStep::class,
        Step\ContentDevStep::NAME => Step\ContentDevStep::class,
        Step\WpCliConfigStep::NAME => Step\WpCliConfigStep::class,
    ];

    /**
     * @var Util\Locator
     */
    private $locator;

    /**
     * @var IOInterface
     */
    private $composerIo;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var Util\Requirements
     */
    private $requirements;

    /**
     * phpcs:disable Inpsyde.CodeQuality.NoAccessors
     */
    public static function getSubscribedEvents(): array
    {
        // phpcs:enable

        return [
            'post-install-cmd' => 'run',
            'post-update-cmd' => 'run',
        ];
    }

    /**
     * phpcs:disable Inpsyde.CodeQuality.NoAccessors
     */
    public function getCapabilities(): array
    {
        // phpcs:enable

        return [CommandProvider::class => __CLASS__];
    }

    /**
     * phpcs:disable Inpsyde.CodeQuality.NoAccessors
     */
    public function getCommands(): array
    {
        // phpcs:enable

        return [new WpStarterCommand()];
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->composerIo = $io;
        $this->requirements = new Util\Requirements($composer, $io, new Filesystem());
    }

    /**
     * Run WP Starter installation adding all the steps to Builder and launching steps processing.
     *
     * It is possible to provide the names of steps to run.
     *
     * @param Event|null $event
     * @param array $selectedStepNames
     * @return void
     */
    public function run(Event $event = null, array $selectedStepNames = [])
    {
        $config = $this->requirements->config();
        $requireWp = $config[Config::REQUIRE_WP]->not(false);

        $wpVersion = null;
        if ($requireWp) {
            $wpVersionDiscover = new Util\WpVersion($this->composerIo);
            $composer = $this->composer;
            $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();
            $wpVersion = $wpVersionDiscover->discover(...$packages);
        }

        if (!$wpVersion && $requireWp) {
            $this->composerIo->writeError(
                [
                    '',
                    "<bg=red;fg=white;option=bold>                                             </>",
                    "<bg=red;fg=white;option=bold>    Error running WP Starter.                </>",
                    "<bg=red;fg=white;option=bold>    No supported WordPress version found.    </>",
                    "<bg=red;fg=white;option=bold>                                             </>",
                    '',
                ]
            );

            $event or exit(1);

            return;
        }

        // If WP version was found and no version is set in configs, let's set it with the finding.
        if ($wpVersion && !$config[Config::WP_VERSION]->notEmpty()) {
            $config->appendConfig(Config::WP_VERSION, $wpVersion);
        }

        $this->locator = new Util\Locator(
            $this->requirements,
            $this->composer->getConfig(),
            $this->composerIo,
            $this->requirements->filesystem()
        );

        try {
            $steps = new Step\Steps($this->locator, $this->composer);
            $selectedStepNames = array_filter($selectedStepNames, 'is_string');
            $customSteps = $this->locator->config()[Config::CUSTOM_STEPS]->unwrapOrFallback([]);
            $skippedSteps = $this->locator->config()[Config::SKIP_STEPS]->unwrapOrFallback([]);
            $stepClasses = array_diff(array_merge(self::STEP_CLASSES, $customSteps), $skippedSteps);
            $hasWpCli = false;

            $this->factorySteps($steps, $stepClasses, $selectedStepNames, $hasWpCli);
            $this->createExecutor($hasWpCli, $steps, $this->locator->config());
            $this->logo();
            $steps->run($this->locator->config(), $this->locator->paths());

            $event or exit(0);
        } catch (\Throwable $throwable) {
            $this->locator->io()->writeError($throwable->getMessage());

            $event or exit(1);
        }
    }

    /**
     * @param Step\Steps $steps
     * @param array $stepClasses
     * @param array $selectedStepNames
     * @param bool $hasWpCliStep
     */
    private function factorySteps(
        Step\Steps $steps,
        array $stepClasses,
        array $selectedStepNames,
        bool &$hasWpCliStep
    ) {

        $stepsAdded = [];

        foreach ($stepClasses as $stepName => $stepClass) {
            if (!$stepName
                || ($selectedStepNames && !in_array($stepName, $selectedStepNames, true))
                || in_array($stepName, $stepsAdded, true)
            ) {
                continue;
            }

            $step = $this->factoryStep($stepClass);
            if ($step->name() === $stepName) {
                $hasWpCliStep = $hasWpCliStep || ($stepName === Step\WpCliCommandsStep::NAME);
                $steps->addStep($step);
                $stepsAdded[] = $stepName;
            }
        }
    }

    /**
     * @param string $stepClass
     * @return Step\Step
     */
    private function factoryStep(string $stepClass): Step\Step
    {
        if (!is_subclass_of($stepClass, Step\Step::class, true)) {
            return new Step\NullStep();
        }

        return new $stepClass($this->locator, $this->composer);
    }

    /**
     * @param bool $hasWpCliStep
     * @param Step\Steps $steps
     * @param Config $config
     */
    private function createExecutor(bool $hasWpCliStep, Step\Steps $steps, Config $config)
    {
        if (!$hasWpCliStep && $config[Config::WP_CLI_COMMANDS]->notEmpty()) {
            $steps->addStep(new Step\WpCliCommandsStep($this->locator));
            $hasWpCliStep = true;
        }

        if (!$hasWpCliStep) {
            return;
        }

        $executorFactory = new WpCli\ExecutorFactory(
            $this->locator,
            $this->composer->getRepositoryManager()->getLocalRepository(),
            $this->composer->getInstallationManager()
        );

        $php = (new PhpExecutableFinder())->find();
        if (!$php) {
            throw new \Exception('PHP executable not found.');
        }

        $config = $this->locator->config();
        $wpCliCommand = new WpCli\Command($config, $this->locator->urlDownloader());
        $wpCliExecutor = $executorFactory->create($wpCliCommand, $php);
        $config->appendConfig(Config::WP_CLI_EXECUTOR, $wpCliExecutor);
    }

    /**
     * @return void
     */
    private function logo()
    {
        // phpcs:disable
        $logo = <<<LOGO
<fg=magenta> __      __ ___  </><fg=yellow>  ___  _____  _    ___  _____  ___  ___  </>
<fg=magenta> \ \    / /| _ \ </><fg=yellow> / __||_   _|/_\  | _ \|_   _|| __|| _ \ </>
<fg=magenta>  \ \/\/ / |  _/ </><fg=yellow> \__ \  | | / _ \ |   /  | |  | _| |   / </>
<fg=magenta>   \_/\_/  |_|   </><fg=yellow> |___/  |_|/_/ \_\|_|_\  |_|  |___||_|_\ </>
LOGO;
        // phpcs:enable

        $this->composerIo->write("\n{$logo}\n");
    }
}
