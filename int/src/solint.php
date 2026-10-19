<?php

/**
 * This script serves as the main entry point for the SOL26 interpreter.
 *
 * It parses command-line arguments, and uses the Interpreter class to load and execute
 * the specified program in the SOL-XML format.
 *
 * IPP: You should not need to modify this file, except for adding extra CLI options if needed.
 *
 * Author: Ondřej Ondryáš <iondryas@fit.vut.cz>
 *
 * AI usage notice: The author used OpenAI Codex to create the implementation of this
 *                  module based on its Python counterpart.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ahc\Cli\Exception\RuntimeException as CliRuntimeException;
use Ahc\Cli\Input\Command;
use IPP\Interpreter\Interpreter;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\Exception\ErrorCode;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\PsrLogMessageProcessor;

// Define the CLI arguments
$command = new class ('solint', 'SOL26 interpreter') extends Command {
    protected function handleUnknown(string $arg, ?string $value = null): mixed
    {
        throw new CliRuntimeException(sprintf('Option "%s" not registered', $arg));
    }

    public function incrementVerbose(?string $value = null): bool
    {
        $this->set('verbose', ((int) ($this->verbose ?? 0)) + 1);
        return false;
    }
};

$command->unset('version');
$command->unset('verbosity');
$command->option('-s, --source [path]', 'Path to the SOL-XML source file to be interpreted.');
$command->option(
    '-i, --input [path]',
    'Path to a file used as standard input for the interpreted program.'
);
$command->option(
    '-v, --verbose',
    'Enable verbose logging output (using once = INFO, using twice = DEBUG).',
    null,
    0
)->on([$command, 'incrementVerbose']);

// Parse the provided arguments
try {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    $command->parse($arguments);
} catch (CliRuntimeException $e) {
    ErrorCode::GENERAL_OPTIONS->fire($e->getMessage());
}

$parsedValues = $command->values();
$sourcePath = $parsedValues['source'] ?? null;
$inputPath = $parsedValues['input'] ?? null;
$verbosity = (int) ($parsedValues['verbose'] ?? 0);

if (!empty($command->args())) {
    $first = array_values($command->args())[0];
    ErrorCode::GENERAL_OPTIONS->fire(sprintf('Unexpected positional argument: %s', (string) $first));
}

// Check that the provided paths are valid files (exist and are not directories)
if ($sourcePath === null) {
    ErrorCode::GENERAL_OPTIONS->fire('Missing required option: --source.');
}
if ($sourcePath === true) {
    ErrorCode::GENERAL_OPTIONS->fire('Option --source requires a value.');
}
if ($inputPath === true) {
    ErrorCode::GENERAL_OPTIONS->fire('Option --input requires a value.');
}
if (!is_string($sourcePath)) {
    ErrorCode::GENERAL_OPTIONS->fire('Invalid --source value.');
}
if ($inputPath !== null && !is_string($inputPath)) {
    ErrorCode::GENERAL_OPTIONS->fire('Invalid --input value.');
}

// Map verbosity count to logging level: default warning, -v info, -vv debug.
$logLevel = Level::Warning;
if ($verbosity >= 2) {
    $logLevel = Level::Debug;
} elseif ($verbosity === 1) {
    $logLevel = Level::Info;
}

// Set up logging
// IPP: You do not have to use logging – but it is the recommended practice.
// See this for more information: https://seldaek.github.io/monolog/
$logger = new Logger('main');
$handler = new StreamHandler('php://stderr', $logLevel);
$handler->setFormatter(
    new LineFormatter(
        "%datetime% %level_name% [%channel%][%extra.class%:%extra.line%] %message%\n",
        'Y-m-d H:i:s',
        true,
        true,
        true
    )
);
$logger->pushProcessor(new PsrLogMessageProcessor());
$logger->pushProcessor(new IntrospectionProcessor(Level::Debug));
$logger->pushHandler($handler);

// Validate source/input files before interpreter startup.
if (!is_file($sourcePath)) {
    ErrorCode::GENERAL_INPUT->fire('Source file does not exist or is not a file.');
}

if ($inputPath !== null && !is_file($inputPath)) {
    ErrorCode::GENERAL_INPUT->fire('Input file does not exist or is not a file.');
}

// Create an instance of the interpreter
$interpreter = new Interpreter($logger->withName('interpreter'));

try {
    // Load the program from the source file
    $interpreter->loadProgram($sourcePath);

    if ($inputPath !== null) {
        // Execute the program using the provided input file as standard input
        $inputIo = new \SplFileObject($inputPath, 'r');
    } else {
        // Execute the program with an empty input stream if no input file was provided
        $inputIo = null;
    }

    $interpreter->execute($inputIo);
} catch (InterpreterError $e) {
    // You are NOT allowed to use exit() anywhere in your code.
    // Handle interpretation errors by raising an appropriate InterpreterError
    // with the correct error code.
    $logger->debug('InterpreterError', ['exception' => $e]);
    $message = $e->getMessage() !== '' ? $e->getMessage() : null;
    $e->errorCode->fire($message);
} catch (\Throwable $e) {
    $logger->error('Unhandled exception during interpretation', ['exception' => $e]);
    ErrorCode::GENERAL_OTHER->fire($e->getMessage());
}
