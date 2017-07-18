<?php

namespace GetOpt;

class Getopt implements \Countable, \ArrayAccess, \IteratorAggregate
{
    const NO_ARGUMENT = 0;
    const REQUIRED_ARGUMENT = 1;
    const OPTIONAL_ARGUMENT = 2;
    const MULTIPLE_ARGUMENT = 3;

    const SETTING_SCRIPT_NAME  = 'scriptName';
    const SETTING_DEFAULT_MODE = 'defaultMode';

    /** @var OptionParser */
    protected $optionParser;

    /** @var HelpInterface */
    protected $help;

    /** @var array */
    protected $settings = [
        self::SETTING_DEFAULT_MODE => self::NO_ARGUMENT
    ];

    /** @var Option[] */
    protected $options = [];

    /** @var Command[] */
    protected $commands = [];

    /** @var Operand[] */
    protected $operands = [];

    /** The command that is executed determined by process
     * @var Command */
    protected $command;

    /** @var Option[] */
    protected $optionMapping = [];

    /** @var string[] */
    protected $operandValues = [];

    /**
     * Creates a new Getopt object.
     *
     * The argument $options can be either a string in the format accepted by the PHP library
     * function getopt() or an array.
     *
     * @param array $options
     * @param array $settings
     * @link https://www.gnu.org/s/hello/manual/libc/Getopt.html GNU Getopt manual
     */
    public function __construct($options = null, array $settings = [])
    {
        if ($options !== null) {
            $this->addOptions($options);
        }

        $this->set(
            self::SETTING_SCRIPT_NAME,
            isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : (
                isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null
            )
        );
        foreach ($settings as $setting => $value) {
            $this->set($setting, $value);
        }
    }

    /**
     * Set $setting to $value
     *
     * @param string $setting
     * @param mixed $value
     * @return self
     */
    public function set($setting, $value)
    {
        $this->settings[$setting] = $value;
        return $this;
    }

    /**
     * Get the current value of $setting
     *
     * @param string $setting
     * @return mixed
     */
    public function get($setting)
    {
        return isset($this->settings[$setting]) ? $this->settings[$setting] : null;
    }

