<?php
/* (c) Anton Medvedev <anton@medv.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer\Command;

use Deployer\Configuration\Configuration;
use Deployer\Deployer;
use Deployer\Exception\Exception;
use Deployer\Exception\GracefulShutdownException;
use Deployer\Executor\Planner;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption as Option;
use Symfony\Component\Console\Output\OutputInterface as Output;
use function Deployer\Support\find_config_line;
use function Deployer\warning;

class MainCommand extends SelectCommand
{
    use CustomOption;
    use CommandCommon;

    public function __construct(string $name, ?string $description, Deployer $deployer)
    {
        parent::__construct($name, $deployer);
        if ($description) {
            $this->setDescription($description);
        }
    }

    protected function configure()
    {
        parent::configure();

        // Add global options defined with `option()` func.
        $this->getDefinition()->addOptions($this->deployer->inputDefinition->getOptions());

        $this->addOption(
            'option',
            'o',
            Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY,
            'Set configuration option'
        );
        $this->addOption(
            'limit',
            'l',
            Option::VALUE_REQUIRED,
            'How many tasks to run in parallel?'
        );
        $this->addOption(
            'no-hooks',
            null,
            Option::VALUE_NONE,
            'Run tasks without after/before hooks'
        );
        $this->addOption(
            'plan',
            null,
            Option::VALUE_NONE,
            'Show execution plan'
        );
        $this->addOption(
            'start-from',
            null,
            Option::VALUE_REQUIRED,
            'Start execution from this task'
        );
        $this->addOption(
            'log',
            null,
            Option::VALUE_REQUIRED,
            'Write log to a file'
        );
        $this->addOption(
            'profile',
            null,
            Option::VALUE_REQUIRED,
            'Write profile to a file'
        );
    }

    protected function execute(Input $input, Output $output)
    {
        $this->deployer->input = $input;
        $this->deployer->output = $output;
        $this->deployer['log_file'] = $input->getOption('log');
        $this->telemetry([
            'project_hash' => empty($this->deployer->config['repository']) ? null : sha1($this->deployer->config['repository']),
            'hosts_count' => $this->deployer->hosts->count(),
            'recipes' => $this->deployer->config->get('recipes', []),
        ]);
        $hosts = $this->selectHosts($input, $output);
        $this->applyOverrides($hosts, $input->getOption('option'));

        $plan = $input->getOption('plan') ? new Planner($output, $hosts) : null;

        $this->deployer->scriptManager->setHooksEnabled(!$input->getOption('no-hooks'));
        $startFrom = $input->getOption('start-from');
        if ($startFrom && !$this->deployer->tasks->has($startFrom)) {
            throw new Exception("Task ${startFrom} does not exist.");
        }
        $tasks = $this->deployer->scriptManager->getTasks($this->getName(), $startFrom);

        if (empty($tasks)) {
            throw new Exception('No task will be executed, because the selected hosts do not meet the conditions of the tasks');
        }

        if (!$plan) {
            $this->validateConfig();
            $this->deployer->server->start();
            $this->deployer->master->connect($hosts);
        }
        $exitCode = $this->deployer->master->run($tasks, $hosts, $plan);

        if ($plan) {
            $plan->render();
            return 0;
        }

        if ($exitCode === 0) {
            return 0;
        }
        if ($exitCode === GracefulShutdownException::EXIT_CODE) {
            return 1;
        }

        // Check if we have tasks to execute on failure.
        if ($this->deployer['fail']->has($this->getName())) {
            $taskName = $this->deployer['fail']->get($this->getName());
            $tasks = $this->deployer->scriptManager->getTasks($taskName);
            $this->deployer->master->run($tasks, $hosts);
        }

        return $exitCode;
    }

    private function validateConfig(): void
    {
        if (!defined('DEPLOYER_DEPLOY_FILE')) {
            return;
        }
        $validate = function (Configuration $configA, Configuration $configB): void {
            $keysA = array_keys($configA->ownValues());
            $keysB = array_keys($configB->ownValues());
            for ($i = 0; $i < count($keysA); $i++) {
                for ($j = $i + 1; $j < count($keysB); $j++) {
                    $a = $keysA[$i];
                    $b = $keysB[$j];
                    if (levenshtein($a, $b) == 1) {
                        $source = file_get_contents(DEPLOYER_DEPLOY_FILE);
                        $code = '';
                        foreach (find_config_line($source, $a) as list($n, $line)) {
                            $code .= "    $n: " . str_replace($a, "<fg=red>$a</fg=red>", $line) . "\n";
                        }
                        foreach (find_config_line($source, $b) as list($n, $line)) {
                            $code .= "    $n: " . str_replace($b, "<fg=red>$b</fg=red>", $line) . "\n";
                        }
                        if (!empty($code)) {
                            warning(<<<AAA
                                Did you mean "<fg=green>$a</fg=green>" or "<fg=green>$b</fg=green>"?</>
                                
                                $code
                                AAA
                            );
                        }
                    }
                }
            }
        };

        $validate($this->deployer->config, $this->deployer->config);
        foreach ($this->deployer->hosts as $host) {
            $validate($host->config(), $this->deployer->config);
        }
    }
}