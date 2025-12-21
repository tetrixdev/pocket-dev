<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryUpdateTool;
use Illuminate\Console\Command;

class MemoryUpdateCommand extends Command
{
    protected $signature = 'memory:update
        {--id= : The UUID of the object to update}
        {--name= : New name for the object}
        {--data= : Fields to update as JSON (merged with existing)}
        {--replace-data : Replace all data instead of merging}
        {--field= : Field name for text operation}
        {--append= : Text to append to field}
        {--prepend= : Text to prepend to field}
        {--replace-text= : Text to find for replacement}
        {--with= : Replacement text (used with --replace-text)}
        {--insert-after= : Marker text to insert after}
        {--insert-text= : Text to insert (used with --insert-after)}';

    protected $description = 'Update an existing memory object';

    public function handle(): int
    {
        $id = $this->option('id');
        $name = $this->option('name');
        $data = $this->option('data');
        $replaceData = $this->option('replace-data');

        // Text operation options
        $field = $this->option('field');
        $append = $this->option('append');
        $prepend = $this->option('prepend');
        $replaceText = $this->option('replace-text');
        $withText = $this->option('with');
        $insertAfter = $this->option('insert-after');
        $insertText = $this->option('insert-text');

        if (empty($id)) {
            return $this->outputError('The --id option is required');
        }

        $input = ['id' => $id];

        if ($name) {
            $input['name'] = $name;
        }

        if ($data) {
            $decodedData = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->outputError('Invalid JSON in --data: ' . json_last_error_msg());
            }
            $input['data'] = $decodedData;
        }

        if ($replaceData) {
            $input['replace_data'] = true;
        }

        // Build text operation if specified
        if ($field) {
            $textOp = ['field' => $field];

            if ($append !== null) {
                $textOp['operation'] = 'append';
                $textOp['text'] = $append;
            } elseif ($prepend !== null) {
                $textOp['operation'] = 'prepend';
                $textOp['text'] = $prepend;
            } elseif ($replaceText !== null) {
                if ($withText === null) {
                    return $this->outputError('--replace-text requires --with to specify the replacement text');
                }
                $textOp['operation'] = 'replace';
                $textOp['find'] = $replaceText;
                $textOp['text'] = $withText;
            } elseif ($insertAfter !== null) {
                if ($insertText === null) {
                    return $this->outputError('--insert-after requires --insert-text to specify the text to insert');
                }
                $textOp['operation'] = 'insert_after';
                $textOp['find'] = $insertAfter;
                $textOp['text'] = $insertText;
            } else {
                return $this->outputError('--field requires one of: --append, --prepend, --replace-text, --insert-after');
            }

            $input['text_operation'] = $textOp;
        }

        $tool = new MemoryUpdateTool();
        $context = new ExecutionContext(getcwd());
        $result = $tool->execute($input, $context);

        $this->outputResult($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    private function outputError(string $message): int
    {
        $this->output->writeln(json_encode([
            'output' => $message,
            'is_error' => true,
        ], JSON_PRETTY_PRINT));

        return Command::FAILURE;
    }

    private function outputResult(array $result): void
    {
        $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT));
    }
}