    /**
     * Process the given $arguments
     *
     * Sets the value for defined options, operands and the command.
     *
     * @param array|string|Arguments $arguments
     */
    public function process($arguments = null)
    {
        if ($arguments === null) {
            $arguments = isset($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : [];
            $arguments = new Arguments($arguments);
        } elseif (is_array($arguments)) {
            $arguments = new Arguments($arguments);
        } elseif (is_string($arguments)) {
            $arguments = Arguments::fromString($arguments);
        } elseif (!$arguments instanceof Arguments) {
            throw new \InvalidArgumentException(
                '$arguments has to be an instance of Arguments, an arguments string, an array of arguments or null'
            );
        }


        $setCommand = function (Command $command) {
            foreach ($command->getOptions() as $option) {
                $this->addOption($option);
            }
            $this->command = $command;
        };

        $addOperand = function ($value) {
            if (($operand = $this->nextOperand()) && $operand->hasValidation() && !$operand->validates($value)) {
                throw new \UnexpectedValueException(sprintf('Operand %s has an invalid value', $operand->getName()));
            }

            $this->operandValues[] = $value;
        };

        $arguments->process($this, $setCommand, $addOperand);

        if (($operand = $this->nextOperand()) && $operand->isRequired()) {
            throw new \UnexpectedValueException(sprintf('Operand %s is required', $operand->getName()));
        }
    }

    /**
     * Add $options to the list of options
     *
     * $options can be a string as for phps `getopt()` function, an array of Option instances or an array of arrays.
     *
     * You can also mix Option instances and arrays. Eg.:
     * $getopt->addOptions([
     *   ['?', 'help', Getopt::NO_ARGUMENT, 'Show this help'],
     *   new Option('v', 'verbose'),
     *   (new Option(null, 'version'))->setDescription('Print version and exit'),
     *   Option::create('q', 'quiet')->setDescription('Don\'t write any output')
     *   new Option(
     *     'c',
     *     'config',
     *     Getopt::REQUIRED_ARGUMENT,
     *     new Argument(getenv('HOME') . '/.myapp.inc', 'file_exists', 'file')
     *   )
     * ]);
     *
     * @see OptionParser::parseArray() fo see how to use arrays
     * @param string|array|Option[] $options
     * @return self
     */
    public function addOptions($options)
    {
        if (is_string($options)) {
            $options = $this->getOptionParser()->parseString($options);
        }

        if (!is_array($options)) {
            throw new \InvalidArgumentException('Getopt(): argument must be string or array');
        }

        foreach ($options as $option) {
            $this->addOption($option);
        }

        return $this;
    }

    /**
     * Add $option to the list of options
     *
     * $option can also be a string in format of phps `getopt()` function. But only the first option will be added.
     *
     * Otherwise it has to be an array or an Option instance.
     *
     * @see Getopt::addOptions() for more details
     * @param string|array|Option $option
     * @return self
     */
    public function addOption($option)
    {
        if (!$option instanceof Option) {
            if (is_string($option)) {
                $options = $this->getOptionParser()->parseString($option);
                // this is addOption - so we use only the first one
                $option = $options[0];
            } elseif (is_array($option)) {
                $option = $this->getOptionParser()->parseArray($option);
            } else {
                throw new \InvalidArgumentException(sprintf(
                    '$option has to be a string, an array or an Option. %s given',
                    gettype($option)
                ));
            }
        }

        if ($this->getOption($option->short(), true) || $this->getOption($option->long(), true)) {
            throw new \InvalidArgumentException('$option`s short and long name have to be unique');
        }

        $this->options[] = $option;

        return $this;
    }

    /**
     * Get an option by $name
     *
     * If $object is set to true it returns the Option instead of the value.
     *
     * @param string $name Short or long name of the option
     * @return Option|mixed
     */
    public function getOption($name, $object = false)
    {
        if (!isset($this->optionMapping[$name])) {
            $this->optionMapping[$name] = null;
            foreach ($this->options as $option) {
                if ($option->matches($name)) {
                    $this->optionMapping[$name] = $option;
                    break;
                }
            }
        }

        if ($object) {
            return $this->optionMapping[$name];
        }

        return $this->optionMapping[$name] !== null ? $this->optionMapping[$name]->getValue() : null;
    }

    /**
     * Returns the list of options. Must be invoked after parse() (otherwise it returns an empty array).
     *
     * If $object is set to true it returns an array of Option instances.
     *
     * @param bool $objects
     * @return array
     */
    public function getOptions($objects = false)
    {
        if ($objects) {
            return $this->options;
        }

        $result = [];

        foreach ($this->options as $option) {
            $value = $option->getValue();
            if ($value !== null) {
                if ($short = $option->short()) {
                    $result[$short] = $value;
                }
                if ($long = $option->long()) {
                    $result[$long] = $value;
                }
            }
        }

        return $result;
    }

    public function hasOptions()
    {
        return !empty($this->options);
    }

    /**
     * Add an array of $commands
     *
     * @param Command[] $commands
     * @return self
     */
    public function addCommands(array $commands)
    {
        foreach ($commands as $command) {
            $this->addCommand($command);
        }
        return $this;
    }

    /**
     * Add a $command
     *
     * @param Command $command
     * @return self
     */
    public function addCommand(Command $command)
    {
        foreach ($command->getOptions() as $option) {
            if ($this->getOption($option->short(), true) || $this->getOption($option->long(), true)) {
                throw new \InvalidArgumentException('$command has conflicting options');
            }
        }
        $this->commands[$command->getName()] = $command;
        return $this;
    }

    /**
     * Get the current or a named command.
     *
     * @param string $name
     * @return Command
     */
    public function getCommand($name = null)
    {
        if ($name !== null) {
            return isset($this->commands[$name]) ? $this->commands[$name] : null;
        }

        return $this->command;
    }

    /**
     * @return Command[]
     */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * Check if commands are defined
     *
     * @return bool
     */
    public function hasCommands()
    {
        return !empty($this->commands);
    }

    public function addOperands(array $operands)
    {
        foreach ($operands as $operand) {
            $this->addOperand($operand);
        }

        return $this;
    }

    public function addOperand(Operand $operand)
    {
        $this->operands[] = $operand;

        return $this;
    }

    protected function nextOperand()
    {
        return count($this->operands) > count($this->operandValues) ?
            $this->operands[count($this->operandValues)] : null;
    }

    /**
     * Returns the list of operands. Must be invoked after parse().
     *
     * @return array
     */
    public function getOperands()
    {
        return $this->operandValues;
    }

    /**
     * Returns the i-th operand (starting with 0), or null if it does not exist. Must be invoked after parse().
     *
     * @param int|string $i
     * @return string
     */
    public function getOperand($i)
    {
        if (is_string($i)) {
            $name = $i;
            foreach ($this->operands as $i => $operand) {
                if ($operand->getName() === $name) {
                    // return default when there is no value
                    if ($i >= count($this->operandValues)) {
                        return $operand->getDefaultValue();
                    }
                    break;
                }
            }
        }

        return ($i < count($this->operandValues)) ? $this->operandValues[$i] : null;
    }

    /**
     * Define a custom Help object
     *
     * @param HelpInterface $help
     * @return self
     * @codeCoverageIgnore trivial
     */
    public function setHelp(HelpInterface $help)
    {
        $this->help = $help;
        return $this;
    }

    /**
     * Get the current Help instance
     *
     * @return HelpInterface
     */
    public function getHelp()
    {
        if (!$this->help) {
            $this->help = new Help();
        }

        return $this->help;
    }

    /**
     * Returns an usage information text generated from the given options.
     *
     * The $padding got removed due to refactoring. Help is an own class now. You can change the layout by using a
     * custom template or using a custom help formatter (has to implement HelpInterface)
     *
     * @see Help for setting a custom template
     * @see HelpInterface for creating an custom help formatter
     * @param array $data This data will be forwarded to HelpInterface::render and is available in templates
     * @return string
     */
    public function getHelpText(array $data = [])
    {
        return $this->getHelp()->render($this, $data);
    }

    /**
     * Create or get the OptionParser
     *
     * @return OptionParser
     */
    protected function getOptionParser()
    {
        if ($this->optionParser === null) {
            $this->optionParser = new OptionParser($this->settings[self::SETTING_DEFAULT_MODE]);
        }

        return $this->optionParser;
    }

    // backward compatibility

    /**
     * Set script name manually
     *
     * @param string $scriptName
     * @return self
     * @deprecated Use `Getopt::set(Getopt::SETTING_SCRIPT_NAME, $scriptName)` instead
     * @codeCoverageIgnore
     */
    public function setScriptName($scriptName)
    {
        return $this->set(self::SETTING_SCRIPT_NAME, $scriptName);
    }

    /**
     * Process $arguments or $_SERVER['argv']
     *
     * These function is an alias for process now. Parse was not the correct verb for what
     * the function is currently doing.
     *
     * @deprecated Use `Getopt::process($arguments)` instead
     * @param mixed $arguments optional ARGV array or argument string
     * @codeCoverageIgnore
     */
    public function parse($arguments = null)
    {
        $this->process($arguments);
    }

    // array functions

    public function getIterator()
    {
        $result = [];

        foreach ($this->options as $option) {
            if ($value = $option->getValue()) {
                $name = $option->short() ?: $option->long();
                $result[$name] = $value;
            }
        }

        return new \ArrayIterator($result);
    }

    public function offsetExists($offset)
    {
        $option = $this->getOption($offset, true);
        return $option && $option->getValue() !== null;
    }

    public function offsetGet($offset)
    {
        $option = $this->getOption($offset, true);
        return $option ? $option->getValue() : null;
    }

    public function offsetSet($offset, $value)
    {
        throw new \LogicException('Read only array access');
    }

    public function offsetUnset($offset)
    {
        throw new \LogicException('Read only array access');
    }

    public function count()
    {
        return $this->getIterator()->count();
    }
}
