<?php

/**
 * This module contains the main logic of the interpreter.
 *
 * IPP: You must definitely modify this file. Bend it to your will.
 *
 * Author: Ondrej Ondryas <iondryas@fit.vut.cz>
 * Author:
 *
 * AI usage notice: The template author used OpenAI Codex to create the implementation of this
 *                  module based on its Python counterpart.
 */

declare(strict_types=1);

namespace IPP\Interpreter;

use DOMDocument;
use DOMElement;
use IPP\Interpreter\Analysis\StaticChecker;
use IPP\Interpreter\InputModel\XmlValidationException;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\InputModel\Program;
use IPP\Interpreter\Runtime\Runtime;
use IPP\Interpreter\Runtime\ProgramExecutor;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use SplFileObject;

/**
 * The main interpreter class, responsible for loading the source file and executing the program.
 */
class Interpreter
{
    private LoggerInterface $logger;
    private ?Program $currentProgram = null;
    private StaticChecker $staticChecker;
    private ProgramExecutor $programExecutor;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?StaticChecker $staticChecker = null,
        ?ProgramExecutor $programExecutor = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->staticChecker = $staticChecker ?? new StaticChecker();
        $this->programExecutor = $programExecutor ?? new ProgramExecutor();
    }

    /**
     * Reads the source SOL-XML file and stores it as the target program for this interpreter.
     * If any program was previously loaded, it is replaced by the new one.
     *
     * IPP: If you wish to run static checks on the program before execution, this is a good
     *      place to call them from.
     */
    public function loadProgram(string $sourceFilePath): void
    {
        $this->logger->info('Opening source file: {source_file}', ['source_file' => $sourceFilePath]);

        $xmlDocument = new DOMDocument();
        // Temporarily suppress libxml warnings so XML parse errors are reported
        // through interpreter error codes instead of raw PHP warnings.
        $previous = libxml_use_internal_errors(true);

        try {
            if ($xmlDocument->load($sourceFilePath) !== true) {
                throw new InterpreterError(
                    ErrorCode::INT_XML,
                    'Error parsing input XML'
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $rootElement = $xmlDocument->documentElement;
        if (!$rootElement instanceof DOMElement) {
            throw new InterpreterError(ErrorCode::INT_STRUCTURE, 'Invalid SOL-XML structure');
        }

        try {
            // Build the typed in-memory model first, then run static checks on it.
            $this->currentProgram = Program::fromXml($rootElement);
            if ($this->currentProgram->language !== 'SOL26') {
                throw new InterpreterError(
                    ErrorCode::INT_STRUCTURE,
                    'Invalid SOL-XML structure: attribute language must be "SOL26".'
                );
            }
            $this->staticChecker->check($this->currentProgram);
        } catch (XmlValidationException $e) {
            // XML-model validation errors are mapped to the required structure error code.
            throw new InterpreterError(ErrorCode::INT_STRUCTURE, 'Invalid SOL-XML structure', $e);
        }
    }

    /**
     * Executes the currently loaded program, using the provided input stream as standard input.
     */
    public function execute(?SplFileObject $inputIo): void
    {
        if ($this->currentProgram === null) {
            throw new InterpreterError(ErrorCode::INT_OTHER, 'No program is loaded.');
        }

        $this->logger->info('Executing program');
        // Each execute() call gets a fresh runtime (stdin handle, objects, execution state).
        $this->programExecutor->execute($this->currentProgram, new Runtime($inputIo));
    }
}
