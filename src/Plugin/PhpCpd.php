<?php

namespace PHPCensor\Plugin;

use Exception;
use PHPCensor\Builder;
use PHPCensor\Exception\RuntimeException;
use PHPCensor\Model\Build;
use PHPCensor\Model\BuildError;
use PHPCensor\Plugin;
use PHPCensor\ZeroConfigPluginInterface;

/**
 * PHP Copy / Paste Detector - Allows PHP Copy / Paste Detector testing.
 *
 * @author Dan Cryer <dan@block8.co.uk>
 */
class PhpCpd extends Plugin implements ZeroConfigPluginInterface
{
    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'php_cpd';
    }

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        $this->executable = $this->findBinary(['phpcpd', 'phpcpd.phar']);
    }

    /**
     * {@inheritdoc}
     */
    public static function canExecuteOnStage($stage, Build $build)
    {
        if (Build::STAGE_TEST === $stage) {
            return true;
        }

        return false;
    }

    /**
     * Runs PHP Copy/Paste Detector in a specified directory.
     */
    public function execute()
    {
        $ignore              = '';
        $ignoreForNewVersion = '';
        if (\is_array($this->ignore)) {
            foreach ($this->ignore as $item) {
                $ignoreForNewVersion .= \sprintf(' --exclude="%s"', $item);

                $item = \rtrim($item, '/');
                if (\is_file($this->builder->buildPath . $item)) {
                    $ignoredFile     = \explode('/', $item);
                    $filesToIgnore[] = \array_pop($ignoredFile);
                } else {
                    $ignore .= \sprintf(' --exclude="%s"', $item);
                }
            }
        }

        if (isset($filesToIgnore)) {
            $filesToIgnore = \sprintf(' --names-exclude="%s"', \implode(',', $filesToIgnore));
            $ignore        = $ignore . $filesToIgnore;
        }

        $phpcpd   = $this->executable;
        $lastLine = \exec(
            \sprintf('cd "%s" && ' . $phpcpd . ' %s "%s" --version', $this->builder->buildPath, $ignore, $this->directory)
        );
        if (false !== \strpos($lastLine, '--names-exclude')) {
            $ignore = $ignoreForNewVersion;
        }

        $tmpFileName = \tempnam(\sys_get_temp_dir(), (self::pluginName() . '_'));

        $cmd     = 'cd "%s" && ' . $phpcpd . ' --log-pmd "%s" %s "%s"';
        $success = $this->builder->executeCommand($cmd, $this->builder->buildPath, $tmpFileName, $ignore, $this->directory);

        $errorCount = $this->processReport(\file_get_contents($tmpFileName));

        $this->build->storeMeta((self::pluginName() . '-warnings'), $errorCount);

        \unlink($tmpFileName);

        return $success;
    }

    /**
     * Process the PHPCPD XML report.
     *
     * @param $xmlString
     *
     * @return int
     *
     * @throws Exception
     */
    protected function processReport($xmlString)
    {
        $xml = simplexml_load_string($xmlString);

        if (false === $xml) {
            $this->builder->log($xmlString);
            throw new RuntimeException('Could not process the report generated by PHPCpd.');
        }

        $warnings = 0;
        foreach ($xml->duplication as $duplication) {
            foreach ($duplication->file as $file) {
                $fileName = (string)$file['path'];
                $fileName = str_replace($this->builder->buildPath, '', $fileName);

                $message = <<<CPD
Copy and paste detected:

```
{$duplication->codefragment}
```
CPD;

                $this->build->reportError(
                    $this->builder,
                    self::pluginName(),
                    $message,
                    BuildError::SEVERITY_NORMAL,
                    $fileName,
                    (int)$file['line'],
                    (int)$file['line'] + (int)$duplication['lines']
                );
            }

            $warnings++;
        }

        return $warnings;
    }
}
