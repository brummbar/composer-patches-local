<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that run real Composer in a temporary project.
 *
 * Set the environment variable KEEP_FIXTURES=1 to keep the project directories.
 */
abstract class ComposerTestCase extends TestCase
{
    /** @var list<ComposerProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            if (getenv('KEEP_FIXTURES') === '1') {
                fwrite(STDERR, "\nKept fixture: {$project->directory}\n");
                continue;
            }
            $project->remove();
        }
        $this->projects = [];
    }

    protected function createProject(): ComposerProject
    {
        return $this->projects[] = new ComposerProject();
    }

    /**
     * Registers a project that the test made, for example a checkout, so that tear down removes it.
     */
    protected function track(ComposerProject $project): ComposerProject
    {
        return $this->projects[] = $project;
    }

    protected function assertComposerSucceeded(ComposerResult $result): void
    {
        $this->assertSame(0, $result->exitCode, $this->describe('Composer must exit with code 0.', $result));
    }

    protected function assertComposerFailed(ComposerResult $result): void
    {
        $this->assertNotSame(0, $result->exitCode, $this->describe('Composer must exit with an error.', $result));
    }

    protected function assertOutputContains(string $needle, ComposerResult $result): void
    {
        $this->assertStringContainsString($needle, $result->output, $this->describe("The output must contain: $needle", $result));
    }

    protected function assertProjectFileSame(string $expected, ComposerProject $project, string $path): void
    {
        $this->assertSame($expected, $project->readFile($path), "Unexpected content in $path.");
    }

    /**
     * Builds a failure message that shows the Composer output.
     */
    protected function describe(string $message, ComposerResult $result): string
    {
        return sprintf(
            "%s\nCommand: %s\nExit code: %d\n--- Composer output ---\n%s\n--- End of output ---",
            $message,
            $result->commandLine,
            $result->exitCode,
            $result->output,
        );
    }

    /**
     * Returns environment variables that send HTTP traffic to a closed local port.
     *
     * @return array<string, string>
     */
    protected function unreachableNetwork(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        $proxy = "http://127.0.0.1:$port";

        return ['http_proxy' => $proxy, 'HTTP_PROXY' => $proxy, 'no_proxy' => '', 'NO_PROXY' => ''];
    }
}
