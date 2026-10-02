<?php

/**
 * This file is part of the AIChatPageComponent plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Tests;

use ai\AIChatPageComponentLLM;
use ai\AIChatPageComponentRAMSES;
use PHPUnit\Framework\TestCase;
use platform\AIChatPageComponentException;
use ReflectionClass;
use ReflectionProperty;

/**
 * AI service with short timeouts for the tests
 */
class ShortTimeoutRAMSES extends AIChatPageComponentRAMSES
{
    protected const STREAM_FIRST_DATA_TIMEOUT = 3;
    protected const STREAM_IDLE_TIMEOUT = 2;
    protected const STREAM_END_GRACE = 1;
    protected const REQUEST_TIMEOUT = 20;
}

/**
 * Tests the end detection and timeouts of streamed answers against a local server
 *
 * The server simulates an AI service that sends events split across chunks and
 * keeps the connection open after the end marker or stops sending.
 */
class StreamHandlingTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base_url = '';
    private static string $router = '';

    private AIChatPageComponentLLM $llm;

    public static function setUpBeforeClass(): void
    {
        self::$router = tempnam(sys_get_temp_dir(), 'pcaic_sse') . '.php';
        file_put_contents(self::$router, <<<'PHP'
<?php
header('Content-Type: text/event-stream');
while (ob_get_level() > 0) {
    ob_end_flush();
}
$event = static fn (string $content, ?string $finish = null): string => 'data: '
    . json_encode(['choices' => [['delta' => ['content' => $content], 'finish_reason' => $finish]]]) . "\n\n";
$send = static function (string $data): void {
    echo $data;
    flush();
    usleep(50000);
};
$first = $event('Hallo ');
switch ($_GET['case'] ?? '') {
    case 'done_open': // event split across chunks, connection stays open after [DONE]
        $send(substr($first, 0, 12));
        $send(substr($first, 12));
        $send($event('Welt'));
        $send($event('', 'stop'));
        $send("data: [DONE]\n\n");
        sleep(15);
        break;
    case 'finish_stall': // end of answer reported, end marker missing
        $send($first);
        $send($event('', 'stop'));
        sleep(15);
        break;
    case 'stall': // no data in the middle of the answer
        $send($first);
        sleep(15);
        break;
    case 'silent': // no data at all
        sleep(15);
        break;
    case 'close': // regular end
        $send($first);
        $send($event('', 'stop'));
        $send("data: [DONE]\n\n");
        break;
}
PHP);

        $port = random_int(20000, 40000);
        self::$base_url = "http://127.0.0.1:$port/";
        $command = sprintf(
            'PHP_CLI_SERVER_WORKERS=12 exec %s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(self::$router)
        );
        self::$server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        self::fail('Test server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$router);
    }

    protected function setUp(): void
    {
        $this->llm = (new ReflectionClass(ShortTimeoutRAMSES::class))->newInstanceWithoutConstructor();
        $logger = new ReflectionProperty(AIChatPageComponentLLM::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($this->llm, new \ilLogger());
        $this->llm->setStreaming(true);
    }

    /**
     * @return array{error: int, seconds: float, forwarded: string, raw: string}
     */
    private function request(string $case): array
    {
        $forwarded = '';
        ob_start(static function (string $buffer) use (&$forwarded): string {
            $forwarded .= $buffer;
            return '';
        });

        $request = \Closure::bind(function (string $url): array {
            $raw = '';
            $curl = curl_init($url);
            $state = $this->configureChatRequest($curl, $raw);
            $start = microtime(true);
            curl_exec($curl);
            $seconds = microtime(true) - $start;
            $error = curl_errno($curl);
            curl_close($curl);
            return ['error' => $this->resolveChatRequestError($error, $state, $url), 'seconds' => $seconds, 'raw' => $raw];
        }, $this->llm, AIChatPageComponentLLM::class);

        try {
            $result = $request(self::$base_url . '?case=' . $case);
        } finally {
            ob_end_flush();
        }

        return $result + ['forwarded' => $forwarded];
    }

    public function testTransferEndsAtEndMarkerAlthoughConnectionStaysOpen(): void
    {
        $result = $this->request('done_open');

        $this->assertSame(0, $result['error']);
        $this->assertLessThan(3, $result['seconds']);
        $this->assertStringContainsString('[DONE]', $result['raw']);
    }

    public function testEventsSplitAcrossChunksAreForwarded(): void
    {
        $result = $this->request('done_open');

        $this->assertStringContainsString('"content":"Hallo "', $result['forwarded']);
        $this->assertStringContainsString('"content":"Welt"', $result['forwarded']);
    }

    public function testMissingEndMarkerAfterCompleteAnswerIsNoError(): void
    {
        $result = $this->request('finish_stall');

        // Ended shortly after the reported end of the answer
        $this->assertSame(0, $result['error']);
        $this->assertLessThan(4, $result['seconds']);
    }

    public function testStalledAnswerIsReportedAsBusy(): void
    {
        $this->expectException(AIChatPageComponentException::class);
        $this->expectExceptionMessage(AIChatPageComponentLLM::SERVICE_BUSY);

        $this->request('stall');
    }

    public function testStalledAnswerIsAbortedAfterIdleTimeout(): void
    {
        $start = microtime(true);
        try {
            $this->request('stall');
            $this->fail('Exception expected');
        } catch (AIChatPageComponentException $e) {
            $this->assertLessThan(5, microtime(true) - $start);
        }
    }

    public function testMissingFirstDataIsReportedAsBusy(): void
    {
        $this->expectException(AIChatPageComponentException::class);
        $this->expectExceptionMessage(AIChatPageComponentLLM::SERVICE_BUSY);

        $this->request('silent');
    }

    public function testRegularEnd(): void
    {
        $result = $this->request('close');

        $this->assertSame(0, $result['error']);
        $this->assertStringContainsString('"content":"Hallo "', $result['forwarded']);
    }
}
